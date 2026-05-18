<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$user_role = $_SESSION['role'] ?? '';

$_SESSION = [];
session_destroy();

if ($user_role === 'admin') {
    header('Location: /login.php');
} else {
    header('Location: /index.php');
}
exit;
