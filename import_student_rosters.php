<?php
require_once __DIR__ . '/db.php';

$sqlitePath = 'C:/Users/UB/PycharmProjects/ZoomReport/asistencia_zoom.db';


function mapStudentRosterStatus($statusRaw) {
    $statusRaw = strtoupper(trim($statusRaw ?? ''));

    if (
        $statusRaw === 'RETIRADO' ||
        $statusRaw === 'INACTIVO' ||
        $statusRaw === 'DROPPED' ||
        $statusRaw === 'WITHDRAWN'
    ) {
        return 'dropped';
    }

    if (
        $statusRaw === 'COMPLETADO' ||
        $statusRaw === 'COMPLETED'
    ) {
        return 'completed';
    }

    if (
        $statusRaw === 'WAITING' ||
        $statusRaw === 'ESPERA' ||
        $statusRaw === 'WAITLIST'
    ) {
        return 'waiting';
    }

    return 'active';
}

try {

    $sqlite = new PDO("sqlite:" . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $rows = $sqlite->query("
        SELECT
            full_name,
            phone,
            roster_name,
            status
        FROM students
        WHERE roster_name IS NOT NULL
          AND TRIM(roster_name) != ''
          AND full_name IS NOT NULL
          AND TRIM(full_name) != ''
    ")->fetchAll();

    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    foreach ($rows as $r) {

        $full_name = trim($r['full_name']);
        $roster_name = trim($r['roster_name']);
        $statusRaw = $r['status'] ?? '';

        $rosterStatus = mapStudentRosterStatus($statusRaw);

        $phone = preg_replace('/\D+/', '', $r['phone'] ?? '');

        if ($phone === '') {
            $phone = null;
        }

        // FIND STUDENT
        if ($phone !== null) {
            $studentStmt = $pdo->prepare("
                SELECT student_id
                FROM students_master
                WHERE phone_normalized = ?
                LIMIT 1
            ");
            $studentStmt->execute([$phone]);
        } else {
            $studentStmt = $pdo->prepare("
                SELECT student_id
                FROM students_master
                WHERE LOWER(canonical_name) = LOWER(?)
                LIMIT 1
            ");
            $studentStmt->execute([$full_name]);
        }

        $student = $studentStmt->fetch();

        if (!$student) {
            $skipped++;
            continue;
        }

        $student_id = $student['student_id'];

        // FIND ROSTER
        $rosterStmt = $pdo->prepare("
            SELECT roster_id
            FROM rosters
            WHERE roster_name = ?
            LIMIT 1
        ");
        $rosterStmt->execute([$roster_name]);

        $roster = $rosterStmt->fetch();

        if (!$roster) {
            $skipped++;
            continue;
        }

        $roster_id = $roster['roster_id'];

        // CHECK IF RELATION EXISTS
        $check = $pdo->prepare("
            SELECT id
            FROM student_rosters
            WHERE student_id = ?
              AND roster_id = ?
            LIMIT 1
        ");
        $check->execute([$student_id, $roster_id]);

        $existing = $check->fetch();

        // INSERT OR UPDATE STATUS
        $insert = $pdo->prepare("
            INSERT INTO student_rosters
            (student_id, roster_id, status)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status)
        ");

        $insert->execute([
            $student_id,
            $roster_id,
            $rosterStatus
        ]);

        if ($existing) {
            $updated++;
        } else {
            $inserted++;
        }
    }

    echo "<h3>Student Rosters Imported / Updated</h3>";
    echo "Total read: " . count($rows) . "<br>";
    echo "Inserted: $inserted<br>";
    echo "Updated: $updated<br>";
    echo "Skipped: $skipped<br>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>