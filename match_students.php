<?php

require_once __DIR__ . '/db.php';

echo "<h2>Automatic Student Matching</h2>";

$stmt = $pdo->query("
    SELECT *
    FROM attendance_zoom
    WHERE match_status = 'unmatched'
");

$records = $stmt->fetchAll();

$matched = 0;

foreach ($records as $r) {

    $attendance_id = $r['attendance_id'];
    $raw_name = trim($r['raw_name']);
    $email = trim($r['email']);

    $student_id = null;

    // ==========================================
    // 1. MATCH POR EMAIL
    // ==========================================

    if (!empty($email)) {

        $emailMatch = $pdo->prepare("
            SELECT student_id
            FROM students_master
            WHERE LOWER(email) = LOWER(?)
            LIMIT 1
        ");

        $emailMatch->execute([$email]);

        $found = $emailMatch->fetch();

        if ($found) {
            $student_id = $found['student_id'];
        }
    }

    // ==========================================
    // 2. MATCH POR ALIAS EXACTO
    // ==========================================

    if (!$student_id) {

        $aliasMatch = $pdo->prepare("
            SELECT student_id
            FROM student_aliases
            WHERE LOWER(alias_name) = LOWER(?)
            LIMIT 1
        ");

        $aliasMatch->execute([$raw_name]);

        $foundAlias = $aliasMatch->fetch();

        if ($foundAlias) {
            $student_id = $foundAlias['student_id'];
        }
    }

    // ==========================================
    // 3. MATCH SIMPLE POR NOMBRE
    // ==========================================

    if (!$student_id) {

        $students = $pdo->query("
            SELECT student_id, canonical_name
            FROM students_master
        ");

        $allStudents = $students->fetchAll();

        foreach ($allStudents as $s) {

            similar_text(
                strtolower($raw_name),
                strtolower($s['canonical_name']),
                $percent
            );

            if ($percent >= 85) {

                $student_id = $s['student_id'];

                // Guardar alias automáticamente
                $saveAlias = $pdo->prepare("
                    INSERT INTO student_aliases
                    (student_id, alias_name, source)
                    VALUES (?, ?, ?)
                ");

                $saveAlias->execute([
                    $student_id,
                    $raw_name,
                    'zoom'
                ]);

                break;
            }
        }
    }

    // ==========================================
    // SI ENCONTRO MATCH
    // ==========================================

    if ($student_id) {

        $update = $pdo->prepare("
            UPDATE attendance_zoom
            SET student_id = ?,
                match_status = 'matched'
            WHERE attendance_id = ?
        ");

        $update->execute([
            $student_id,
            $attendance_id
        ]);

        echo "MATCHED: $raw_name <br>";

        $matched++;
    }
}

echo "<hr>";
echo "<b>Total matched:</b> $matched";
?>