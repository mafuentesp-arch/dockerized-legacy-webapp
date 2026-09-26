<?php
require_once __DIR__ . '/db.php';

$sqlitePath = 'C:/xampp/htdocs/esol/asistencia_zoom.db';
$sqlitePath = 'C:/Users/UB/PycharmProjects/ZoomReport/asistencia_zoom.db';


try {
    $sqlite = new PDO("sqlite:" . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $rows = $sqlite->query("
        SELECT roster_name, full_name, phone, email, class_date, present, status, source_file
        FROM applebaum_attendance
        WHERE roster_name IS NOT NULL
          AND TRIM(roster_name) != ''
          AND class_date IS NOT NULL
    ")->fetchAll();

    $inserted = 0;
    $skipped = 0;

    foreach ($rows as $r) {
        $roster_name = trim($r['roster_name']);
        $full_name = trim($r['full_name']);
        $phone = preg_replace('/\D+/', '', $r['phone'] ?? '');
        $email = trim($r['email'] ?? '');
        $class_date = trim($r['class_date']);
        $present = intval($r['present']);
        $status = trim($r['status'] ?? '');
        $source_file = trim($r['source_file'] ?? '');

        $student_id = null;
        $roster_id = null;

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

        if ($phone !== '') {
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

        $check = $pdo->prepare("
            SELECT attendance_id
            FROM attendance_class
            WHERE student_id = ?
              AND roster_id = ?
              AND class_date = ?
            LIMIT 1
        ");
        $check->execute([$student_id, $roster_id, $class_date]);

        if ($check->fetch()) {
            $skipped++;
            continue;
        }

		$insert = $pdo->prepare("
			INSERT INTO attendance_class
			(
				student_id,
				roster_id,
				roster_name,
				student_name,
				phone,
				email,
				class_date,
				present,
				status,
				source_file,
				source_type
			)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'applebaum_sqlite')
			ON DUPLICATE KEY UPDATE
				present = VALUES(present),
				status = VALUES(status),
				source_file = VALUES(source_file),
				source_type = VALUES(source_type)
		");
        $insert->execute([
            $student_id,
            $roster_id,
            $roster_name,
            $full_name,
            $phone,
            $email,
            $class_date,
            $present,
            $status,
            $source_file
        ]);

        $inserted++;
    }

    echo "<h3>Applebaum Attendance Imported</h3>";
    echo "Total read: " . count($rows) . "<br>";
    echo "Inserted: $inserted<br>";
    echo "Skipped/Duplicates: $skipped<br>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>