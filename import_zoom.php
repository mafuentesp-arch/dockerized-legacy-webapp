<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/security.php';
// ==========================================
// import_zoom.php
// IMPORTADOR INTELIGENTE ZOOM ATTENDANCE
// ==========================================

/* session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
 */
 
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/audit.php';
$message = "";

function normalizeZoomName(string $name): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $name)));
}

function parseZoomDateTime(string $value): ?int
{
    $timestamp = strtotime(trim($value));

    return $timestamp !== false ? $timestamp : null;
}

if (isset($_FILES['zoom_file'])) {

    $file = $_FILES['zoom_file']['tmp_name'];

    if (($handle = fopen($file, "r")) !== FALSE) {

        // Detectar separador
        $firstLine = fgets($handle);
        $sep = (strpos($firstLine, ';') !== false) ? ';' : ',';
        rewind($handle);

        // Leer cabecera
        $header = fgetcsv($handle, 5000, $sep);

        $imported = 0;
        $updated = 0;
        $skippedInvalid = 0;
        $consolidatedConnections = 0;

        $students = [];

        $emailMatch = $pdo->prepare("
            SELECT student_id
            FROM students_master
            WHERE LOWER(email) = LOWER(?)
            LIMIT 1
        ");

        $aliasMatch = $pdo->prepare("
            SELECT student_id
            FROM student_aliases
            WHERE LOWER(alias_name) = LOWER(?)
            LIMIT 1
        ");

        $canonicalNameMatches = [];
        $canonicalStmt = $pdo->prepare("
            SELECT student_id, canonical_name
            FROM students_master
        ");
        $canonicalStmt->execute();

        foreach ($canonicalStmt->fetchAll() as $candidate) {
            $candidateName = normalizeZoomName($candidate['canonical_name']);
            $canonicalNameMatches[$candidateName][] = $candidate['student_id'];
        }

        while (($data = fgetcsv($handle, 5000, $sep)) !== FALSE) {

            if (count($data) < 5) continue;

            $raw_name = trim($data[0]);
            $email = trim($data[1]);

            $join_time = trim($data[2]);
            $leave_time = trim($data[3]);

            $duration = floatval($data[4]);

            if (empty($raw_name)) continue;

            // LIMPIAR NOMBRE
            $normalized_name = normalizeZoomName($raw_name);
            $joinTimestamp = parseZoomDateTime($join_time);
            $leaveTimestamp = parseZoomDateTime($leave_time);

            if ($joinTimestamp === null || $leaveTimestamp === null) {
                $skippedInvalid++;
                continue;
            }

            $class_date = date('Y-m-d', $joinTimestamp);

            if ($class_date === '1970-01-01') {
                $skippedInvalid++;
                continue;
            }

            $student_id = null;
            $match_status = 'unmatched';

            if (!empty($email)) {
                $emailMatch->execute([$email]);
                $found = $emailMatch->fetch();

                if ($found) {
                    $student_id = $found['student_id'];
                    $match_status = 'matched';
                }
            }

            if (!$student_id) {
                $aliasMatch->execute([$raw_name]);
                $foundAlias = $aliasMatch->fetch();

                if ($foundAlias) {
                    $student_id = $foundAlias['student_id'];
                    $match_status = 'matched';
                }
            }

            if (!$student_id) {
                $foundNames = $canonicalNameMatches[$normalized_name] ?? [];

                if (count($foundNames) === 1) {
                    $student_id = $foundNames[0];
                    $match_status = 'matched';
                }
            }

            $key = $student_id
                ? 'student:' . $student_id . ':' . $class_date
                : 'name:' . $normalized_name . ':' . $class_date;

            // Primera vez
            if (!isset($students[$key])) {

                $students[$key] = [
                    'student_id' => $student_id,
                    'raw_name' => $raw_name,
                    'normalized_name' => $normalized_name,
                    'email' => $email,
                    'class_date' => $class_date,
                    'first_join' => date('Y-m-d H:i:s', $joinTimestamp),
                    'last_leave' => date('Y-m-d H:i:s', $leaveTimestamp),
                    'total_minutes' => $duration,
                    'connections' => 1,
                    'match_status' => $match_status
                ];

            } else {

                // Actualizar primera conexión
                if (strlen($raw_name) > strlen($students[$key]['raw_name'])) {
                    $students[$key]['raw_name'] = $raw_name;
                    $students[$key]['normalized_name'] = $normalized_name;
                }

                if (!empty($email) && empty($students[$key]['email'])) {
                    $students[$key]['email'] = $email;
                }

                if ($joinTimestamp < strtotime($students[$key]['first_join'])) {
                    $students[$key]['first_join'] = date('Y-m-d H:i:s', $joinTimestamp);
                }

                // Actualizar última desconexión
                if ($leaveTimestamp > strtotime($students[$key]['last_leave'])) {
                    $students[$key]['last_leave'] = date('Y-m-d H:i:s', $leaveTimestamp);
                }

                // Acumular minutos
                $students[$key]['total_minutes'] += $duration;

                // Contar conexiones
                $students[$key]['connections']++;

            }
        }

        fclose($handle);

        // ==========================================
        // INSERTAR EN DB
        // ==========================================

        foreach ($students as $student) {

            $student_id = $student['student_id'];
            $class_date = $student['class_date'];
            $match_status = $student['match_status'];
            $consolidatedConnections += max(0, $student['connections'] - 1);

            if ($student_id) {
                $check = $pdo->prepare("
                    SELECT attendance_id, first_join, last_leave
                    FROM attendance_zoom
                    WHERE student_id = ?
                    AND class_date = ?
                    LIMIT 1
                ");
                $check->execute([$student_id, $class_date]);
            } else {
                $check = $pdo->prepare("
                    SELECT attendance_id, first_join, last_leave
                    FROM attendance_zoom
                    WHERE normalized_name = ?
                    AND class_date = ?
                    LIMIT 1
                ");
                $check->execute([$student['normalized_name'], $class_date]);
            }

            $existing = $check->fetch();

            if ($existing) {
                $firstJoin = date('Y-m-d H:i:s', min(strtotime($existing['first_join']), strtotime($student['first_join'])));
                $lastLeave = date('Y-m-d H:i:s', max(strtotime($existing['last_leave']), strtotime($student['last_leave'])));

                $stmt = $pdo->prepare("
                    UPDATE attendance_zoom
                    SET student_id = ?,
                        raw_name = ?,
                        normalized_name = ?,
                        email = ?,
                        first_join = ?,
                        last_leave = ?,
                        total_connections = ?,
                        total_minutes = ?,
                        source_file = ?,
                        match_status = ?
                    WHERE attendance_id = ?
                ");

                $stmt->execute([
                    $student_id,
                    $student['raw_name'],
                    $student['normalized_name'],
                    $student['email'],
                    $firstJoin,
                    $lastLeave,
                    $student['connections'],
                    round($student['total_minutes'], 2),
                    $_FILES['zoom_file']['name'],
                    $match_status,
                    $existing['attendance_id']
                ]);

                $updated++;
            } else {
                // INSERTAR ATTENDANCE
                $stmt = $pdo->prepare("
                    INSERT INTO attendance_zoom
                    (
                        student_id,
                        raw_name,
                        normalized_name,
                        email,
                        class_date,
                        first_join,
                        last_leave,
                        total_connections,
                        total_minutes,
                        source_file,
                        match_status
                    )
                    VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $student_id,
                    $student['raw_name'],
                    $student['normalized_name'],
                    $student['email'],
                    $class_date,
                    $student['first_join'],
                    $student['last_leave'],
                    $student['connections'],
                    round($student['total_minutes'], 2),
                    $_FILES['zoom_file']['name'],
                    $match_status
                ]);

                $imported++;
            }
        }

        $message = "
            <div class='alert alert-success'>
                <b>Import completed successfully</b><br>
                Imported: $imported<br>
                Updated: $updated<br>
                Skipped invalid: $skippedInvalid<br>
                Consolidated connections: $consolidatedConnections
            </div>
        ";

        logActivity('IMPORT', 'attendance_zoom', null, 'Imported Zoom attendance file ' . ($_FILES['zoom_file']['name'] ?? '') . ' (' . $imported . ' imported, ' . $updated . ' updated, ' . $skippedInvalid . ' skipped invalid, ' . $consolidatedConnections . ' consolidated connections)');
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Import Zoom Attendance</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

</head>

<body>

<div class="container-fluid app-page">

    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">Import Zoom Attendance</h3>
        <small class="page-subtitle">Upload and import Zoom attendance data.</small>
    </div>

    <div class="card shadow">

        <div class="card-body">

            <?= $message ?>

            <form method="POST" enctype="multipart/form-data">

                <div class="mb-3">

                    <label class="form-label">
                        Select Zoom CSV File
                    </label>

                    <input
                        type="file"
                        name="zoom_file"
                        class="form-control"
                        required
                    >

                </div>

                <button class="btn btn-primary">
                    IMPORT ATTENDANCE
                </button>

            </form>

        </div>

    </div>

</div>

</body>
</html>
