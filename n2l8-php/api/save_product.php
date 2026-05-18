<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!is_customer_user()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Login required']);
    exit;
}

$product_id = (int)($_POST['product_id'] ?? 0);
if (!$product_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing product']);
    exit;
}

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT id, title FROM products WHERE id = ? AND is_active = 1');
$stmt->execute([$product_id]);
$product = $stmt->fetch();
if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Product not found']);
    exit;
}

$check = $pdo->prepare('SELECT id FROM user_saved_products WHERE user_id = ? AND product_id = ?');
$check->execute([$_SESSION['user_id'], $product_id]);
$existing = $check->fetch();

if ($existing) {
    $pdo->prepare('DELETE FROM user_saved_products WHERE id = ?')->execute([$existing['id']]);
    $saved = false;
    $action = 'unsave_product';
} else {
    $pdo->prepare('INSERT INTO user_saved_products (user_id, product_id) VALUES (?, ?)')
        ->execute([$_SESSION['user_id'], $product_id]);
    $saved = true;
    $action = 'save_product';
}

$pdo->prepare('INSERT INTO user_activity (user_id, product_id, action, metadata, page) VALUES (?, ?, ?, ?, ?)')
    ->execute([$_SESSION['user_id'], $product_id, $action, $product['title'], '/shop.php']);

echo json_encode(['success' => true, 'saved' => $saved]);
