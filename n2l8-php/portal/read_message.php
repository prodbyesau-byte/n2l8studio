<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

if (session_status() === PHP_SESSION_NONE) session_start();

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$message_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($message_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid message ID']);
    exit;
}

try {
    $pdo = get_pdo();
    
    // Verify recipient matches the logged-in user (prevents direct object reference attacks)
    $stmt = $pdo->prepare('UPDATE messages SET is_read = 1 WHERE id = ? AND recipient_id = ?');
    $stmt->execute([$message_id, $user_id]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        // Message might already be read or doesn't belong to the user
        echo json_encode(['success' => true, 'note' => 'No rows updated']);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;
