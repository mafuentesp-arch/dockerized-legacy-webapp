<?php

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function ensure_session_started(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function csrf_token(): string
{
    ensure_session_started();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

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

function clean_text($value, int $max = 255): string
{
    return mb_substr(trim((string) ($value ?? '')), 0, $max);
}

function clean_int($value): int
{
    return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : 0;
}

function clean_phone($value): string
{
    return preg_replace('/[^0-9+()\-\s]/', '', (string) ($value ?? ''));
}

function get_setting(PDO $pdo, string $name, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_name = ? LIMIT 1');
    $stmt->execute([$name]);
    $value = $stmt->fetchColumn();

    return $value !== false ? (string) $value : $default;
}

function redirect_to(string $path): void
{
    header('Location: ' . $path);
    exit;
}
