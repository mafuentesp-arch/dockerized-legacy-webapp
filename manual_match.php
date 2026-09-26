<?php
require_once __DIR__ . '/db.php';

$message = "";

/* ================================
   SAVE MANUAL MATCH
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attendance_id = intval($_POST['attendance_id'] ?? 0);
    $student_id    = intval($_POST['student_id'] ?? 0);
    $raw_name      = trim($_POST['raw_name'] ?? '');
    $selected_roster = trim($_POST['selected_roster'] ?? '');

    if ($attendance_id > 0 && $student_id > 0) {

        $update = $pdo->prepare("
            UPDATE attendance_zoom
            SET student_id = ?,
                match_status = 'matched'
            WHERE attendance_id = ?
        ");
        $update->execute([$student_id, $attendance_id]);

        if ($raw_name !== '') {
            $checkAlias = $pdo->prepare("
                SELECT COUNT(*)
                FROM student_aliases
                WHERE student_id = ?
                  AND LOWER(alias_name) = LOWER(?)
            ");
            $checkAlias->execute([$student_id, $raw_name]);

            if ($checkAlias->fetchColumn() == 0) {
                $alias = $pdo->prepare("
                    INSERT INTO student_aliases
                    (student_id, alias_name, source)
                    VALUES (?, ?, 'manual_zoom')
                ");
                $alias->execute([$student_id, $raw_name]);
            }
        }

        $message = "<div class='alert alert-success'>Match saved successfully.</div>";
    }
}

/* ================================
   LOAD ROSTERS FROM MYSQL
================================ */
$rosters = $pdo->query("
    SELECT DISTINCT roster_name
    FROM students_master
    WHERE roster_name IS NOT NULL
      AND roster_name != ''
    ORDER BY roster_name ASC
")->fetchAll();

$selected_roster = $_GET['roster_name'] ?? ($_POST['selected_roster'] ?? '');

/* ================================
   LOAD STUDENTS BY SELECTED ROSTER
================================ */
$students = [];

if ($selected_roster !== '') {
    $studentsStmt = $pdo->prepare("
        SELECT student_id, canonical_name, phone, email, roster_name
        FROM students_master
        WHERE roster_name = ?
        ORDER BY canonical_name ASC
    ");
    $studentsStmt->execute([$selected_roster]);
    $students = $studentsStmt->fetchAll();
}

/* ================================
   LOAD UNMATCHED ATTENDANCE
================================ */
$attendance = $pdo->query("
    SELECT *
    FROM attendance_zoom
    WHERE match_status = 'unmatched'
    ORDER BY class_date DESC, raw_name ASC
")->fetchAll();

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manual Match - Zoom Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

<div class="container-fluid mt-4">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Manual Match - Zoom Attendance</h3>

        <div>
            <a href="attendance_view.php" class="btn btn-secondary btn-sm">Attendance View</a>
            <a href="match_students.php" class="btn btn-primary btn-sm">Run Auto Match</a>
            <a href="import_zoom.php" class="btn btn-success btn-sm">Import Zoom</a>
        </div>
    </div>

    <?= $message ?>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Select Roster / Program</label>
                    <select name="roster_name" class="form-select" required>
                        <option value="">Select roster...</option>

                        <?php foreach ($rosters as $r): ?>
                            <option value="<?= htmlspecialchars($r['roster_name']) ?>"
                                <?= ($selected_roster == $r['roster_name']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['roster_name']) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="col-md-2">
                    <button class="btn btn-primary w-100">Filter Students</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selected_roster === ''): ?>

        <div class="alert alert-warning">
            Please select a roster/program first.
        </div>

    <?php else: ?>

        <div class="alert alert-info">
            Selected roster: <b><?= htmlspecialchars($selected_roster) ?></b><br>
            Students available: <b><?= count($students) ?></b><br>
            Unmatched attendance records: <b><?= count($attendance) ?></b>
        </div>

        <table class="table table-bordered table-striped table-sm align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Date</th>
                    <th>Zoom Name</th>
                    <th>Email</th>
                    <th>Minutes</th>
                    <th>Connections</th>
                    <th>Match With Student</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($attendance as $a): ?>
                    <tr>
                        <td><?= htmlspecialchars($a['class_date']) ?></td>

                        <td>
                            <b><?= htmlspecialchars($a['raw_name']) ?></b>
                        </td>

                        <td><?= htmlspecialchars($a['email']) ?></td>

                        <td><?= htmlspecialchars($a['total_minutes']) ?></td>

                        <td><?= htmlspecialchars($a['total_connections']) ?></td>

                        <td>
                            <form method="POST" class="d-flex gap-2">

                                <input type="hidden" name="attendance_id" value="<?= $a['attendance_id'] ?>">
                                <input type="hidden" name="raw_name" value="<?= htmlspecialchars($a['raw_name']) ?>">
                                <input type="hidden" name="selected_roster" value="<?= htmlspecialchars($selected_roster) ?>">

                                <select name="student_id" class="form-select form-select-sm" required>
                                    <option value="">Select student...</option>

                                    <?php foreach ($students as $s): ?>
                                        <option value="<?= $s['student_id'] ?>">
                                            <?= htmlspecialchars($s['canonical_name']) ?>

                                            <?php if (!empty($s['phone'])): ?>
                                                - <?= htmlspecialchars($s['phone']) ?>
                                            <?php endif; ?>

                                            <?php if (!empty($s['email'])): ?>
                                                - <?= htmlspecialchars($s['email']) ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>

                                </select>

                                <button class="btn btn-success btn-sm">
                                    Save
                                </button>

                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div>

</body>
</html>