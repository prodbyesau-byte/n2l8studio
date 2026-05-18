<?php
require_once __DIR__ . '/../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$was_owner = is_owner();
session_destroy();
header('Location: ' . ($was_owner ? '/admin/login.php' : '/login.php'));
exit;
