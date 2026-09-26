<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/audit.php';
ensure_session_started();

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$username = '';
$timeoutMessage = isset($_GET['timeout']) && $_GET['timeout'] === '1'
    ? 'Your session expired due to inactivity.'
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    

	$username = trim($_POST['username'] ?? '');
	$password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'username and password are required.';
    } else {
        $stmt = $pdo->prepare("
			SELECT user_id, username, password, role
			FROM users
			WHERE username = ?
			LIMIT 1
		");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $error = 'Invalid login.';
        } else {
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['user_id'];
          //  $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['last_activity'] = time();

            logActivity('LOGIN', 'users', (int) $user['user_id'], 'User ' . $user['username'] . ' logged in');

            header('Location: dashboard.php');
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f4f6f9; }
        .login-card {
            max-width:420px;
            border:0;
            border-radius:16px;
            box-shadow:0 8px 22px rgba(0,0,0,.06);
        }
    </style>
</head>

<body>
<div class="container min-vh-100 d-flex align-items-center justify-content-center">
    <div class="card login-card w-100">
        <div class="card-body p-4">
            <h3 class="mb-1">Demo Student Hub</h3>
            <p class="text-muted mb-4">Sign in to continue</p>

            <?php if ($timeoutMessage !== ''): ?>
                <div class="alert alert-warning"><?= e($timeoutMessage) ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <label class="form-label fw-bold">Username</label>
                <input type="text"
                       name="username"
                       class="form-control mb-3"
                       required
                       value="<?= e($username) ?>">

                <label class="form-label fw-bold">Password</label>
                <input type="password"
                       name="password"
                       class="form-control mb-4"
                       required>

                <button class="btn btn-primary w-100">Login</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
