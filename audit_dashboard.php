<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['admin']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';
ensure_session_started();

$dateFrom = cleanText($_GET['date_from'] ?? '', 20);
$dateTo = cleanText($_GET['date_to'] ?? '', 20);
$username = cleanText($_GET['username'] ?? '', 100);
$role = cleanText($_GET['role'] ?? '', 50);
$actionType = cleanText($_GET['action_type'] ?? '', 50);
$entityType = cleanText($_GET['entity_type'] ?? '', 100);
$ipAddress = cleanText($_GET['ip_address'] ?? '', 45);
$search = cleanText($_GET['search'] ?? '', 200);
$export = ($_GET['export'] ?? '') === 'csv';

$where = [];
$params = [];

if ($dateFrom !== '') {
    $where[] = 'created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $where[] = 'created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}

if ($username !== '') {
    $where[] = 'username LIKE ?';
    $params[] = '%' . $username . '%';
}

if ($role !== '') {
    $where[] = 'role = ?';
    $params[] = $role;
}

if ($actionType !== '') {
    $where[] = 'action_type = ?';
    $params[] = $actionType;
}

if ($entityType !== '') {
    $where[] = 'entity_type = ?';
    $params[] = $entityType;
}

if ($ipAddress !== '') {
    $where[] = 'ip_address LIKE ?';
    $params[] = '%' . $ipAddress . '%';
}

if ($search !== '') {
    $where[] = 'description LIKE ?';
    $params[] = '%' . $search . '%';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$kpis = [
    'total_logs' => (int) $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(),
    'today_logs' => (int) $pdo->query('SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
    'unique_users' => (int) $pdo->query('SELECT COUNT(DISTINCT user_id) FROM activity_logs WHERE user_id IS NOT NULL')->fetchColumn(),
    'login_count' => (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action_type = 'LOGIN'")->fetchColumn(),
    'create_count' => (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action_type = 'CREATE'")->fetchColumn(),
    'update_count' => (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action_type = 'UPDATE'")->fetchColumn(),
    'delete_count' => (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action_type = 'DELETE'")->fetchColumn(),
    'import_count' => (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action_type = 'IMPORT'")->fetchColumn(),
];

$roles = $pdo->query("
    SELECT DISTINCT role
    FROM activity_logs
    WHERE role IS NOT NULL AND role <> ''
    ORDER BY role ASC
")->fetchAll(PDO::FETCH_COLUMN);

$actionTypes = $pdo->query("
    SELECT DISTINCT action_type
    FROM activity_logs
    ORDER BY action_type ASC
")->fetchAll(PDO::FETCH_COLUMN);

$entityTypes = $pdo->query("
    SELECT DISTINCT entity_type
    FROM activity_logs
    ORDER BY entity_type ASC
")->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare("
    SELECT log_id, user_id, username, role, action_type, entity_type, entity_id, description, ip_address, user_agent, created_at
    FROM activity_logs
    $whereSql
    ORDER BY created_at DESC
    LIMIT 500
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity_logs.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['log_id', 'user_id', 'username', 'role', 'action_type', 'entity_type', 'entity_id', 'description', 'ip_address', 'user_agent', 'created_at']);

    foreach ($logs as $log) {
        fputcsv($out, [
            $log['log_id'],
            $log['user_id'],
            $log['username'],
            $log['role'],
            $log['action_type'],
            $log['entity_type'],
            $log['entity_id'],
            $log['description'],
            $log['ip_address'],
            $log['user_agent'],
            $log['created_at']
        ]);
    }

    fclose($out);
    exit;
}

function actionBadgeClass(string $actionType): string
{
    return match ($actionType) {
        'LOGIN' => 'bg-primary',
        'LOGOUT' => 'bg-secondary',
        'CREATE' => 'bg-success',
        'UPDATE' => 'bg-warning text-dark',
        'DELETE' => 'bg-danger',
        'IMPORT' => 'bg-info text-dark',
        'TRANSFER' => 'bg-dark',
        default => 'bg-secondary',
    };
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Audit Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f4f6f9; }
        .card {
            border:0;
            border-radius:16px;
            box-shadow:0 8px 22px rgba(0,0,0,.06);
        }
        .kpi-value {
            font-size:1.65rem;
            font-weight:800;
        }
        .table-wrap {
            max-height:680px;
            overflow:auto;
        }
    </style>
</head>

<body>
<div class="container-fluid app-page">

    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">Audit Dashboard</h3>
        <small class="page-subtitle">Review user activity and system events.</small>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-xl-1-5"><div class="card"><div class="card-body"><small class="text-muted">Total logs</small><div class="kpi-value text-primary"><?= e($kpis['total_logs']) ?></div></div></div></div>
        <div class="col-md-3 col-xl-1-5"><div class="card"><div class="card-body"><small class="text-muted">Today</small><div class="kpi-value text-success"><?= e($kpis['today_logs']) ?></div></div></div></div>
        <div class="col-md-3 col-xl-1-5"><div class="card"><div class="card-body"><small class="text-muted">Users</small><div class="kpi-value text-dark"><?= e($kpis['unique_users']) ?></div></div></div></div>
        <div class="col-md-3 col-xl-1-5"><div class="card"><div class="card-body"><small class="text-muted">Logins</small><div class="kpi-value text-primary"><?= e($kpis['login_count']) ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><small class="text-muted">Creates</small><div class="kpi-value text-success"><?= e($kpis['create_count']) ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><small class="text-muted">Updates</small><div class="kpi-value text-warning"><?= e($kpis['update_count']) ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><small class="text-muted">Deletes</small><div class="kpi-value text-danger"><?= e($kpis['delete_count']) ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><small class="text-muted">Imports</small><div class="kpi-value text-info"><?= e($kpis['import_count']) ?></div></div></div></div>
    </div>

    <form method="GET" class="card mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label fw-bold">From</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">To</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Username</label>
                <input name="username" class="form-control" value="<?= e($username) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Role</label>
                <select name="role" class="form-select">
                    <option value="">All roles</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= e($r) ?>" <?= $role === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Action</label>
                <select name="action_type" class="form-select">
                    <option value="">All actions</option>
                    <?php foreach ($actionTypes as $a): ?>
                        <option value="<?= e($a) ?>" <?= $actionType === $a ? 'selected' : '' ?>><?= e($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Entity</label>
                <select name="entity_type" class="form-select">
                    <option value="">All entities</option>
                    <?php foreach ($entityTypes as $entity): ?>
                        <option value="<?= e($entity) ?>" <?= $entityType === $entity ? 'selected' : '' ?>><?= e($entity) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">IP address</label>
                <input name="ip_address" class="form-control" value="<?= e($ipAddress) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">Search description</label>
                <input name="search" class="form-control" value="<?= e($search) ?>">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100">Filter</button>
            </div>
            <div class="col-md-2">
                <a class="btn btn-outline-secondary w-100" href="audit_dashboard.php">Reset</a>
            </div>
            <div class="col-md-2">
                <a class="btn btn-success w-100" href="audit_dashboard.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Export CSV</a>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-header bg-white fw-bold d-flex justify-content-between">
            <span>Activity Logs</span>
            <span class="text-muted">Showing <?= e(count($logs)) ?> latest records</span>
        </div>

        <div class="table-wrap">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>Created</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>ID</th>
                        <th>Description</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="text-nowrap"><?= e($log['created_at']) ?></td>
                            <td><?= e($log['username'] ?? 'system') ?></td>
                            <td><span class="badge bg-light text-dark border"><?= e($log['role'] ?? '') ?></span></td>
                            <td><span class="badge <?= e(actionBadgeClass((string) $log['action_type'])) ?>"><?= e($log['action_type']) ?></span></td>
                            <td><?= e($log['entity_type']) ?></td>
                            <td><?= e($log['entity_id']) ?></td>
                            <td><?= e($log['description']) ?></td>
                            <td><?= e($log['ip_address']) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (count($logs) === 0): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No activity logs found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
