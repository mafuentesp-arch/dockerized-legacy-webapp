<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['admin','staff']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';

if (file_exists(__DIR__ . '/includes/audit.php')) {
    require_once __DIR__ . '/includes/audit.php';
}

ensure_session_started();

$message = '';
$summary = null;
$selectedRosterId = cleanInt($_POST['roster_id'] ?? $_GET['roster_id'] ?? 0);

function normalizeImportKey($value): string
{
    return strtolower(preg_replace('/\s+/', '', trim((string) ($value ?? ''))));
}

function normalizeImportPhone($value): string
{
    return preg_replace('/\D+/', '', (string) ($value ?? ''));
}

function excelColumnIndex(string $cellRef): int
{
    preg_match('/^[A-Z]+/i', $cellRef, $matches);
    $letters = strtoupper($matches[0] ?? '');
    $index = 0;

    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }

    return $index - 1;
}

function readXlsxFallback(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PhpSpreadsheet or ZipArchive is required to read XLSX files.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Unable to open XLSX file.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $shared = simplexml_load_string($sharedXml);
        foreach ($shared->si as $si) {
            if (isset($si->t)) {
                $sharedStrings[] = (string) $si->t;
            } else {
                $text = '';
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('Unable to read first worksheet.');
    }

    $sheet = simplexml_load_string($sheetXml);
    $rows = [];

    foreach ($sheet->sheetData->row as $row) {
        $rowValues = [];

        foreach ($row->c as $cell) {
            $cellRef = (string) $cell['r'];
            $type = (string) $cell['t'];
            $index = excelColumnIndex($cellRef);
            $value = (string) $cell->v;

            if ($type === 's') {
                $value = $sharedStrings[(int) $value] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) $cell->is->t;
            }

            $rowValues[$index] = trim($value);
        }

        if ($rowValues) {
            ksort($rowValues);
            $rows[] = $rowValues;
        }
    }

    return $rows;
}

function readExcelRows(string $path, string $extension): array
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        return $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
    }

    if ($extension === 'xlsx') {
        return readXlsxFallback($path);
    }

    throw new RuntimeException('PhpSpreadsheet is required to read XLS files.');
}

function parseRosterDate($value, int $year): ?string
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return null;
    }

    if (is_numeric($raw)) {
        $timestamp = ((int) $raw - 25569) * 86400;
        return gmdate('Y-m-d', $timestamp);
    }

    $raw = str_replace(['.', '/'], '-', $raw);
    $formats = ['j-M-Y', 'd-M-Y', 'j-M-y', 'd-M-y', 'Y-m-d', 'm-d-Y', 'n-j-Y'];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, str_contains($format, 'Y') || str_contains($format, 'y') ? $raw : $raw . '-' . $year);
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }

    $date = DateTime::createFromFormat('j-M-Y', $raw . '-' . $year);
    return $date instanceof DateTime ? $date->format('Y-m-d') : null;
}

function findHeaderRow(array $rows): array
{
    $required = ['name', 'last name', 'phone', 'email', 'country'];

    foreach ($rows as $rowIndex => $row) {
        $normalized = [];
        foreach ($row as $index => $value) {
            $normalized[strtolower(trim((string) $value))] = $index;
        }

        $found = [];
        foreach ($required as $column) {
            if (array_key_exists($column, $normalized)) {
                $found[$column] = $normalized[$column];
            }
        }

        if (count($found) === count($required)) {
            return [$rowIndex, $found];
        }
    }

    throw new RuntimeException('Could not detect required header row.');
}

$rosters = $pdo->query("
    SELECT r.roster_id, r.roster_name, r.year, r.season, p.program_name
    FROM rosters r
    LEFT JOIN programs p ON r.program_id = p.program_id
    ORDER BY r.year DESC, p.program_name ASC, r.roster_name ASC
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        if ($selectedRosterId <= 0) {
            throw new RuntimeException('Please select a roster.');
        }

        $rosterStmt = $pdo->prepare("
            SELECT r.roster_id, r.roster_name, r.year, r.season, p.program_name
            FROM rosters r
            LEFT JOIN programs p ON r.program_id = p.program_id
            WHERE r.roster_id = ?
            LIMIT 1
        ");
        $rosterStmt->execute([$selectedRosterId]);
        $selectedRoster = $rosterStmt->fetch();

        if (!$selectedRoster) {
            throw new RuntimeException('Selected roster was not found.');
        }

        if (!isset($_FILES['attendance_file']) || $_FILES['attendance_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Please upload a valid Excel file.');
        }

        $originalName = $_FILES['attendance_file']['name'] ?? '';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'xls'], true)) {
            throw new RuntimeException('Only .xlsx and .xls files are allowed.');
        }

        $rows = readExcelRows($_FILES['attendance_file']['tmp_name'], $extension);
        [$headerIndex, $columns] = findHeaderRow($rows);
        $header = $rows[$headerIndex];
        $countryIndex = $columns['country'];
        $dateColumns = [];

        foreach ($header as $index => $value) {
            if ($index <= $countryIndex) {
                continue;
            }

            $date = parseRosterDate($value, (int) $selectedRoster['year']);
            if ($date !== null) {
                $dateColumns[$index] = $date;
            }
        }

        if (!$dateColumns) {
            throw new RuntimeException('No attendance date columns were detected.');
        }

        $totalStudents = 0;
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            $firstName = trim((string) ($row[$columns['name']] ?? ''));
            $lastName = trim((string) ($row[$columns['last name']] ?? ''));
            $fullName = trim($firstName . ' ' . $lastName);
            $phone = trim((string) ($row[$columns['phone']] ?? ''));
            $email = trim((string) ($row[$columns['email']] ?? ''));
            $country = trim((string) ($row[$columns['country']] ?? ''));
            $phoneNormalized = normalizeImportPhone($phone);
            $nameKey = normalizeImportKey($fullName);

            if ($fullName === '') {
                $skipped++;
                continue;
            }

            $totalStudents++;

            $student = null;
            if ($email !== '') {
                $stmt = $pdo->prepare("SELECT student_id FROM students_master WHERE LOWER(email) = LOWER(?) LIMIT 1");
                $stmt->execute([$email]);
                $student = $stmt->fetch();
            }

            if (!$student && $phoneNormalized !== '') {
                $stmt = $pdo->prepare("SELECT student_id FROM students_master WHERE phone_normalized = ? LIMIT 1");
                $stmt->execute([$phoneNormalized]);
                $student = $stmt->fetch();
            }

            if (!$student) {
                $stmt = $pdo->prepare("
                    SELECT student_id
                    FROM students_master
                    WHERE LOWER(REPLACE(canonical_name, ' ', '')) = ?
                    LIMIT 1
                ");
                $stmt->execute([$nameKey]);
                $student = $stmt->fetch();
            }

            if ($student) {
                $studentId = (int) $student['student_id'];
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO students_master
                    (canonical_name, phone, phone_normalized, email, language, status)
                    VALUES (?, ?, ?, ?, ?, 'active')
                ");
                $stmt->execute([$fullName, $phone, $phoneNormalized ?: null, $email, $country]);
                $studentId = (int) $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare("
                SELECT status
                FROM student_rosters
                WHERE student_id = ?
                  AND roster_id = ?
                LIMIT 1
            ");
            $stmt->execute([$studentId, $selectedRosterId]);
            $rosterStatus = $stmt->fetchColumn();

            if ($rosterStatus === false) {
                $rosterStatus = 'active';
                $stmt = $pdo->prepare("
                    INSERT INTO student_rosters
                    (student_id, roster_id, status)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$studentId, $selectedRosterId, $rosterStatus]);
            }

            foreach ($dateColumns as $index => $classDate) {
                $cellValue = trim((string) ($row[$index] ?? ''));
                $present = $cellValue === '1' ? 1 : 0;

                $stmt = $pdo->prepare("
                    SELECT attendance_id
                    FROM attendance_records
                    WHERE student_id = ?
                      AND roster_id = ?
                      AND class_date = ?
                    LIMIT 1
                ");
                $stmt->execute([$studentId, $selectedRosterId, $classDate]);
                $existingId = $stmt->fetchColumn();

                if ($existingId) {
                    $stmt = $pdo->prepare("
                        UPDATE attendance_records
                        SET present = ?,
                            attendance_source = 'excel',
                            raw_name = ?,
                            email = ?,
                            status = ?,
                            source_file = ?
                        WHERE attendance_id = ?
                    ");
                    $stmt->execute([$present, $fullName, $email, $rosterStatus, $originalName, $existingId]);
                    $updated++;
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO attendance_records
                        (student_id, roster_id, class_date, present, attendance_source, raw_name, email, status, source_file)
                        VALUES (?, ?, ?, ?, 'excel', ?, ?, ?, ?)
                    ");
                    $stmt->execute([$studentId, $selectedRosterId, $classDate, $present, $fullName, $email, $rosterStatus, $originalName]);
                    $inserted++;
                }
            }
        }

        if (function_exists('logActivity')) {
            logActivity('IMPORT', 'attendance_records', null, 'Imported roster Excel attendance for ' . $selectedRoster['roster_name']);
        }

        $summary = [
            'roster' => $selectedRoster,
            'total_students' => $totalStudents,
            'dates_detected' => count($dateColumns),
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    } catch (Throwable $e) {
        $message = "<div class='alert alert-danger'>" . e($e->getMessage()) . "</div>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Import Roster Excel Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f4f6f9; }
        .card {
            border:0;
            border-radius:16px;
            box-shadow:0 8px 22px rgba(0,0,0,.06);
        }
    </style>
</head>

<body>
<div class="container-fluid app-page">
    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">Import Excel Attendance</h3>
        <small class="page-subtitle">Import attendance records from roster Excel files.</small>
    </div>

    <?= $message ?>

    <?php if ($summary): ?>
        <div class="alert alert-success">
            <b>Import completed for <?= e($summary['roster']['roster_name']) ?></b><br>
            Total students read: <?= e($summary['total_students']) ?><br>
            Dates detected: <?= e($summary['dates_detected']) ?><br>
            Attendance records inserted: <?= e($summary['inserted']) ?><br>
            Records updated: <?= e($summary['updated']) ?><br>
            Skipped rows: <?= e($summary['skipped']) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header bg-white fw-bold">Upload Excel Attendance Roster</div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="col-lg-6">
                    <label class="form-label fw-bold">Roster</label>
                    <select name="roster_id" class="form-select" required>
                        <option value="">Select roster...</option>
                        <?php foreach ($rosters as $roster): ?>
                            <option value="<?= e($roster['roster_id']) ?>" <?= $selectedRosterId === (int) $roster['roster_id'] ? 'selected' : '' ?>>
                                <?= e($roster['program_name']) ?> -
                                <?= e($roster['roster_name']) ?>
                                (<?= e($roster['season']) ?> <?= e($roster['year']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-4">
                    <label class="form-label fw-bold">Excel file</label>
                    <input type="file" name="attendance_file" class="form-control" accept=".xlsx,.xls" required>
                </div>

                <div class="col-lg-2">
                    <button class="btn btn-primary w-100">Import</button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
