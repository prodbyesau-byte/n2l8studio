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

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    echo "Product not found.";
    exit;
}

$is_preorder  = (int)($product['is_preorder'] ?? 0);
$release_time = !empty($product['release_date']) ? strtotime($product['release_date']) : 0;
if ($is_preorder && $release_time > time()) {
    http_response_code(403);
    echo "This product is a pre-order and has not been released yet.";
    exit;
}

$is_free = (float)($product['price'] ?? 0) <= 0;
if (!$is_free) {
    http_response_code(403);
    echo "This product is not free.";
    exit;
}

$format = trim($_GET['format'] ?? 'zip');
$allowed_formats = ['zip', 'mp3_mastered', 'mp3_unmastered', 'wav_mastered', 'wav_unmastered', 'stems_file'];
if (!in_array($format, $allowed_formats)) {
    http_response_code(400);
    echo "Invalid format requested.";
    exit;
}

$file_column = 'zip_file';
if ($format === 'mp3_mastered')   $file_column = 'mp3_mastered';
if ($format === 'mp3_unmastered') $file_column = 'mp3_unmastered';
if ($format === 'wav_mastered')   $file_column = 'wav_mastered';
if ($format === 'wav_unmastered') $file_column = 'wav_unmastered';
if ($format === 'stems_file')     $file_column = 'stems_file';

if (empty($product['allow_download']) || empty($product[$file_column])) {
    http_response_code(403);
    echo "Requested download format is not available or enabled for this product.";
    exit;
}

// Record the free purchase inside orders if they are logged in
if (is_logged_in()) {
    $user_email = $_SESSION['email'] ?? '';
    $user_id = $_SESSION['user_id'];
    
    if ($user_email) {
        $check_stmt = $pdo->prepare('SELECT id FROM orders WHERE customer_email = ? AND product_id = ?');
        $check_stmt->execute([$user_email, $id]);
        if (!$check_stmt->fetch()) {
            // Insert as completed order with WAV/STEMS license tier so they can download all files in their library
            $pdo->prepare('INSERT INTO orders (customer_email, product_id, license_tier, status) VALUES (?, ?, ?, "completed")')
                ->execute([$user_email, $id, 'WAV/STEMS']);
                
            // Also insert into user_saved_products (just in case)
            $pdo->prepare('INSERT IGNORE INTO user_saved_products (user_id, product_id) VALUES (?, ?)')
                ->execute([$user_id, $id]);
                
            log_action($pdo, "User {$_SESSION['username']} downloaded free kit/beat '{$product['title']}' ({$format}) and added it to library.");
        }
    }
} else {
    log_action($pdo, "Anonymous visitor downloaded free kit/beat '{$product['title']}' ({$format})");
}

// Redirect the browser to the actual direct file
$download_path = UPLOAD_URL . $product[$file_column];
header('Location: ' . $download_path);
exit;
