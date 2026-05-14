<?php
/**
 * PayPal — Create Order
 * Called by the PayPal JS SDK createOrder() callback.
 * Returns JSON: { "id": "PAYPAL_ORDER_ID" }
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

$product_id = (int)($_POST['product_id'] ?? 0);
if (!$product_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing product_id']);
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

if ((float)$product['price'] <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Product is free — no payment needed']);
    exit;
}

// Create PayPal order
$order = paypal_request('POST', '/v2/checkout/orders', [
    'intent' => 'CAPTURE',
    'purchase_units' => [[
        'reference_id' => 'product_' . $product['id'],
        'description'  => $product['title'] . ' — n2l8studio',
        'amount'       => [
            'currency_code' => 'USD',
            'value'         => number_format((float)$product['price'], 2, '.', ''),
        ],
    ]],
    'application_context' => [
        'brand_name'  => 'n2l8studio',
        'landing_page' => 'NO_PREFERENCE',
        'user_action'  => 'PAY_NOW',
        'shipping_preference' => 'NO_SHIPPING',
    ],
], 'n2l8-create-' . $product['id'] . '-' . time());

if (isset($order['_error']) || !isset($order['id'])) {
    http_response_code(500);
    echo json_encode(['error' => 'PayPal order creation failed', 'detail' => $order]);
    exit;
}

echo json_encode(['id' => $order['id']]);
