<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$action   = $_POST['action']   ?? '';
$metadata = $_POST['metadata'] ?? '';

if (!$action) {
    echo json_encode(['success' => false, 'error' => 'Missing action']);
    exit;
}

$pdo = get_pdo();
$log_msg = $action . ($metadata ? ':' . $metadata : '');
log_visitor($pdo, $log_msg);

echo json_encode(['success' => true]);
