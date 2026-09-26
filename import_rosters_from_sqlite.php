
<?php
require_once __DIR__ . '/db.php';

$sqlitePath = 'C:/xampp/htdocs/esol/asistencia_zoom.db';
$sqlitePath = 'C:/Users/UB/PycharmProjects/ZoomReport/asistencia_zoom.db';

function detectProgram($rosterName) {
    $name = strtoupper($rosterName);

    if (strpos($name, 'YONKERS') !== false) {
        return 'Yonkers';
    }

    if (strpos($name, 'APPLEBAUM') !== false && strpos($name, 'PM') !== false) {
        return 'Applebaum PM';
    }

    if (strpos($name, 'APPLEBAUM') !== false && strpos($name, 'AM') !== false) {
        return 'Applebaum AM';
    }

    if (strpos($name, 'APPLEBAUM') !== false) {
        return 'Applebaum AM';
    }

    return 'Unknown';
}

function detectSeason($rosterName) {
    $name = strtoupper($rosterName);

    if (strpos($name, 'SPRING') !== false) return 'Spring';
    if (strpos($name, 'SUMMER') !== false) return 'Summer';
    if (strpos($name, 'FALL') !== false) return 'Fall';
    if (strpos($name, 'WINTER') !== false) return 'Winter';

    return null;
}

function detectYear($rosterName) {
    if (preg_match('/20[0-9]{2}/', $rosterName, $m)) {
        return intval($m[0]);
    }

    return null;
}

try {
    $sqlite = new PDO("sqlite:" . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->beginTransaction();

    $rows = $sqlite->query("
        SELECT DISTINCT roster_name
        FROM students
        WHERE roster_name IS NOT NULL
          AND TRIM(roster_name) != ''
        ORDER BY roster_name
    ")->fetchAll();

    $programsCreated = 0;
    $rostersCreated = 0;
    $rostersSkipped = 0;

    foreach ($rows as $r) {
        $rosterName = trim($r['roster_name']);
        if ($rosterName === '') continue;

        $programName = detectProgram($rosterName);
        $season = detectSeason($rosterName);
        $year = detectYear($rosterName);

        // 1. Program
        $stmt = $pdo->prepare("
            SELECT program_id
            FROM programs
            WHERE program_name = ?
            LIMIT 1
        ");
        $stmt->execute([$programName]);
        $program = $stmt->fetch();

        if ($program) {
            $programId = $program['program_id'];
        } else {
            $insertProgram = $pdo->prepare("
                INSERT INTO programs (program_name, status)
                VALUES (?, 'active')
            ");
            $insertProgram->execute([$programName]);
            $programId = $pdo->lastInsertId();
            $programsCreated++;
        }

        // 2. Roster
        $checkRoster = $pdo->prepare("
            SELECT roster_id
            FROM rosters
            WHERE program_id = ?
              AND roster_name = ?
            LIMIT 1
        ");
        $checkRoster->execute([$programId, $rosterName]);
        $existingRoster = $checkRoster->fetch();

        if ($existingRoster) {
            $rostersSkipped++;
            continue;
        }

        $insertRoster = $pdo->prepare("
            INSERT INTO rosters
            (program_id, roster_name, season, year, status)
            VALUES (?, ?, ?, ?, 'active')
        ");
        $insertRoster->execute([
            $programId,
            $rosterName,
            $season,
            $year
        ]);

        $rostersCreated++;
    }

    $pdo->commit();

    echo "<h3>Rosters Imported from SQLite</h3>";
    echo "SQLite rosters read: " . count($rows) . "<br>";
    echo "Programs created: $programsCreated<br>";
    echo "Rosters created: $rostersCreated<br>";
    echo "Rosters skipped: $rostersSkipped<br>";
    echo "<br><a href='manual_match.php'>Go to Manual Match</a>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo "Error: " . $e->getMessage();
}
?>