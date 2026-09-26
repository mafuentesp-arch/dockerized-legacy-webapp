<?php
require_once __DIR__ . '/db.php';

//$sqlitePath = 'C:/xampp/htdocs/esol/asistencia_zoom.db';
$sqlitePath = 'C:/Users/UB/PycharmProjects/ZoomReport/asistencia_zoom.db';



try {
    $sqlite = new PDO("sqlite:" . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $rows = $sqlite->query("
        SELECT full_name, phone, email, language, status
        FROM students
        WHERE full_name IS NOT NULL
          AND TRIM(full_name) != ''
    ")->fetchAll();

    $inserted = 0;
    $skipped = 0;

    foreach ($rows as $s) {
        $name = trim($s['full_name'] ?? '');
        $phone = trim($s['phone'] ?? '');
        $email = trim($s['email'] ?? '');
        $language = trim($s['language'] ?? '');
        $statusRaw = strtoupper(trim($s['status'] ?? ''));

        if ($name === '') {
            continue;
        }

        $phone_normalized = preg_replace('/\D+/', '', $phone);
        if ($phone_normalized === '') {
            $phone_normalized = null;
        }

        $status = 'active';
        if ($statusRaw === 'INACTIVO' || $statusRaw === 'DROPPED') {
            $status = 'inactive';
        }

        if ($phone_normalized !== null) {
            $check = $pdo->prepare("
                SELECT student_id
                FROM students_master
                WHERE phone_normalized = ?
                   OR LOWER(canonical_name) = LOWER(?)
                LIMIT 1
            ");
            $check->execute([$phone_normalized, $name]);
        } else {
            $check = $pdo->prepare("
                SELECT student_id
                FROM students_master
                WHERE LOWER(canonical_name) = LOWER(?)
                LIMIT 1
            ");
            $check->execute([$name]);
        }

        if ($check->fetch()) {
            $skipped++;
            continue;
        }

        $stmt = $pdo->prepare("
            INSERT INTO students_master
            (canonical_name, phone, phone_normalized, email, language, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $name,
            $phone,
            $phone_normalized,
            $email,
            $language,
            $status
        ]);

        $newStudentId = $pdo->lastInsertId();

        $alias = $pdo->prepare("
            INSERT INTO student_aliases
            (student_id, alias_name, source)
            VALUES (?, ?, ?)
        ");

        $alias->execute([
            $newStudentId,
            $name,
            'sqlite_students'
        ]);

        $inserted++;
    }

    echo "<h3>Students imported from SQLite</h3>";
    echo "Total read: " . count($rows) . "<br>";
    echo "Inserted: $inserted<br>";
    echo "Skipped: $skipped<br>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>