<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';

$selectedYear = $_GET['year'] ?? '';
$selectedProgram = $_GET['program_id'] ?? '';

$where = "WHERE 1=1";
$params = [];

if ($selectedYear !== '') {
    $where .= " AND r.year = ?";
    $params[] = $selectedYear;
}

if ($selectedProgram !== '') {
    $where .= " AND p.program_id = ?";
    $params[] = $selectedProgram;
}

/* =========================
   FILTER DATA
========================= */
$years = $pdo->query("
    SELECT DISTINCT year 
    FROM rosters 
    WHERE year IS NOT NULL 
    ORDER BY year DESC
")->fetchAll(PDO::FETCH_COLUMN);

$programs = $pdo->query("
    SELECT program_id, program_name
    FROM programs
    ORDER BY program_name
")->fetchAll();

/* =========================
   HISTORICAL SUMMARY
========================= */
$stmt = $pdo->prepare("
    SELECT 
        r.year,
        r.season,
        p.program_name,
        COUNT(DISTINCT sr.student_id) AS total_students,
        COUNT(DISTINCT CASE WHEN sr.status = 'active' THEN sr.student_id END) AS active_students,
        COUNT(DISTINCT CASE WHEN sr.status = 'dropped' THEN sr.student_id END) AS dropped_students,
        COUNT(DISTINCT ac.class_date) AS class_sessions,
        COUNT(ac.attendance_id) AS attendance_records,
        SUM(CASE WHEN ac.present = 1 THEN 1 ELSE 0 END) AS present_records,

        ROUND(
            CASE 
                WHEN COUNT(ac.attendance_id) > 0 
                THEN SUM(CASE WHEN ac.present = 1 THEN 1 ELSE 0 END) / COUNT(ac.attendance_id) * 100
                ELSE 0
            END, 1
        ) AS attendance_rate,

        ROUND(
            CASE 
                WHEN COUNT(DISTINCT sr.student_id) > 0
                THEN COUNT(DISTINCT CASE WHEN sr.status = 'dropped' THEN sr.student_id END) / COUNT(DISTINCT sr.student_id) * 100
                ELSE 0
            END, 1
        ) AS dropped_rate,

        ROUND(
            CASE 
                WHEN COUNT(DISTINCT sr.student_id) > 0
                THEN COUNT(DISTINCT CASE WHEN sr.status = 'active' THEN sr.student_id END) / COUNT(DISTINCT sr.student_id) * 100
                ELSE 0
            END, 1
        ) AS retention_rate

    FROM rosters r
    INNER JOIN programs p ON r.program_id = p.program_id
    LEFT JOIN student_rosters sr ON r.roster_id = sr.roster_id
    LEFT JOIN attendance_class ac 
        ON sr.student_id = ac.student_id
       AND sr.roster_id = ac.roster_id
    $where
    GROUP BY r.year, r.season, p.program_name
    ORDER BY r.year ASC, FIELD(r.season,'Winter','Spring','Summer','Fall'), p.program_name ASC
");
$stmt->execute($params);
$data = $stmt->fetchAll();

/* =========================
   KPI SUMMARY
========================= */
$totalStudents = 0;
$totalSessions = 0;
$totalRecords = 0;
$totalPresent = 0;
$totalDropped = 0;
$totalActive = 0;

foreach ($data as $row) {
    $totalStudents += intval($row['total_students']);
    $totalSessions += intval($row['class_sessions']);
    $totalRecords += intval($row['attendance_records']);
    $totalPresent += intval($row['present_records']);
    $totalDropped += intval($row['dropped_students']);
    $totalActive += intval($row['active_students']);
}

$globalAttendance = $totalRecords > 0 ? round(($totalPresent / $totalRecords) * 100, 1) : 0;
$globalDroppedRate = $totalStudents > 0 ? round(($totalDropped / $totalStudents) * 100, 1) : 0;
$globalRetentionRate = $totalStudents > 0 ? round(($totalActive / $totalStudents) * 100, 1) : 0;

/* =========================
   CHART DATA
========================= */
$labels = [];
$attendanceValues = [];
$droppedValues = [];
$retentionValues = [];
$studentsValues = [];
$sessionsValues = [];

foreach ($data as $row) {
    $label = $row['year'] . ' ' . $row['season'] . ' - ' . $row['program_name'];

    $labels[] = $label;
    $attendanceValues[] = floatval($row['attendance_rate']);
    $droppedValues[] = floatval($row['dropped_rate']);
    $retentionValues[] = floatval($row['retention_rate']);
    $studentsValues[] = intval($row['total_students']);
    $sessionsValues[] = intval($row['class_sessions']);
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Historical Analytics</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body { background:#f4f6f9; }
        .card {
            border: none;
            border-radius: 18px;
            box-shadow: 0 8px 22px rgba(0,0,0,.06);
        }
        .kpi-value {
            font-size: 2rem;
            font-weight: 800;
        }
        .table-wrap {
            max-height: 520px;
            overflow-y: auto;
        }
        .small-muted {
            font-size: .78rem;
            color: #6c757d;
        }
    </style>
</head>

<body>

<div class="container-fluid app-page">

    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">Historical Analytics</h3>
        <small class="page-subtitle">Compare attendance, retention, dropped students, sessions and records across years.</small>
    </div>

    <form method="GET" class="card mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-bold">Year</label>
                <select name="year" class="form-select">
                    <option value="">All Years</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?= htmlspecialchars($y) ?>" <?= $selectedYear == $y ? 'selected' : '' ?>>
                            <?= htmlspecialchars($y) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-bold">Program</label>
                <select name="program_id" class="form-select">
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?= htmlspecialchars($p['program_id']) ?>" <?= $selectedProgram == $p['program_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['program_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <button class="btn btn-primary w-100">Apply</button>
            </div>

            <div class="col-md-2">
                <a href="historical_dashboard.php" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-4">

        <div class="col-md-2">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Students</small>
                    <div class="kpi-value text-primary"><?= $totalStudents ?></div>
                    <div class="small-muted">Unique per roster/cycle</div>
                </div>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Class Sessions</small>
                    <div class="kpi-value text-dark"><?= $totalSessions ?></div>
                    <div class="small-muted">Distinct class dates</div>
                </div>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Attendance Records</small>
                    <div class="kpi-value text-secondary"><?= $totalRecords ?></div>
                    <div class="small-muted">Student/date records</div>
                </div>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Attendance %</small>
                    <div class="kpi-value text-success"><?= $globalAttendance ?>%</div>
                    <div class="small-muted">Present / records</div>
                </div>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Dropped %</small>
                    <div class="kpi-value text-danger"><?= $globalDroppedRate ?>%</div>
                    <div class="small-muted">Dropped / students</div>
                </div>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Retention %</small>
                    <div class="kpi-value text-success"><?= $globalRetentionRate ?>%</div>
                    <div class="small-muted">Active / students</div>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-3 mb-4">

        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-white fw-bold">Attendance Trend</div>
                <div class="card-body">
                    <canvas id="attendanceTrend" height="150"></canvas>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-white fw-bold">Dropped vs Retention Trend</div>
                <div class="card-body">
                    <canvas id="retentionTrend" height="150"></canvas>
                </div>
            </div>
        </div>

    </div>

    <div class="card mb-4">
        <div class="card-header bg-white fw-bold">Students and Class Sessions Trend</div>
        <div class="card-body">
            <canvas id="studentsSessionsTrend" height="95"></canvas>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white fw-bold">Historical Summary Table</div>

        <div class="card-body table-wrap">
            <table class="table table-sm table-bordered table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Year</th>
                        <th>Season</th>
                        <th>Program</th>
                        <th>Students</th>
                        <th>Active</th>
                        <th>Dropped</th>
                        <th>Dropped %</th>
                        <th>Retention %</th>
                        <th>Class Sessions</th>
                        <th>Attendance Records</th>
                        <th>Present Records</th>
                        <th>Attendance %</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($data as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['year']) ?></td>
                            <td><?= htmlspecialchars($r['season']) ?></td>
                            <td><b><?= htmlspecialchars($r['program_name']) ?></b></td>
                            <td><?= htmlspecialchars($r['total_students']) ?></td>
                            <td><span class="badge bg-success"><?= htmlspecialchars($r['active_students']) ?></span></td>
                            <td><span class="badge bg-danger"><?= htmlspecialchars($r['dropped_students']) ?></span></td>
                            <td><?= htmlspecialchars($r['dropped_rate']) ?>%</td>
                            <td><?= htmlspecialchars($r['retention_rate']) ?>%</td>
                            <td><?= htmlspecialchars($r['class_sessions']) ?></td>
                            <td><?= htmlspecialchars($r['attendance_records']) ?></td>
                            <td><?= htmlspecialchars($r['present_records']) ?></td>
                            <td>
                                <span class="badge bg-primary">
                                    <?= htmlspecialchars($r['attendance_rate']) ?>%
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (count($data) == 0): ?>
                        <tr>
                            <td colspan="12" class="text-center text-muted">
                                No historical data found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
const labels = <?= json_encode($labels) ?>;

new Chart(document.getElementById('attendanceTrend'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Attendance %',
            data: <?= json_encode($attendanceValues) ?>,
            tension: .3
        }]
    },
    options: {
        responsive: true,
        scales: { y: { beginAtZero: true, max: 100 } }
    }
});

new Chart(document.getElementById('retentionTrend'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Dropped %',
                data: <?= json_encode($droppedValues) ?>,
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, .15)',
                tension: .3
            },
            {
                label: 'Retention %',
                data: <?= json_encode($retentionValues) ?>,
                borderColor: '#198754',
                backgroundColor: 'rgba(25, 135, 84, .15)',
                tension: .3
            }
        ]
    },
    options: {
        responsive: true,
        scales: { y: { beginAtZero: true, max: 100 } }
    }
});

new Chart(document.getElementById('studentsSessionsTrend'), {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Students',
                data: <?= json_encode($studentsValues) ?>
            },
            {
                label: 'Class Sessions',
                data: <?= json_encode($sessionsValues) ?>
            }
        ]
    },
    options: {
        responsive: true
    }
});
</script>

</body>
</html>
