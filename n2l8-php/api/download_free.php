<?php
// /api/download_free.php?id=X — records a free download kit/beat transaction into the user's library and triggers direct ZIP download
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo "Missing product ID.";
    exit;
}

if (!is_logged_in()) {
    $redirect_url = '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']);
    header('Location: ' . $redirect_url);
    exit;
}

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    echo "Product not found.";
    exit;
}

$is_free = (float)($product['price'] ?? 0) <= 0;
if (!$is_free) {
    http_response_code(403);
    echo "This product is not free.";
    exit;
}

if (empty($product['allow_download']) || empty($product['zip_file'])) {
    http_response_code(403);
    echo "Download is not enabled for this product.";
    exit;
}

// Record the free purchase inside orders if it doesn't already exist
$user_email = $_SESSION['email'] ?? '';
$user_id = $_SESSION['user_id'];

if ($user_email) {
    $check_stmt = $pdo->prepare('SELECT id FROM orders WHERE customer_email = ? AND product_id = ?');
    $check_stmt->execute([$user_email, $id]);
    if (!$check_stmt->fetch()) {
        // Insert as completed order
        $pdo->prepare('INSERT INTO orders (customer_email, product_id, status) VALUES (?, ?, "completed")')
            ->execute([$user_email, $id]);
            
        // Also insert into user_saved_products (just in case)
        $pdo->prepare('INSERT IGNORE INTO user_saved_products (user_id, product_id) VALUES (?, ?)')
            ->execute([$user_id, $id]);
            
        log_action($pdo, "User {$_SESSION['username']} downloaded free kit/beat '{$product['title']}' and added it to library.");
    }
}

// Redirect the browser to the actual direct ZIP file
$zip_url = UPLOAD_URL . $product['zip_file'];
header('Location: ' . $zip_url);
exit;
