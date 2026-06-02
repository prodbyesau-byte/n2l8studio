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
$license_tier = isset($_POST['license_tier']) ? trim($_POST['license_tier']) : '';

// Record order in database
$pdo->prepare(
    'INSERT INTO orders (customer_email, product_id, license_tier, status) VALUES (?, ?, ?, ?)'
)->execute([$payer_email, $product_id, $license_tier ?: null, 'completed']);

// Auto-sync library for logged-in user matching their session
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
    $pdo->prepare("INSERT IGNORE INTO user_saved_products (user_id, product_id) VALUES (?, ?)")
        ->execute([$user_id, $product_id]);
}

$tier_log = $license_tier ? " ({$license_tier})" : "";
log_action($pdo, "PayPal purchase: {$product['title']}{$tier_log} by {$payer_email} (\${$amount})");

// Generate authorized format URLs
$download_urls = [];
$is_beat = ($product['type'] === 'beat');
$tier = strtoupper($license_tier ?? '');

if ($is_beat) {
    $pfiles_stmt = $pdo->prepare('SELECT * FROM product_files WHERE product_id = ?');
    $pfiles_stmt->execute([$product['id']]);
    $all_files = $pfiles_stmt->fetchAll();
    
    foreach ($all_files as $f) {
        $ftier = strtoupper($f['license_tier']);
        if ($tier === 'EXCLUSIVE') {
            $download_urls[$f['original_name']] = UPLOAD_URL . $f['filename'];
        } elseif ($tier === 'PREMIUM') {
            if ($ftier === 'BASIC' || $ftier === 'PREMIUM') {
                $download_urls[$f['original_name']] = UPLOAD_URL . $f['filename'];
            }
        } else {
            // Basic
            if ($ftier === 'BASIC') {
                $download_urls[$f['original_name']] = UPLOAD_URL . $f['filename'];
            }
        }
    }
}

// Keep standard zip_file as legacy/fallback
$download_url = $product['zip_file'] ? UPLOAD_URL . $product['zip_file'] : null;

echo json_encode([
    'success'       => true,
    'title'         => $product['title'],
    'download_url'  => $download_url,
    'download_urls' => !empty($download_urls) ? $download_urls : null,
    'order_id'      => $order_id,
    'email'         => $payer_email,
]);
