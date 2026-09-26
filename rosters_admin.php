<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['admin','coordinator']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/audit.php';
ensure_session_started();

$message = "";

/* =========================
   LOAD PROGRAMS
========================= */
$programs = $pdo->query("
    SELECT program_id, program_name
    FROM programs
    ORDER BY program_name ASC
")->fetchAll();

/* =========================
   SAVE / UPDATE ROSTER
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_roster'])) {
    verify_csrf();

    $roster_id = cleanInt($_POST['roster_id'] ?? 0);
    $program_id = cleanInt($_POST['program_id'] ?? 0);

    $roster_name = cleanText($_POST['roster_name'] ?? '', 200);
    $season = cleanText($_POST['season'] ?? '', 50);
    $year = cleanInt($_POST['year'] ?? 0);
    $status = cleanText($_POST['status'] ?? '', 20);

    $teacher_name = cleanText($_POST['teacher_name'] ?? '', 150);
    $schedule_notes = cleanText($_POST['schedule_notes'] ?? '', 255);
    $zoom_link = filter_var(trim($_POST['zoom_link'] ?? ''), FILTER_SANITIZE_URL);
    $capacity = cleanInt($_POST['capacity'] ?? 0);
    $notes = cleanText($_POST['notes'] ?? '', 2000);

    $allowedSeasons = ['Winter', 'Spring', 'Summer', 'Fall'];
    $allowedStatus = ['active', 'closed'];

    if (!in_array($season, $allowedSeasons, true)) {
        die("Invalid season.");
    }

    if (!in_array($status, $allowedStatus, true)) {
        die("Invalid status.");
    }

    if ($program_id <= 0 || $roster_name === '' || $year <= 0) {
        $message = "<div class='alert alert-danger'>Program, roster name and year are required.</div>";
    } else {
        if ($roster_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE rosters
                SET program_id = ?,
                    roster_name = ?,
                    season = ?,
                    year = ?,
                    status = ?,
                    teacher_name = ?,
                    schedule_notes = ?,
                    zoom_link = ?,
                    capacity = ?,
                    notes = ?
                WHERE roster_id = ?
            ");

            $stmt->execute([
                $program_id,
                $roster_name,
                $season,
                $year,
                $status,
                $teacher_name,
                $schedule_notes,
                $zoom_link,
                $capacity ?: null,
                $notes,
                $roster_id
            ]);

            logActivity('UPDATE', 'rosters', $roster_id, 'Updated roster ' . $roster_name);

            header("Location: rosters_admin.php?updated=1");
            exit;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO rosters
                (
                    program_id,
                    roster_name,
                    season,
                    year,
                    status,
                    teacher_name,
                    schedule_notes,
                    zoom_link,
                    capacity,
                    notes
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $program_id,
                $roster_name,
                $season,
                $year,
                $status,
                $teacher_name,
                $schedule_notes,
                $zoom_link,
                $capacity ?: null,
                $notes
            ]);

            $newRosterId = (int) $pdo->lastInsertId();
            logActivity('CREATE', 'rosters', $newRosterId, 'Created roster ' . $roster_name);

            header("Location: rosters_admin.php?saved=1");
            exit;
        }
    }
}

/* =========================
   CLOSE / ACTIVATE ROSTER
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'])) {
    verify_csrf();

    $roster_id = cleanInt($_POST['roster_id'] ?? 0);
    $new_status = cleanText($_POST['new_status'] ?? '', 20);

    if ($roster_id > 0 && in_array($new_status, ['active', 'closed'], true)) {
        $stmt = $pdo->prepare("
            UPDATE rosters
            SET status = ?
            WHERE roster_id = ?
        ");
        $stmt->execute([$new_status, $roster_id]);

        logActivity('UPDATE', 'rosters', $roster_id, 'Changed roster #' . $roster_id . ' status to ' . $new_status);

        header("Location: rosters_admin.php?status_changed=1");
        exit;
    }
}

if (isset($_GET['saved'])) {
    $message = "<div class='alert alert-success'>Roster created successfully.</div>";
}

if (isset($_GET['updated'])) {
    $message = "<div class='alert alert-success'>Roster updated successfully.</div>";
}

if (isset($_GET['status_changed'])) {
    $message = "<div class='alert alert-success'>Roster status updated successfully.</div>";
}

/* =========================
   EDIT RECORD
========================= */
$edit_id = cleanInt($_GET['edit_id'] ?? 0);
$editRecord = null;

if ($edit_id > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM rosters
        WHERE roster_id = ?
        LIMIT 1
    ");
    $stmt->execute([$edit_id]);
    $editRecord = $stmt->fetch();
}

/* =========================
   FILTERS
========================= */
$search = cleanText($_GET['search'] ?? '', 100);
$filter_program = cleanInt($_GET['program_id'] ?? 0);
$filter_year = cleanInt($_GET['year'] ?? 0);
$filter_season = cleanText($_GET['season'] ?? '', 50);
$filter_status = cleanText($_GET['status'] ?? '', 20);

$where = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $where .= " AND r.roster_name LIKE ?";
    $params[] = "%$search%";
}

if ($filter_program > 0) {
    $where .= " AND r.program_id = ?";
    $params[] = $filter_program;
}

if ($filter_year > 0) {
    $where .= " AND r.year = ?";
    $params[] = $filter_year;
}

if (in_array($filter_season, ['Winter', 'Spring', 'Summer', 'Fall'], true)) {
    $where .= " AND r.season = ?";
    $params[] = $filter_season;
}

if (in_array($filter_status, ['active', 'closed'], true)) {
    $where .= " AND r.status = ?";
    $params[] = $filter_status;
}

/* =========================
   LOAD ROSTERS WITH KPIs
========================= */
$stmt = $pdo->prepare("
    SELECT
        r.*,
        p.program_name,

        COUNT(DISTINCT sr.student_id) AS total_students,

        COUNT(DISTINCT CASE WHEN sr.status = 'active' THEN sr.student_id END) AS active_students,

        COUNT(DISTINCT CASE WHEN sr.status = 'dropped' THEN sr.student_id END) AS dropped_students,

        ROUND(
            CASE 
                WHEN COUNT(ar.attendance_id) > 0
                THEN SUM(CASE WHEN ar.present = 1 THEN 1 ELSE 0 END) / COUNT(ar.attendance_id) * 100
                ELSE 0
            END, 1
        ) AS attendance_rate

    FROM rosters r
    LEFT JOIN programs p ON r.program_id = p.program_id
    LEFT JOIN student_rosters sr ON r.roster_id = sr.roster_id
    LEFT JOIN attendance_records ar ON r.roster_id = ar.roster_id
    $where
    GROUP BY r.roster_id
    ORDER BY r.year DESC, p.program_name ASC, r.roster_name ASC
");
$stmt->execute($params);
$rosters = $stmt->fetchAll();

$years = $pdo->query("
    SELECT DISTINCT year
    FROM rosters
    WHERE year IS NOT NULL
    ORDER BY year DESC
")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Rosters Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body { background:#f4f6f9; }
        .card {
            border:0;
            border-radius:16px;
            box-shadow:0 8px 22px rgba(0,0,0,.06);
        }
        .table-wrap {
            max-height:650px;
            overflow-y:auto;
        }
    </style>
</head>

<body>

<div class="container-fluid app-page">

    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">Rosters Admin</h3>
        <small class="page-subtitle">Create and manage program rosters.</small>
    </div>

    <?= $message ?>

    <div class="row g-3">

        <div class="col-md-4">

            <div class="card mb-3">
                <div class="card-header bg-primary text-white fw-bold">
                    <?= $editRecord ? 'Edit Roster' : 'Create Roster' ?>
                </div>

                <div class="card-body">

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="save_roster" value="1">
                        <input type="hidden" name="roster_id" value="<?= e($editRecord['roster_id'] ?? 0) ?>">

                        <label class="form-label fw-bold">Program</label>
                        <select name="program_id" class="form-select mb-2" required>
                            <option value="">Select program...</option>
                            <?php foreach ($programs as $p): ?>
                                <option value="<?= e($p['program_id']) ?>"
                                    <?= (($editRecord['program_id'] ?? '') == $p['program_id']) ? 'selected' : '' ?>>
                                    <?= e($p['program_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label class="form-label fw-bold">Roster Name</label>
                        <input name="roster_name"
                               class="form-control mb-2"
                               required
                               maxlength="200"
                               value="<?= e($editRecord['roster_name'] ?? '') ?>">

                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Season</label>
                                <select name="season" class="form-select mb-2" required>
                                    <?php foreach (['Winter','Spring','Summer','Fall'] as $season): ?>
                                        <option value="<?= e($season) ?>"
                                            <?= (($editRecord['season'] ?? '') == $season) ? 'selected' : '' ?>>
                                            <?= e($season) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold">Year</label>
                                <input type="number"
                                       name="year"
                                       class="form-control mb-2"
                                       min="2020"
                                       max="2100"
                                       required
                                       value="<?= e($editRecord['year'] ?? date('Y')) ?>">
                            </div>
                        </div>

                        <label class="form-label fw-bold">Status</label>
                        <select name="status" class="form-select mb-2">
                            <option value="active" <?= (($editRecord['status'] ?? '') == 'active') ? 'selected' : '' ?>>active</option>
                            <option value="closed" <?= (($editRecord['status'] ?? '') == 'closed') ? 'selected' : '' ?>>closed</option>
                        </select>

                        <label class="form-label fw-bold">Teacher</label>
                        <input name="teacher_name"
                               class="form-control mb-2"
                               maxlength="150"
                               value="<?= e($editRecord['teacher_name'] ?? '') ?>">

                        <label class="form-label fw-bold">Schedule Notes</label>
                        <input name="schedule_notes"
                               class="form-control mb-2"
                               maxlength="255"
                               value="<?= e($editRecord['schedule_notes'] ?? '') ?>">

                        <label class="form-label fw-bold">Zoom Link</label>
                        <input name="zoom_link"
                               class="form-control mb-2"
                               maxlength="255"
                               value="<?= e($editRecord['zoom_link'] ?? '') ?>">

                        <label class="form-label fw-bold">Capacity</label>
                        <input type="number"
                               name="capacity"
                               class="form-control mb-2"
                               min="0"
                               max="500"
                               value="<?= e($editRecord['capacity'] ?? '') ?>">

                        <label class="form-label fw-bold">Notes</label>
                        <textarea name="notes"
                                  class="form-control mb-3"
                                  rows="3"><?= e($editRecord['notes'] ?? '') ?></textarea>

                        <button class="btn btn-primary w-100">
                            <?= $editRecord ? 'Update Roster' : 'Create Roster' ?>
                        </button>

                        <?php if ($editRecord): ?>
                            <a href="rosters_admin.php" class="btn btn-outline-secondary w-100 mt-2">Cancel Edit</a>
                        <?php endif; ?>
                    </form>

                </div>
            </div>

        </div>

        <div class="col-md-8">

            <div class="card mb-3">
                <div class="card-header bg-white fw-bold">
                    Filters
                </div>

                <div class="card-body">
                    <form method="GET" class="row g-2">

                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Search roster</label>
                            <input name="search"
                                   class="form-control"
                                   value="<?= e($search) ?>"
                                   placeholder="Roster name...">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Program</label>
                            <select name="program_id" class="form-select">
                                <option value="0">All</option>
                                <?php foreach ($programs as $p): ?>
                                    <option value="<?= e($p['program_id']) ?>" <?= $filter_program == $p['program_id'] ? 'selected' : '' ?>>
                                        <?= e($p['program_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Year</label>
                            <select name="year" class="form-select">
                                <option value="0">All</option>
                                <?php foreach ($years as $y): ?>
                                    <option value="<?= e($y) ?>" <?= $filter_year == $y ? 'selected' : '' ?>>
                                        <?= e($y) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Season</label>
                            <select name="season" class="form-select">
                                <option value="">All</option>
                                <?php foreach (['Winter','Spring','Summer','Fall'] as $season): ?>
                                    <option value="<?= e($season) ?>" <?= $filter_season == $season ? 'selected' : '' ?>>
                                        <?= e($season) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-1">
                            <label class="form-label small fw-bold">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All</option>
                                <option value="active" <?= $filter_status == 'active' ? 'selected' : '' ?>>A</option>
                                <option value="closed" <?= $filter_status == 'closed' ? 'selected' : '' ?>>C</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <button class="btn btn-primary w-100 mt-3">Apply</button>
                        </div>

                        <div class="col-md-3">
                            <a href="rosters_admin.php" class="btn btn-outline-secondary w-100 mt-3">Reset</a>
                        </div>

                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-white fw-bold d-flex justify-content-between">
                    <span>Rosters</span>
                    <span><?= e(count($rosters)) ?> records</span>
                </div>

                <div class="table-wrap">
                    <table class="table table-sm table-bordered table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Roster</th>
                                <th>Program</th>
                                <th>Period</th>
                                <th>Status</th>
                                <th>Students</th>
                                <th>Attendance</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($rosters as $r): ?>
                                <tr>
                                    <td>
                                        <b><?= e($r['roster_name']) ?></b><br>
                                        <small><?= e($r['teacher_name']) ?></small>
                                    </td>

                                    <td><?= e($r['program_name']) ?></td>

                                    <td><?= e($r['season']) ?> <?= e($r['year']) ?></td>

                                    <td>
                                        <?php if ($r['status'] === 'active'): ?>
                                            <span class="badge bg-success">active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">closed</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        Total: <?= e($r['total_students']) ?><br>
                                        <small>
                                            <span class="badge bg-success">Active: <?= e($r['active_students']) ?></span>
                                            <span class="badge bg-danger">Dropped: <?= e($r['dropped_students']) ?></span>
                                        </small>
                                    </td>

                                    <td>
                                        <span class="badge bg-primary">
                                            <?= e($r['attendance_rate']) ?>%
                                        </span>
                                    </td>

                                    <td>
                                        <a class="btn btn-sm btn-warning"
                                           href="rosters_admin.php?edit_id=<?= e($r['roster_id']) ?>">
                                            Edit
                                        </a>

                                        <a class="btn btn-sm btn-success"
                                           href="students_by_roster.php?roster_id=<?= e($r['roster_id']) ?>">
                                            Students
                                        </a>

                                        <a class="btn btn-sm btn-info"
                                           href="attendance_view.php?roster_id=<?= e($r['roster_id']) ?>">
                                            Attendance
                                        </a>

                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="change_status" value="1">
                                            <input type="hidden" name="roster_id" value="<?= e($r['roster_id']) ?>">
                                            <input type="hidden" name="new_status" value="<?= $r['status'] === 'active' ? 'closed' : 'active' ?>">

                                            <button class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('Change roster status?');">
                                                <?= $r['status'] === 'active' ? 'Close' : 'Activate' ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (count($rosters) == 0): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">
                                        No rosters found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>

                    </table>
                </div>
            </div>

        </div>

    </div>

</div>

</body>
</html>
