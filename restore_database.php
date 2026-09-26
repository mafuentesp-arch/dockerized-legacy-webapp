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
$maxUploadBytes = 100 * 1024 * 1024;

function findMysqlBinary(): ?string
{
    $candidates = [
        'C:\\xampp\\mysql\\bin\\mysql.exe',
        'C:\\xampp\\mysql\\bin\\mysql',
        'mysql',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === 'mysql') {
            return $candidate;
        }

        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function createMysqlRestoreDefaultsFile(string $host, string $username, string $password): string
{
    $path = tempnam(sys_get_temp_dir(), 'mysql_restore_');
    if ($path === false) {
        throw new RuntimeException('Unable to prepare restore credentials.');
    }

    $contents = "[client]\n"
        . "host=" . $host . "\n"
        . "user=" . $username . "\n";

    if ($password !== '') {
        $contents .= "password=" . $password . "\n";
    }

    if (file_put_contents($path, $contents) === false) {
        @unlink($path);
        throw new RuntimeException('Unable to prepare restore credentials.');
    }

    @chmod($path, 0600);

    return $path;
}

function runDatabaseRestore(string $mysql, string $defaultsFile, string $dbname, string $sqlPath): void
{
    $command = [
        $mysql,
        '--defaults-extra-file=' . $defaultsFile,
        $dbname,
    ];

    $descriptorSpec = [
        0 => ['file', $sqlPath, 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start database restore.');
    }

    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        error_log('Database restore failed: ' . trim((string) $output . "\n" . (string) $error));
        throw new RuntimeException('Database restore failed. Please verify the SQL file and database credentials.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['restore_database'] ?? '') === '1') {
    verify_csrf();

    $defaultsFile = null;

    try {
        if (($_POST['confirm_restore'] ?? '') !== '1') {
            throw new RuntimeException('Please confirm that you understand this will restore the database.');
        }

        if (!isset($_FILES['sql_file'])) {
            throw new RuntimeException('Please upload a SQL backup file.');
        }

        if ($_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The SQL file upload failed. Please try again.');
        }

        $originalName = (string) ($_FILES['sql_file']['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension !== 'sql') {
            throw new RuntimeException('Only .sql backup files are allowed.');
        }

        $fileSize = (int) ($_FILES['sql_file']['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > $maxUploadBytes) {
            throw new RuntimeException('The SQL file size is not valid for restore.');
        }

        $tmpPath = (string) ($_FILES['sql_file']['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('The uploaded SQL file could not be verified.');
        }

        $mysql = findMysqlBinary();
        if ($mysql === null) {
            throw new RuntimeException('mysql command line client was not found on this server.');
        }

        $defaultsFile = createMysqlRestoreDefaultsFile((string) $host, (string) $username, (string) $password);
        runDatabaseRestore($mysql, $defaultsFile, (string) $dbname, $tmpPath);

        if (function_exists('logActivity')) {
            logActivity('RESTORE', 'database', null, 'Restored database from backup file ' . $originalName);
        }

        $message = "<div class='alert alert-success'>Database restored successfully.</div>";
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
    <title>Database Restore</title>
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
        <h3 class="page-title">Database Restore</h3>
        <small class="page-subtitle">Restore the ESOL database from a SQL backup file.</small>
    </div>

    <?= $message ?>

    <div class="card mb-4 border-danger">
        <div class="card-header bg-danger text-white fw-bold">Restore Warning</div>
        <div class="card-body">
            <p class="mb-0">
                Restoring a database can overwrite current data. Confirm that the selected backup file is correct before continuing.
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white fw-bold">Upload SQL Backup</div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="restore_database" value="1">

                <div class="mb-3">
                    <label class="form-label fw-bold">SQL backup file</label>
                    <input type="file" name="sql_file" class="form-control" accept=".sql" required>
                    <small class="text-muted">Maximum file size: <?= e(number_format($maxUploadBytes / 1024 / 1024)) ?> MB</small>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="confirm_restore" value="1" id="confirm_restore" required>
                    <label class="form-check-label fw-bold" for="confirm_restore">
                        I understand this will restore the database.
                    </label>
                </div>

                <button class="btn btn-danger">Restore Database</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
