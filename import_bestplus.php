<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/audit.php';

$stats = null;

function parseDateValue($value) {
    $value = trim($value ?? '');
    return $value === '' ? null : date('Y-m-d', strtotime($value));
}

function parseTimeValue($value) {
    $value = trim($value ?? '');
    return $value === '' ? null : date('H:i:s', strtotime($value));
}

function calculateDuration($start, $end) {
    if (!$start || !$end) return null;

    $s = strtotime($start);
    $e = strtotime($end);

    if ($e < $s) {
        $e += 86400;
    }

    return round(($e - $s) / 60, 2);
}

if (isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];

    $handle = fopen($file, "r");
    $firstLine = fgets($handle);
    $sep = (strpos($firstLine, ';') !== false) ? ';' : ',';
    rewind($handle);

    fgetcsv($handle, 5000, $sep);

    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    while (($data = fgetcsv($handle, 5000, $sep)) !== false) {

        if (count($data) < 14 || empty($data[0])) {
            $skipped++;
            continue;
        }

        $firstName = trim($data[0] ?? '');
        $lastName  = trim($data[1] ?? '');
        $student   = trim($firstName . " " . $lastName);

        $regNo     = trim($data[2] ?? '');
        $site      = trim($data[3] ?? '');
        $class     = trim($data[4] ?? '');
        $formName  = trim($data[5] ?? '');
        $version   = trim($data[6] ?? '');

        $tester    = trim(($data[7] ?? '') . " " . ($data[8] ?? ''));

        $test_date = parseDateValue($data[9] ?? '');
        $start_time = parseTimeValue($data[10] ?? '');
        $end_time   = parseTimeValue($data[11] ?? '');
        $duration_minutes = calculateDuration($start_time, $end_time);

        $scale_score = intval($data[12] ?? 0);
        $interpretation1 = trim($data[13] ?? '');
        $interpretation2 = trim($data[14] ?? '');
        $interpretation3 = trim($data[15] ?? '');

        if ($student === '' || !$test_date) {
            $skipped++;
            continue;
        }

        $check = $pdo->prepare("
            SELECT id
            FROM test_results
            WHERE student_name = ?
              AND test_date = ?
              AND class_id = ?
              AND scale_score = ?
              AND admin_first_name = ?
            LIMIT 1
        ");

        $check->execute([
            $student,
            $test_date,
            $class,
            $scale_score,
            $tester
        ]);

        $existing = $check->fetch();

        if ($existing) {
            $stmt = $pdo->prepare("
                UPDATE test_results
                SET
                    registration_no = ?,
                    site = ?,
                    test_type = ?,
                    level = ?,
                    start_time = ?,
                    end_time = ?,
                    duration_minutes = ?,
                    interpretation1 = ?,
                    score = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $regNo,
                $site,
                $formName,
                $version,
                $start_time,
                $end_time,
                $duration_minutes,
                $interpretation1,
                $scale_score,
                $existing['id']
            ]);

            $updated++;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO test_results
                (
                    student_name,
                    registration_no,
                    site,
                    class_id,
                    admin_first_name,
                    test_date,
                    test_type,
                    level,
                    start_time,
                    end_time,
                    duration_minutes,
                    scale_score,
                    score,
                    interpretation1
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $student,
                $regNo,
                $site,
                $class,
                $tester,
                $test_date,
                $formName,
                $version,
                $start_time,
                $end_time,
                $duration_minutes,
                $scale_score,
                $scale_score,
                $interpretation1
            ]);

            $inserted++;
        }
    }

    fclose($handle);

    $stats = [
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped
    ];

    logActivity('IMPORT', 'test_results', null, 'Imported BEST Plus file ' . ($_FILES['csv_file']['name'] ?? '') . ' (' . $inserted . ' inserted, ' . $updated . ' updated, ' . $skipped . ' skipped)');
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Import BEST Plus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>

<div class="container-fluid app-page">
    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">Import BEST Plus Results</h3>
        <small class="page-subtitle">Upload and import BEST Plus CSV results.</small>
    </div>

    <div class="card shadow col-md-6 mx-auto">
        <div class="card-body">

            <?php if ($stats): ?>
                <div class="alert alert-success">
                    <b>Import completed</b><br>
                    Inserted: <?= $stats['inserted'] ?><br>
                    Updated: <?= $stats['updated'] ?><br>
                    Skipped: <?= $stats['skipped'] ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <label class="form-label fw-bold">Select BEST Plus CSV</label>
                <input type="file" name="csv_file" class="form-control mb-3" accept=".csv" required>

                <button class="btn btn-primary w-100">
                    Import BEST Plus
                </button>
            </form>

        </div>
    </div>
</div>

</body>
</html>
