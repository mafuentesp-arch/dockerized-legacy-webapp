<?php
require_once __DIR__ . '/db.php';

$sqlitePath = 'C:/xampp/htdocs/esol/asistencia_zoom.db';
$sqlitePath = 'C:/Users/UB/PycharmProjects/ZoomReport/asistencia_zoom.db';

try {
    $sqlite = new PDO("sqlite:" . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $rows = $sqlite->query("
        SELECT 
            id,
            student_id,
            roster_name,
            full_name,
            phone,
            email,
            program,
            date,
            comment,
            time_spent,
            created_at,
            updated_at
        FROM outreach
    ")->fetchAll();

    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    foreach ($rows as $r) {
        $sqliteId = intval($r['id']);
        $fullName = trim($r['full_name'] ?? '');
        $phone = preg_replace('/\D+/', '', $r['phone'] ?? '');
        $email = trim($r['email'] ?? '');
        $rosterName = trim($r['roster_name'] ?? '');
        $program = trim($r['program'] ?? '');
        $date = trim($r['date'] ?? '');
        $comment = trim($r['comment'] ?? '');
        $timeSpent = floatval($r['time_spent'] ?? 0);
        $createdAt = trim($r['created_at'] ?? '');
        $updatedAt = trim($r['updated_at'] ?? '');

        if ($date === '') {
            $date = null;
        }

        if ($createdAt === '') {
            $createdAt = null;
        }

        if ($updatedAt === '') {
            $updatedAt = null;
        }

        $studentId = null;
        $rosterId = null;

        // Buscar estudiante por teléfono primero
        if ($phone !== '') {
            $studentStmt = $pdo->prepare("
                SELECT student_id
                FROM students_master
                WHERE phone_normalized = ?
                LIMIT 1
            ");
            $studentStmt->execute([$phone]);
            $student = $studentStmt->fetch();

            if ($student) {
                $studentId = $student['student_id'];
            }
        }

        // Si no encuentra por teléfono, buscar por nombre
        if (!$studentId && $fullName !== '') {
            $studentStmt = $pdo->prepare("
                SELECT student_id
                FROM students_master
                WHERE LOWER(canonical_name) = LOWER(?)
                LIMIT 1
            ");
            $studentStmt->execute([$fullName]);
            $student = $studentStmt->fetch();

            if ($student) {
                $studentId = $student['student_id'];
            }
        }

        // Buscar roster
        if ($rosterName !== '') {
            $rosterStmt = $pdo->prepare("
                SELECT roster_id
                FROM rosters
                WHERE roster_name = ?
                LIMIT 1
            ");
            $rosterStmt->execute([$rosterName]);
            $roster = $rosterStmt->fetch();

            if ($roster) {
                $rosterId = $roster['roster_id'];
            }
        }

        // Revisar si ya existe
        $check = $pdo->prepare("
            SELECT outreach_id
            FROM outreach
            WHERE sqlite_outreach_id = ?
            LIMIT 1
        ");
        $check->execute([$sqliteId]);
        $existing = $check->fetch();

        if ($existing) {
            $stmt = $pdo->prepare("
                UPDATE outreach
                SET 
                    student_id = ?,
                    roster_id = ?,
                    roster_name = ?,
                    full_name = ?,
                    phone = ?,
                    email = ?,
                    program = ?,
                    outreach_date = ?,
                    comment = ?,
                    time_spent = ?,
                    created_at = ?,
                    updated_at = ?
                WHERE sqlite_outreach_id = ?
            ");

            $stmt->execute([
                $studentId,
                $rosterId,
                $rosterName,
                $fullName,
                $phone,
                $email,
                $program,
                $date,
                $comment,
                $timeSpent,
                $createdAt,
                $updatedAt,
                $sqliteId
            ]);

            $updated++;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO outreach
                (
                    student_id,
                    roster_id,
                    sqlite_outreach_id,
                    roster_name,
                    full_name,
                    phone,
                    email,
                    program,
                    outreach_date,
                    comment,
                    time_spent,
                    created_at,
                    updated_at,
                    source_type
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sqlite_outreach')
            ");

            $stmt->execute([
                $studentId,
                $rosterId,
                $sqliteId,
                $rosterName,
                $fullName,
                $phone,
                $email,
                $program,
                $date,
                $comment,
                $timeSpent,
                $createdAt,
                $updatedAt
            ]);

            $inserted++;
        }
    }

    echo "<h3>Outreach Imported from SQLite</h3>";
    echo "Total read: " . count($rows) . "<br>";
    echo "Inserted: $inserted<br>";
    echo "Updated: $updated<br>";
    echo "Skipped: $skipped<br>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>