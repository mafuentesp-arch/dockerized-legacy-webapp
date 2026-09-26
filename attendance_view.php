<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';

$selected_program_id = cleanInt($_GET['program_id'] ?? 0);
$selected_roster_id = cleanInt($_GET['roster_id'] ?? 0);
$selected_student_id = cleanInt($_GET['student_id'] ?? 0);
$date_from = cleanText($_GET['date_from'] ?? '', 10);
$date_to = cleanText($_GET['date_to'] ?? '', 10);
$selected_source = cleanText($_GET['source'] ?? 'all', 32);
$allowed_sources = ['all', 'attendance_records', 'attendance_zoom'];

if (!in_array($selected_source, $allowed_sources, true)) {
    $selected_source = 'all';
}

if ($date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = '';
}

if ($date_to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = '';
}

$records = [];
$selected_roster = null;
$selected_student = null;

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

$programsStmt = $pdo->prepare("
    SELECT DISTINCT p.program_id, p.program_name
    FROM programs p
    INNER JOIN rosters r ON p.program_id = r.program_id
    ORDER BY p.program_name ASC
");
$programsStmt->execute();
$programs = $programsStmt->fetchAll();

$rostersStmt = $pdo->prepare("
    SELECT r.roster_id, r.roster_name, r.season, r.year, p.program_name
    FROM rosters r
    LEFT JOIN programs p ON r.program_id = p.program_id
    ORDER BY r.year DESC, FIELD(r.season, 'Winter', 'Spring', 'Summer', 'Fall'), p.program_name ASC, r.roster_name ASC
");
$rostersStmt->execute();
$rosters = $rostersStmt->fetchAll();

$studentsStmt = $pdo->prepare("
    SELECT DISTINCT sm.student_id, sm.canonical_name AS student_name
    FROM attendance_records ar
    INNER JOIN students_master sm ON ar.student_id = sm.student_id
    UNION
    SELECT DISTINCT sm.student_id, sm.canonical_name AS student_name
    FROM attendance_zoom az
    INNER JOIN students_master sm ON az.student_id = sm.student_id
    ORDER BY student_name ASC
");
$studentsStmt->execute();
$students = $studentsStmt->fetchAll();

/* =========================
   LOAD ROSTER INFO
========================= */
if ($selected_roster_id > 0) {
    $stmt = $pdo->prepare("
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
    $stmt->execute([$selected_roster_id]);
    $selected_roster = $stmt->fetch();
}

/* =========================
   LOAD STUDENT INFO
========================= */
if ($selected_student_id > 0) {
    $stmt = $pdo->prepare("
        SELECT student_id, canonical_name, phone, email
        FROM students_master
        WHERE student_id = ?
        LIMIT 1
    ");
    $stmt->execute([$selected_student_id]);
    $selected_student = $stmt->fetch();
}

/* =========================
   BUILD QUERIES
========================= */
$recordWhere = "WHERE 1=1";
$recordParams = [];

if ($selected_roster_id > 0) {
    $recordWhere .= " AND v.roster_id = ?";
    $recordParams[] = $selected_roster_id;
}

if ($selected_program_id > 0) {
    $recordWhere .= " AND p.program_id = ?";
    $recordParams[] = $selected_program_id;
}

if ($selected_student_id > 0) {
    $recordWhere .= " AND v.student_id = ?";
    $recordParams[] = $selected_student_id;
}

if ($date_from !== '') {
    $recordWhere .= " AND v.class_date >= ?";
    $recordParams[] = $date_from;
}

if ($date_to !== '') {
    $recordWhere .= " AND v.class_date <= ?";
    $recordParams[] = $date_to;
}

if ($selected_source !== 'attendance_zoom') {
    $stmt = $pdo->prepare("
        SELECT
            v.*,
            'attendance_records' AS record_source
        FROM vw_attendance_full v
        LEFT JOIN rosters r ON v.roster_id = r.roster_id
        LEFT JOIN programs p ON r.program_id = p.program_id
        $recordWhere
    ");
    $stmt->execute($recordParams);
    $records = $stmt->fetchAll();
}

$zoomWhere = "WHERE 1=1";
$zoomParams = [];

if ($selected_roster_id > 0) {
    $zoomWhere .= "
        AND az.student_id IS NOT NULL
        AND EXISTS (
            SELECT 1
            FROM student_rosters sr
            WHERE sr.student_id = az.student_id
              AND sr.roster_id = ?
        )
    ";
    $zoomParams[] = $selected_roster_id;
}

if ($selected_program_id > 0) {
    $zoomWhere .= "
        AND az.student_id IS NOT NULL
        AND EXISTS (
            SELECT 1
            FROM student_rosters sr
            INNER JOIN rosters r_filter ON sr.roster_id = r_filter.roster_id
            WHERE sr.student_id = az.student_id
              AND r_filter.program_id = ?
        )
    ";
    $zoomParams[] = $selected_program_id;
}

if ($selected_student_id > 0) {
    $zoomWhere .= " AND az.student_id = ?";
    $zoomParams[] = $selected_student_id;
}

if ($date_from !== '') {
    $zoomWhere .= " AND az.class_date >= ?";
    $zoomParams[] = $date_from;
}

if ($date_to !== '') {
    $zoomWhere .= " AND az.class_date <= ?";
    $zoomParams[] = $date_to;
}

if ($selected_source === 'all') {
    $zoomWhere .= "
        AND NOT EXISTS (
            SELECT 1
            FROM attendance_records ar_dedupe
            WHERE ar_dedupe.student_id = az.student_id
              AND ar_dedupe.class_date = az.class_date
        )
    ";
}

if ($selected_source !== 'attendance_records') {
    $stmt = $pdo->prepare("
        SELECT
            az.attendance_id,
            az.student_id,
            NULL AS roster_id,
            '' AS roster_name,
            '' AS program_name,
            az.class_date,
            COALESCE(sm.canonical_name, az.raw_name) AS canonical_name,
            az.raw_name,
            az.email,
            az.email AS student_email,
            1 AS present,
            az.match_status AS status,
            az.first_join,
            az.last_leave,
            az.total_connections,
            az.total_minutes,
            'zoom' AS attendance_source,
            az.source_file,
            'attendance_zoom' AS record_source
        FROM attendance_zoom az
        LEFT JOIN students_master sm ON az.student_id = sm.student_id
        $zoomWhere
    ");
    $stmt->execute($zoomParams);
    $records = array_merge($records, $stmt->fetchAll());
}

usort($records, static function (array $a, array $b): int {
    return [
        (string) ($a['program_name'] ?? ''),
        (string) ($a['roster_name'] ?? ''),
        (string) ($a['class_date'] ?? ''),
        (string) ($a['canonical_name'] ?? ''),
        (string) ($a['record_source'] ?? ''),
    ] <=> [
        (string) ($b['program_name'] ?? ''),
        (string) ($b['roster_name'] ?? ''),
        (string) ($b['class_date'] ?? ''),
        (string) ($b['canonical_name'] ?? ''),
        (string) ($b['record_source'] ?? ''),
    ];
});

/* =========================
   SUMMARY
========================= */
$total_records = count($records);
$total_present = 0;
$total_absent = 0;
$total_minutes = 0;

foreach ($records as $r) {
    if (intval($r['present']) === 1) {
        $total_present++;
    } else {
        $total_absent++;
    }

    $total_minutes += floatval($r['total_minutes'] ?? 0);
}

$attendance_rate = $total_records > 0
    ? round(($total_present / $total_records) * 100, 1)
    : 0;
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Attendance View</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body { background:#f4f6f9; }
        .card {
            border:0;
            border-radius:16px;
            box-shadow:0 8px 22px rgba(0,0,0,.06);
        }
        .kpi-value {
            font-size:2rem;
            font-weight:800;
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
        <h3 class="page-title">Attendance View</h3>
        <small class="page-subtitle">Review attendance records by roster or student.</small>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label fw-bold">Program</label>
                    <select name="program_id" class="form-select">
                        <option value="0">All programs</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?= e($program['program_id']) ?>" <?= $selected_program_id === (int) $program['program_id'] ? 'selected' : '' ?>>
                                <?= e($program['program_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-bold">Roster</label>
                    <select name="roster_id" class="form-select">
                        <option value="0">All rosters</option>
                        <?php foreach ($rosters as $roster): ?>
                            <option value="<?= e($roster['roster_id']) ?>" <?= $selected_roster_id === (int) $roster['roster_id'] ? 'selected' : '' ?>>
                                <?= e($roster['program_name']) ?> -
                                <?= e($roster['roster_name']) ?>
                                (<?= e($roster['season']) ?> <?= e($roster['year']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-bold">Source</label>
                    <select name="source" class="form-select">
                        <option value="all" <?= $selected_source === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="attendance_records" <?= $selected_source === 'attendance_records' ? 'selected' : '' ?>>attendance_records</option>
                        <option value="attendance_zoom" <?= $selected_source === 'attendance_zoom' ? 'selected' : '' ?>>attendance_zoom</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-bold">Student</label>
                    <select name="student_id" class="form-select">
                        <option value="0">All students</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?= e($student['student_id']) ?>" <?= $selected_student_id === (int) $student['student_id'] ? 'selected' : '' ?>>
                                <?= e($student['student_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-bold">Date from</label>
                    <input type="date" name="date_from" class="form-control" value="<?= e($date_from) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-bold">Date to</label>
                    <input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>">
                </div>

                <div class="col-md-2 d-grid gap-2">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="attendance_view.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selected_roster): ?>
        <div class="alert alert-info">
            <b>Roster:</b>
            <?= htmlspecialchars($selected_roster['program_name'] ?? '') ?>
            —
            <?= htmlspecialchars($selected_roster['roster_name'] ?? '') ?>
            (
            <?= htmlspecialchars($selected_roster['season'] ?? '') ?>
            <?= htmlspecialchars($selected_roster['year'] ?? '') ?>
            )
        </div>
    <?php endif; ?>

    <?php if ($selected_student): ?>
        <div class="alert alert-warning">
            <b>Student:</b>
            <?= htmlspecialchars($selected_student['canonical_name']) ?>
            |
            <b>Phone:</b> <?= htmlspecialchars($selected_student['phone'] ?? '') ?>
            |
            <b>Email:</b> <?= htmlspecialchars($selected_student['email'] ?? '') ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">

        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Total Records</small>
                    <div class="kpi-value"><?= htmlspecialchars($total_records) ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Present</small>
                    <div class="kpi-value text-success"><?= htmlspecialchars($total_present) ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Absent</small>
                    <div class="kpi-value text-danger"><?= htmlspecialchars($total_absent) ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Attendance Rate</small>
                    <div class="kpi-value text-primary"><?= htmlspecialchars($attendance_rate) ?>%</div>
                    <?php if ($total_minutes > 0): ?>
                        <small>Total minutes: <?= htmlspecialchars($total_minutes) ?></small>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

    <div class="card">
        <div class="card-header bg-white fw-bold d-flex justify-content-between">
            <span>Attendance Records</span>
            <span><?= htmlspecialchars($total_records) ?> records</span>
        </div>

        <div class="table-wrap">
            <table class="table table-bordered table-striped table-sm align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Date</th>
                        <th>Program</th>
                        <th>Roster</th>
                        <th>Student Name</th>
                        <th>Raw Name</th>
                        <th>Email</th>
                        <th>Present</th>
                        <th>Status</th>
                        <th>First Join</th>
                        <th>Last Leave</th>
                        <th>Connections</th>
                        <th>Total Minutes</th>
                        <th>Source</th>
                        <th>File</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($records as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['class_date'] ?? '') ?></td>

                            <td><?= htmlspecialchars($r['program_name'] ?? '') ?></td>

                            <td><?= htmlspecialchars($r['roster_name'] ?? '') ?></td>

                            <td>
                                <b><?= htmlspecialchars($r['canonical_name'] ?? '') ?></b>
                            </td>

                            <td><?= htmlspecialchars($r['raw_name'] ?? '') ?></td>

                            <td><?= htmlspecialchars($r['email'] ?? $r['student_email'] ?? '') ?></td>

                            <td>
                                <?php if (intval($r['present']) === 1): ?>
                                    <span class="badge bg-success">Present</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Absent</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge <?= e(statusBadgeClass((string) ($r['status'] ?? ''))) ?>">
                                    <?= e($r['status'] ?? '') ?>
                                </span>
                            </td>

                            <td><?= htmlspecialchars($r['first_join'] ?? '') ?></td>

                            <td><?= htmlspecialchars($r['last_leave'] ?? '') ?></td>

                            <td><?= htmlspecialchars($r['total_connections'] ?? '') ?></td>

                            <td><?= htmlspecialchars($r['total_minutes'] ?? '') ?></td>

                            <td>
                                <?php if (($r['record_source'] ?? '') === 'attendance_zoom'): ?>
                                    <span class="badge bg-primary">Zoom</span>
                                <?php elseif (($r['record_source'] ?? '') === 'attendance_records'): ?>
                                    <span class="badge bg-dark">Records</span>
                                <?php elseif (($r['attendance_source'] ?? '') === 'sqlite'): ?>
                                    <span class="badge bg-secondary">Class</span>
                                <?php else: ?>
                                    <span class="badge bg-dark"><?= htmlspecialchars($r['attendance_source'] ?? '') ?></span>
                                <?php endif; ?>
                            </td>

                            <td><?= htmlspecialchars($r['source_file'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (count($records) == 0): ?>
                        <tr>
                            <td colspan="14" class="text-center text-muted">
                                No attendance records found.
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
