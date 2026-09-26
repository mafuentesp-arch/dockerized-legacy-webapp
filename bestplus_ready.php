<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';

if (file_exists(__DIR__ . '/includes/audit.php')) {
    require_once __DIR__ . '/includes/audit.php';
}

ensure_session_started();

function testing_setting(PDO $pdo, string $name, string $default = ''): string
{
    $stmt = $pdo->prepare("
        SELECT setting_value
        FROM system_settings
        WHERE setting_name = ?
        LIMIT 1
    ");
    $stmt->execute([$name]);
    $value = $stmt->fetchColumn();

    return $value !== false ? (string) $value : $default;
}

function save_testing_setting(PDO $pdo, string $name, string $value): void
{
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_name, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$name, $value]);
}

$role = $_SESSION['role'] ?? '';
$currentYear = testing_setting($pdo, 'current_year', date('Y'));
$currentSeason = testing_setting($pdo, 'current_season', 'Spring');
$defaultMinHours = (float) testing_setting($pdo, 'testing_min_hours', '30');
$defaultClassHours = (float) testing_setting($pdo, 'testing_class_hours', '3');
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_testing_settings') {
    verify_csrf();

    if ($role !== 'admin') {
        http_response_code(403);
        exit('Access denied.');
    }

    $postedMinHours = max(0, round((float) ($_POST['default_min_hours'] ?? $defaultMinHours), 2));
    $postedClassHours = max(0, round((float) ($_POST['default_class_hours'] ?? $defaultClassHours), 2));

    save_testing_setting($pdo, 'testing_min_hours', (string) $postedMinHours);
    save_testing_setting($pdo, 'testing_class_hours', (string) $postedClassHours);

    if (function_exists('logActivity')) {
        logActivity('UPDATE', 'system_settings', null, 'Updated testing eligibility settings');
    }

    header('Location: bestplus_ready.php?settings_updated=1');
    exit;
}

if (isset($_GET['settings_updated'])) {
    $message = "<div class='alert alert-success'>Testing eligibility settings updated.</div>";
    $defaultMinHours = (float) testing_setting($pdo, 'testing_min_hours', '30');
    $defaultClassHours = (float) testing_setting($pdo, 'testing_class_hours', '3');
}

$selectedProgram = cleanInt($_GET['program_id'] ?? 0);
$selectedRoster = cleanInt($_GET['roster_id'] ?? 0);
$selectedSeason = cleanText($_GET['season'] ?? $currentSeason, 20);
$selectedYear = cleanText($_GET['year'] ?? $currentYear, 10);
$preFilter = cleanText($_GET['pre_filter'] ?? 'all', 20);
$minHours = max(0, (float) ($_GET['min_hours'] ?? $defaultMinHours));
$classHours = max(0, (float) ($_GET['class_hours'] ?? $defaultClassHours));

if (!in_array($preFilter, ['all', 'pre_only', 'no_pre'], true)) {
    $preFilter = 'all';
}

$years = $pdo->query("
    SELECT DISTINCT year
    FROM rosters
    WHERE year IS NOT NULL AND year <> ''
    ORDER BY year DESC
")->fetchAll(PDO::FETCH_COLUMN);

$seasons = $pdo->query("
    SELECT DISTINCT season
    FROM rosters
    WHERE season IS NOT NULL AND season <> ''
    ORDER BY FIELD(season, 'Winter', 'Spring', 'Summer', 'Fall'), season
")->fetchAll(PDO::FETCH_COLUMN);

$programsStmt = $pdo->prepare("
    SELECT DISTINCT p.program_id, p.program_name
    FROM programs p
    INNER JOIN rosters r ON p.program_id = r.program_id
    WHERE (? = '' OR r.year = ?)
      AND (? = '' OR r.season = ?)
    ORDER BY p.program_name ASC
");
$programsStmt->execute([$selectedYear, $selectedYear, $selectedSeason, $selectedSeason]);
$programs = $programsStmt->fetchAll();

$rostersSql = "
    SELECT r.roster_id, r.roster_name, r.year, r.season, p.program_name
    FROM rosters r
    INNER JOIN programs p ON r.program_id = p.program_id
    WHERE (? = '' OR r.year = ?)
      AND (? = '' OR r.season = ?)
";
$rosterParams = [$selectedYear, $selectedYear, $selectedSeason, $selectedSeason];

if ($selectedProgram > 0) {
    $rostersSql .= " AND p.program_id = ?";
    $rosterParams[] = $selectedProgram;
}

$rostersSql .= " ORDER BY p.program_name ASC, r.roster_name ASC";
$rostersStmt = $pdo->prepare($rostersSql);
$rostersStmt->execute($rosterParams);
$rosters = $rostersStmt->fetchAll();

$where = "
WHERE sr.status = 'active'
  AND (? = '' OR r.year = ?)
  AND (? = '' OR r.season = ?)
";
$params = [$selectedYear, $selectedYear, $selectedSeason, $selectedSeason];

if ($selectedProgram > 0) {
    $where .= " AND p.program_id = ?";
    $params[] = $selectedProgram;
}

if ($selectedRoster > 0) {
    $where .= " AND r.roster_id = ?";
    $params[] = $selectedRoster;
}

if ($preFilter === 'pre_only') {
    $where .= " AND COALESCE(t.pre_tests, 0) > 0";
} elseif ($preFilter === 'no_pre') {
    $where .= " AND COALESCE(t.pre_tests, 0) = 0";
}

$stmt = $pdo->prepare("
    SELECT
        sm.student_id,
        sm.canonical_name,
        p.program_name,
        r.roster_id,
        r.roster_name,
        r.year,
        r.season,
        COALESCE(a.attendance_records, 0) AS attendance_records,
        COALESCE(a.present_sessions, 0) AS present_sessions,
        COALESCE(a.total_hours, 0) AS total_hours,
        COALESCE(a.attendance_percent, 0) AS attendance_percent,
        a.last_attendance_date,
        COALESCE(t.pre_tests, 0) AS pre_tests,
        COALESCE(t.post_tests, 0) AS post_tests
    FROM student_rosters sr
    INNER JOIN students_master sm ON sr.student_id = sm.student_id
    INNER JOIN rosters r ON sr.roster_id = r.roster_id
    INNER JOIN programs p ON r.program_id = p.program_id
    LEFT JOIN (
        SELECT
            ar.student_id,
            ar.roster_id,
            COUNT(ar.attendance_id) AS attendance_records,
            SUM(CASE WHEN ar.present = 1 THEN 1 ELSE 0 END) AS present_sessions,
            ROUND(
                SUM(
                    CASE
                        WHEN ar.present = 1 AND COALESCE(ar.total_minutes, 0) > 0 THEN ar.total_minutes / 60
                        WHEN ar.present = 1 THEN ?
                        ELSE 0
                    END
                ),
                2
            ) AS total_hours,
            ROUND(
                CASE
                    WHEN COUNT(ar.attendance_id) > 0
                    THEN SUM(CASE WHEN ar.present = 1 THEN 1 ELSE 0 END) / COUNT(ar.attendance_id) * 100
                    ELSE 0
                END,
                1
            ) AS attendance_percent,
            MAX(CASE WHEN ar.present = 1 THEN ar.class_date ELSE NULL END) AS last_attendance_date
        FROM attendance_records ar
        GROUP BY ar.student_id, ar.roster_id
    ) a ON a.student_id = sm.student_id AND a.roster_id = r.roster_id
    LEFT JOIN (
        SELECT
            LOWER(TRIM(student_name)) AS normalized_name,
            SUM(
                CASE
                    WHEN COALESCE(
                        pre_post,
                        CASE WHEN UPPER(test_type) LIKE '%POST%' THEN 'POST' ELSE 'PRE' END
                    ) = 'PRE'
                    THEN 1 ELSE 0
                END
            ) AS pre_tests,
            SUM(
                CASE
                    WHEN COALESCE(
                        pre_post,
                        CASE WHEN UPPER(test_type) LIKE '%POST%' THEN 'POST' ELSE 'PRE' END
                    ) = 'POST'
                    THEN 1 ELSE 0
                END
            ) AS post_tests
        FROM test_results
        GROUP BY LOWER(TRIM(student_name))
    ) t ON t.normalized_name = LOWER(TRIM(sm.canonical_name))
    $where
    GROUP BY
        sm.student_id,
        sm.canonical_name,
        p.program_name,
        r.roster_id,
        r.roster_name,
        r.year,
        r.season,
        a.attendance_records,
        a.present_sessions,
        a.total_hours,
        a.attendance_percent,
        a.last_attendance_date,
        t.pre_tests,
        t.post_tests
    HAVING total_hours >= ?
    ORDER BY p.program_name ASC, r.roster_name ASC, total_hours DESC, sm.canonical_name ASC
");
$stmt->execute(array_merge([$classHours], $params, [$minHours]));
$students = $stmt->fetchAll();

if (function_exists('logActivity')) {
    logActivity('VIEW', 'testing_eligibility', null, 'Viewed testing eligibility report');
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (function_exists('logActivity')) {
        logActivity('EXPORT', 'testing_eligibility', null, 'Exported testing eligibility CSV');
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="testing_eligibility.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Student Name',
        'Program',
        'Roster',
        'Season',
        'Year',
        'Total Attendance Hours',
        'Attendance %',
        'PRE/POST Status',
        'Last Attendance Date'
    ]);

    foreach ($students as $student) {
        $preTests = (int) $student['pre_tests'];
        $postTests = (int) $student['post_tests'];
        $status = $preTests === 0 ? 'No PRE yet' : ($postTests === 0 ? 'PRE tested only' : 'PRE and POST tested');

        fputcsv($out, [
            $student['canonical_name'],
            $student['program_name'],
            $student['roster_name'],
            $student['season'],
            $student['year'],
            $student['total_hours'],
            $student['attendance_percent'] . '%',
            $status,
            $student['last_attendance_date']
        ]);
    }

    fclose($out);
    exit;
}

$totalEligible = count($students);
$totalHours = 0;
$missingPre = 0;
$readyForPost = 0;

foreach ($students as $student) {
    $totalHours += (float) $student['total_hours'];

    if ((int) $student['pre_tests'] === 0) {
        $missingPre++;
    } elseif ((int) $student['post_tests'] === 0) {
        $readyForPost++;
    }
}

$averageHours = $totalEligible > 0 ? round($totalHours / $totalEligible, 1) : 0;
$queryForLinks = $_GET;
unset($queryForLinks['export'], $queryForLinks['settings_updated']);
$csvUrl = 'bestplus_ready.php?' . http_build_query(array_merge($queryForLinks, ['export' => 'csv']));
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Testing Eligibility</title>
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
            max-height:620px;
            overflow-y:auto;
        }
        @media print {
            .no-print,
            .esol-topbar,
            .esol-sidebar,
            .offcanvas {
                display:none !important;
            }
            .app-page {
                margin:0 !important;
                max-width:100% !important;
                padding:0 !important;
                width:100% !important;
            }
            .card {
                box-shadow:none;
            }
            .table-wrap {
                max-height:none;
                overflow:visible;
            }
        }
    </style>
</head>
<body>
<div class="container-fluid app-page">
    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">Testing Eligibility</h3>
        <small class="page-subtitle">Students ready for BEST Plus testing based on accumulated attendance hours.</small>
    </div>

    <?= $message ?>

    <?php if ($role === 'admin'): ?>
        <form method="POST" class="card mb-4 no-print">
            <div class="card-header bg-white fw-bold">Eligibility Settings</div>
            <div class="card-body row g-3 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_testing_settings">

                <div class="col-md-3">
                    <label class="form-label fw-bold">Default Minimum Hours</label>
                    <input type="number" name="default_min_hours" class="form-control" min="0" step="0.25" value="<?= e($defaultMinHours) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-bold">Configured Class Hours</label>
                    <input type="number" name="default_class_hours" class="form-control" min="0" step="0.25" value="<?= e($defaultClassHours) ?>">
                </div>

                <div class="col-md-2">
                    <button class="btn btn-primary w-100">Save</button>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <form method="GET" class="card mb-4 no-print">
        <div class="card-body row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label fw-bold">Year</label>
                <select name="year" class="form-select">
                    <option value="">All Years</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= e($year) ?>" <?= $selectedYear === (string) $year ? 'selected' : '' ?>><?= e($year) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold">Season</label>
                <select name="season" class="form-select">
                    <option value="">All Seasons</option>
                    <?php foreach ($seasons as $season): ?>
                        <option value="<?= e($season) ?>" <?= $selectedSeason === (string) $season ? 'selected' : '' ?>><?= e($season) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold">Program</label>
                <select name="program_id" class="form-select" onchange="this.form.submit()">
                    <option value="0">All Programs</option>
                    <?php foreach ($programs as $program): ?>
                        <option value="<?= e($program['program_id']) ?>" <?= $selectedProgram === (int) $program['program_id'] ? 'selected' : '' ?>>
                            <?= e($program['program_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold">Roster</label>
                <select name="roster_id" class="form-select">
                    <option value="0">All Rosters</option>
                    <?php foreach ($rosters as $roster): ?>
                        <option value="<?= e($roster['roster_id']) ?>" <?= $selectedRoster === (int) $roster['roster_id'] ? 'selected' : '' ?>>
                            <?= e($roster['program_name'] . ' - ' . $roster['roster_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold">PRE Status</label>
                <select name="pre_filter" class="form-select">
                    <option value="all" <?= $preFilter === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="pre_only" <?= $preFilter === 'pre_only' ? 'selected' : '' ?>>PRE tested only</option>
                    <option value="no_pre" <?= $preFilter === 'no_pre' ? 'selected' : '' ?>>No PRE yet</option>
                </select>
            </div>

            <div class="col-md-1">
                <label class="form-label fw-bold">Hours</label>
                <input type="number" name="min_hours" class="form-control" min="0" step="0.25" value="<?= e($minHours) ?>">
            </div>

            <div class="col-md-1">
                <label class="form-label fw-bold">Class</label>
                <input type="number" name="class_hours" class="form-control" min="0" step="0.25" value="<?= e($classHours) ?>">
            </div>

            <div class="col-md-12 d-flex flex-wrap gap-2">
                <button class="btn btn-primary">Apply</button>
                <a class="btn btn-success" href="<?= e($csvUrl) ?>">Export CSV</a>
                <button class="btn btn-outline-dark" type="button" onclick="window.print()">PDF Print</button>
                <a class="btn btn-outline-secondary" href="bestplus_ready.php">Reset</a>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Total Eligible Students</small>
                    <div class="kpi-value text-primary"><?= e($totalEligible) ?></div>
                    <small>At least <?= e($minHours) ?> hours</small>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Average Hours</small>
                    <div class="kpi-value text-success"><?= e($averageHours) ?></div>
                    <small>Among eligible students</small>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Students Missing PRE</small>
                    <div class="kpi-value text-warning"><?= e($missingPre) ?></div>
                    <small>No PRE test found</small>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <small class="text-muted">Students Ready for POST</small>
                    <div class="kpi-value text-info"><?= e($readyForPost) ?></div>
                    <small>PRE found, no POST found</small>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white fw-bold d-flex justify-content-between">
            <span>Students Ready for Testing</span>
            <span><?= e($totalEligible) ?> students</span>
        </div>

        <div class="table-wrap">
            <table class="table table-sm table-bordered table-striped align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Student Name</th>
                        <th>Program</th>
                        <th>Roster</th>
                        <th>Total Attendance Hours</th>
                        <th>Attendance %</th>
                        <th>PRE/POST Status</th>
                        <th>Last Attendance Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $index => $student): ?>
                        <?php
                        $preTests = (int) $student['pre_tests'];
                        $postTests = (int) $student['post_tests'];
                        $status = $preTests === 0 ? 'No PRE yet' : ($postTests === 0 ? 'Ready for POST' : 'PRE and POST tested');
                        $badge = $preTests === 0 ? 'bg-warning text-dark' : ($postTests === 0 ? 'bg-info text-dark' : 'bg-secondary');
                        ?>
                        <tr>
                            <td><?= e($index + 1) ?></td>
                            <td><b><?= e($student['canonical_name']) ?></b></td>
                            <td><?= e($student['program_name']) ?></td>
                            <td><?= e($student['roster_name']) ?></td>
                            <td><span class="badge bg-primary"><?= e($student['total_hours']) ?></span></td>
                            <td><?= e($student['attendance_percent']) ?>%</td>
                            <td><span class="badge <?= e($badge) ?>"><?= e($status) ?></span></td>
                            <td><?= e($student['last_attendance_date'] ?: '') ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if ($totalEligible === 0): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">No eligible students found for the selected criteria.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
