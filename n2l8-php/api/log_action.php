<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$action   = $_POST['action']   ?? '';
$metadata = $_POST['metadata'] ?? '';
$product_id = isset($_POST['product_id']) && $_POST['product_id'] !== '' ? (int)$_POST['product_id'] : null;

if (!$action) {
    echo json_encode(['success' => false, 'error' => 'Missing action']);
    exit;
}

$pdo = get_pdo();
$log_msg = $action . ($metadata ? ':' . $metadata : '');
log_visitor($pdo, $log_msg);

if (is_customer_user()) {
    try {
        $page = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH) ?: '';
        $pdo->prepare('INSERT INTO user_activity (user_id, product_id, action, metadata, page) VALUES (?, ?, ?, ?, ?)')
            ->execute([$_SESSION['user_id'], $product_id, substr($action, 0, 80), substr($metadata, 0, 255), substr($page, 0, 255)]);
    } catch (Throwable $e) {
        // Never break public interactions because history logging failed.
    }
}

echo json_encode(['success' => true]);
