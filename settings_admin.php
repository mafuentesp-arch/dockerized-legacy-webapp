<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['admin']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';

if (file_exists(__DIR__ . '/includes/audit.php')) {
    require_once __DIR__ . '/includes/audit.php';
}

ensure_session_started();

$seasons = ['Winter', 'Spring', 'Summer', 'Fall'];
$message = '';

function getSettingValue(PDO $pdo, string $name, string $default = ''): string
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

function saveSettingValue(PDO $pdo, string $name, string $value): void
{
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_name, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$name, $value]);
}

$currentYear = getSettingValue($pdo, 'current_year', date('Y'));
$currentSeason = getSettingValue($pdo, 'current_season', 'Spring');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $newYear = cleanInt($_POST['current_year'] ?? 0);
    $newSeason = cleanText($_POST['current_season'] ?? '', 20);

    if ($newYear < 2020 || $newYear > 2100) {
        $message = "<div class='alert alert-danger'>Current year must be between 2020 and 2100.</div>";
    } elseif (!in_array($newSeason, $seasons, true)) {
        $message = "<div class='alert alert-danger'>Invalid season selected.</div>";
    } else {
        saveSettingValue($pdo, 'current_year', (string) $newYear);
        saveSettingValue($pdo, 'current_season', $newSeason);

        if (function_exists('logActivity')) {
            logActivity('UPDATE', 'system_settings', null, 'Updated current cycle to ' . $newSeason . ' ' . $newYear);
        }

        header('Location: settings_admin.php?updated=1');
        exit;
    }
}

if (isset($_GET['updated'])) {
    $message = "<div class='alert alert-success'>Settings updated successfully.</div>";
    $currentYear = getSettingValue($pdo, 'current_year', date('Y'));
    $currentSeason = getSettingValue($pdo, 'current_season', 'Spring');
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Settings Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f4f6f9; }
        .card {
            border:0;
            border-radius:16px;
            box-shadow:0 8px 22px rgba(0,0,0,.06);
        }
    </style>
</head>

<body>
<div class="container-fluid app-page">

    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="page-header">
        <h3 class="page-title">Settings Admin</h3>
        <small class="page-subtitle">Manage the active ESOL cycle.</small>
    </div>

    <?= $message ?>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header bg-white fw-bold">Current Cycle</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                        <label class="form-label fw-bold">Current Season</label>
                        <select name="current_season" class="form-select mb-3" required>
                            <?php foreach ($seasons as $season): ?>
                                <option value="<?= e($season) ?>" <?= $currentSeason === $season ? 'selected' : '' ?>>
                                    <?= e($season) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label class="form-label fw-bold">Current Year</label>
                        <input type="number"
                               name="current_year"
                               class="form-control mb-3"
                               min="2020"
                               max="2100"
                               required
                               value="<?= e($currentYear) ?>">

                        <button class="btn btn-primary w-100">Update Settings</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header bg-white fw-bold">Current Settings</div>
                <div class="card-body">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Setting</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>current_season</td>
                                <td><?= e($currentSeason) ?></td>
                            </tr>
                            <tr>
                                <td>current_year</td>
                                <td><?= e($currentYear) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
