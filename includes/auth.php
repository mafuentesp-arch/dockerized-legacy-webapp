<?php

if (!function_exists('auth_session_started')) {
    function auth_session_started(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}

if (!function_exists('auth_destroy_session')) {
    function auth_destroy_session(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin(string $redirectTo = 'login.php'): void
    {
        auth_session_started();

        if (empty($_SESSION['user_id'])) {
            header('Location: ' . $redirectTo);
            exit;
        }

        $timeoutSeconds = 30 * 60;
        $lastActivity = (int) ($_SESSION['last_activity'] ?? time());

        if (time() - $lastActivity > $timeoutSeconds) {
            auth_destroy_session();
            header('Location: login.php?timeout=1');
            exit;
        }

        $_SESSION['last_activity'] = time();
    }
}

if (!function_exists('requireRole')) {
    function requireRole($roles): void
    {
        requireLogin();

        $allowedRoles = is_array($roles) ? $roles : [$roles];
        $currentRole = $_SESSION['role'] ?? '';

        if (!in_array($currentRole, $allowedRoles, true)) {
            http_response_code(403);
            ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Access Denied</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f4f6f9; }
        .access-card {
            max-width:520px;
            border:0;
            border-radius:16px;
            box-shadow:0 8px 22px rgba(0,0,0,.06);
        }
    </style>
</head>
<body>
<div class="container min-vh-100 d-flex align-items-center justify-content-center">
    <div class="card access-card w-100">
        <div class="card-body p-4 text-center">
            <h3 class="mb-3">Access denied</h3>
            <p class="text-muted mb-4">You do not have permission to view this page.</p>
            <a href="dashboard.php" class="btn btn-primary">Back to dashboard</a>
        </div>
    </div>
</div>
</body>
</html>
            <?php
            exit;
        }
    }
}
