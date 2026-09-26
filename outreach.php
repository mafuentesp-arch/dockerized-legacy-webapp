<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/audit.php';

$message = "";

/* =========================
   HELPERS
========================= */
function getSetting($pdo, $name, $default = '') {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_name = ? LIMIT 1");
    $stmt->execute([$name]);
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : $default;
}

function cleanPhoneForWhatsApp($phone) {
    $phone = preg_replace('/\D+/', '', $phone ?? '');
    if (strlen($phone) == 10) {
        $phone = '1' . $phone;
    }
    return $phone;
}

function applyTemplate($template, $data) {
    $replacements = [
        '{name}' => $data['name'] ?? '',
        '{phone}' => $data['phone'] ?? '',
        '{email}' => $data['email'] ?? '',
        '{roster}' => $data['roster'] ?? '',
        '{program}' => $data['program'] ?? '',
        '{attendance_percent}' => $data['attendance_percent'] ?? ''
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

$currentYear = getSetting($pdo, 'current_year', date('Y'));
$currentSeason = getSetting($pdo, 'current_season', 'Spring');

/* =========================
   SELECTED VALUES
========================= */
$selectedAddRoster = intval($_GET['add_roster_id'] ?? $_POST['add_roster_id'] ?? 0);
$edit_id = intval($_GET['edit_id'] ?? 0);

/* =========================
   DELETE
========================= */
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);

    if ($delete_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM outreach WHERE outreach_id = ?");
        $stmt->execute([$delete_id]);
        logActivity('DELETE', 'outreach', $delete_id, 'Deleted outreach record #' . $delete_id);
        header("Location: outreach.php?deleted=1");
        exit;
    }
}

if (isset($_GET['deleted'])) {
    $message = "<div class='alert alert-success'>Outreach record deleted successfully.</div>";
}

/* =========================
   TEMPLATES
========================= */
$templates = $pdo->query("
    SELECT template_id, template_name, template_type, message_body
    FROM message_templates
    WHERE status = 'active'
    ORDER BY template_id ASC
")->fetchAll();

/* =========================
   CURRENT ROSTERS FOR ADD
========================= */
$currentRostersStmt = $pdo->prepare("
    SELECT 
        r.roster_id,
        r.roster_name,
        r.season,
        r.year,
        p.program_name
    FROM rosters r
    INNER JOIN programs p ON r.program_id = p.program_id
    WHERE r.year = ?
      AND r.season = ?
      AND r.status = 'active'
    ORDER BY p.program_name ASC, r.roster_name ASC
");
$currentRostersStmt->execute([$currentYear, $currentSeason]);
$currentRosters = $currentRostersStmt->fetchAll();

/* =========================
   ALL ROSTERS FOR FILTERS
========================= */
$allRosters = $pdo->query("
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
   STUDENTS BY CURRENT ROSTER
========================= */
$addStudents = [];

if ($selectedAddRoster > 0) {
    $studentsStmt = $pdo->prepare("
        SELECT 
            sm.student_id,
            sm.canonical_name,
            sm.phone,
            sm.email,
            r.roster_name,
            p.program_name,
            ROUND(
                CASE 
                    WHEN COUNT(v.attendance_id) > 0
                    THEN SUM(CASE WHEN v.present = 1 THEN 1 ELSE 0 END) / COUNT(v.attendance_id) * 100
                    ELSE 0
                END, 1
            ) AS attendance_percent
        FROM student_rosters sr
        INNER JOIN students_master sm ON sr.student_id = sm.student_id
        INNER JOIN rosters r ON sr.roster_id = r.roster_id
        INNER JOIN programs p ON r.program_id = p.program_id
        LEFT JOIN vw_attendance_full v 
            ON v.student_id = sm.student_id
           AND v.roster_id = r.roster_id
        WHERE sr.roster_id = ?
          AND sr.status = 'active'
          AND r.year = ?
          AND r.season = ?
        GROUP BY sm.student_id, sm.canonical_name, sm.phone, sm.email, r.roster_name, p.program_name
        ORDER BY sm.canonical_name ASC
    ");
    $studentsStmt->execute([$selectedAddRoster, $currentYear, $currentSeason]);
    $addStudents = $studentsStmt->fetchAll();
}

/* =========================
   LOAD EDIT RECORD
========================= */
$editRecord = null;

if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM outreach WHERE outreach_id = ? LIMIT 1");
    $stmt->execute([$edit_id]);
    $editRecord = $stmt->fetch();

    if ($editRecord) {
        $selectedAddRoster = intval($editRecord['roster_id']);

        $studentsStmt = $pdo->prepare("
            SELECT 
                sm.student_id,
                sm.canonical_name,
                sm.phone,
                sm.email,
                r.roster_name,
                p.program_name,
                ROUND(
                    CASE 
                        WHEN COUNT(v.attendance_id) > 0
                        THEN SUM(CASE WHEN v.present = 1 THEN 1 ELSE 0 END) / COUNT(v.attendance_id) * 100
                        ELSE 0
                    END, 1
                ) AS attendance_percent
            FROM student_rosters sr
            INNER JOIN students_master sm ON sr.student_id = sm.student_id
            INNER JOIN rosters r ON sr.roster_id = r.roster_id
            INNER JOIN programs p ON r.program_id = p.program_id
            LEFT JOIN vw_attendance_full v 
                ON v.student_id = sm.student_id
               AND v.roster_id = r.roster_id
            WHERE sr.roster_id = ?
            GROUP BY sm.student_id, sm.canonical_name, sm.phone, sm.email, r.roster_name, p.program_name
            ORDER BY sm.canonical_name ASC
        ");
        $studentsStmt->execute([$selectedAddRoster]);
        $addStudents = $studentsStmt->fetchAll();
    }
}

/* =========================
   SAVE / UPDATE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_outreach'])) {

    $outreach_id = intval($_POST['outreach_id'] ?? 0);
    $student_id = intval($_POST['student_id'] ?? 0);
    $roster_id = intval($_POST['add_roster_id'] ?? 0);
    $outreach_date = trim($_POST['outreach_date'] ?? '');
    $comment = trim($_POST['comment'] ?? '');
    $time_spent = floatval($_POST['time_spent'] ?? 0);
    $template_id = intval($_POST['template_id'] ?? 0);

    if ($outreach_date === '') {
        $outreach_date = date('Y-m-d');
    }

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

        logActivity('UPDATE', 'outreach', $outreach_id, 'Updated outreach record #' . $outreach_id);

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

        $newOutreachId = (int) $pdo->lastInsertId();
        logActivity('CREATE', 'outreach', $newOutreachId, 'Created outreach record for ' . $full_name);

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
   CONSULTATION FILTERS
========================= */
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$filter_roster_id = intval($_GET['filter_roster_id'] ?? 0);
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

if ($search_name !== '') {
    $where .= " AND (o.full_name LIKE ? OR o.phone LIKE ? OR o.email LIKE ?)";
    $params[] = "%$search_name%";
    $params[] = "%$search_name%";
    $params[] = "%$search_name%";
}

/* =========================
   LOAD RECORDS
========================= */
$stmt = $pdo->prepare("
    SELECT 
        o.*,
        sm.canonical_name,
        r.roster_name AS linked_roster_name,
        p.program_name AS linked_program_name,
        att.attendance_percent
    FROM outreach o
    LEFT JOIN students_master sm ON o.student_id = sm.student_id
    LEFT JOIN rosters r ON o.roster_id = r.roster_id
    LEFT JOIN programs p ON r.program_id = p.program_id
    LEFT JOIN (
        SELECT
            student_id,
            roster_id,
            ROUND(
                CASE
                    WHEN COUNT(attendance_id) > 0
                    THEN SUM(CASE WHEN present = 1 THEN 1 ELSE 0 END) / COUNT(attendance_id) * 100
                    ELSE 0
                END, 1
            ) AS attendance_percent
        FROM vw_attendance_full
        GROUP BY student_id, roster_id
    ) att ON att.student_id = o.student_id
          AND att.roster_id = o.roster_id
    $where
    ORDER BY o.outreach_date DESC, o.created_at DESC
    LIMIT 500
");
$stmt->execute($params);
$records = $stmt->fetchAll();

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
        textarea { resize: vertical; }
        .token-box {
            font-size: .8rem;
            background: #eef2ff;
            padding: 8px;
            border-radius: 10px;
        }
    </style>
</head>

<body>

<div class="container-fluid app-page">

    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">Outreach Management</h3>
        <small class="page-subtitle">Create outreach records and review student contact history.</small>
    </div>

    <?= $message ?>

    <div class="alert alert-info">
        New outreach uses the current period. Consultation can search any historical date.
    </div>

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

                    <?php if (!$editRecord): ?>
                        <form method="GET" class="mb-3">
                            <label class="form-label fw-bold">Current Roster</label>
                            <select name="add_roster_id" class="form-select" onchange="this.form.submit()" required>
                                <option value="">Select current roster...</option>

                                <?php foreach ($currentRosters as $r): ?>
                                    <option value="<?= htmlspecialchars($r['roster_id']) ?>"
                                        <?= $selectedAddRoster == $r['roster_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($r['program_name']) ?> —
                                        <?= htmlspecialchars($r['roster_name']) ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </form>
                    <?php endif; ?>

                    <form method="POST">

                        <input type="hidden" name="save_outreach" value="1">
                        <input type="hidden" name="outreach_id" value="<?= htmlspecialchars($editRecord['outreach_id'] ?? 0) ?>">
                        <input type="hidden" name="add_roster_id" value="<?= htmlspecialchars($selectedAddRoster) ?>">

                        <div class="mb-3">
                            <label class="form-label fw-bold">Student</label>
                            <select id="student_id" name="student_id" class="form-select" required <?= $selectedAddRoster <= 0 ? 'disabled' : '' ?>>
                                <option value="">Select student from selected roster...</option>

                                <?php foreach ($addStudents as $s): ?>
                                    <option value="<?= htmlspecialchars($s['student_id']) ?>"
                                        data-name="<?= htmlspecialchars($s['canonical_name']) ?>"
                                        data-phone="<?= htmlspecialchars($s['phone']) ?>"
                                        data-email="<?= htmlspecialchars($s['email']) ?>"
                                        data-roster="<?= htmlspecialchars($s['roster_name']) ?>"
                                        data-program="<?= htmlspecialchars($s['program_name']) ?>"
                                        data-attendance="<?= htmlspecialchars($s['attendance_percent']) ?>"
                                        <?= (($editRecord['student_id'] ?? '') == $s['student_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['canonical_name']) ?>
                                        <?php if (!empty($s['phone'])): ?>
                                            - <?= htmlspecialchars($s['phone']) ?>
                                        <?php endif; ?>
                                        - Attendance: <?= htmlspecialchars($s['attendance_percent']) ?>%
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Message Type</label>
                            <select id="template_id" name="template_id" class="form-select">
                                <option value="">Select message type...</option>

                                <?php foreach ($templates as $t): ?>
                                    <option value="<?= htmlspecialchars($t['template_id']) ?>"
                                        data-body="<?= htmlspecialchars($t['message_body']) ?>">
                                        <?= htmlspecialchars($t['template_name']) ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="token-box mb-3">
                            Available parameters:
                            <b>{name}</b>,
                            <b>{phone}</b>,
                            <b>{email}</b>,
                            <b>{roster}</b>,
                            <b>{program}</b>,
                            <b>{attendance_percent}</b>
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
                            <label class="form-label fw-bold">Message / Comment</label>
                            <textarea id="comment"
                                      name="comment"
                                      class="form-control"
                                      rows="5"
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

                        <div class="d-flex gap-2 mb-3">
                            <button type="button" class="btn btn-success w-50" onclick="openWhatsApp()">
                                WhatsApp
                            </button>

                            <button type="button" class="btn btn-info w-50" onclick="openTelegram()">
                                Telegram
                            </button>
                        </div>

                        <button class="btn btn-primary w-100" <?= $selectedAddRoster <= 0 ? 'disabled' : '' ?>>
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
                    Consultation Filters
                </div>

                <div class="card-body">
                    <form method="GET" class="row g-2">

                        <div class="col-md-3">
                            <label class="form-label small fw-bold">From</label>
                            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-bold">To</label>
                            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Roster</label>
                            <select name="filter_roster_id" class="form-select">
                                <option value="0">All rosters</option>

                                <?php foreach ($allRosters as $r): ?>
                                    <option value="<?= htmlspecialchars($r['roster_id']) ?>"
                                        <?= $filter_roster_id == $r['roster_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($r['program_name']) ?> —
                                        <?= htmlspecialchars($r['roster_name']) ?>
                                        (<?= htmlspecialchars($r['season'] ?? '') ?> <?= htmlspecialchars($r['year'] ?? '') ?>)
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label small fw-bold">Search student / phone / email</label>
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
                                <?php
                                    $rowPhone = cleanPhoneForWhatsApp($r['phone'] ?? '');
                                    $rowMessageData = [
                                        'name' => $r['canonical_name'] ?? $r['full_name'] ?? '',
                                        'phone' => $r['phone'] ?? '',
                                        'email' => $r['email'] ?? '',
                                        'roster' => $r['linked_roster_name'] ?? $r['roster_name'] ?? '',
                                        'program' => $r['linked_program_name'] ?? $r['program'] ?? '',
                                        'attendance_percent' => $r['attendance_percent'] ?? ''
                                    ];
                                    $rowMsg = rawurlencode(applyTemplate($r['comment'] ?? '', $rowMessageData));
                                    $waUrl = $rowPhone ? "https://wa.me/" . $rowPhone . "?text=" . $rowMsg : "#";
                                    $tgUrl = "https://t.me/share/url?url=&text=" . $rowMsg;
                                ?>
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
                                        <?php if ($rowPhone): ?>
                                            <a class="btn btn-sm btn-success" target="_blank" href="<?= htmlspecialchars($waUrl) ?>">WA</a>
                                        <?php endif; ?>

                                        <a class="btn btn-sm btn-info" target="_blank" href="<?= htmlspecialchars($tgUrl) ?>">TG</a>

                                        <a class="btn btn-sm btn-warning" href="outreach.php?edit_id=<?= htmlspecialchars($r['outreach_id']) ?>">
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

<script>
function getSelectedStudentData() {
    const studentSelect = document.getElementById('student_id');
    const option = studentSelect.options[studentSelect.selectedIndex];

    if (!option || !option.value) {
        return null;
    }

    return {
        name: option.dataset.name || '',
        phone: option.dataset.phone || '',
        email: option.dataset.email || '',
        roster: option.dataset.roster || '',
        program: option.dataset.program || '',
        attendance_percent: option.dataset.attendance || ''
    };
}

function resolveMessageTemplate(template, data) {
    const values = {
        name: data?.name || '',
        phone: data?.phone || '',
        email: data?.email || '',
        roster: data?.roster || '',
        program: data?.program || '',
        attendance_percent: data?.attendance_percent || ''
    };

    return (template || '').replace(/\{(name|phone|email|roster|program|attendance_percent)\}/g, (match, key) => {
        return values[key] || '';
    });
}

function applyTemplateToMessage() {
    const templateSelect = document.getElementById('template_id');
    const option = templateSelect.options[templateSelect.selectedIndex];
    const student = getSelectedStudentData();

    if (!option || !student) {
        return;
    }

    document.getElementById('comment').value = resolveMessageTemplate(option.dataset.body || '', student);
}

function cleanPhone(phone) {
    phone = (phone || '').replace(/\D+/g, '');

    if (phone.length === 10) {
        phone = '1' + phone;
    }

    return phone;
}

function openWhatsApp() {
    const student = getSelectedStudentData();

    if (!student) {
        alert('Please select a student first.');
        return;
    }

    const phone = cleanPhone(student.phone);
    const msg = resolveMessageTemplate(document.getElementById('comment').value, student);

    if (!phone) {
        alert('This student does not have a phone number.');
        return;
    }

    window.open('https://wa.me/' + phone + '?text=' + encodeURIComponent(msg), '_blank');
}

function openTelegram() {
    const student = getSelectedStudentData();

    if (!student) {
        alert('Please select a student first.');
        return;
    }

    const msg = resolveMessageTemplate(document.getElementById('comment').value, student);
    window.open('https://t.me/share/url?url=&text=' + encodeURIComponent(msg), '_blank');
}

document.getElementById('template_id')?.addEventListener('change', applyTemplateToMessage);
document.getElementById('student_id')?.addEventListener('change', applyTemplateToMessage);
</script>

</body>
</html>
