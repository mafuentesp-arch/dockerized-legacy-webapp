<?php

if (!function_exists('ensure_session_started')) {
    function ensure_session_started(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        ensure_session_started();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(): void
    {
        ensure_session_started();

        $postedToken = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if ($postedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
            http_response_code(403);
            exit('Invalid security token.');
        }
    }
}

if (!function_exists('cleanText')) {
    function cleanText($value, int $max = 255): string
    {
        $value = trim((string) ($value ?? ''));
        $value = strip_tags($value);

        return mb_substr($value, 0, $max);
    }
}

if (!function_exists('cleanInt')) {
    function cleanInt($value): int
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : 0;
    }
}

if (!function_exists('cleanPhone')) {
    function cleanPhone($value): string
    {
        return preg_replace('/\D+/', '', (string) ($value ?? ''));
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin(string $redirectTo = 'index.php'): void
    {
        ensure_session_started();

        if (empty($_SESSION['user_id'])) {
            header('Location: ' . $redirectTo);
            exit;
        }
    }
}

if (!function_exists('requireRole')) {
    function requireRole($roles, string $redirectTo = 'index.php'): void
    {
        requireLogin($redirectTo);

        $allowedRoles = is_array($roles) ? $roles : [$roles];
        $currentRole = $_SESSION['role'] ?? '';

        if (!in_array($currentRole, $allowedRoles, true)) {
            http_response_code(403);
            exit('Access denied.');
        }
    }
}
