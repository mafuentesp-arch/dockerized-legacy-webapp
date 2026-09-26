<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/db.php';

/* =========================
   SETTINGS
========================= */
function getSetting($pdo, $name, $default = '') {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_name = ? LIMIT 1");
    $stmt->execute([$name]);
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : $default;
}

$currentYear = getSetting($pdo, 'current_year', date('Y'));
$currentSeason = getSetting($pdo, 'current_season', 'Spring');

$selectedRoster = intval($_GET['roster_id'] ?? 0);

/* =========================
   CURRENT ROSTERS
========================= */
$rostersStmt = $pdo->prepare("
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
    ORDER BY p.program_name ASC, r.roster_name ASC
");
$rostersStmt->execute([$currentYear, $currentSeason]);
$rosters = $rostersStmt->fetchAll();

$rosterFilter = "";
$params = [$currentYear, $currentSeason];

if ($selectedRoster > 0) {
    $rosterFilter = " AND v.roster_id = ? ";
    $params[] = $selectedRoster;
}

/* =========================
   ACTIVE STUDENTS KPI
========================= */
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT sr.student_id)
    FROM student_rosters sr
    INNER JOIN rosters r ON sr.roster_id = r.roster_id
    WHERE sr.status = 'active'
      AND r.year = ?
      AND r.season = ?
      " . ($selectedRoster > 0 ? " AND r.roster_id = ? " : "") . "
");
$stmt->execute($selectedRoster > 0 ? [$currentYear, $currentSeason, $selectedRoster] : [$currentYear, $currentSeason]);
$totalActiveStudents = intval($stmt->fetchColumn());

/* =========================
   DROPPED STUDENTS KPI
========================= */
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT sr.student_id)
    FROM student_rosters sr
    INNER JOIN rosters r ON sr.roster_id = r.roster_id
    WHERE sr.status = 'dropped'
      AND r.year = ?
      AND r.season = ?
      " . ($selectedRoster > 0 ? " AND r.roster_id = ? " : "") . "
");
$stmt->execute($selectedRoster > 0 ? [$currentYear, $currentSeason, $selectedRoster] : [$currentYear, $currentSeason]);
$totalDropped = intval($stmt->fetchColumn());

/* =========================
   ATTENDANCE KPI FROM VIEW
========================= */
$stmt = $pdo->prepare("
    SELECT 
        COUNT(v.attendance_id) AS total_records,
        SUM(CASE WHEN v.present = 1 THEN 1 ELSE 0 END) AS present_count
    FROM vw_attendance_full v
    INNER JOIN student_rosters sr 
        ON v.student_id = sr.student_id 
       AND v.roster_id = sr.roster_id
    WHERE sr.status = 'active'
      AND v.year = ?
      AND v.season = ?
      $rosterFilter
");
$stmt->execute($params);
$att = $stmt->fetch();

$totalAttendance = intval($att['total_records'] ?? 0);
$totalPresent = intval($att['present_count'] ?? 0);
$attendanceRate = $totalAttendance > 0 ? round(($totalPresent / $totalAttendance) * 100, 1) : 0;

/* =========================
   UNMATCHED ZOOM KPI
========================= */
$unmatchedZoom = $pdo->query("
    SELECT COUNT(*) 
    FROM attendance_records 
    WHERE attendance_source = 'zoom'
      AND student_id IS NULL
")->fetchColumn();

/* =========================
   ACTIVE STUDENTS BY PROGRAM
========================= */
$stmt = $pdo->prepare("
    SELECT 
        p.program_name,
        COUNT(DISTINCT sr.student_id) AS active_students
    FROM student_rosters sr
    INNER JOIN rosters r ON sr.roster_id = r.roster_id
    INNER JOIN programs p ON r.program_id = p.program_id
    WHERE sr.status = 'active'
      AND r.year = ?
      AND r.season = ?
      " . ($selectedRoster > 0 ? " AND r.roster_id = ? " : "") . "
    GROUP BY p.program_id, p.program_name
    ORDER BY p.program_name ASC
");
$stmt->execute($selectedRoster > 0 ? [$currentYear, $currentSeason, $selectedRoster] : [$currentYear, $currentSeason]);
$activeStudentsByProgram = $stmt->fetchAll();

/* =========================
   ATTENDANCE BY ROSTER
========================= */
$stmt = $pdo->prepare("
    SELECT 
        v.roster_id,
        v.roster_name,
        v.program_name,
        COUNT(v.attendance_id) AS total_records,
        SUM(CASE WHEN v.present = 1 THEN 1 ELSE 0 END) AS present_count,
        ROUND(
            CASE 
                WHEN COUNT(v.attendance_id) > 0
                THEN SUM(CASE WHEN v.present = 1 THEN 1 ELSE 0 END) / COUNT(v.attendance_id) * 100
                ELSE 0
            END, 1
        ) AS attendance_rate
    FROM vw_attendance_full v
    INNER JOIN student_rosters sr 
        ON v.student_id = sr.student_id 
       AND v.roster_id = sr.roster_id
    WHERE sr.status = 'active'
      AND v.year = ?
      AND v.season = ?
      $rosterFilter
    GROUP BY v.roster_id, v.roster_name, v.program_name
    ORDER BY v.program_name ASC, v.roster_name ASC
");
$stmt->execute($params);
$attendanceByRoster = $stmt->fetchAll();

/* =========================
   STUDENT STATUS SUMMARY
========================= */
$stmt = $pdo->prepare("
    SELECT 
        sr.status,
        COUNT(DISTINCT sr.student_id) AS total
    FROM student_rosters sr
    INNER JOIN rosters r ON sr.roster_id = r.roster_id
    WHERE r.year = ?
      AND r.season = ?
      " . ($selectedRoster > 0 ? " AND r.roster_id = ? " : "") . "
    GROUP BY sr.status
");
$stmt->execute($selectedRoster > 0 ? [$currentYear, $currentSeason, $selectedRoster] : [$currentYear, $currentSeason]);
$statusSummary = $stmt->fetchAll();

/* =========================
   STUDENTS AT RISK
========================= */
$stmt = $pdo->prepare("
    SELECT 
        v.student_id,
        v.canonical_name,
        v.phone,
        v.roster_id,
        v.roster_name,
        v.program_name,
        COUNT(v.attendance_id) AS total_classes,
        SUM(CASE WHEN v.present = 1 THEN 1 ELSE 0 END) AS attended_classes,
        ROUND(
            CASE 
                WHEN COUNT(v.attendance_id) > 0
                THEN SUM(CASE WHEN v.present = 1 THEN 1 ELSE 0 END) / COUNT(v.attendance_id) * 100
                ELSE 0
            END, 1
        ) AS attendance_rate
    FROM vw_attendance_full v
    INNER JOIN student_rosters sr 
        ON v.student_id = sr.student_id 
       AND v.roster_id = sr.roster_id
    WHERE sr.status = 'active'
      AND v.year = ?
      AND v.season = ?
      $rosterFilter
    GROUP BY v.student_id, v.roster_id, v.canonical_name, v.phone, v.roster_name, v.program_name
    HAVING attendance_rate < 60
    ORDER BY attendance_rate ASC
    LIMIT 25
");
$stmt->execute($params);
$studentsAtRisk = $stmt->fetchAll();

/* =========================
   READY FOR TEST
========================= */
$stmt = $pdo->prepare("
    SELECT 
        v.student_id,
        v.canonical_name,
        v.phone,
        v.roster_id,
        v.roster_name,
        v.program_name,
        SUM(CASE WHEN v.present = 1 THEN 1 ELSE 0 END) AS attended_sessions
    FROM vw_attendance_full v
    INNER JOIN student_rosters sr 
        ON v.student_id = sr.student_id 
       AND v.roster_id = sr.roster_id
    WHERE sr.status = 'active'
      AND v.year = ?
      AND v.season = ?
      $rosterFilter
    GROUP BY v.student_id, v.roster_id, v.canonical_name, v.phone, v.roster_name, v.program_name
    HAVING attended_sessions >= 30
    ORDER BY attended_sessions DESC
    LIMIT 25
");
$stmt->execute($params);
$readyForTest = $stmt->fetchAll();

$chartLabels = array_column($attendanceByRoster, 'roster_name');
$chartValues = array_column($attendanceByRoster, 'attendance_rate');

$statusLabels = array_column($statusSummary, 'status');
$statusValues = array_column($statusSummary, 'total');

function statusChartColor(string $status): string
{
    $normalized = strtolower(trim($status));

    if (in_array($normalized, ['active', 'activo'], true)) {
        return '#198754';
    }

    if (in_array($normalized, ['dropped', 'retired', 'retirado', 'inactive', 'inactivo'], true)) {
        return '#dc3545';
    }

    if (in_array($normalized, ['promoted', 'completed', 'completado'], true)) {
        return '#0dcaf0';
    }

    if ($normalized === 'moved') {
        return '#ffc107';
    }

    return '#6c757d';
}

$statusColors = array_map('statusChartColor', $statusLabels);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Demo Student Hub Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
	
        body { background: #f4f6f9; }
        .kpi-card {
            border: none;
            border-radius: 18px;
            box-shadow: 0 8px 22px rgba(0,0,0,.06);
        }
        .kpi-value {
            font-size: 2rem;
            font-weight: 800;
        }
        .section-card {
            border: none;
            border-radius: 18px;
            box-shadow: 0 8px 22px rgba(0,0,0,.06);
        }
        .table-wrap {
            max-height: 390px;
            overflow-y: auto;
        }
    </style>
</head>

<body>

<div class="container-fluid app-page">

    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">Dashboard</h3>
        <small class="page-subtitle">Overview of current ESOL roster, attendance, and testing activity.</small>
    </div>

    <form method="GET" class="card section-card mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label fw-bold">Filter by current roster</label>
                <select name="roster_id" class="form-select">
                    <option value="0">All current rosters</option>

                    <?php foreach ($rosters as $r): ?>
                        <option value="<?= htmlspecialchars($r['roster_id']) ?>" 
                            <?= $selectedRoster == $r['roster_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['program_name']) ?> —
                            <?= htmlspecialchars($r['roster_name']) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="col-md-2">
                <button class="btn btn-primary w-100">Apply Filter</button>
            </div>

            <div class="col-md-2">
                <a href="dashboard.php" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-4">

        <div class="col-md-3">
            <div class="card kpi-card">
                <div class="card-body">
                    <small class="text-muted">Active Students</small>
                    <div class="kpi-value text-success"><?= htmlspecialchars($totalActiveStudents) ?></div>
                    <small>Current cycle only</small>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card kpi-card">
                <div class="card-body">
                    <small class="text-muted">Dropped Students</small>
                    <div class="kpi-value text-danger"><?= htmlspecialchars($totalDropped) ?></div>
                    <small>Not counted as active risk</small>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card kpi-card">
                <div class="card-body">
                    <small class="text-muted">Attendance Rate</small>
                    <div class="kpi-value text-success"><?= htmlspecialchars($attendanceRate) ?>%</div>
                    <small>From unified attendance_records</small>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card kpi-card">
                <div class="card-body">
                    <small class="text-muted">Unmatched Zoom</small>
                    <div class="kpi-value text-warning"><?= htmlspecialchars($unmatchedZoom) ?></div>
                    <small>Needs identity match</small>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-3 mb-4">
        <?php foreach ($activeStudentsByProgram as $p): ?>
            <div class="col-md-4">
                <div class="card kpi-card">
                    <div class="card-body">
                        <small class="text-muted">
                            Active Students — <?= htmlspecialchars($currentSeason) ?> <?= htmlspecialchars($currentYear) ?>
                        </small>
                        <div class="kpi-value text-success">
                            <?= htmlspecialchars($p['active_students']) ?>
                        </div>
                        <small><?= htmlspecialchars($p['program_name']) ?></small>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-4">

        <div class="col-md-8">
            <div class="card section-card">
                <div class="card-header bg-white fw-bold">
                    Attendance Rate by Roster
                </div>
                <div class="card-body">
                    <canvas id="attendanceChart" height="120"></canvas>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card section-card">
                <div class="card-header bg-white fw-bold">
                    Student Status Summary
                </div>
                <div class="card-body">
                    <canvas id="statusChart" height="210"></canvas>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-3">

        <div class="col-md-6">
            <div class="card section-card">
                <div class="card-header bg-danger text-white fw-bold">
                    Students at Risk — Active Only / Below 60%
                </div>

                <div class="card-body table-wrap">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Student</th>
                                <th>Roster</th>
                                <th>Attendance</th>
                                <th>Open</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($studentsAtRisk as $s): ?>
                                <tr>
                                    <td>
                                        <b><?= htmlspecialchars($s['canonical_name']) ?></b><br>
                                        <small><?= htmlspecialchars($s['phone'] ?? '') ?></small>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($s['program_name']) ?><br>
                                        <small><?= htmlspecialchars($s['roster_name']) ?></small>
                                    </td>

                                    <td>
                                        <span class="badge bg-danger">
                                            <?= htmlspecialchars($s['attendance_rate']) ?>%
                                        </span>
                                    </td>

                                    <td>
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="attendance_view.php?roster_id=<?= htmlspecialchars($s['roster_id']) ?>&student_id=<?= htmlspecialchars($s['student_id']) ?>"
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (count($studentsAtRisk) == 0): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted">
                                        No active students at risk for this cycle.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>

                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card section-card">
                <div class="card-header bg-success text-white fw-bold">
                    Possible BEST Plus Ready — 30+ Sessions
                </div>

                <div class="card-body table-wrap">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Student</th>
                                <th>Roster</th>
                                <th>Sessions</th>
                                <th>Open</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($readyForTest as $s): ?>
                                <tr>
                                    <td>
                                        <b><?= htmlspecialchars($s['canonical_name']) ?></b><br>
                                        <small><?= htmlspecialchars($s['phone'] ?? '') ?></small>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($s['program_name']) ?><br>
                                        <small><?= htmlspecialchars($s['roster_name']) ?></small>
                                    </td>

                                    <td>
                                        <span class="badge bg-success">
                                            <?= htmlspecialchars($s['attended_sessions']) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="attendance_view.php?roster_id=<?= htmlspecialchars($s['roster_id']) ?>">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (count($readyForTest) == 0): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted">
                                        No students ready yet.
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
const attendanceLabels = <?= json_encode($chartLabels) ?>;
const attendanceValues = <?= json_encode($chartValues) ?>;

new Chart(document.getElementById('attendanceChart'), {
    type: 'bar',
    data: {
        labels: attendanceLabels,
        datasets: [{
            label: 'Attendance %',
            data: attendanceValues,
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, max: 100 }
        }
    }
});

const statusLabels = <?= json_encode($statusLabels) ?>;
const statusValues = <?= json_encode($statusValues) ?>;
const statusColors = <?= json_encode($statusColors) ?>;

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabels,
        datasets: [{
            data: statusValues,
            backgroundColor: statusColors
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});
</script>

</body>
</html>
