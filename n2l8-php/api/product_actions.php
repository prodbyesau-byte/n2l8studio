<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

$user_id = get_current_user_id();
if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$action = $_POST['action'] ?? '';
$product_id = (int)($_POST['product_id'] ?? 0);

if (!$product_id || !in_array($action, ['toggle_upvote', 'toggle_save'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$pdo = get_pdo();

if ($action === 'toggle_upvote') {
    // Check if already upvoted
    $stmt = $pdo->prepare("SELECT id FROM product_upvotes WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$user_id, $product_id]);
    $exists = $stmt->fetch();
    
    if ($exists) {
        // Remove upvote
        $pdo->prepare("DELETE FROM product_upvotes WHERE user_id = ? AND product_id = ?")->execute([$user_id, $product_id]);
        $is_active = false;
        log_action('remove_upvote', 'Removed upvote for product ' . $product_id);
    } else {
        // Add upvote
        $pdo->prepare("INSERT IGNORE INTO product_upvotes (user_id, product_id) VALUES (?, ?)")->execute([$user_id, $product_id]);
        $is_active = true;
        log_action('add_upvote', 'Upvoted product ' . $product_id);
    }
    
    // Get new count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_upvotes WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $count = (int)$stmt->fetchColumn();
    
    echo json_encode(['success' => true, 'is_active' => $is_active, 'count' => $count]);
    exit;
}

if ($action === 'toggle_save') {
    // Check if already saved
    $stmt = $pdo->prepare("SELECT id FROM user_saved_products WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$user_id, $product_id]);
    $exists = $stmt->fetch();
    
    if ($exists) {
        // Remove from saved/playlist
        $pdo->prepare("DELETE FROM user_saved_products WHERE user_id = ? AND product_id = ?")->execute([$user_id, $product_id]);
        $is_active = false;
        log_action('remove_saved', 'Removed product ' . $product_id . ' from playlist');
    } else {
        // Add to saved/playlist
        $pdo->prepare("INSERT IGNORE INTO user_saved_products (user_id, product_id) VALUES (?, ?)")->execute([$user_id, $product_id]);
        $is_active = true;
        log_action('add_saved', 'Added product ' . $product_id . ' to playlist');
    }
    
    echo json_encode(['success' => true, 'is_active' => $is_active]);
    exit;
}
