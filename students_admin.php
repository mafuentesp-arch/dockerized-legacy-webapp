<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['admin','coordinator']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/audit.php';
ensure_session_started();

$message = "";

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

/* SAVE / UPDATE STUDENT */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student'])) {
    verify_csrf();

    $student_id = cleanInt($_POST['student_id'] ?? 0);
    $canonical_name = cleanText($_POST['canonical_name'] ?? '', 200);
    $phone = cleanPhone($_POST['phone'] ?? '');
	$phoneForDb = $phone !== '' ? $phone : null;
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $status = cleanText($_POST['status'] ?? 'active', 30);

    $allowedStatus = ['active', 'inactive', 'dropped', 'completed'];

    if (!in_array($status, $allowedStatus, true)) {
        die("Invalid status.");
    }

    if ($canonical_name === '') {
        $message = "<div class='alert alert-danger'>Student name is required.</div>";
    } else {
        if ($student_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE students_master
                SET canonical_name = ?,
                    phone = ?,
                    phone_normalized = ?,
                    email = ?,
                    status = ?
                WHERE student_id = ?
            ");
			$stmt->execute([
				$canonical_name,
				$phoneForDb,
				$phoneForDb,
				$email,
				$status,
				$student_id
			]);
            logActivity('UPDATE', 'students_master', $student_id, 'Updated student ' . $canonical_name);

            header("Location: students_admin.php?updated=1");
            exit;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO students_master
                (canonical_name, phone, phone_normalized, email, status)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
				$canonical_name,
				$phoneForDb,
				$phoneForDb,
				$email,
				$status
			]);

            $newStudentId = (int) $pdo->lastInsertId();
            logActivity('CREATE', 'students_master', $newStudentId, 'Created student ' . $canonical_name);

            header("Location: students_admin.php?saved=1");
            exit;
        }
    }
}

/* CHANGE STUDENT ROSTER STATUS */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_roster_status'])) {
    verify_csrf();

    $student_id = cleanInt($_POST['student_id'] ?? 0);
    $roster_id = cleanInt($_POST['roster_id'] ?? 0);
    $new_status = cleanText($_POST['new_status'] ?? '', 30);

    if ($student_id > 0 && $roster_id > 0 && in_array($new_status, ['active', 'dropped', 'completed', 'waiting', 'moved'], true)) {
        $stmt = $pdo->prepare("
            UPDATE student_rosters
            SET status = ?
            WHERE student_id = ?
              AND roster_id = ?
        ");
        $stmt->execute([$new_status, $student_id, $roster_id]);

        logActivity('UPDATE', 'student_rosters', $student_id, 'Changed student #' . $student_id . ' roster #' . $roster_id . ' status to ' . $new_status);

        header("Location: students_admin.php?student_id=$student_id&status_changed=1");
        exit;
    }
}

/* ASSIGN STUDENT TO ROSTER */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_student_roster'])) {
    verify_csrf();

    $student_id = cleanInt($_POST['student_id'] ?? 0);
    $roster_id = cleanInt($_POST['roster_id'] ?? 0);
    $status = cleanText($_POST['status'] ?? 'active', 30);
    $allowedRosterStatus = ['active', 'waiting', 'completed', 'dropped', 'moved'];

    if ($student_id <= 0 || $roster_id <= 0 || !in_array($status, $allowedRosterStatus, true)) {
        $message = "<div class='alert alert-danger'>Invalid roster assignment.</div>";
    } else {
        $stmt = $pdo->prepare("
            SELECT canonical_name
            FROM students_master
            WHERE student_id = ?
            LIMIT 1
        ");
        $stmt->execute([$student_id]);
        $studentName = $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT
                r.roster_name,
                r.season,
                r.year,
                p.program_name
            FROM rosters r
            INNER JOIN programs p ON r.program_id = p.program_id
            WHERE r.roster_id = ?
            LIMIT 1
        ");
        $stmt->execute([$roster_id]);
        $roster = $stmt->fetch();

        if ($studentName === false || !$roster) {
            $message = "<div class='alert alert-danger'>Student or roster not found.</div>";
        } else {
            $check = $pdo->prepare("
                SELECT id
                FROM student_rosters
                WHERE student_id = ?
                  AND roster_id = ?
                LIMIT 1
            ");
            $check->execute([$student_id, $roster_id]);
            $existingRoster = $check->fetch();

            if ($existingRoster) {
                $stmt = $pdo->prepare("
                    UPDATE student_rosters
                    SET status = ?
                    WHERE student_id = ?
                      AND roster_id = ?
                ");
                $stmt->execute([$status, $student_id, $roster_id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO student_rosters
                    (student_id, roster_id, status)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$student_id, $roster_id, $status]);
            }

            $rosterLabel = $roster['program_name'] . ' - ' . $roster['roster_name'] . ' (' . $roster['season'] . ' ' . $roster['year'] . ')';
            logActivity('UPDATE', 'student_rosters', $student_id, 'Assigned ' . $studentName . ' to ' . $rosterLabel . ' with ' . $status);

            header("Location: students_admin.php?student_id=$student_id&assigned=1");
            exit;
        }
    }
}


/* TRANSFER STUDENT TO ANOTHER ROSTER */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_student'])) {
    verify_csrf();

    $student_id = cleanInt($_POST['student_id'] ?? 0);
    $from_roster_id = cleanInt($_POST['from_roster_id'] ?? 0);
    $to_roster_id = cleanInt($_POST['to_roster_id'] ?? 0);
    $reason = cleanText($_POST['reason'] ?? '', 1000);

    if ($student_id <= 0 || $from_roster_id <= 0 || $to_roster_id <= 0) {
        die("Invalid transfer data.");
    }

    if ($from_roster_id === $to_roster_id) {
        die("Destination roster cannot be the same as current roster.");
    }

    try {
        $pdo->beginTransaction();

        $check = $pdo->prepare("
            SELECT id
            FROM student_rosters
            WHERE student_id = ?
              AND roster_id = ?
            LIMIT 1
        ");
        $check->execute([$student_id, $to_roster_id]);
        $existingTarget = $check->fetch();

        $stmt = $pdo->prepare("
            UPDATE student_rosters
            SET status = 'moved'
            WHERE student_id = ?
              AND roster_id = ?
        ");
        $stmt->execute([$student_id, $from_roster_id]);

        if ($existingTarget) {
            $stmt = $pdo->prepare("
                UPDATE student_rosters
                SET status = 'active'
                WHERE student_id = ?
                  AND roster_id = ?
            ");
            $stmt->execute([$student_id, $to_roster_id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO student_rosters
                (student_id, roster_id, status)
                VALUES (?, ?, 'active')
            ");
            $stmt->execute([$student_id, $to_roster_id]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO student_transfers
            (student_id, from_roster_id, to_roster_id, reason, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $student_id,
            $from_roster_id,
            $to_roster_id,
            $reason,
            'system'
        ]);

        $pdo->commit();

        logActivity('TRANSFER', 'student_rosters', $student_id, 'Transferred student #' . $student_id . ' from roster #' . $from_roster_id . ' to roster #' . $to_roster_id);

        header("Location: students_admin.php?student_id=$student_id&transferred=1");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Transfer error: " . e($e->getMessage()));
    }
}

/* MESSAGES */
if (isset($_GET['saved'])) {
    $message = "<div class='alert alert-success'>Student created successfully.</div>";
}

if (isset($_GET['updated'])) {
    $message = "<div class='alert alert-success'>Student updated successfully.</div>";
}

if (isset($_GET['status_changed'])) {
    $message = "<div class='alert alert-success'>Roster status updated successfully.</div>";
}

if (isset($_GET['transferred'])) {
    $message = "<div class='alert alert-success'>Student transferred successfully.</div>";
}

if (isset($_GET['assigned'])) {
    $message = "<div class='alert alert-success'>Student roster assignment saved successfully.</div>";
}

/* FILTERS */
$search = cleanText($_GET['search'] ?? '', 100);
$filter_status = cleanText($_GET['status'] ?? '', 30);
$assign_search = cleanText($_GET['assign_search'] ?? '', 100);
$assign_search = preg_replace('/\s+/', ' ', trim($assign_search));
$selected_student_id = cleanInt($_GET['student_id'] ?? 0);
$edit_id = cleanInt($_GET['edit_id'] ?? 0);

/* EDIT RECORD */
$editRecord = null;

if ($edit_id > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM students_master
        WHERE student_id = ?
        LIMIT 1
    ");
    $stmt->execute([$edit_id]);
    $editRecord = $stmt->fetch();
}

/* STUDENT LIST */
$where = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $where .= " AND (
        sm.canonical_name LIKE ?
        OR sm.phone LIKE ?
        OR sm.email LIKE ?
    )";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (in_array($filter_status, ['active', 'inactive', 'dropped','moved', 'completed'], true)) {

    if ($filter_status === 'dropped') {
        $where .= " AND sr.status = 'dropped' ";
    } elseif ($filter_status === 'moved') {

        $where .= " AND sr.status = 'moved' ";

    } elseif ($filter_status === 'active') {
        $where .= " AND sr.status = 'active' ";
    } else {
        $where .= " AND sm.status = ? ";
        $params[] = $filter_status;
    }
}

$stmt = $pdo->prepare("
    SELECT
        sm.student_id,
        sm.canonical_name,
        sm.phone,
        sm.email,
        sm.status,

        COUNT(DISTINCT sr.roster_id) AS total_rosters,

        COUNT(DISTINCT CASE WHEN sr.status = 'active' THEN sr.roster_id END) AS active_rosters,

		MAX(CASE WHEN sr.status = 'dropped' THEN 1 ELSE 0 END) AS has_dropped_roster,
		MAX(CASE WHEN sr.status = 'moved' THEN 1 ELSE 0 END) AS has_moved_roster
		

    FROM students_master sm

    LEFT JOIN student_rosters sr 
        ON sm.student_id = sr.student_id

    $where

    GROUP BY 
        sm.student_id,
        sm.canonical_name,
        sm.phone,
        sm.email,
        sm.status

    ORDER BY sm.canonical_name ASC

    LIMIT 500
");
$stmt->execute($params);
$students = $stmt->fetchAll();

/* STUDENT ASSIGNMENT SEARCH */
$assignSearchResults = [];

if ($assign_search !== '') {
    $assignTerms = preg_split('/\s+/', mb_strtolower($assign_search), -1, PREG_SPLIT_NO_EMPTY);
    $assignWhere = [];
    $assignParams = [];

    foreach ($assignTerms as $term) {
        $assignWhere[] = "LOWER(CONCAT_WS(' ', canonical_name, phone, email)) LIKE ?";
        $assignParams[] = '%' . $term . '%';
    }

    if ($assignWhere) {
        $stmt = $pdo->prepare("
            SELECT
                student_id,
                canonical_name,
                phone,
                email,
                status
            FROM students_master
            WHERE " . implode(' AND ', $assignWhere) . "
            ORDER BY canonical_name ASC
            LIMIT 25
        ");
        $stmt->execute($assignParams);
        $assignSearchResults = $stmt->fetchAll();
    }
}

/* SELECTED STUDENT PROFILE */
$selectedStudent = null;
$studentRosters = [];
$studentOutreach = [];
$studentTests = [];

if ($selected_student_id > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM students_master
        WHERE student_id = ?
        LIMIT 1
    ");
    $stmt->execute([$selected_student_id]);
    $selectedStudent = $stmt->fetch();

    if ($selectedStudent) {
        $stmt = $pdo->prepare("
            SELECT
                sr.status AS roster_status,
                r.roster_id,
                r.roster_name,
                r.season,
                r.year,
                p.program_name,

                COUNT(ar.attendance_id) AS attendance_records,

                SUM(CASE WHEN ar.present = 1 THEN 1 ELSE 0 END) AS present_records,

                ROUND(
                    CASE
                        WHEN COUNT(ar.attendance_id) > 0
                        THEN SUM(CASE WHEN ar.present = 1 THEN 1 ELSE 0 END) / COUNT(ar.attendance_id) * 100
                        ELSE 0
                    END, 1
                ) AS attendance_rate

            FROM student_rosters sr
            INNER JOIN rosters r ON sr.roster_id = r.roster_id
            INNER JOIN programs p ON r.program_id = p.program_id
            LEFT JOIN attendance_records ar
                ON ar.student_id = sr.student_id
               AND ar.roster_id = sr.roster_id

            WHERE sr.student_id = ?

            GROUP BY
                sr.status,
                r.roster_id,
                r.roster_name,
                r.season,
                r.year,
                p.program_name

            ORDER BY r.year DESC, FIELD(r.season,'Winter','Spring','Summer','Fall'), p.program_name ASC
        ");
        $stmt->execute([$selected_student_id]);
        $studentRosters = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT *
            FROM outreach
            WHERE student_id = ?
            ORDER BY outreach_date DESC, created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$selected_student_id]);
        $studentOutreach = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT *,
                COALESCE(
                    pre_post,
                    CASE 
                        WHEN UPPER(test_type) LIKE '%POST%' THEN 'POST'
                        ELSE 'PRE'
                    END
                ) AS computed_pre_post
            FROM test_results
            WHERE LOWER(TRIM(student_name)) = LOWER(TRIM(?))
            ORDER BY test_date DESC
        ");
        $stmt->execute([$selectedStudent['canonical_name']]);
        $studentTests = $stmt->fetchAll();
    }
}

$currentYear = date('Y');
$currentSeason = 'Spring';

try {
    $stmt = $pdo->prepare("
        SELECT setting_name, setting_value
        FROM system_settings
        WHERE setting_name IN ('current_year', 'current_season')
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $setting) {
        if ($setting['setting_name'] === 'current_year' && $setting['setting_value'] !== '') {
            $currentYear = (string) $setting['setting_value'];
        }
        if ($setting['setting_name'] === 'current_season' && $setting['setting_value'] !== '') {
            $currentSeason = (string) $setting['setting_value'];
        }
    }
} catch (Throwable $e) {
    $currentYear = date('Y');
    $currentSeason = 'Spring';
}

$stmt = $pdo->prepare("
    SELECT 
        r.roster_id,
        r.roster_name,
        r.season,
        r.year,
        p.program_name
    FROM rosters r
    INNER JOIN programs p ON r.program_id = p.program_id
    ORDER BY
        CASE WHEN r.year = ? AND r.season = ? THEN 0 ELSE 1 END,
        r.year DESC,
        FIELD(r.season,'Winter','Spring','Summer','Fall'),
        p.program_name ASC,
        r.roster_name ASC
");
$stmt->execute([$currentYear, $currentSeason]);
$allRosters = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Students Admin</title>

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
        <h3 class="page-title">Students Admin</h3>
        <small class="page-subtitle">Manage student records, roster assignments, and status changes.</small>
    </div>

    <?= $message ?>

    <div class="row g-3">

        <div class="col-md-4">

            <div class="card mb-3">
                <div class="card-header bg-success text-white fw-bold">
                    <?= $editRecord ? 'Edit Student' : 'Create Student' ?>
                </div>

                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="save_student" value="1">
                        <input type="hidden" name="student_id" value="<?= e($editRecord['student_id'] ?? 0) ?>">

                        <label class="form-label fw-bold">Full Name</label>
                        <input name="canonical_name"
                               class="form-control mb-2"
                               maxlength="200"
                               required
                               value="<?= e($editRecord['canonical_name'] ?? '') ?>">

                        <label class="form-label fw-bold">Phone</label>
                        <input name="phone"
                               class="form-control mb-2"
                               maxlength="30"
                               value="<?= e($editRecord['phone'] ?? '') ?>">

                        <label class="form-label fw-bold">Email</label>
                        <input type="email"
                               name="email"
                               class="form-control mb-2"
                               maxlength="150"
                               value="<?= e($editRecord['email'] ?? '') ?>">

                        <label class="form-label fw-bold">Status</label>
                        <select name="status" class="form-select mb-3">
                            <?php foreach (['active','inactive','dropped','completed'] as $st): ?>
                                <option value="<?= e($st) ?>"
                                    <?= (($editRecord['status'] ?? 'active') == $st) ? 'selected' : '' ?>>
                                    <?= e($st) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button class="btn btn-success w-100">
                            <?= $editRecord ? 'Update Student' : 'Create Student' ?>
                        </button>

                        <?php if ($editRecord): ?>
                            <a href="students_admin.php" class="btn btn-outline-secondary w-100 mt-2">Cancel Edit</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header bg-white fw-bold">
                    Search Student
                </div>

                <div class="card-body">
                    <form method="GET" class="mb-3">
                        <label class="form-label fw-bold">Search Student</label>
                        <div class="input-group">
                            <input name="assign_search"
                                   class="form-control"
                                   value="<?= e($assign_search) ?>"
                                   placeholder="Name, phone, or email">
                            <button class="btn btn-primary">Search</button>
                        </div>
                    </form>

                    <?php if ($assign_search !== ''): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th>Status</th>
                                        <th>Select</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignSearchResults as $result): ?>
                                        <tr>
                                            <td><?= e($result['canonical_name']) ?></td>
                                            <td><?= e($result['phone']) ?></td>
                                            <td><?= e($result['email']) ?></td>
                                            <td>
                                                <span class="badge <?= e(statusBadgeClass((string) ($result['status'] ?? ''))) ?>">
                                                    <?= e($result['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a class="btn btn-sm btn-outline-primary"
                                                   href="students_admin.php?student_id=<?= e($result['student_id']) ?>&assign_search=<?= urlencode($assign_search) ?>">
                                                    Select
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <?php if (count($assignSearchResults) === 0): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">No matching students found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($selectedStudent): ?>
                <div class="card">
                    <div class="card-header bg-primary text-white fw-bold">
                        Student Profile
                    </div>

                    <div class="card-body">
                        <h5><?= e($selectedStudent['canonical_name']) ?></h5>
                        <p class="mb-2">
                            <b>Phone:</b> <?= e($selectedStudent['phone']) ?><br>
                            <b>Email:</b> <?= e($selectedStudent['email']) ?><br>
                            <b>Status:</b>
                            <span class="badge <?= e(statusBadgeClass((string) ($selectedStudent['status'] ?? ''))) ?>">
                                <?= e($selectedStudent['status']) ?>
                            </span>
                        </p>

                        <a href="students_admin.php?edit_id=<?= e($selectedStudent['student_id']) ?>" class="btn btn-sm btn-warning">
                            Edit Student
                        </a>

                        <a href="outreach.php?search_name=<?= urlencode($selectedStudent['canonical_name']) ?>" class="btn btn-sm btn-dark">
                            Outreach
                        </a>

                        <a href="bestplus_view.php?search=<?= urlencode($selectedStudent['canonical_name']) ?>" class="btn btn-sm btn-primary">
                            BEST
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($selectedStudent): ?>
                <div class="card mt-3">
                    <div class="card-header bg-white fw-bold">
                        Assign Student to Roster
                    </div>

                    <div class="card-body">
                        <p class="mb-2">
                            <b>Selected Student:</b> <?= e($selectedStudent['canonical_name']) ?>
                        </p>

                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="assign_student_roster" value="1">
                            <input type="hidden" name="student_id" value="<?= e($selectedStudent['student_id']) ?>">

                            <label class="form-label fw-bold">Roster</label>
                            <select name="roster_id" class="form-select mb-2" required>
                                <option value="">Select roster...</option>
                                <?php foreach ($allRosters as $roster): ?>
                                    <option value="<?= e($roster['roster_id']) ?>">
                                        #<?= e($roster['roster_id']) ?>
                                        <?= e($roster['program_name']) ?> -
                                        <?= e($roster['roster_name']) ?>
                                        (<?= e($roster['season']) ?> <?= e($roster['year']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label class="form-label fw-bold">Status</label>
                            <select name="status" class="form-select mb-3" required>
                                <?php foreach (['active','waiting','completed','dropped','moved'] as $statusOption): ?>
                                    <option value="<?= e($statusOption) ?>"><?= e($statusOption) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <button class="btn btn-primary w-100">
                                Save Assignment
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <div class="col-md-8">

            <div class="card mb-3">
                <div class="card-header bg-white fw-bold">Search Students</div>

                <div class="card-body">
                    <form method="GET" class="row g-2">

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Search name / phone / email</label>
                            <input name="search"
                                   class="form-control"
                                   value="<?= e($search) ?>"
                                   placeholder="Search student...">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All</option>
                                <?php foreach (['active','inactive','dropped','moved','completed'] as $st): ?>
                                    <option value="<?= e($st) ?>" <?= $filter_status == $st ? 'selected' : '' ?>>
                                        <?= e($st) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3 d-flex align-items-end">
                            <button class="btn btn-primary w-100">Apply</button>
                        </div>

                        <div class="col-md-3">
                            <a href="students_admin.php" class="btn btn-outline-secondary w-100 mt-2">Reset</a>
                        </div>

                    </form>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header bg-white fw-bold d-flex justify-content-between">
                    <span>Students</span>
                    <span><?= e(count($students)) ?> records</span>
                </div>

                <div class="table-wrap">
                    <table class="table table-sm table-bordered table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Student</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th>Rosters</th>
                                <th>Attendance</th>
                                <th>Outreach</th>
                                <th>BEST</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($students as $s): ?>
                                <tr>
                                    <td><b><?= e($s['canonical_name']) ?></b></td>

                                    <td>
                                        <?= e($s['phone']) ?><br>
                                        <small><?= e($s['email']) ?></small>
                                    </td>

                                    <td>
										<?php if (intval($s['has_moved_roster'] ?? 0) === 1): ?>

											<span class="badge bg-warning text-dark">moved to another group</span>

										<?php elseif (intval($s['has_dropped_roster'] ?? 0) === 1): ?>

											<span class="badge bg-danger">dropped from roster</span>

										<?php elseif (($s['status'] ?? '') === 'active'): ?>

											<span class="badge bg-success">active</span>

										<?php elseif (($s['status'] ?? '') === 'completed'): ?>

											<span class="badge bg-info text-dark">completed</span>

										<?php elseif (($s['status'] ?? '') === 'inactive'): ?>

											<span class="badge bg-danger">inactive</span>

										<?php else: ?>

											<span class="badge bg-secondary"><?= e($s['status'] ?? '') ?></span>

										<?php endif; ?>
									</td>
                                    <td>
                                        Total: <?= e($s['total_rosters']) ?><br>
                                        <small>Active: <?= e($s['active_rosters']) ?></small>
                                    </td>

                                    <td>
										<a href="attendance_view.php?student_id=<?= e($s['student_id']) ?>"
										   class="btn btn-sm btn-outline-info">
											View
										</a>
									</td>

									<td>
										<a href="outreach.php?search_name=<?= urlencode($s['canonical_name']) ?>"
										   class="btn btn-sm btn-outline-dark">
											View
										</a>
									</td>

									<td>
										<a href="bestplus_view.php?search=<?= urlencode($s['canonical_name']) ?>"
										   class="btn btn-sm btn-outline-warning">
											View
										</a>
									</td>

                                    <td>
                                        <a class="btn btn-sm btn-info"
                                           href="students_admin.php?student_id=<?= e($s['student_id']) ?>">
                                            Profile
                                        </a>

                                        <a class="btn btn-sm btn-warning"
                                           href="students_admin.php?edit_id=<?= e($s['student_id']) ?>">
                                            Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (count($students) == 0): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted">
                                        No students found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>

                    </table>
                </div>
            </div>

            <?php if ($selectedStudent): ?>

                <div class="card mb-3">
                    <div class="card-header bg-white fw-bold">
                        Rosters History
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Program</th>
                                    <th>Roster</th>
                                    <th>Period</th>
                                    <th>Status</th>
                                    <th>Attendance</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($studentRosters as $r): ?>
                                    <tr>
                                        <td><?= e($r['program_name']) ?></td>
                                        <td><?= e($r['roster_name']) ?></td>
                                        <td><?= e($r['season']) ?> <?= e($r['year']) ?></td>
                                        <td>
                                            <span class="badge <?= e(statusBadgeClass((string) ($r['roster_status'] ?? ''))) ?>"><?= e($r['roster_status']) ?></span>
                                        </td>
                                        <td><?= e($r['attendance_rate']) ?>%</td>
                                        <td>
                                            <a class="btn btn-sm btn-info"
                                               href="attendance_view.php?roster_id=<?= e($r['roster_id']) ?>&student_id=<?= e($selectedStudent['student_id']) ?>">
                                                Attendance
                                            </a>

                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="change_roster_status" value="1">
                                                <input type="hidden" name="student_id" value="<?= e($selectedStudent['student_id']) ?>">
                                                <input type="hidden" name="roster_id" value="<?= e($r['roster_id']) ?>">
                                                <input type="hidden" name="new_status" value="<?= $r['roster_status'] === 'active' ? 'dropped' : 'active' ?>">

                                                <button class="btn btn-sm btn-outline-danger"
                                                        onclick="return confirm('Change student roster status?');">
                                                    <?= $r['roster_status'] === 'active' ? 'Drop' : 'Reactivate' ?>
                                                </button>
                                            </form>
											<form method="POST" class="mt-2">

												<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

												<input type="hidden" name="transfer_student" value="1">

												<input type="hidden" name="student_id" value="<?= e($selectedStudent['student_id']) ?>">

												<input type="hidden" name="from_roster_id" value="<?= e($r['roster_id']) ?>">

												<select name="to_roster_id"
														class="form-select form-select-sm mb-1"
														required>

													<option value="">Move to...</option>

													<?php foreach ($allRosters as $dest): ?>

														<?php if ($dest['roster_id'] != $r['roster_id']): ?>

															<option value="<?= e($dest['roster_id']) ?>">

																<?= e($dest['program_name']) ?> —

																<?= e($dest['roster_name']) ?>

																(<?= e($dest['season']) ?> <?= e($dest['year']) ?>)

															</option>

														<?php endif; ?>

													<?php endforeach; ?>

												</select>

												<input type="text"
													   name="reason"
													   class="form-control form-control-sm mb-1"
													   placeholder="Reason...">

												<button type="submit"
														class="btn btn-sm btn-outline-primary">
													Transfer
												</button>

											</form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (count($studentRosters) == 0): ?>
                                    <tr><td colspan="6" class="text-center text-muted">No roster history found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="row g-3">

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-white fw-bold">
                                Recent Outreach
                            </div>

                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Date</th>
                                            <th>Comment</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php foreach ($studentOutreach as $o): ?>
                                            <tr>
                                                <td><?= e($o['outreach_date']) ?></td>
                                                <td><?= nl2br(e($o['comment'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>

                                        <?php if (count($studentOutreach) == 0): ?>
                                            <tr><td colspan="2" class="text-center text-muted">No outreach found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-white fw-bold">
                                BEST Plus Tests
                            </div>

                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Score</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php foreach ($studentTests as $t): ?>
                                            <tr>
                                                <td><?= e($t['test_date']) ?></td>
                                                <td><?= e($t['computed_pre_post']) ?></td>
                                                <td><?= e($t['scale_score'] ?: $t['score']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>

                                        <?php if (count($studentTests) == 0): ?>
                                            <tr><td colspan="3" class="text-center text-muted">No BEST Plus tests found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

</body>
</html>
