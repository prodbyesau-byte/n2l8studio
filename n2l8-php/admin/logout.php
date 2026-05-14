<?php
require_once __DIR__ . '/../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
session_destroy();
header('Location: /admin/login.php');
exit;
