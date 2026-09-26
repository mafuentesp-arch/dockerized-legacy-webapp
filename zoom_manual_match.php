<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['admin', 'staff']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';

if (file_exists(__DIR__ . '/includes/audit.php')) {
    require_once __DIR__ . '/includes/audit.php';
}

$message = '';
$selectedProgramId = cleanInt($_GET['program_id'] ?? $_POST['program_id'] ?? 0);
$selectedRosterId = cleanInt($_GET['roster_id'] ?? $_POST['roster_id'] ?? 0);
$selectedDate = cleanText($_GET['class_date'] ?? $_POST['class_date'] ?? '', 20);
$selectedSourceFile = cleanText($_GET['source_file'] ?? $_POST['source_file'] ?? '', 255);
$studentSearch = cleanText($_GET['student_search'] ?? $_POST['student_search'] ?? '', 100);
$studentSearch = preg_replace('/\s+/', ' ', trim($studentSearch));

function zoom_match_setting(PDO $pdo, string $name, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare("
            SELECT setting_value
            FROM system_settings
            WHERE setting_name = ?
            LIMIT 1
        ");
        $stmt->execute([$name]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function attendance_zoom_has_column(PDO $pdo, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM attendance_zoom LIKE ?");
        $stmt->execute([$column]);

        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

$hasZoomRosterId = attendance_zoom_has_column($pdo, 'roster_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_zoom_match'])) {
    verify_csrf();

    $attendanceId = cleanInt($_POST['attendance_id'] ?? 0);
    $studentId = cleanInt($_POST['student_id'] ?? 0);

    if ($selectedRosterId <= 0 || $attendanceId <= 0 || $studentId <= 0) {
        $message = "<div class='alert alert-danger'>Select a roster, Zoom record, and student before saving.</div>";
    } else {
        $attendanceStmt = $pdo->prepare("
            SELECT attendance_id, raw_name
            FROM attendance_zoom
            WHERE attendance_id = ?
              AND match_status = 'unmatched'
            LIMIT 1
        ");
        $attendanceStmt->execute([$attendanceId]);
        $attendance = $attendanceStmt->fetch();

        $studentStmt = $pdo->prepare("
            SELECT sm.student_id, sm.canonical_name
            FROM student_rosters sr
            INNER JOIN students_master sm ON sr.student_id = sm.student_id
            WHERE sr.roster_id = ?
              AND sm.student_id = ?
            LIMIT 1
        ");
        $studentStmt->execute([$selectedRosterId, $studentId]);
        $student = $studentStmt->fetch();

        $rosterStmt = $pdo->prepare("
            SELECT r.roster_name, r.season, r.year, p.program_name
            FROM rosters r
            INNER JOIN programs p ON r.program_id = p.program_id
            WHERE r.roster_id = ?
            LIMIT 1
        ");
        $rosterStmt->execute([$selectedRosterId]);
        $roster = $rosterStmt->fetch();

        if (!$attendance) {
            $message = "<div class='alert alert-danger'>Unable to find the selected unmatched Zoom record.</div>";
        } elseif (!$student) {
            $message = "<div class='alert alert-danger'>Selected student is not assigned to the selected roster.</div>";
        } elseif (!$roster) {
            $message = "<div class='alert alert-danger'>Selected roster was not found.</div>";
        } else {
            if ($hasZoomRosterId) {
                $updateStmt = $pdo->prepare("
                    UPDATE attendance_zoom
                    SET student_id = ?,
                        roster_id = ?,
                        match_status = 'matched'
                    WHERE attendance_id = ?
                ");
                $updateStmt->execute([$studentId, $selectedRosterId, $attendanceId]);
            } else {
                $updateStmt = $pdo->prepare("
                    UPDATE attendance_zoom
                    SET student_id = ?,
                        match_status = 'matched'
                    WHERE attendance_id = ?
                ");
                $updateStmt->execute([$studentId, $attendanceId]);
            }

            $rawName = trim((string) $attendance['raw_name']);

            if ($rawName !== '') {
                $aliasCheck = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM student_aliases
                    WHERE student_id = ?
                      AND LOWER(alias_name) = LOWER(?)
                ");
                $aliasCheck->execute([$studentId, $rawName]);

                if ((int) $aliasCheck->fetchColumn() === 0) {
                    $aliasStmt = $pdo->prepare("
                        INSERT INTO student_aliases
                        (student_id, alias_name, source)
                        VALUES (?, ?, 'manual_zoom')
                    ");
                    $aliasStmt->execute([$studentId, $rawName]);
                }
            }

            $rosterName = $roster['program_name'] . ' - ' . $roster['roster_name'] . ' (' . $roster['season'] . ' ' . $roster['year'] . ')';

            if (function_exists('logActivity')) {
                logActivity(
                    'UPDATE',
                    'attendance_zoom',
                    $attendanceId,
                    'Matched Zoom ' . $rawName . ' to ' . $student['canonical_name'] . ' in ' . $rosterName
                );
            }

            $query = http_build_query([
                'matched' => 1,
                'program_id' => $selectedProgramId,
                'roster_id' => $selectedRosterId,
                'class_date' => $selectedDate,
                'source_file' => $selectedSourceFile,
                'student_search' => $studentSearch,
            ]);
            header('Location: zoom_manual_match.php?' . $query);
            exit;
        }
    }
}

if (isset($_GET['matched'])) {
    $message = "<div class='alert alert-success'>Zoom record matched successfully.</div>";
}

$currentYear = zoom_match_setting($pdo, 'current_year', date('Y'));
$currentSeason = zoom_match_setting($pdo, 'current_season', 'Spring');

$programsStmt = $pdo->prepare("
    SELECT program_id, program_name
    FROM programs
    ORDER BY program_name ASC
");
$programsStmt->execute();
$programs = $programsStmt->fetchAll();

$rosterParams = [];
$rosterWhere = '';

if ($selectedProgramId > 0) {
    $rosterWhere = 'WHERE r.program_id = ?';
    $rosterParams[] = $selectedProgramId;
}

$rosterParams[] = $currentYear;
$rosterParams[] = $currentSeason;

$rostersStmt = $pdo->prepare("
    SELECT
        r.roster_id,
        r.roster_name,
        r.season,
        r.year,
        r.program_id,
        p.program_name
    FROM rosters r
    INNER JOIN programs p ON r.program_id = p.program_id
    $rosterWhere
    ORDER BY
        CASE WHEN r.year = ? AND r.season = ? THEN 0 ELSE 1 END,
        r.year DESC,
        FIELD(r.season,'Winter','Spring','Summer','Fall'),
        p.program_name ASC,
        r.roster_name ASC
");
$rostersStmt->execute($rosterParams);
$rosters = $rostersStmt->fetchAll();

$selectedRoster = null;

if ($selectedRosterId > 0) {
    $selectedRosterStmt = $pdo->prepare("
        SELECT r.roster_id, r.roster_name, r.season, r.year, p.program_name
        FROM rosters r
        INNER JOIN programs p ON r.program_id = p.program_id
        WHERE r.roster_id = ?
        LIMIT 1
    ");
    $selectedRosterStmt->execute([$selectedRosterId]);
    $selectedRoster = $selectedRosterStmt->fetch();
}

$dateStmt = $pdo->prepare("
    SELECT DISTINCT class_date
    FROM attendance_zoom
    WHERE class_date IS NOT NULL
    ORDER BY class_date DESC
    LIMIT 200
");
$dateStmt->execute();
$classDates = $dateStmt->fetchAll();

$sourceStmt = $pdo->prepare("
    SELECT DISTINCT source_file
    FROM attendance_zoom
    WHERE source_file IS NOT NULL
      AND source_file <> ''
    ORDER BY source_file ASC
");
$sourceStmt->execute();
$sourceFiles = $sourceStmt->fetchAll();

$zoomWhere = [];
$zoomParams = [];

if ($selectedDate !== '') {
    $zoomWhere[] = 'class_date = ?';
    $zoomParams[] = $selectedDate;
}

if ($selectedSourceFile !== '') {
    $zoomWhere[] = 'source_file = ?';
    $zoomParams[] = $selectedSourceFile;
}

$zoomFilterSql = $zoomWhere ? ' AND ' . implode(' AND ', $zoomWhere) : '';

$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_filtered,
        SUM(CASE WHEN match_status = 'unmatched' THEN 1 ELSE 0 END) AS unmatched_count,
        SUM(CASE WHEN match_status = 'matched' THEN 1 ELSE 0 END) AS matched_count
    FROM attendance_zoom
    WHERE 1=1
    $zoomFilterSql
");
$summaryStmt->execute($zoomParams);
$summary = $summaryStmt->fetch() ?: [
    'total_filtered' => 0,
    'unmatched_count' => 0,
    'matched_count' => 0,
];

$totalFiltered = (int) ($summary['total_filtered'] ?? 0);
$matchedCount = (int) ($summary['matched_count'] ?? 0);
$matchRate = $totalFiltered > 0 ? round(($matchedCount / $totalFiltered) * 100, 1) : 0;

$students = [];

if ($selectedRosterId > 0) {
    $studentWhere = ['sr.roster_id = ?'];
    $studentParams = [$selectedRosterId];

    if ($studentSearch !== '') {
        $terms = preg_split('/\s+/', mb_strtolower($studentSearch), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($terms as $term) {
            $studentWhere[] = "LOWER(CONCAT_WS(' ', sm.canonical_name, sm.phone, sm.email)) LIKE ?";
            $studentParams[] = '%' . $term . '%';
        }
    }

    $studentSearchStmt = $pdo->prepare("
        SELECT DISTINCT
            sm.student_id,
            sm.canonical_name,
            sm.phone,
            sm.email,
            sm.status
        FROM student_rosters sr
        INNER JOIN students_master sm ON sr.student_id = sm.student_id
        WHERE " . implode(' AND ', $studentWhere) . "
        ORDER BY sm.canonical_name ASC
        LIMIT 75
    ");
    $studentSearchStmt->execute($studentParams);
    $students = $studentSearchStmt->fetchAll();
}

$attendanceStmt = $pdo->prepare("
    SELECT
        attendance_id,
        raw_name,
        email,
        class_date,
        total_minutes,
        source_file
    FROM attendance_zoom
    WHERE match_status = 'unmatched'
    $zoomFilterSql
    ORDER BY class_date DESC, raw_name ASC
    LIMIT 200
");
$attendanceStmt->execute($zoomParams);
$unmatchedAttendance = $attendanceStmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Zoom Match</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>

<div class="container-fluid app-page">

    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">Zoom Match</h3>
        <small class="page-subtitle">Match unmatched Zoom attendance names to students in a selected roster.</small>
    </div>

    <?= $message ?>

    <div class="card mb-3">
        <div class="card-header bg-white fw-bold">Filters</div>
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Program</label>
                    <select name="program_id" class="form-select">
                        <option value="">All programs</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?= e($program['program_id']) ?>" <?= $selectedProgramId === (int) $program['program_id'] ? 'selected' : '' ?>>
                                <?= e($program['program_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-bold">Roster</label>
                    <select name="roster_id" class="form-select" required>
                        <option value="">Select roster...</option>
                        <?php foreach ($rosters as $roster): ?>
                            <option value="<?= e($roster['roster_id']) ?>" <?= $selectedRosterId === (int) $roster['roster_id'] ? 'selected' : '' ?>>
                                <?= e($roster['roster_name']) ?> -
                                <?= e($roster['program_name']) ?>
                                (<?= e($roster['season']) ?> <?= e($roster['year']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-bold">Date</label>
                    <select name="class_date" class="form-select">
                        <option value="">All dates</option>
                        <?php foreach ($classDates as $dateRow): ?>
                            <option value="<?= e($dateRow['class_date']) ?>" <?= $selectedDate === (string) $dateRow['class_date'] ? 'selected' : '' ?>>
                                <?= e($dateRow['class_date']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-bold">Source file</label>
                    <select name="source_file" class="form-select">
                        <option value="">All files</option>
                        <?php foreach ($sourceFiles as $fileRow): ?>
                            <option value="<?= e($fileRow['source_file']) ?>" <?= $selectedSourceFile === (string) $fileRow['source_file'] ? 'selected' : '' ?>>
                                <?= e($fileRow['source_file']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-10">
                    <label class="form-label fw-bold">Search roster students</label>
                    <input name="student_search"
                           class="form-control"
                           value="<?= e($studentSearch) ?>"
                           placeholder="Student name, phone, or email">
                </div>

                <div class="col-md-2">
                    <button class="btn btn-primary w-100">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selectedRoster): ?>
        <div class="alert alert-info">
            Selected roster:
            <b><?= e($selectedRoster['program_name']) ?> - <?= e($selectedRoster['roster_name']) ?></b>
            (<?= e($selectedRoster['season']) ?> <?= e($selectedRoster['year']) ?>)
        </div>
    <?php else: ?>
        <div class="alert alert-warning">Select a roster before matching Zoom records.</div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">Unmatched for Filter</div>
                    <div class="display-6"><?= e((int) ($summary['unmatched_count'] ?? 0)) ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">Matched for Filter</div>
                    <div class="display-6"><?= e($matchedCount) ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">Match Rate</div>
                    <div class="display-6"><?= e($matchRate) ?>%</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header bg-white fw-bold d-flex justify-content-between">
                    <span>Unmatched Zoom Records</span>
                    <span><?= e(count($unmatchedAttendance)) ?> shown</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Raw Name</th>
                                <th>Email</th>
                                <th>Date</th>
                                <th>Minutes</th>
                                <th>Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unmatchedAttendance as $record): ?>
                                <tr>
                                    <td><?= e($record['attendance_id']) ?></td>
                                    <td><b><?= e($record['raw_name']) ?></b></td>
                                    <td><?= e($record['email']) ?></td>
                                    <td><?= e($record['class_date']) ?></td>
                                    <td><?= e($record['total_minutes']) ?></td>
                                    <td><?= e($record['source_file']) ?></td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (count($unmatchedAttendance) === 0): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No unmatched Zoom records found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header bg-white fw-bold d-flex justify-content-between">
                    <span>Roster Student Matches</span>
                    <span><?= e(count($students)) ?> students</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Zoom Name</th>
                                <th>Student</th>
                                <th>Save</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unmatchedAttendance as $record): ?>
                                <tr>
                                    <td>
                                        <b><?= e($record['raw_name']) ?></b><br>
                                        <small class="text-muted"><?= e($record['class_date']) ?> · <?= e($record['source_file']) ?></small>
                                    </td>
                                    <td>
                                        <form method="POST" class="d-flex gap-2">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="save_zoom_match" value="1">
                                            <input type="hidden" name="program_id" value="<?= e($selectedProgramId) ?>">
                                            <input type="hidden" name="roster_id" value="<?= e($selectedRosterId) ?>">
                                            <input type="hidden" name="class_date" value="<?= e($selectedDate) ?>">
                                            <input type="hidden" name="source_file" value="<?= e($selectedSourceFile) ?>">
                                            <input type="hidden" name="student_search" value="<?= e($studentSearch) ?>">
                                            <input type="hidden" name="attendance_id" value="<?= e($record['attendance_id']) ?>">

                                            <select name="student_id" class="form-select form-select-sm" required>
                                                <option value="">Select roster student...</option>
                                                <?php foreach ($students as $student): ?>
                                                    <option value="<?= e($student['student_id']) ?>">
                                                        <?= e($student['canonical_name']) ?>
                                                        <?php if (!empty($student['phone'])): ?>
                                                            - <?= e($student['phone']) ?>
                                                        <?php endif; ?>
                                                        <?php if (!empty($student['email'])): ?>
                                                            - <?= e($student['email']) ?>
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                    </td>
                                    <td>
                                            <button class="btn btn-success btn-sm" <?= ($selectedRosterId <= 0 || count($students) === 0) ? 'disabled' : '' ?>>
                                                Save
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (count($unmatchedAttendance) === 0): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted">No records to match.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

</body>
</html>
