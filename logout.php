<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/audit.php';
ensure_session_started();

logActivity('LOGOUT', 'users', isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'User ' . ($_SESSION['username'] ?? '') . ' logged out');

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

header('Location: login.php');
exit;
