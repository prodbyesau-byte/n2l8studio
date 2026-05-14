<?php
/**
 * PayPal — Capture Order
 * Called after the buyer approves payment in the PayPal popup.
 * Returns JSON: { "success": true, "download_url": "...", "title": "..." }
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$order_id   = trim($_POST['order_id']   ?? '');
$product_id = (int)($_POST['product_id'] ?? 0);

if (!$order_id || !$product_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing order_id or product_id']);
    exit;
}

// Fetch product
$pdo  = get_pdo();
$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1');
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    echo json_encode(['error' => 'Product not found']);
    exit;
}

// Capture the PayPal order
$capture = paypal_request(
    'POST',
    '/v2/checkout/orders/' . $order_id . '/capture',
    [],
    'n2l8-capture-' . $order_id
);

if (isset($capture['_error'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Capture failed', 'detail' => $capture]);
    exit;
}

$status = $capture['status'] ?? '';
if ($status !== 'COMPLETED') {
    http_response_code(402);
    echo json_encode(['error' => 'Payment not completed', 'status' => $status]);
    exit;
}

// Extract buyer email from PayPal response
$payer_email = $capture['payer']['email_address'] ?? 'unknown@paypal.com';
$amount      = $capture['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? $product['price'];

// Record order in database
$pdo->prepare(
    'INSERT INTO orders (customer_email, product_id, status) VALUES (?, ?, ?)'
)->execute([$payer_email, $product_id, 'completed']);

log_action($pdo, "PayPal purchase: {$product['title']} by {$payer_email} (\${$amount})");

// Return download URL
$download_url = $product['zip_file']
    ? UPLOAD_URL . $product['zip_file']
    : null;

echo json_encode([
    'success'      => true,
    'title'        => $product['title'],
    'download_url' => $download_url,
    'order_id'     => $order_id,
    'email'        => $payer_email,
]);
