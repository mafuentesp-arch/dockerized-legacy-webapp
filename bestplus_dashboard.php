<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';

function getSetting($pdo, $name, $default = '') {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_name = ? LIMIT 1");
    $stmt->execute([$name]);
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : $default;
}

$currentYear = getSetting($pdo, 'current_year', date('Y'));
$currentSeason = getSetting($pdo, 'current_season', 'Spring');
$search = cleanText($_GET['search'] ?? '', 150);
$filterPrePost = cleanText($_GET['pre_post'] ?? '', 10);
$filterTester = cleanText($_GET['admin_first_name'] ?? '', 100);
$filterSite = cleanText($_GET['site'] ?? '', 100);
$filterLevel = cleanText($_GET['level'] ?? '', 50);
$dateFrom = cleanText($_GET['date_from'] ?? '', 20);
$dateTo = cleanText($_GET['date_to'] ?? '', 20);

$testTypeExpr = "
    COALESCE(
        pre_post,
        CASE 
            WHEN UPPER(test_type) LIKE '%POST%' THEN 'POST'
            ELSE 'PRE'
        END
    )
";

$totalPre = $pdo->query("SELECT COUNT(*) FROM test_results WHERE $testTypeExpr = 'PRE'")->fetchColumn();
$totalPost = $pdo->query("SELECT COUNT(*) FROM test_results WHERE $testTypeExpr = 'POST'")->fetchColumn();

$avgScore = $pdo->query("
    SELECT ROUND(AVG(COALESCE(scale_score, score)), 1)
    FROM test_results
    WHERE COALESCE(scale_score, score) IS NOT NULL
")->fetchColumn();

$avgDuration = $pdo->query("
    SELECT ROUND(AVG(duration_minutes), 1)
    FROM test_results
    WHERE duration_minutes IS NOT NULL
")->fetchColumn();

$readyStmt = $pdo->prepare("
    SELECT 
        sm.student_id,
        sm.canonical_name,
        r.roster_name,
        p.program_name,
        SUM(CASE WHEN ar.present = 1 THEN 1 ELSE 0 END) AS attended_sessions
    FROM student_rosters sr
    INNER JOIN students_master sm ON sr.student_id = sm.student_id
    INNER JOIN rosters r ON sr.roster_id = r.roster_id
    INNER JOIN programs p ON r.program_id = p.program_id
    LEFT JOIN attendance_records ar 
        ON ar.student_id = sm.student_id 
       AND ar.roster_id = r.roster_id
    LEFT JOIN test_results tr 
        ON tr.student_id = sm.student_id
        OR LOWER(tr.student_name) = LOWER(sm.canonical_name)
    WHERE sr.status = 'active'
      AND r.year = ?
      AND r.season = ?
    GROUP BY sm.student_id, sm.canonical_name, r.roster_name, p.program_name
    HAVING attended_sessions >= 30
       AND SUM(CASE WHEN COALESCE(tr.pre_post, CASE WHEN UPPER(tr.test_type) LIKE '%POST%' THEN 'POST' ELSE 'PRE' END) = 'PRE' THEN 1 ELSE 0 END) = 0
    ORDER BY attended_sessions DESC
");
$readyStmt->execute([$currentYear, $currentSeason]);
$readyStudents = $readyStmt->fetchAll();

$pendingPostStmt = $pdo->query("
    SELECT 
        student_name,
        MAX(CASE WHEN $testTypeExpr = 'PRE' THEN COALESCE(scale_score, score) END) AS pre_score,
        MAX(CASE WHEN $testTypeExpr = 'POST' THEN COALESCE(scale_score, score) END) AS post_score
    FROM test_results
    GROUP BY student_name
    HAVING pre_score IS NOT NULL
       AND post_score IS NULL
    ORDER BY student_name ASC
    LIMIT 50
");
$pendingPost = $pendingPostStmt->fetchAll();

$gainStmt = $pdo->query("
    SELECT 
        student_name,
        MIN(CASE WHEN $testTypeExpr = 'PRE' THEN COALESCE(scale_score, score) END) AS pre_score,
        MAX(CASE WHEN $testTypeExpr = 'POST' THEN COALESCE(scale_score, score) END) AS post_score,
        MAX(CASE WHEN $testTypeExpr = 'POST' THEN COALESCE(scale_score, score) END)
        -
        MIN(CASE WHEN $testTypeExpr = 'PRE' THEN COALESCE(scale_score, score) END) AS gain_score
    FROM test_results
    GROUP BY student_name
    HAVING pre_score IS NOT NULL
       AND post_score IS NOT NULL
    ORDER BY gain_score DESC
    LIMIT 50
");
$gainAnalysis = $gainStmt->fetchAll();

$testerStats = $pdo->query("
    SELECT 
        admin_first_name AS tester,
        COUNT(*) AS total_tests,
        ROUND(AVG(COALESCE(scale_score, score)), 1) AS avg_score,
        ROUND(AVG(duration_minutes), 1) AS avg_duration
    FROM test_results
    GROUP BY admin_first_name
    ORDER BY total_tests DESC
")->fetchAll();

$labels = array_column($testerStats, 'tester');
$values = array_column($testerStats, 'total_tests');

$testWhere = [];
$testParams = [];

if ($search !== '') {
    $testWhere[] = "(student_name LIKE ? OR registration_no LIKE ?)";
    $testParams[] = '%' . $search . '%';
    $testParams[] = '%' . $search . '%';
}

if (in_array($filterPrePost, ['PRE', 'POST'], true)) {
    $testWhere[] = "$testTypeExpr = ?";
    $testParams[] = $filterPrePost;
}

if ($filterTester !== '') {
    $testWhere[] = "admin_first_name = ?";
    $testParams[] = $filterTester;
}

if ($filterSite !== '') {
    $testWhere[] = "site = ?";
    $testParams[] = $filterSite;
}

if ($filterLevel !== '') {
    $testWhere[] = "interpretation1 = ?";
    $testParams[] = $filterLevel;
}

if ($dateFrom !== '') {
    $testWhere[] = "test_date >= ?";
    $testParams[] = $dateFrom;
}

if ($dateTo !== '') {
    $testWhere[] = "test_date <= ?";
    $testParams[] = $dateTo;
}

$testWhereSql = $testWhere ? 'WHERE ' . implode(' AND ', $testWhere) : '';

$testStmt = $pdo->prepare("
    SELECT
        id,
        student_name,
        registration_no,
        test_date,
        $testTypeExpr AS computed_pre_post,
        interpretation1 AS nrs_level,
        COALESCE(scale_score, score) AS score_value,
        admin_first_name,
        duration_minutes,
        site
    FROM test_results
    $testWhereSql
    ORDER BY test_date DESC, student_name ASC
    LIMIT 200
");
$testStmt->execute($testParams);
$matchingTests = $testStmt->fetchAll();

$testers = $pdo->query("
    SELECT DISTINCT admin_first_name
    FROM test_results
    WHERE admin_first_name IS NOT NULL
      AND admin_first_name <> ''
    ORDER BY admin_first_name ASC
")->fetchAll(PDO::FETCH_COLUMN);

$sites = $pdo->query("
    SELECT DISTINCT site
    FROM test_results
    WHERE site IS NOT NULL
      AND site <> ''
    ORDER BY site ASC
")->fetchAll(PDO::FETCH_COLUMN);

$levels = $pdo->query("
    SELECT DISTINCT interpretation1
    FROM test_results
    WHERE interpretation1 IS NOT NULL
      AND interpretation1 <> ''
    ORDER BY interpretation1 ASC
")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>BEST Plus Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background:#f4f6f9; }
        .card { border:0; border-radius:18px; box-shadow:0 8px 22px rgba(0,0,0,.06); }
        .kpi { font-size:2rem; font-weight:800; }
        .table-wrap { max-height:390px; overflow-y:auto; }
    </style>
</head>
<body>

<div class="container-fluid app-page">
    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">BEST Plus Dashboard</h3>
        <small class="page-subtitle">Testing analytics and recent BEST Plus results.</small>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card"><div class="card-body"><small>Total PRE Tests</small><div class="kpi text-primary"><?= $totalPre ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><small>Total POST Tests</small><div class="kpi text-success"><?= $totalPost ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><small>Average Scale Score</small><div class="kpi text-dark"><?= $avgScore ?: 0 ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><small>Average Duration</small><div class="kpi text-info"><?= $avgDuration ?: 0 ?> min</div></div></div></div>
    </div>

    <form method="GET" class="card mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-bold">Search student or registration</label>
                <input name="search" class="form-control" value="<?= e($search) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold">PRE / POST</label>
                <select name="pre_post" class="form-select">
                    <option value="">All</option>
                    <option value="PRE" <?= $filterPrePost === 'PRE' ? 'selected' : '' ?>>PRE</option>
                    <option value="POST" <?= $filterPrePost === 'POST' ? 'selected' : '' ?>>POST</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold">Tester</label>
                <select name="admin_first_name" class="form-select">
                    <option value="">All testers</option>
                    <?php foreach ($testers as $tester): ?>
                        <option value="<?= e($tester) ?>" <?= $filterTester === $tester ? 'selected' : '' ?>>
                            <?= e($tester) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold">Site</label>
                <select name="site" class="form-select">
                    <option value="">All sites</option>
                    <?php foreach ($sites as $site): ?>
                        <option value="<?= e($site) ?>" <?= $filterSite === $site ? 'selected' : '' ?>>
                            <?= e($site) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold">NRS Level</label>
                <select name="level" class="form-select">
                    <option value="">All NRS levels</option>
                    <?php foreach ($levels as $level): ?>
                        <option value="<?= e($level) ?>" <?= $filterLevel === $level ? 'selected' : '' ?>>
                            <?= e($level) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold">Date from</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold">Date to</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
            </div>

            <div class="col-md-2">
                <button class="btn btn-primary w-100">Filter</button>
            </div>

            <div class="col-md-2">
                <a href="bestplus_dashboard.php" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </div>
    </form>

    <div class="card mb-4">
        <div class="card-header bg-white fw-bold d-flex justify-content-between">
            <span>Recent / Matching BEST Plus Tests</span>
            <span class="text-muted">Showing <?= e(count($matchingTests)) ?> of 200 max</span>
        </div>

        <div class="table-wrap">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>Student</th>
                        <th>Registration</th>
                        <th>Date</th>
                        <th>PRE/POST</th>
                        <th>NRS Level</th>
                        <th>Scale Score</th>
                        <th>Tester</th>
                        <th>Duration</th>
                        <th>Site</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matchingTests as $test): ?>
                        <tr>
                            <td><?= e($test['student_name']) ?></td>
                            <td><?= e($test['registration_no']) ?></td>
                            <td><?= e($test['test_date']) ?></td>
                            <td><span class="badge bg-secondary"><?= e($test['computed_pre_post']) ?></span></td>
                            <td><?= e($test['nrs_level']) ?></td>
                            <td><?= e($test['score_value']) ?></td>
                            <td><?= e($test['admin_first_name']) ?></td>
                            <td><?= e($test['duration_minutes']) ?></td>
                            <td><?= e($test['site']) ?></td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" href="bestplus_view.php?id=<?= e($test['id']) ?>">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$matchingTests): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">No BEST Plus tests found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-white fw-bold">Testing by Tester</div>
                <div class="card-body"><canvas id="testerChart" height="150"></canvas></div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-white fw-bold">Students Ready for Testing / No PRE Yet</div>
                <div class="card-body table-wrap">
                    <table class="table table-sm table-bordered">
                        <thead class="table-dark"><tr><th>Student</th><th>Roster</th><th>Sessions</th></tr></thead>
                        <tbody>
                        <?php foreach ($readyStudents as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['canonical_name']) ?></td>
                                <td><?= htmlspecialchars($r['program_name']) ?> — <?= htmlspecialchars($r['roster_name']) ?></td>
                                <td><span class="badge bg-success"><?= htmlspecialchars($r['attended_sessions']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$readyStudents): ?><tr><td colspan="3" class="text-center text-muted">No students ready.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-warning fw-bold">Students Pending POST</div>
                <div class="card-body table-wrap">
                    <table class="table table-sm table-bordered">
                        <thead class="table-dark"><tr><th>Student</th><th>PRE Score</th></tr></thead>
                        <tbody>
                        <?php foreach ($pendingPost as $p): ?>
                            <tr><td><?= htmlspecialchars($p['student_name']) ?></td><td><?= htmlspecialchars($p['pre_score']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$pendingPost): ?><tr><td colspan="2" class="text-center text-muted">No pending POST records.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-success text-white fw-bold">Gain Score Analysis</div>
                <div class="card-body table-wrap">
                    <table class="table table-sm table-bordered">
                        <thead class="table-dark"><tr><th>Student</th><th>PRE</th><th>POST</th><th>Gain</th></tr></thead>
                        <tbody>
                        <?php foreach ($gainAnalysis as $g): ?>
                            <tr>
                                <td><?= htmlspecialchars($g['student_name']) ?></td>
                                <td><?= htmlspecialchars($g['pre_score']) ?></td>
                                <td><?= htmlspecialchars($g['post_score']) ?></td>
                                <td><span class="badge bg-primary"><?= htmlspecialchars($g['gain_score']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$gainAnalysis): ?><tr><td colspan="4" class="text-center text-muted">No PRE/POST gains yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
new Chart(document.getElementById('testerChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [{
            label: 'Total Tests',
            data: <?= json_encode($values) ?>
        }]
    },
    options: { responsive:true, plugins:{legend:{display:false}} }
});
</script>

</body>
</html>

