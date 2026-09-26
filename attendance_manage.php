<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['admin', 'staff']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';

if (file_exists(__DIR__ . '/includes/audit.php')) {
    require_once __DIR__ . '/includes/audit.php';
}

$allowedRosterStatuses = ['active', 'dropped', 'moved', 'completed', 'waiting'];
$message = '';
$summary = null;

$selectedRosterId = cleanInt($_POST['roster_id'] ?? $_GET['roster_id'] ?? 0);
$selectedDate = cleanText($_POST['class_date'] ?? $_GET['class_date'] ?? '', 20);
$search = cleanText($_GET['search'] ?? '', 120);
$statusFilter = cleanText($_GET['status'] ?? '', 30);

if (!in_array($statusFilter, $allowedRosterStatuses, true)) {
    $statusFilter = '';
}

function getAttendanceSetting(PDO $pdo, string $name, string $default): string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_name = ? LIMIT 1");
    $stmt->execute([$name]);
    $value = $stmt->fetchColumn();

    return $value !== false ? (string) $value : $default;
}

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

function normalizeAttendanceStatusBucket(string $status): string
{
    $normalized = strtolower(trim($status));

    if (in_array($normalized, ['active', 'activo'], true)) {
        return 'active';
    }

    if (in_array($normalized, ['dropped', 'retired', 'retirado', 'inactive', 'inactivo', 'withdrawn'], true)) {
        return 'dropped';
    }

    if (in_array($normalized, ['moved', 'transfer', 'transferred'], true)) {
        return 'moved';
    }

    if (in_array($normalized, ['promoted', 'completed', 'completado'], true)) {
        return 'promoted';
    }

    return 'unknown';
}

function getAttendanceSummary(PDO $pdo, int $rosterId, string $classDate, string $search = '', string $statusFilter = ''): array
{
    $summary = [
        'present' => 0,
        'absent' => 0,
        'dropped' => 0,
        'moved' => 0,
        'promoted' => 0,
        'active' => 0,
        'total' => 0,
    ];

    if ($rosterId <= 0 || $classDate === '') {
        return $summary;
    }

    $where = "WHERE sr.roster_id = ?";
    $params = [$rosterId];

    if ($search !== '') {
        $where .= "
          AND (
              sm.canonical_name LIKE ?
              OR sm.phone LIKE ?
              OR sm.email LIKE ?
          )
        ";
        $likeSearch = '%' . $search . '%';
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
    }

    if ($statusFilter !== '') {
        $where .= " AND sr.status = ?";
        $params[] = $statusFilter;
    }

    $stmt = $pdo->prepare("
        SELECT
            sr.status AS roster_status,
            ar.present
        FROM student_rosters sr
        INNER JOIN students_master sm ON sr.student_id = sm.student_id
        LEFT JOIN attendance_records ar
            ON ar.student_id = sm.student_id
           AND ar.roster_id = ?
           AND ar.class_date = ?
        $where
    ");
    $stmt->execute([$rosterId, $classDate, ...$params]);

    foreach ($stmt->fetchAll() as $row) {
        $summary['total']++;

        if ($row['present'] !== null) {
            if ((int) $row['present'] === 1) {
                $summary['present']++;
            } else {
                $summary['absent']++;
            }
        }

        $bucket = normalizeAttendanceStatusBucket((string) ($row['roster_status'] ?? ''));
        if (isset($summary[$bucket])) {
            $summary[$bucket]++;
        }
    }

    return $summary;
}

function loadRegularRoster(PDO $pdo, int $rosterId, string $currentYear, string $currentSeason): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            roster_id,
            MAX(roster_name) AS roster_name,
            MAX(season) AS season,
            MAX(year) AS year,
            MAX(program_name) AS program_name
        FROM (
            SELECT
                r.roster_id,
                r.roster_name,
                r.season,
                r.year,
                COALESCE(p.program_name, 'Unassigned') AS program_name
            FROM rosters r
            LEFT JOIN programs p ON r.program_id = p.program_id
            WHERE r.roster_id = ?
              AND r.year = ?
              AND r.season = ?

            UNION ALL

            SELECT
                ac.roster_id,
                ac.roster_name,
                ? AS season,
                ? AS year,
                CASE
                    WHEN UPPER(ac.roster_name) LIKE '%YONKERS%' THEN 'Yonkers'
                    WHEN UPPER(ac.roster_name) LIKE '%APPLEBAUM%PM%' THEN 'Applebaum PM'
                    WHEN UPPER(ac.roster_name) LIKE '%APPLEBAUM%AM%' THEN 'Applebaum AM'
                    ELSE 'Unassigned'
                END AS program_name
            FROM attendance_class ac
            LEFT JOIN rosters r ON ac.roster_id = r.roster_id
            WHERE r.roster_id IS NULL
              AND ac.roster_id = ?
              AND UPPER(ac.roster_name) LIKE CONCAT('%', UPPER(?), '%')
              AND ac.roster_name LIKE CONCAT('%', ?, '%')
        ) roster_sources
        GROUP BY roster_id
        LIMIT 1
    ");
    $stmt->execute([$rosterId, $currentYear, $currentSeason, $currentSeason, $currentYear, $rosterId, $currentSeason, $currentYear]);
    $roster = $stmt->fetch();

    return ($roster && !empty($roster['roster_id'])) ? $roster : null;
}

function loadCurrentPeriodRosters(PDO $pdo, string $currentYear, string $currentSeason): array
{
    $stmt = $pdo->prepare("
        SELECT
            roster_id,
            MAX(roster_name) AS roster_name,
            MAX(season) AS season,
            MAX(year) AS year,
            MAX(program_name) AS program_name
        FROM (
            SELECT
                r.roster_id,
                r.roster_name,
                r.season,
                r.year,
                COALESCE(p.program_name, 'Unassigned') AS program_name
            FROM rosters r
            LEFT JOIN programs p ON r.program_id = p.program_id
            WHERE r.year = ?
              AND r.season = ?
              AND r.status = 'active'

            UNION ALL

            SELECT
                ac.roster_id,
                ac.roster_name,
                ? AS season,
                ? AS year,
                CASE
                    WHEN UPPER(ac.roster_name) LIKE '%YONKERS%' THEN 'Yonkers'
                    WHEN UPPER(ac.roster_name) LIKE '%APPLEBAUM%PM%' THEN 'Applebaum PM'
                    WHEN UPPER(ac.roster_name) LIKE '%APPLEBAUM%AM%' THEN 'Applebaum AM'
                    ELSE 'Unassigned'
                END AS program_name
            FROM attendance_class ac
            LEFT JOIN rosters r ON ac.roster_id = r.roster_id
            WHERE r.roster_id IS NULL
              AND ac.roster_id IS NOT NULL
              AND ac.roster_id > 0
              AND UPPER(ac.roster_name) LIKE CONCAT('%', UPPER(?), '%')
              AND ac.roster_name LIKE CONCAT('%', ?, '%')
        ) roster_sources
        GROUP BY roster_id
        ORDER BY
            year DESC,
            FIELD(season, 'Winter', 'Spring', 'Summer', 'Fall'),
            program_name ASC,
            roster_name ASC
    ");
    $stmt->execute([$currentYear, $currentSeason, $currentSeason, $currentYear, $currentSeason, $currentYear]);

    return $stmt->fetchAll();
}

$currentYear = getAttendanceSetting($pdo, 'current_year', date('Y'));
$currentSeason = getAttendanceSetting($pdo, 'current_season', 'Spring');

$rosters = loadCurrentPeriodRosters($pdo, $currentYear, $currentSeason);

$programStatsStmt = $pdo->prepare("
    SELECT
        COALESCE(p.program_name, 'Unassigned') AS program_name,
        COUNT(DISTINCT sr.student_id) AS total_students,
        COUNT(ar.attendance_id) AS total_attendance_records,
        SUM(CASE WHEN ar.present = 1 THEN 1 ELSE 0 END) AS present_records,
        SUM(CASE WHEN ar.attendance_id IS NOT NULL AND ar.present = 0 THEN 1 ELSE 0 END) AS absent_records,
        COUNT(DISTINCT CASE WHEN sr.status = 'dropped' THEN sr.student_id END) AS dropped_students,
        COUNT(DISTINCT CASE WHEN sr.status = 'active' THEN sr.student_id END) AS active_students,
        ROUND(
            CASE
                WHEN COUNT(ar.attendance_id) > 0
                THEN SUM(CASE WHEN ar.present = 1 THEN 1 ELSE 0 END) / COUNT(ar.attendance_id) * 100
                ELSE 0
            END, 1
        ) AS attendance_percentage,
        ROUND(
            CASE
                WHEN COUNT(DISTINCT sr.student_id) > 0
                THEN COUNT(DISTINCT CASE WHEN sr.status = 'active' THEN sr.student_id END) / COUNT(DISTINCT sr.student_id) * 100
                ELSE 0
            END, 1
        ) AS retention_percentage,
        ROUND(
            CASE
                WHEN COUNT(DISTINCT sr.student_id) > 0
                THEN COUNT(DISTINCT CASE WHEN sr.status = 'dropped' THEN sr.student_id END) / COUNT(DISTINCT sr.student_id) * 100
                ELSE 0
            END, 1
        ) AS dropped_percentage
    FROM rosters r
    LEFT JOIN programs p ON r.program_id = p.program_id
    LEFT JOIN student_rosters sr ON r.roster_id = sr.roster_id
    LEFT JOIN attendance_records ar
        ON ar.student_id = sr.student_id
       AND ar.roster_id = sr.roster_id
    WHERE r.year = ?
      AND r.season = ?
    GROUP BY COALESCE(p.program_name, 'Unassigned')
    ORDER BY program_name ASC
");
$programStatsStmt->execute([$currentYear, $currentSeason]);
$programStats = $programStatsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['save_attendance'] ?? '') === '1') {
    verify_csrf();

    try {
        if ($selectedRosterId <= 0) {
            throw new RuntimeException('Please select a roster.');
        }

        if ($selectedDate === '' || !DateTime::createFromFormat('Y-m-d', $selectedDate)) {
            throw new RuntimeException('Please select a valid class date.');
        }

        $selectedRoster = loadRegularRoster($pdo, $selectedRosterId, $currentYear, $currentSeason);
        if (!$selectedRoster) {
            throw new RuntimeException('Selected roster is not in the current period or is not available for manual attendance.');
        }

        $attendance = $_POST['attendance'] ?? [];
        if (!is_array($attendance)) {
            throw new RuntimeException('Invalid attendance submission.');
        }

        $studentsStmt = $pdo->prepare("
            SELECT
                sm.student_id,
                sm.canonical_name,
                sm.email,
                sr.status AS roster_status
            FROM student_rosters sr
            INNER JOIN students_master sm ON sr.student_id = sm.student_id
            WHERE sr.roster_id = ?
            ORDER BY sm.canonical_name ASC
        ");
        $studentsStmt->execute([$selectedRosterId]);
        $rosterStudents = $studentsStmt->fetchAll();

        $studentMap = [];
        foreach ($rosterStudents as $student) {
            $studentMap[(int) $student['student_id']] = $student;
        }

        $findStmt = $pdo->prepare("
            SELECT attendance_id
            FROM attendance_records
            WHERE student_id = ?
              AND roster_id = ?
              AND class_date = ?
            LIMIT 1
        ");
        $updateStmt = $pdo->prepare("
            UPDATE attendance_records
            SET present = ?,
                attendance_source = 'manual',
                raw_name = ?,
                email = ?,
                status = ?,
                total_minutes = 0,
                total_connections = 0,
                first_join = NULL,
                last_leave = NULL,
                source_file = 'manual_attendance'
            WHERE attendance_id = ?
        ");
        $insertStmt = $pdo->prepare("
            INSERT INTO attendance_records
            (student_id, roster_id, class_date, present, attendance_source, raw_name, email, status, total_minutes, total_connections, first_join, last_leave, source_file)
            VALUES (?, ?, ?, ?, 'manual', ?, ?, ?, 0, 0, NULL, NULL, 'manual_attendance')
        ");

        $totalStudents = 0;
        $present = 0;
        $absent = 0;
        $inserted = 0;
        $updated = 0;

        $pdo->beginTransaction();

        foreach ($attendance as $studentId => $presentValue) {
            $studentId = (int) $studentId;
            if (!isset($studentMap[$studentId])) {
                continue;
            }

            $isPresent = (string) $presentValue === '1' ? 1 : 0;
            $student = $studentMap[$studentId];
            $studentName = (string) ($student['canonical_name'] ?? '');
            $studentEmail = (string) ($student['email'] ?? '');
            $rosterStatus = (string) ($student['roster_status'] ?? '');

            $findStmt->execute([$studentId, $selectedRosterId, $selectedDate]);
            $existingId = $findStmt->fetchColumn();

            if ($existingId) {
                $updateStmt->execute([$isPresent, $studentName, $studentEmail, $rosterStatus, $existingId]);
                $updated++;
            } else {
                $insertStmt->execute([$studentId, $selectedRosterId, $selectedDate, $isPresent, $studentName, $studentEmail, $rosterStatus]);
                $inserted++;
            }

            $totalStudents++;
            if ($isPresent === 1) {
                $present++;
            } else {
                $absent++;
            }
        }

        $pdo->commit();

        if (function_exists('logActivity')) {
            logActivity('UPDATE', 'attendance_records', null, 'Updated manual attendance for ' . $selectedRoster['roster_name'] . ' on ' . $selectedDate);
        }

        $summary = [
            'total_students' => $totalStudents,
            'present' => $present,
            'absent' => $absent,
            'inserted' => $inserted,
            'updated' => $updated,
        ];
        $message = "<div class='alert alert-success'>Manual attendance saved successfully.</div>";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "<div class='alert alert-danger'>" . e($e->getMessage()) . "</div>";
    }
}

$selectedRoster = $selectedRosterId > 0 ? loadRegularRoster($pdo, $selectedRosterId, $currentYear, $currentSeason) : null;
if ($selectedRosterId > 0 && !$selectedRoster) {
    $message = $message ?: "<div class='alert alert-warning'>Only current period rosters can be managed here.</div>";
    $selectedRosterId = 0;
}
$students = [];
$attendanceSummary = [
    'present' => 0,
    'absent' => 0,
    'dropped' => 0,
    'moved' => 0,
    'promoted' => 0,
    'active' => 0,
    'total' => 0,
];

if ($selectedRoster && $selectedDate !== '') {
    $where = "
        WHERE sr.roster_id = ?
    ";
    $params = [$selectedRosterId];

    if ($search !== '') {
        $where .= "
          AND (
              sm.canonical_name LIKE ?
              OR sm.phone LIKE ?
              OR sm.email LIKE ?
          )
        ";
        $likeSearch = '%' . $search . '%';
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
    }

    if ($statusFilter !== '') {
        $where .= " AND sr.status = ?";
        $params[] = $statusFilter;
    }

    $stmt = $pdo->prepare("
        SELECT
            sm.student_id,
            sm.canonical_name,
            sm.phone,
            sm.email,
            sr.status AS roster_status,
            ar.attendance_id,
            ar.present
        FROM student_rosters sr
        INNER JOIN students_master sm ON sr.student_id = sm.student_id
        LEFT JOIN attendance_records ar
            ON ar.student_id = sm.student_id
           AND ar.roster_id = ?
           AND ar.class_date = ?
        $where
        ORDER BY sm.canonical_name ASC
    ");
    $stmt->execute([$selectedRosterId, $selectedDate, ...$params]);
    $students = $stmt->fetchAll();
    $attendanceSummary = getAttendanceSummary($pdo, $selectedRosterId, $selectedDate, $search, $statusFilter);
}

$attendanceSummaryCards = [
    ['label' => 'Present', 'value' => $attendanceSummary['present'], 'class' => 'bg-success text-white'],
    ['label' => 'Absent', 'value' => $attendanceSummary['absent'], 'class' => 'bg-danger text-white'],
    ['label' => 'Dropped', 'value' => $attendanceSummary['dropped'], 'class' => 'summary-bg-dropped text-white'],
    ['label' => 'Moved', 'value' => $attendanceSummary['moved'], 'class' => 'bg-warning text-dark'],
    ['label' => 'Promoted', 'value' => $attendanceSummary['promoted'], 'class' => 'bg-info text-dark'],
    ['label' => 'Active', 'value' => $attendanceSummary['active'], 'class' => 'summary-bg-active text-white'],
    ['label' => 'Total Students', 'value' => $attendanceSummary['total'], 'class' => 'bg-dark text-white'],
];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body { background:#f4f6f9; }
        .card {
            border:0;
            border-radius:16px;
            box-shadow:0 8px 22px rgba(0,0,0,.06);
        }
        .kpi-value {
            font-size:1.8rem;
            font-weight:800;
        }
        .table-wrap {
            max-height:640px;
            overflow:auto;
        }
        .summary-card {
            min-height: 86px;
        }
        .summary-bg-dropped {
            background: #8b1e1e;
        }
        .summary-bg-active {
            background: #16856f;
        }
    </style>
</head>

<body>
<div class="container-fluid app-page">

    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">Manage Attendance</h3>
        <small class="page-subtitle">Manual daily attendance for the current period.</small>
    </div>

    <?= $message ?>

    <div class="alert alert-secondary">
        <b>Current period:</b> <?= e($currentSeason) ?> <?= e($currentYear) ?>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-white fw-bold">Current Period Attendance by Program</div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered table-striped align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Program</th>
                        <th>Total Students</th>
                        <th>Attendance Records</th>
                        <th>Present</th>
                        <th>Absent</th>
                        <th>Attendance %</th>
                        <th>Active</th>
                        <th>Dropped</th>
                        <th>Retention %</th>
                        <th>Dropped %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($programStats as $stat): ?>
                        <tr>
                            <td><b><?= e($stat['program_name']) ?></b></td>
                            <td><?= e($stat['total_students']) ?></td>
                            <td><?= e($stat['total_attendance_records']) ?></td>
                            <td><span class="badge bg-success"><?= e($stat['present_records']) ?></span></td>
                            <td><span class="badge bg-danger"><?= e($stat['absent_records']) ?></span></td>
                            <td><?= e($stat['attendance_percentage']) ?>%</td>
                            <td><span class="badge bg-success"><?= e($stat['active_students']) ?></span></td>
                            <td><span class="badge bg-danger"><?= e($stat['dropped_students']) ?></span></td>
                            <td><?= e($stat['retention_percentage']) ?>%</td>
                            <td><?= e($stat['dropped_percentage']) ?>%</td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (count($programStats) === 0): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No current period program data found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($summary): ?>
        <div class="alert alert-info">
            <b>Save summary:</b>
            Total students <?= e($summary['total_students']) ?> |
            Present <?= e($summary['present']) ?> |
            Absent <?= e($summary['absent']) ?> |
            Inserted <?= e($summary['inserted']) ?> |
            Updated <?= e($summary['updated']) ?>
        </div>
    <?php endif; ?>

    <form method="GET" class="card mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-lg-4">
                <label class="form-label fw-bold">Roster</label>
                <select name="roster_id" class="form-select" required>
                    <option value="">Select roster...</option>
                    <?php foreach ($rosters as $roster): ?>
                        <option value="<?= e($roster['roster_id']) ?>" <?= $selectedRosterId === (int) $roster['roster_id'] ? 'selected' : '' ?>>
                            <?= e($roster['program_name']) ?> -
                            <?= e($roster['roster_name']) ?>
                            (<?= e($roster['season']) ?> <?= e($roster['year']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2">
                <label class="form-label fw-bold">Class Date</label>
                <input type="date" name="class_date" class="form-control" value="<?= e($selectedDate) ?>" required>
            </div>

            <div class="col-lg-3">
                <label class="form-label fw-bold">Search</label>
                <input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="Name, phone, or email">
            </div>

            <div class="col-lg-2">
                <label class="form-label fw-bold">Roster Status</label>
                <select name="status" class="form-select">
                    <option value="">All statuses</option>
                    <?php foreach ($allowedRosterStatuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-1">
                <button class="btn btn-primary w-100">Load</button>
            </div>
        </div>
    </form>

    <?php if ($selectedRoster && $selectedDate !== ''): ?>
        <div class="row g-2 mb-4">
            <?php foreach ($attendanceSummaryCards as $card): ?>
                <div class="col-6 col-md-4 col-xl">
                    <div class="card summary-card <?= e($card['class']) ?>">
                        <div class="card-body py-3">
                            <small class="fw-bold"><?= e($card['label']) ?></small>
                            <div class="kpi-value"><?= e($card['value']) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header bg-white fw-bold d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <span>Student Attendance</span>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-success" id="markAllPresent">Mark all present</button>
                <button type="button" class="btn btn-sm btn-outline-danger" id="markAllAbsent">Mark all absent</button>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="save_attendance" value="1">
            <input type="hidden" name="roster_id" value="<?= e($selectedRosterId) ?>">
            <input type="hidden" name="class_date" value="<?= e($selectedDate) ?>">

            <div class="table-wrap">
                <table class="table table-bordered table-striped table-sm align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Student Name</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Roster Status</th>
                            <th>Current Attendance</th>
                            <th>Update</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($students as $student): ?>
                            <?php
                                $studentId = (int) $student['student_id'];
                                $presentValue = $student['present'] === null ? '' : (string) (int) $student['present'];
                            ?>
                            <tr>
                                <td><b><?= e($student['canonical_name']) ?></b></td>
                                <td><?= e($student['phone']) ?></td>
                                <td><?= e($student['email']) ?></td>
                                <td><span class="badge <?= e(statusBadgeClass((string) ($student['roster_status'] ?? ''))) ?>"><?= e($student['roster_status']) ?></span></td>
                                <td>
                                    <?php if ($presentValue === '1'): ?>
                                        <span class="badge bg-success">Present</span>
                                    <?php elseif ($presentValue === '0'): ?>
                                        <span class="badge bg-danger">Absent</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border">No record</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Attendance for <?= e($student['canonical_name']) ?>">
                                        <input type="radio" class="btn-check attendance-present" name="attendance[<?= e($studentId) ?>]" id="present_<?= e($studentId) ?>" value="1" <?= $presentValue === '1' ? 'checked' : '' ?> required>
                                        <label class="btn btn-outline-success" for="present_<?= e($studentId) ?>">Present</label>

                                        <input type="radio" class="btn-check attendance-absent" name="attendance[<?= e($studentId) ?>]" id="absent_<?= e($studentId) ?>" value="0" <?= $presentValue === '0' || $presentValue === '' ? 'checked' : '' ?> required>
                                        <label class="btn btn-outline-danger" for="absent_<?= e($studentId) ?>">Absent</label>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if ($selectedRoster && $selectedDate !== '' && count($students) === 0): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">No students match the selected filters.</td>
                            </tr>
                        <?php endif; ?>

                        <?php if (!$selectedRoster || $selectedDate === ''): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">Select a roster and date to manage attendance.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($selectedRoster && $selectedDate !== '' && count($students) > 0): ?>
                <div class="card-body border-top d-flex justify-content-end">
                    <button class="btn btn-primary">Save Attendance</button>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
document.getElementById('markAllPresent')?.addEventListener('click', function () {
    document.querySelectorAll('.attendance-present').forEach(function (input) {
        input.checked = true;
    });
});

document.getElementById('markAllAbsent')?.addEventListener('click', function () {
    document.querySelectorAll('.attendance-absent').forEach(function (input) {
        input.checked = true;
    });
});
</script>
</body>
</html>
