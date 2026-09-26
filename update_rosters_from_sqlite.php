<?php
require_once __DIR__ . '/db.php';

//$sqlitePath = 'C:/xampp/htdocs/esol/asistencia_zoom.db';
$sqlitePath = 'C:/Users/UB/PycharmProjects/ZoomReport/asistencia_zoom.db';
try {

    $sqlite = new PDO("sqlite:" . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $rows = $sqlite->query("
        SELECT
            full_name,
            roster_name,
            phone
        FROM students
        WHERE roster_name IS NOT NULL
          AND TRIM(roster_name) != ''
    ")->fetchAll();

    $updated = 0;

    foreach ($rows as $r) {

        $name = trim($r['full_name']);
        $roster = trim($r['roster_name']);
        $phone = preg_replace('/\D+/', '', $r['phone']);

        if ($phone == '') {
            $phone = null;
        }

        // =====================================
        // UPDATE BY PHONE
        // =====================================

        if ($phone !== null) {

            $stmt = $pdo->prepare("
                UPDATE students_master
                SET roster_name = ?
                WHERE phone_normalized = ?
            ");

            $stmt->execute([
                $roster,
                $phone
            ]);

            $updated += $stmt->rowCount();

        } else {

            // =====================================
            // UPDATE BY NAME
            // =====================================

            $stmt = $pdo->prepare("
                UPDATE students_master
                SET roster_name = ?
                WHERE LOWER(canonical_name) = LOWER(?)
            ");

            $stmt->execute([
                $roster,
                $name
            ]);

            $updated += $stmt->rowCount();
        }
    }

    echo "<h3>Roster Update Completed</h3>";
    echo "Updated students: " . $updated;

} catch (Exception $e) {

    echo "Error: " . $e->getMessage();

}
?>