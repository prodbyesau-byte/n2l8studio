<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$action = $_POST['action'] ?? '';
$product_id = (int)($_POST['product_id'] ?? 0);

$allowed_actions = ['toggle_upvote', 'fetch_playlists', 'create_playlist', 'toggle_playlist_item', 'delete_playlist'];
if (!in_array($action, $allowed_actions)) {
    echo json_encode(['success' => false, 'error' => 'Invalid action: ' . $action]);
    exit;
}

// Certain actions require product_id
if (in_array($action, ['toggle_upvote', 'toggle_playlist_item']) && !$product_id) {
    echo json_encode(['success' => false, 'error' => 'Missing product ID']);
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
        log_action($pdo, 'Removed upvote for product ' . $product_id);
    } else {
        // Add upvote
        $pdo->prepare("INSERT IGNORE INTO product_upvotes (user_id, product_id) VALUES (?, ?)")->execute([$user_id, $product_id]);
        $is_active = true;
        log_action($pdo, 'Upvoted product ' . $product_id);
    }
    
    // Get new count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_upvotes WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $count = (int)$stmt->fetchColumn();
    
    echo json_encode(['success' => true, 'is_active' => $is_active, 'count' => $count]);
    exit;
}

if ($action === 'fetch_playlists') {
    $stmt = $pdo->prepare("
        SELECT pl.id, pl.name, 
               CASE WHEN pi.product_id IS NOT NULL THEN 1 ELSE 0 END as is_in_playlist
        FROM playlists pl
        LEFT JOIN playlist_items pi ON pl.id = pi.playlist_id AND pi.product_id = ?
        WHERE pl.user_id = ?
        ORDER BY pl.created_at DESC
    ");
    $stmt->execute([$product_id, $user_id]);
    echo json_encode(['success' => true, 'playlists' => $stmt->fetchAll()]);
    exit;
}

if ($action === 'create_playlist') {
    $name = trim($_POST['name'] ?? 'New Playlist');
    if (empty($name)) $name = 'My Playlist';
    $stmt = $pdo->prepare("INSERT INTO playlists (user_id, name) VALUES (?, ?)");
    $stmt->execute([$user_id, $name]);
    $playlist_id = $pdo->lastInsertId();
    echo json_encode(['success' => true, 'playlist' => ['id' => $playlist_id, 'name' => $name]]);
    exit;
}

if ($action === 'toggle_playlist_item') {
    $playlist_id = (int)($_POST['playlist_id'] ?? 0);
    // Verify ownership
    $stmt = $pdo->prepare("SELECT id FROM playlists WHERE id = ? AND user_id = ?");
    $stmt->execute([$playlist_id, $user_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Playlist not found']);
        exit;
    }
    
    // Check if already in playlist
    $stmt = $pdo->prepare("SELECT id FROM playlist_items WHERE playlist_id = ? AND product_id = ?");
    $stmt->execute([$playlist_id, $product_id]);
    if ($stmt->fetch()) {
        $pdo->prepare("DELETE FROM playlist_items WHERE playlist_id = ? AND product_id = ?")->execute([$playlist_id, $product_id]);
        log_action($pdo, "Removed product $product_id from playlist $playlist_id");
        echo json_encode(['success' => true, 'is_active' => false]);
    } else {
        $pdo->prepare("INSERT INTO playlist_items (playlist_id, product_id) VALUES (?, ?)")->execute([$playlist_id, $product_id]);
        log_action($pdo, "Added product $product_id to playlist $playlist_id");
        echo json_encode(['success' => true, 'is_active' => true]);
    }
    exit;
}

if ($action === 'delete_playlist') {
    $playlist_id = (int)($_POST['playlist_id'] ?? 0);
    // Verify ownership
    $stmt = $pdo->prepare("SELECT id FROM playlists WHERE id = ? AND user_id = ?");
    $stmt->execute([$playlist_id, $user_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Playlist not found']);
        exit;
    }
    
    $pdo->prepare("DELETE FROM playlists WHERE id = ? AND user_id = ?")->execute([$playlist_id, $user_id]);
    log_action($pdo, "Deleted playlist $playlist_id");
    echo json_encode(['success' => true]);
    exit;
}

