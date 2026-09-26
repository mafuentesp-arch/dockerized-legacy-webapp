<?php

if (!function_exists('logActivity')) {
    function logActivity(string $actionType, string $entityType, ?int $entityId = null, string $description = ''): void
    {
        try {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            global $pdo;

            if (!isset($pdo) || !$pdo instanceof PDO) {
                require __DIR__ . '/../db.php';
            }

            if (!isset($pdo) || !$pdo instanceof PDO) {
                return;
            }

            $stmt = $pdo->prepare("
                INSERT INTO activity_logs
                (user_id, username, role, action_type, entity_type, entity_id, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $_SESSION['user_id'] ?? null,
                $_SESSION['username'] ?? null,
                $_SESSION['role'] ?? null,
                $actionType,
                $entityType,
                $entityId,
                $description,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Throwable $e) {
            error_log('Activity log failed: ' . $e->getMessage());
        }
    }
}
