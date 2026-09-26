<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['admin']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';

if (file_exists(__DIR__ . '/includes/audit.php')) {
    require_once __DIR__ . '/includes/audit.php';
}

$message = '';

function findMysqldumpBinary(): ?string
{
    $candidates = [
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        'C:\\xampp\\mysql\\bin\\mysqldump',
        'mysqldump',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === 'mysqldump') {
            return $candidate;
        }

        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function createMysqlDefaultsFile(string $host, string $username, string $password): string
{
    $path = tempnam(sys_get_temp_dir(), 'mysql_backup_');
    if ($path === false) {
        throw new RuntimeException('Unable to prepare backup credentials.');
    }

    $contents = "[client]\n"
        . "host=" . $host . "\n"
        . "user=" . $username . "\n";

    if ($password !== '') {
        $contents .= "password=" . $password . "\n";
    }

    if (file_put_contents($path, $contents) === false) {
        @unlink($path);
        throw new RuntimeException('Unable to prepare backup credentials.');
    }

    @chmod($path, 0600);

    return $path;
}

function runDatabaseBackup(string $mysqldump, string $defaultsFile, string $dbname): string
{
    $command = [
        $mysqldump,
        '--defaults-extra-file=' . $defaultsFile,
        '--single-transaction',
        '--routines',
        '--triggers',
        '--databases',
        $dbname,
    ];

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start database backup.');
    }

    fclose($pipes[0]);
    $sql = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0 || trim((string) $sql) === '') {
        error_log('Database backup failed: ' . trim((string) $error));
        throw new RuntimeException('Database backup failed. Please verify mysqldump is available and database credentials are valid.');
    }

    return (string) $sql;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['download_backup'] ?? '') === '1') {
    verify_csrf();

    $defaultsFile = null;

    try {
        $mysqldump = findMysqldumpBinary();
        if ($mysqldump === null) {
            throw new RuntimeException('mysqldump was not found on this server.');
        }

        $defaultsFile = createMysqlDefaultsFile((string) $host, (string) $username, (string) $password);
        $sql = runDatabaseBackup($mysqldump, $defaultsFile, (string) $dbname);

        if (function_exists('logActivity')) {
            logActivity('BACKUP', 'database', null, 'Downloaded database backup');
        }

        $filename = 'esol_backup_' . date('Ymd_His') . '.sql';

        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        header('X-Content-Type-Options: nosniff');

        echo $sql;
        exit;
    } catch (Throwable $e) {
        $message = "<div class='alert alert-danger'>" . e($e->getMessage()) . "</div>";
    } finally {
        if ($defaultsFile !== null && is_file($defaultsFile)) {
            @unlink($defaultsFile);
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Database Backup</title>
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
        <h3 class="page-title">Database Backup</h3>
        <small class="page-subtitle">Download an admin-only MySQL backup.</small>
    </div>

    <?= $message ?>

    <div class="card">
        <div class="card-header bg-white fw-bold">Download MySQL Backup</div>
        <div class="card-body">
            <p class="text-muted">
                Generate a SQL backup of the ESOL database. Database credentials are not displayed.
            </p>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="download_backup" value="1">

                <button class="btn btn-primary">Download Database Backup</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
