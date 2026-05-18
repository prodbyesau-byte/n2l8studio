<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function current_user_role(): string {
    if (empty($_SESSION['user_role']) && isset($_SESSION['user_id'])) {
        try {
            require_once __DIR__ . '/db.php';
            $stmt = get_pdo()->prepare('SELECT username, role FROM users WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            if ($user) {
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['role'];
            }
        } catch (Throwable $e) {
            return '';
        }
    }
    return (string)($_SESSION['user_role'] ?? '');
}

function is_owner(): bool {
    return is_logged_in() && in_array(current_user_role(), ['admin', 'owner'], true);
}

function is_customer_user(): bool {
    return is_logged_in() && !is_owner();
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

function require_owner(): void {
    if (!is_logged_in()) {
        header('Location: /admin/login.php');
        exit;
    }
    if (!is_owner()) {
        http_response_code(403);
        header('Location: /profile.php');
        exit;
    }
}
