<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['admin','staff']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';

if (file_exists(__DIR__ . '/includes/audit.php')) {
    require_once __DIR__ . '/includes/audit.php';
}

$allowedRosterStatuses = ['active', 'dropped', 'completed', 'waiting', 'moved'];
$message = '';
$selected_roster_id = intval($_GET['roster_id'] ?? 0);

function statusBadgeClass(string $status): string
{
    $normalized = strtolower(trim($status));

    if (in_array($normalized, ['active', 'activo'], true)) {
        return 'bg-success';
    }

    if (in_array($normalized, ['dropped', 'retired', 'retirado', 'inactive', 'inactivo'], true)) {
        return 'bg-danger';
    }

    if (in_array($normalized, ['promoted', 'completed', 'completado'], true)) {
        return 'bg-info text-dark';
    }

    if ($normalized === 'moved') {
        return 'bg-warning text-dark';
    }

    return 'bg-secondary';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_roster_status'])) {
    verify_csrf();

    $student_id = cleanInt($_POST['student_id'] ?? 0);
    $roster_id = cleanInt($_POST['roster_id'] ?? 0);
    $new_status = cleanText($_POST['roster_status'] ?? '', 30);

    if ($student_id > 0 && $roster_id > 0 && in_array($new_status, $allowedRosterStatuses, true)) {
        $stmt = $pdo->prepare("
            UPDATE student_rosters
            SET status = ?
            WHERE student_id = ?
              AND roster_id = ?
        ");
        $stmt->execute([$new_status, $student_id, $roster_id]);

        $studentStmt = $pdo->prepare("
            SELECT canonical_name
            FROM students_master
            WHERE student_id = ?
            LIMIT 1
        ");
        $studentStmt->execute([$student_id]);
        $studentName = (string) ($studentStmt->fetchColumn() ?: ('student #' . $student_id));

        $rosterStmt = $pdo->prepare("
            SELECT roster_name
            FROM rosters
            WHERE roster_id = ?
            LIMIT 1
        ");
        $rosterStmt->execute([$roster_id]);
        $rosterName = (string) ($rosterStmt->fetchColumn() ?: ('roster #' . $roster_id));

        if (function_exists('logActivity')) {
            logActivity('UPDATE', 'student_rosters', $student_id, 'Updated roster status for ' . $studentName . ' in ' . $rosterName . ' to ' . $new_status);
        }

        header('Location: students_by_roster.php?roster_id=' . $roster_id . '&updated=1');
        exit;
    }

    $message = "<div class='alert alert-danger'>Invalid roster status update.</div>";
}

if (isset($_GET['updated'])) {
    $message = "<div class='alert alert-success'>Roster status updated successfully.</div>";
}

$rosters = $pdo->query("
    SELECT r.roster_id, r.roster_name, p.program_name, r.season, r.year
    FROM rosters r
    LEFT JOIN programs p ON r.program_id = p.program_id
    ORDER BY r.year DESC, p.program_name ASC, r.roster_name ASC
")->fetchAll();

$students = [];
$selected_roster = null;

if ($selected_roster_id > 0) {
    $stmtRoster = $pdo->prepare("
        SELECT r.roster_name, p.program_name, r.season, r.year
        FROM rosters r
        LEFT JOIN programs p ON r.program_id = p.program_id
        WHERE r.roster_id = ?
    ");
    $stmtRoster->execute([$selected_roster_id]);
    $selected_roster = $stmtRoster->fetch();

    $stmt = $pdo->prepare("
        SELECT 
            sm.student_id,
            sm.canonical_name,
            sm.phone,
            sm.email,
            sm.status AS student_status,
            sr.status AS roster_status
        FROM student_rosters sr
        INNER JOIN students_master sm ON sr.student_id = sm.student_id
        WHERE sr.roster_id = ?
        ORDER BY sm.canonical_name ASC
    ");
    $stmt->execute([$selected_roster_id]);
    $students = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Students by Roster</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body { background:#f4f6f9; }
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 8px 22px rgba(0,0,0,.06);
        }
    </style>
</head>

<body>

<div class="container-fluid app-page">

    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">Students by Program / Roster</h3>
        <small class="page-subtitle">Review roster students and update roster status.</small>
    </div>

    <?= $message ?>

    <form method="GET" class="card card-body mb-3">
        <label class="form-label fw-bold">Select Program / Roster</label>

        <div class="row g-2">
            <div class="col-md-8">
                <select name="roster_id" class="form-select" required>
                    <option value="">Select roster...</option>

                    <?php foreach ($rosters as $r): ?>
                        <option value="<?= htmlspecialchars($r['roster_id']) ?>" 
                            <?= $selected_roster_id == $r['roster_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['program_name']) ?> —
                            <?= htmlspecialchars($r['roster_name']) ?>
                            (<?= htmlspecialchars($r['season'] ?? '') ?> <?= htmlspecialchars($r['year'] ?? '') ?>)
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="col-md-2">
                <button class="btn btn-primary w-100">View Students</button>
            </div>

            <div class="col-md-2">
                <a href="students_by_roster.php" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </div>
    </form>

    <?php if ($selected_roster): ?>
        <div class="alert alert-info">
            <b>
                <?= htmlspecialchars($selected_roster['program_name']) ?> —
                <?= htmlspecialchars($selected_roster['roster_name']) ?>
                (<?= htmlspecialchars($selected_roster['season'] ?? '') ?> <?= htmlspecialchars($selected_roster['year'] ?? '') ?>)
            </b>
            <br>
            Students found: <b><?= count($students) ?></b>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header bg-white fw-bold d-flex justify-content-between">
            <span>Student List</span>
            <span><?= count($students) ?> records</span>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-striped table-sm align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Student Name</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Student Status</th>
                        <th>Roster Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($students as $i => $s): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>

                            <td>
                                <b><?= htmlspecialchars($s['canonical_name']) ?></b>
                            </td>

                            <td><?= htmlspecialchars($s['phone'] ?? '') ?></td>

                            <td><?= htmlspecialchars($s['email'] ?? '') ?></td>

                            <td>
                                <span class="badge <?= e(statusBadgeClass((string) ($s['student_status'] ?? ''))) ?>">
                                    <?= e($s['student_status'] ?? '') ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge <?= e(statusBadgeClass((string) ($s['roster_status'] ?? ''))) ?>">
                                    <?= e($s['roster_status'] ?? '') ?>
                                </span>
                            </td>

                            <td>
                                <form method="POST" class="d-flex gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="update_roster_status" value="1">
                                    <input type="hidden" name="student_id" value="<?= e($s['student_id']) ?>">
                                    <input type="hidden" name="roster_id" value="<?= e($selected_roster_id) ?>">

                                    <select name="roster_status" class="form-select form-select-sm">
                                        <?php foreach ($allowedRosterStatuses as $status): ?>
                                            <option value="<?= e($status) ?>" <?= (($s['roster_status'] ?? '') === $status) ? 'selected' : '' ?>>
                                                <?= e($status) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button class="btn btn-sm btn-primary">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if ($selected_roster_id > 0 && count($students) == 0): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                No students found for this roster.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php if ($selected_roster_id == 0): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                Please select a roster to view students.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

</body>
</html>
