<?php
require_once __DIR__ . '/db.php';

$message = "";

/* =========================
   DELETE OUTREACH
========================= */
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);

    if ($delete_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM outreach WHERE outreach_id = ?");
        $stmt->execute([$delete_id]);

        header("Location: outreach.php?deleted=1");
        exit;
    }
}

if (isset($_GET['deleted'])) {
    $message = "<div class='alert alert-success'>Outreach record deleted successfully.</div>";
}

/* =========================
   LOAD ROSTERS
========================= */
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

/* =========================
   LOAD STUDENTS
========================= */
$students = $pdo->query("
    SELECT 
        student_id,
        canonical_name,
        phone,
        email
    FROM students_master
    ORDER BY canonical_name ASC
")->fetchAll();

/* =========================
   FORM VALUES
========================= */
$edit_id = intval($_GET['edit_id'] ?? 0);
$editRecord = null;

if ($edit_id > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM outreach
        WHERE outreach_id = ?
        LIMIT 1
    ");
    $stmt->execute([$edit_id]);
    $editRecord = $stmt->fetch();
}

/* =========================
   SAVE / UPDATE OUTREACH
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $outreach_id = intval($_POST['outreach_id'] ?? 0);
    $student_id = intval($_POST['student_id'] ?? 0);
    $roster_id = intval($_POST['roster_id'] ?? 0);
    $outreach_date = trim($_POST['outreach_date'] ?? '');
    $comment = trim($_POST['comment'] ?? '');
    $time_spent = floatval($_POST['time_spent'] ?? 0);

    $studentData = null;
    $rosterData = null;

    if ($student_id > 0) {
        $stmt = $pdo->prepare("
            SELECT canonical_name, phone, email
            FROM students_master
            WHERE student_id = ?
            LIMIT 1
        ");
        $stmt->execute([$student_id]);
        $studentData = $stmt->fetch();
    }

    if ($roster_id > 0) {
        $stmt = $pdo->prepare("
            SELECT 
                r.roster_name,
                p.program_name
            FROM rosters r
            LEFT JOIN programs p ON r.program_id = p.program_id
            WHERE r.roster_id = ?
            LIMIT 1
        ");
        $stmt->execute([$roster_id]);
        $rosterData = $stmt->fetch();
    }

    $full_name = $studentData['canonical_name'] ?? '';
    $phone = $studentData['phone'] ?? '';
    $email = $studentData['email'] ?? '';
    $roster_name = $rosterData['roster_name'] ?? '';
    $program = $rosterData['program_name'] ?? '';

    if ($outreach_date === '') {
        $outreach_date = date('Y-m-d');
    }

    if ($outreach_id > 0) {

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
                updated_at = NOW()
            WHERE outreach_id = ?
        ");

        $stmt->execute([
            $student_id ?: null,
            $roster_id ?: null,
            $roster_name,
            $full_name,
            $phone,
            $email,
            $program,
            $outreach_date,
            $comment,
            $time_spent,
            $outreach_id
        ]);

        header("Location: outreach.php?updated=1");
        exit;

    } else {

        $stmt = $pdo->prepare("
            INSERT INTO outreach
            (
                student_id,
                roster_id,
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
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 'manual')
        ");

        $stmt->execute([
            $student_id ?: null,
            $roster_id ?: null,
            $roster_name,
            $full_name,
            $phone,
            $email,
            $program,
            $outreach_date,
            $comment,
            $time_spent
        ]);

        header("Location: outreach.php?saved=1");
        exit;
    }
}

if (isset($_GET['saved'])) {
    $message = "<div class='alert alert-success'>Outreach record saved successfully.</div>";
}

if (isset($_GET['updated'])) {
    $message = "<div class='alert alert-success'>Outreach record updated successfully.</div>";
}

/* =========================
   FILTERS
========================= */
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$filter_roster_id = intval($_GET['filter_roster_id'] ?? 0);
$filter_student_id = intval($_GET['filter_student_id'] ?? 0);
$search_name = trim($_GET['search_name'] ?? '');

$where = "WHERE 1=1";
$params = [];

if ($date_from !== '') {
    $where .= " AND o.outreach_date >= ?";
    $params[] = $date_from;
}

if ($date_to !== '') {
    $where .= " AND o.outreach_date <= ?";
    $params[] = $date_to;
}

if ($filter_roster_id > 0) {
    $where .= " AND o.roster_id = ?";
    $params[] = $filter_roster_id;
}

if ($filter_student_id > 0) {
    $where .= " AND o.student_id = ?";
    $params[] = $filter_student_id;
}

if ($search_name !== '') {
    $where .= " AND (o.full_name LIKE ? OR o.phone LIKE ? OR o.email LIKE ?)";
    $params[] = "%$search_name%";
    $params[] = "%$search_name%";
    $params[] = "%$search_name%";
}

/* =========================
   LOAD OUTREACH RECORDS
========================= */
$stmt = $pdo->prepare("
    SELECT 
        o.*,
        sm.canonical_name,
        r.roster_name AS linked_roster_name,
        p.program_name AS linked_program_name
    FROM outreach o
    LEFT JOIN students_master sm ON o.student_id = sm.student_id
    LEFT JOIN rosters r ON o.roster_id = r.roster_id
    LEFT JOIN programs p ON r.program_id = p.program_id
    $where
    ORDER BY o.outreach_date DESC, o.created_at DESC
    LIMIT 500
");
$stmt->execute($params);
$records = $stmt->fetchAll();

/* =========================
   KPI
========================= */
$totalRecords = count($records);
$totalTime = 0;

foreach ($records as $r) {
    $totalTime += floatval($r['time_spent']);
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Outreach Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body { background:#f4f6f9; }
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 8px 22px rgba(0,0,0,.06);
        }
        .table-wrap {
            max-height: 520px;
            overflow-y: auto;
        }
        textarea {
            resize: vertical;
        }
    </style>
</head>

<body>

<div class="container-fluid p-4">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Outreach Management</h3>

        <div>
            <a href="dashboard.php" class="btn btn-secondary btn-sm">Dashboard</a>
            <a href="students_by_roster.php" class="btn btn-success btn-sm">Students</a>
            <a href="historical_dashboard.php" class="btn btn-dark btn-sm">Historical</a>
        </div>
    </div>

    <?= $message ?>

    <div class="row g-3 mb-4">

        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Records Found</small>
                    <h3><?= $totalRecords ?></h3>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Total Time Spent</small>
                    <h3><?= number_format($totalTime, 2) ?></h3>
                    <small>minutes/hours depending your input</small>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-3">

        <div class="col-md-4">

            <div class="card mb-3">
                <div class="card-header bg-primary text-white fw-bold">
                    <?= $editRecord ? 'Edit Outreach' : 'Add Outreach' ?>
                </div>

                <div class="card-body">

                    <form method="POST">

                        <input type="hidden" name="outreach_id" value="<?= htmlspecialchars($editRecord['outreach_id'] ?? 0) ?>">

                        <div class="mb-3">
                            <label class="form-label fw-bold">Student</label>
                            <select name="student_id" class="form-select" required>
                                <option value="">Select student...</option>

                                <?php foreach ($students as $s): ?>
                                    <option value="<?= htmlspecialchars($s['student_id']) ?>"
                                        <?= (($editRecord['student_id'] ?? '') == $s['student_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['canonical_name']) ?>
                                        <?php if (!empty($s['phone'])): ?>
                                            - <?= htmlspecialchars($s['phone']) ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Roster</label>
                            <select name="roster_id" class="form-select" required>
                                <option value="">Select roster...</option>

                                <?php foreach ($rosters as $r): ?>
                                    <option value="<?= htmlspecialchars($r['roster_id']) ?>"
                                        <?= (($editRecord['roster_id'] ?? '') == $r['roster_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($r['program_name']) ?> —
                                        <?= htmlspecialchars($r['roster_name']) ?>
                                        (<?= htmlspecialchars($r['season'] ?? '') ?> <?= htmlspecialchars($r['year'] ?? '') ?>)
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Date</label>
                            <input type="date"
                                   name="outreach_date"
                                   class="form-control"
                                   value="<?= htmlspecialchars($editRecord['outreach_date'] ?? date('Y-m-d')) ?>"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Comment</label>
                            <textarea name="comment"
                                      class="form-control"
                                      rows="4"
                                      required><?= htmlspecialchars($editRecord['comment'] ?? '') ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Time Spent</label>
                            <input type="number"
                                   step="0.01"
                                   name="time_spent"
                                   class="form-control"
                                   value="<?= htmlspecialchars($editRecord['time_spent'] ?? 0) ?>">
                        </div>

                        <button class="btn btn-primary w-100">
                            <?= $editRecord ? 'Update Outreach' : 'Save Outreach' ?>
                        </button>

                        <?php if ($editRecord): ?>
                            <a href="outreach.php" class="btn btn-outline-secondary w-100 mt-2">
                                Cancel Edit
                            </a>
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

                        <div class="col-md-3">
                            <label class="form-label small fw-bold">From</label>
                            <input type="date"
                                   name="date_from"
                                   class="form-control"
                                   value="<?= htmlspecialchars($date_from) ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-bold">To</label>
                            <input type="date"
                                   name="date_to"
                                   class="form-control"
                                   value="<?= htmlspecialchars($date_to) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Roster</label>
                            <select name="filter_roster_id" class="form-select">
                                <option value="0">All rosters</option>

                                <?php foreach ($rosters as $r): ?>
                                    <option value="<?= htmlspecialchars($r['roster_id']) ?>"
                                        <?= $filter_roster_id == $r['roster_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($r['program_name']) ?> —
                                        <?= htmlspecialchars($r['roster_name']) ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Student</label>
                            <select name="filter_student_id" class="form-select">
                                <option value="0">All students</option>

                                <?php foreach ($students as $s): ?>
                                    <option value="<?= htmlspecialchars($s['student_id']) ?>"
                                        <?= $filter_student_id == $s['student_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['canonical_name']) ?>
                                        <?php if (!empty($s['phone'])): ?>
                                            - <?= htmlspecialchars($s['phone']) ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Search name / phone / email</label>
                            <input type="text"
                                   name="search_name"
                                   class="form-control"
                                   value="<?= htmlspecialchars($search_name) ?>"
                                   placeholder="Search...">
                        </div>

                        <div class="col-md-2 d-flex align-items-end">
                            <button class="btn btn-primary w-100">Apply</button>
                        </div>

                        <div class="col-md-2 d-flex align-items-end">
                            <a href="outreach.php" class="btn btn-outline-secondary w-100">Reset</a>
                        </div>

                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-white fw-bold d-flex justify-content-between">
                    <span>Outreach Records</span>
                    <span><?= $totalRecords ?> records</span>
                </div>

                <div class="table-wrap">
                    <table class="table table-bordered table-striped table-sm align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Roster</th>
                                <th>Comment</th>
                                <th>Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($records as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['outreach_date'] ?? '') ?></td>

                                    <td>
                                        <b><?= htmlspecialchars($r['canonical_name'] ?? $r['full_name'] ?? '') ?></b><br>
                                        <small><?= htmlspecialchars($r['phone'] ?? '') ?></small>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($r['linked_program_name'] ?? $r['program'] ?? '') ?><br>
                                        <small><?= htmlspecialchars($r['linked_roster_name'] ?? $r['roster_name'] ?? '') ?></small>
                                    </td>

                                    <td><?= nl2br(htmlspecialchars($r['comment'] ?? '')) ?></td>

                                    <td><?= htmlspecialchars($r['time_spent'] ?? 0) ?></td>

                                    <td>
                                        <a class="btn btn-sm btn-warning"
                                           href="outreach.php?edit_id=<?= htmlspecialchars($r['outreach_id']) ?>">
                                            Edit
                                        </a>

                                        <a class="btn btn-sm btn-danger"
                                           href="outreach.php?delete_id=<?= htmlspecialchars($r['outreach_id']) ?>"
                                           onclick="return confirm('Delete this outreach record?');">
                                            Delete
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (count($records) == 0): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">
                                        No outreach records found.
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