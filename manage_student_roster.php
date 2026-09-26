<?php
require_once __DIR__ . '/db.php';

$message = "";
$student_id = intval($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
$current_roster_id = intval($_GET['roster_id'] ?? $_POST['current_roster_id'] ?? 0);

/* =========================
   LOAD ROSTERS
========================= */
$rosters = $pdo->query("
    SELECT r.roster_id, r.roster_name, r.season, r.year, p.program_name
    FROM rosters r
    INNER JOIN programs p ON r.program_id = p.program_id
    ORDER BY r.year DESC, p.program_name ASC, r.roster_name ASC
")->fetchAll();

/* =========================
   ACTIONS
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    $target_roster_id = intval($_POST['target_roster_id'] ?? 0);

    if ($action === 'drop') {

        $stmt = $pdo->prepare("
            UPDATE student_rosters
            SET status = 'dropped'
            WHERE student_id = ?
              AND roster_id = ?
        ");
        $stmt->execute([$student_id, $current_roster_id]);

        $message = "<div class='alert alert-success'>Student marked as dropped.</div>";
    }

    if ($action === 'move') {

        $check = $pdo->prepare("
            SELECT id
            FROM student_rosters
            WHERE student_id = ?
              AND roster_id = ?
            LIMIT 1
        ");
        $check->execute([$student_id, $target_roster_id]);
        $exists = $check->fetch();

        if ($exists) {
            $message = "<div class='alert alert-warning'>This student already exists in the selected roster. No movement was made.</div>";
        } else {
            $pdo->beginTransaction();

            $dropOld = $pdo->prepare("
                UPDATE student_rosters
                SET status = 'dropped'
                WHERE student_id = ?
                  AND roster_id = ?
            ");
            $dropOld->execute([$student_id, $current_roster_id]);

            $insertNew = $pdo->prepare("
                INSERT INTO student_rosters
                (student_id, roster_id, status)
                VALUES (?, ?, 'active')
            ");
            $insertNew->execute([$student_id, $target_roster_id]);

            $pdo->commit();

            $message = "<div class='alert alert-success'>Student moved successfully to the new roster.</div>";
            $current_roster_id = $target_roster_id;
        }
    }
}

/* =========================
   LOAD STUDENT
========================= */
$stmt = $pdo->prepare("
    SELECT sm.*, sr.status AS roster_status, r.roster_name, p.program_name
    FROM students_master sm
    INNER JOIN student_rosters sr ON sm.student_id = sr.student_id
    INNER JOIN rosters r ON sr.roster_id = r.roster_id
    INNER JOIN programs p ON r.program_id = p.program_id
    WHERE sm.student_id = ?
      AND r.roster_id = ?
    LIMIT 1
");
$stmt->execute([$student_id, $current_roster_id]);
$student = $stmt->fetch();

if (!$student) {
    die("Student not found in this roster.");
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Student Roster</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

<div class="container mt-4">

    <h3>Manage Student</h3>

    <?= $message ?>

    <div class="card mb-4">
        <div class="card-body">
            <h5><?= htmlspecialchars($student['canonical_name']) ?></h5>
            <p>
                <b>Phone:</b> <?= htmlspecialchars($student['phone']) ?><br>
                <b>Email:</b> <?= htmlspecialchars($student['email']) ?><br>
                <b>Current Roster:</b> <?= htmlspecialchars($student['program_name']) ?> — <?= htmlspecialchars($student['roster_name']) ?><br>
                <b>Status:</b> <?= htmlspecialchars($student['roster_status']) ?>
            </p>
        </div>
    </div>

    <div class="row">

        <div class="col-md-6">
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    Drop / Withdraw Student
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                        <input type="hidden" name="current_roster_id" value="<?= $current_roster_id ?>">
                        <input type="hidden" name="action" value="drop">

                        <label class="form-label">Reason</label>
                        <textarea name="reason" class="form-control mb-3" placeholder="Reason for withdrawal..."></textarea>

                        <button class="btn btn-danger w-100">
                            Mark as Dropped
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    Move Student to Another Roster
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                        <input type="hidden" name="current_roster_id" value="<?= $current_roster_id ?>">
                        <input type="hidden" name="action" value="move">

                        <label class="form-label">Target Roster</label>
                        <select name="target_roster_id" class="form-select mb-3" required>
                            <option value="">Select target roster...</option>
                            <?php foreach ($rosters as $r): ?>
                                <?php if ($r['roster_id'] != $current_roster_id): ?>
                                    <option value="<?= $r['roster_id'] ?>">
                                        <?= htmlspecialchars($r['program_name']) ?> —
                                        <?= htmlspecialchars($r['roster_name']) ?>
                                        (<?= htmlspecialchars($r['season']) ?> <?= htmlspecialchars($r['year']) ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>

                        <label class="form-label">Reason</label>
                        <textarea name="reason" class="form-control mb-3" placeholder="Reason for moving..."></textarea>

                        <button class="btn btn-primary w-100">
                            Move Student
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <hr>

    <a href="students_by_roster.php?roster_id=<?= $current_roster_id ?>" class="btn btn-secondary">
        Back to Students
    </a>

</div>

</body>
</html>