<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/audit.php';

$message = "";
$pageName = basename($_SERVER['PHP_SELF']);

/* ================================
   SELECTED ROSTER
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_roster_id = intval($_POST['selected_roster_id'] ?? 0);
} else {
    $selected_roster_id = intval($_GET['roster_id'] ?? 0);
}

/* ================================
   SAVE ALL MANUAL MATCHES
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['matches'])) {

    $saved = 0;
    $skipped = 0;

    foreach ($_POST['matches'] as $attendance_id => $student_id) {

        $attendance_id = intval($attendance_id);
        $student_id = intval($student_id);
        $raw_name = trim($_POST['raw_names'][$attendance_id] ?? '');

        if ($attendance_id <= 0 || $student_id <= 0) {
            $skipped++;
            continue;
        }

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

        $saved++;
    }

    $message = "
        <div class='alert alert-success'>
            <b>Matches saved successfully.</b><br>
            Saved: $saved<br>
            Skipped: $skipped
        </div>
    ";

    logActivity('UPDATE', 'attendance_zoom', null, 'Saved manual Zoom matches (' . $saved . ' saved, ' . $skipped . ' skipped)');
}

/* ================================
   LOAD ROSTERS
================================ */
$rosters = $pdo->query("
    SELECT 
        r.roster_id,
        r.roster_name,
        r.season,
        r.year,
        p.program_name
    FROM rosters r
    LEFT JOIN programs p ON r.program_id = p.program_id
    ORDER BY r.year DESC, p.program_name ASC, r.roster_name ASC
")->fetchAll();

/* ================================
   LOAD SELECTED ROSTER INFO
================================ */
$selected_roster = null;

if ($selected_roster_id > 0) {
    $stmtRoster = $pdo->prepare("
        SELECT 
            r.roster_id,
            r.roster_name,
            r.season,
            r.year,
            p.program_name
        FROM rosters r
        LEFT JOIN programs p ON r.program_id = p.program_id
        WHERE r.roster_id = ?
        LIMIT 1
    ");
    $stmtRoster->execute([$selected_roster_id]);
    $selected_roster = $stmtRoster->fetch();
}

/* ================================
   LOAD STUDENTS FROM SELECTED ROSTER
================================ */
$students = [];

if ($selected_roster_id > 0) {
    $studentsStmt = $pdo->prepare("
        SELECT 
            sm.student_id,
            sm.canonical_name,
            sm.phone,
            sm.email
        FROM student_rosters sr
        INNER JOIN students_master sm ON sr.student_id = sm.student_id
        WHERE sr.roster_id = ?
        ORDER BY sm.canonical_name ASC
    ");
    $studentsStmt->execute([$selected_roster_id]);
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

<div class="container-fluid app-page">

    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">Manual Match - Zoom Attendance</h3>
        <small class="page-subtitle">Match unmatched Zoom attendance rows to roster students.</small>
    </div>

    <?= $message ?>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="<?= htmlspecialchars($pageName) ?>" class="row g-2 align-items-end">
                <div class="col-md-7">
                    <label class="form-label fw-bold">Select Roster / Program / Season</label>

                    <select name="roster_id" class="form-select" required>
                        <option value="">Select roster...</option>

                        <?php foreach ($rosters as $r): ?>
                            <option value="<?= htmlspecialchars($r['roster_id']) ?>"
                                <?= ($selected_roster_id == intval($r['roster_id'])) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['program_name'] ?? 'Unknown') ?>
                                —
                                <?= htmlspecialchars($r['roster_name']) ?>
                                <?php if (!empty($r['season']) || !empty($r['year'])): ?>
                                    (
                                    <?= htmlspecialchars($r['season'] ?? '') ?>
                                    <?= htmlspecialchars($r['year'] ?? '') ?>
                                    )
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Load Students</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selected_roster_id <= 0 || !$selected_roster): ?>

        <div class="alert alert-warning">
            Please select a roster/program first.
        </div>

    <?php else: ?>

        <div class="alert alert-info">
            Selected roster:
            <b>
                <?= htmlspecialchars($selected_roster['program_name'] ?? '') ?>
                —
                <?= htmlspecialchars($selected_roster['roster_name'] ?? '') ?>
            </b>
            <br>
            Students in this roster: <b><?= count($students) ?></b><br>
            Unmatched attendance records: <b><?= count($attendance) ?></b>
        </div>

        <?php if (count($students) == 0): ?>
            <div class="alert alert-danger">
                No students are linked to this roster yet.
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= htmlspecialchars($pageName) ?>">

            <input type="hidden" name="selected_roster_id" value="<?= htmlspecialchars($selected_roster_id) ?>">

            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Assign matches</strong>
                    <button type="submit" class="btn btn-success btn-sm">Save All Matches</button>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-sm align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Zoom Name</th>
                                <th>Email</th>
                                <th>Minutes</th>
                                <th>Connections</th>
                                <th style="width: 55%;">Match With Student</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($attendance as $a): ?>
                                <tr>
                                    <td><?= htmlspecialchars($a['class_date']) ?></td>

                                    <td>
                                        <b><?= htmlspecialchars($a['raw_name']) ?></b>
                                        <input type="hidden"
                                               name="raw_names[<?= htmlspecialchars($a['attendance_id']) ?>]"
                                               value="<?= htmlspecialchars($a['raw_name']) ?>">
                                    </td>

                                    <td><?= htmlspecialchars($a['email']) ?></td>
                                    <td><?= htmlspecialchars($a['total_minutes']) ?></td>
                                    <td><?= htmlspecialchars($a['total_connections']) ?></td>

                                    <td>
                                        <select name="matches[<?= htmlspecialchars($a['attendance_id']) ?>]"
                                                class="form-select form-select-sm w-100"
                                                style="min-width: 500px;">
                                            <option value="">No match yet...</option>

                                            <?php foreach ($students as $s): ?>
                                                <option value="<?= htmlspecialchars($s['student_id']) ?>">
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
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-footer bg-white text-end">
                    <button type="submit" class="btn btn-success">
                        Save All Matches
                    </button>
                </div>
            </div>

        </form>

    <?php endif; ?>

</div>

</body>
</html>
