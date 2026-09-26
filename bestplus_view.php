<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';

$message = "";

function testTypeExpr() {
    return "COALESCE(pre_post, CASE WHEN UPPER(test_type) LIKE '%POST%' THEN 'POST' ELSE 'PRE' END)";
}

/* DELETE */
if (isset($_GET['delete_id'])) {
    $id = intval($_GET['delete_id']);

    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM test_results WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: bestplus_view.php?deleted=1");
        exit;
    }
}

if (isset($_GET['deleted'])) {
    $message = "<div class='alert alert-success'>Record deleted successfully.</div>";
}

/* UPDATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_result'])) {
    $id = intval($_POST['id']);
    $student_name = trim($_POST['student_name']);
    $test_date = trim($_POST['test_date']);
    $pre_post = trim($_POST['pre_post']);
    $admin_first_name = trim($_POST['admin_first_name']);
    $scale_score = intval($_POST['scale_score']);
    $interpretation1 = trim($_POST['interpretation1']);
    $start_time = trim($_POST['start_time']);
    $end_time = trim($_POST['end_time']);
    $comments = trim($_POST['comments']);

    $duration = null;

    if ($start_time && $end_time) {
        $s = strtotime($start_time);
        $e = strtotime($end_time);

        if ($e < $s) {
            $e += 86400;
        }

        $duration = round(($e - $s) / 60, 2);
    }

    $stmt = $pdo->prepare("
        UPDATE test_results
        SET student_name = ?,
            test_date = ?,
            pre_post = ?,
            admin_first_name = ?,
            scale_score = ?,
            score = ?,
            interpretation1 = ?,
            start_time = ?,
            end_time = ?,
            duration_minutes = ?,
            comments = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $student_name,
        $test_date,
        $pre_post,
        $admin_first_name,
        $scale_score,
        $scale_score,
        $interpretation1,
        $start_time ?: null,
        $end_time ?: null,
        $duration,
        $comments,
        $id
    ]);

    header("Location: bestplus_view.php?updated=1");
    exit;
}

if (isset($_GET['updated'])) {
    $message = "<div class='alert alert-success'>Record updated successfully.</div>";
}

/* FILTERS */
$search = trim($_GET['search'] ?? '');
$tester = trim($_GET['tester'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$type = trim($_GET['type'] ?? '');
$studentProgressName = trim($_GET['student_progress'] ?? '');
$edit_id = intval($_GET['edit_id'] ?? 0);

$where = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $normalizedSearch = preg_replace('/\s+/', ' ', $search);
    $compactSearch = preg_replace('/\s+/', '', $search);

    $where .= "
        AND (
            LOWER(student_name) LIKE LOWER(?)
            OR LOWER(REPLACE(student_name, ' ', '')) LIKE LOWER(?)
            OR LOWER(registration_no) LIKE LOWER(?)
            OR LOWER(REPLACE(registration_no, ' ', '')) LIKE LOWER(?)
        )
    ";
    $params[] = '%' . $normalizedSearch . '%';
    $params[] = '%' . $compactSearch . '%';
    $params[] = '%' . $normalizedSearch . '%';
    $params[] = '%' . $compactSearch . '%';
}

if ($tester !== '') {
    $where .= " AND admin_first_name = ?";
    $params[] = $tester;
}

if ($date_from !== '') {
    $where .= " AND test_date >= ?";
    $params[] = $date_from;
}

if ($date_to !== '') {
    $where .= " AND test_date <= ?";
    $params[] = $date_to;
}

if ($type !== '') {
    $where .= " AND " . testTypeExpr() . " = ?";
    $params[] = $type;
}

/* STUDENT LIST FOR DYNAMIC SEARCH */
$studentsList = $pdo->query("
    SELECT DISTINCT student_name
    FROM test_results
    WHERE student_name IS NOT NULL
      AND student_name != ''
    ORDER BY student_name ASC
")->fetchAll(PDO::FETCH_COLUMN);

/* EXPORT CSV */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare("
        SELECT 
            student_name,
            test_date,
            " . testTypeExpr() . " AS pre_post,
            admin_first_name,
            scale_score,
            interpretation1,
            start_time,
            end_time,
            duration_minutes,
            comments
        FROM test_results
        $where
        ORDER BY test_date DESC, student_name ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename=\"bestplus_results.csv\"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student', 'Date', 'PRE/POST', 'Tester', 'Scale Score', 'NRS Level', 'Start', 'End', 'Duration', 'Comments']);

    foreach ($rows as $r) {
        fputcsv($out, $r);
    }

    fclose($out);
    exit;
}

/* TESTERS */
$testers = $pdo->query("
    SELECT DISTINCT admin_first_name
    FROM test_results
    WHERE admin_first_name IS NOT NULL
      AND admin_first_name != ''
    ORDER BY admin_first_name
")->fetchAll(PDO::FETCH_COLUMN);

/* RECORDS */
$stmt = $pdo->prepare("
    SELECT *,
        " . testTypeExpr() . " AS computed_pre_post
    FROM test_results
    $where
    ORDER BY test_date DESC, student_name ASC
    LIMIT 500
");
$stmt->execute($params);
$records = $stmt->fetchAll();

/* EDIT RECORD */
$editRecord = null;

if ($edit_id > 0) {
    $stmt = $pdo->prepare("
        SELECT *, " . testTypeExpr() . " AS computed_pre_post
        FROM test_results
        WHERE id = ?
    ");
    $stmt->execute([$edit_id]);
    $editRecord = $stmt->fetch();
}

/* STUDENT PROGRESS */
$progressRecords = [];

if ($studentProgressName !== '') {
    $stmt = $pdo->prepare("
        SELECT 
            test_date,
            COALESCE(scale_score, score) AS score_value,
            " . testTypeExpr() . " AS test_kind,
            interpretation1,
            duration_minutes,
            admin_first_name
        FROM test_results
        WHERE student_name = ?
        ORDER BY test_date ASC
    ");
    $stmt->execute([$studentProgressName]);
    $progressRecords = $stmt->fetchAll();
}

$progressGain = null;

if (count($progressRecords) >= 2) {
    $firstScore = intval($progressRecords[0]['score_value']);
    $lastScore = intval($progressRecords[count($progressRecords) - 1]['score_value']);
    $progressGain = $lastScore - $firstScore;
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>BEST Plus Results</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body { background:#f4f6f9; }
        .card {
            border:0;
            border-radius:16px;
            box-shadow:0 8px 22px rgba(0,0,0,.06);
        }
        .table-wrap {
            max-height:560px;
            overflow-y:auto;
        }
        .kpi-value {
            font-size: 1.8rem;
            font-weight: 800;
        }
        @media print {
            .no-print { display:none !important; }
            body { background:white; }
            .card { box-shadow:none; border:1px solid #ccc; }
        }
    </style>
</head>

<body>

<div class="container-fluid app-page">

    <div class="no-print">
        <?php require __DIR__ . '/includes/navbar.php'; ?>
    </div>

    <div class="page-header no-print d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h3 class="page-title">BEST Plus Results</h3>
            <small class="page-subtitle">Review, filter, export, and update BEST Plus test records.</small>
        </div>
        <button onclick="window.print()" class="btn btn-dark btn-sm">Export PDF / Print</button>
    </div>

    <?= $message ?>

    <?php if ($studentProgressName !== '' && count($progressRecords) > 0): ?>
        <div class="card mb-4">
            <div class="card-header bg-info text-white fw-bold d-flex justify-content-between">
                <span>BEST Plus Progress — <?= htmlspecialchars($studentProgressName) ?></span>
                <a href="bestplus_view.php" class="btn btn-light btn-sm no-print">Close Progress</a>
            </div>

            <div class="card-body">

                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <div class="card bg-light">
                            <div class="card-body">
                                <small class="text-muted">Tests Found</small>
                                <div class="kpi-value"><?= count($progressRecords) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card bg-light">
                            <div class="card-body">
                                <small class="text-muted">First Score</small>
                                <div class="kpi-value"><?= htmlspecialchars($progressRecords[0]['score_value']) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card bg-light">
                            <div class="card-body">
                                <small class="text-muted">Last Score</small>
                                <div class="kpi-value"><?= htmlspecialchars($progressRecords[count($progressRecords)-1]['score_value']) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card bg-light">
                            <div class="card-body">
                                <small class="text-muted">Gain</small>
                                <div class="kpi-value <?= ($progressGain ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= $progressGain !== null ? htmlspecialchars($progressGain) : 'N/A' ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <canvas id="studentProgressChart" height="95"></canvas>

                <div class="table-responsive mt-3">
                    <table class="table table-sm table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Score</th>
                                <th>NRS Level</th>
                                <th>Tester</th>
                                <th>Duration</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($progressRecords as $pr): ?>
                                <tr>
                                    <td><?= htmlspecialchars($pr['test_date']) ?></td>
                                    <td><span class="badge bg-primary"><?= htmlspecialchars($pr['test_kind']) ?></span></td>
                                    <td><?= htmlspecialchars($pr['score_value']) ?></td>
                                    <td><?= htmlspecialchars($pr['interpretation1']) ?></td>
                                    <td><?= htmlspecialchars($pr['admin_first_name']) ?></td>
                                    <td><?= htmlspecialchars($pr['duration_minutes']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    <?php elseif ($studentProgressName !== ''): ?>
        <div class="alert alert-warning">
            No progress records found for <?= htmlspecialchars($studentProgressName) ?>.
        </div>
    <?php endif; ?>

    <div class="row g-3">

        <div class="col-md-4 no-print">

            <div class="card mb-3">
                <div class="card-header bg-white fw-bold">Dynamic Search / Filters</div>

                <div class="card-body">
                    <form method="GET" class="row g-2">

                        <div class="col-md-12">
                            <label class="form-label">Student</label>
                            <input type="text"
                                   name="search"
                                   value="<?= htmlspecialchars($search) ?>"
                                   class="form-control"
                                   list="students_list"
                                   placeholder="Type student name...">

                            <datalist id="students_list">
                                <?php foreach ($studentsList as $st): ?>
                                    <option value="<?= htmlspecialchars($st) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Tester</label>
                            <select name="tester" class="form-select">
                                <option value="">All testers</option>

                                <?php foreach ($testers as $t): ?>
                                    <option value="<?= htmlspecialchars($t) ?>" <?= $tester == $t ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t) ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">From</label>
                            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">To</label>
                            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="form-control">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">PRE / POST</label>
                            <select name="type" class="form-select">
                                <option value="">All</option>
                                <option value="PRE" <?= $type == 'PRE' ? 'selected' : '' ?>>PRE</option>
                                <option value="POST" <?= $type == 'POST' ? 'selected' : '' ?>>POST</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <button class="btn btn-primary w-100">Apply</button>
                        </div>

                        <div class="col-md-6">
                            <a href="bestplus_view.php" class="btn btn-outline-secondary w-100">Reset</a>
                        </div>

                        <div class="col-md-12">
                            <a class="btn btn-success w-100"
                               href="bestplus_view.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>">
                                Export Excel CSV
                            </a>
                        </div>

                    </form>
                </div>
            </div>

            <?php if ($editRecord): ?>
                <div class="card">
                    <div class="card-header bg-warning fw-bold">Edit Result</div>

                    <div class="card-body">
                        <form method="POST">

                            <input type="hidden" name="update_result" value="1">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($editRecord['id']) ?>">

                            <label class="form-label">Student</label>
                            <input name="student_name" class="form-control mb-2" value="<?= htmlspecialchars($editRecord['student_name']) ?>">

                            <label class="form-label">Date</label>
                            <input type="date" name="test_date" class="form-control mb-2" value="<?= htmlspecialchars($editRecord['test_date']) ?>">

                            <label class="form-label">PRE / POST</label>
                            <select name="pre_post" class="form-select mb-2">
                                <option value="PRE" <?= $editRecord['computed_pre_post'] == 'PRE' ? 'selected' : '' ?>>PRE</option>
                                <option value="POST" <?= $editRecord['computed_pre_post'] == 'POST' ? 'selected' : '' ?>>POST</option>
                            </select>

                            <label class="form-label">Tester</label>
                            <input name="admin_first_name" class="form-control mb-2" value="<?= htmlspecialchars($editRecord['admin_first_name']) ?>">

                            <label class="form-label">Scale Score</label>
                            <input type="number" name="scale_score" class="form-control mb-2" value="<?= htmlspecialchars($editRecord['scale_score'] ?: $editRecord['score']) ?>">

                            <label class="form-label">NRS Level</label>
                            <input name="interpretation1" class="form-control mb-2" value="<?= htmlspecialchars($editRecord['interpretation1']) ?>">

                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label">Start</label>
                                    <input type="time" name="start_time" class="form-control mb-2" value="<?= htmlspecialchars($editRecord['start_time']) ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">End</label>
                                    <input type="time" name="end_time" class="form-control mb-2" value="<?= htmlspecialchars($editRecord['end_time']) ?>">
                                </div>
                            </div>

                            <label class="form-label">Comments</label>
                            <textarea name="comments" class="form-control mb-3"><?= htmlspecialchars($editRecord['comments'] ?? '') ?></textarea>

                            <button class="btn btn-warning w-100">Update</button>
                            <a href="bestplus_view.php" class="btn btn-outline-secondary w-100 mt-2">Cancel</a>

                        </form>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <div class="col-md-8">

            <div class="card">
                <div class="card-header bg-white fw-bold d-flex justify-content-between">
                    <span>Results</span>
                    <span><?= count($records) ?> records</span>
                </div>

                <div class="table-wrap">
                    <table class="table table-sm table-bordered table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Type</th>
                                <th>Tester</th>
                                <th>Score</th>
                                <th>NRS Level</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Duration</th>
                                <th class="no-print">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($records as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['test_date']) ?></td>

                                    <td><?= htmlspecialchars($r['student_name']) ?></td>

                                    <td>
                                        <span class="badge bg-primary">
                                            <?= htmlspecialchars($r['computed_pre_post']) ?>
                                        </span>
                                    </td>

                                    <td><?= htmlspecialchars($r['admin_first_name']) ?></td>

                                    <td><?= htmlspecialchars($r['scale_score'] ?: $r['score']) ?></td>

                                    <td><?= htmlspecialchars($r['interpretation1']) ?></td>

                                    <td><?= htmlspecialchars($r['start_time']) ?></td>

                                    <td><?= htmlspecialchars($r['end_time']) ?></td>

                                    <td><?= htmlspecialchars($r['duration_minutes']) ?></td>

                                    <td class="no-print">
                                        <a href="bestplus_view.php?student_progress=<?= urlencode($r['student_name']) ?>" 
                                           class="btn btn-sm btn-info">
                                           Progress
                                        </a>

                                        <a href="bestplus_view.php?edit_id=<?= htmlspecialchars($r['id']) ?>" 
                                           class="btn btn-sm btn-warning">
                                           Edit
                                        </a>

                                        <a href="bestplus_view.php?delete_id=<?= htmlspecialchars($r['id']) ?>"
                                           onclick="return confirm('Delete this BEST Plus result?');"
                                           class="btn btn-sm btn-danger">
                                           Delete
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (!$records): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted">
                                        No records found.
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

<?php if ($studentProgressName !== '' && count($progressRecords) > 0): ?>
<script>
new Chart(document.getElementById('studentProgressChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($progressRecords, 'test_date')) ?>,
        datasets: [{
            label: 'Scale Score',
            data: <?= json_encode(array_column($progressRecords, 'score_value')) ?>,
            tension: 0.3,
            pointRadius: 5,
            borderWidth: 3
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                display: true
            }
        },
        scales: {
            y: {
                beginAtZero: false
            }
        }
    }
});
</script>
<?php endif; ?>

</body>
</html>
