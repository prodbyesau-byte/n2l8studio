<?php
// /api/update_product_order.php — saves the drag-and-drop order of kits
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Only allow logged in admin/owners
if (!is_owner()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Admin access required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['product_ids']) || !is_array($input['product_ids'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing product_ids array']);
    exit;
}

$pdo = get_pdo();
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare("UPDATE products SET position = ? WHERE id = ?");
    
    // Assign position 1, 2, 3... to products in the sent order
    foreach ($input['product_ids'] as $index => $id) {
        $stmt->execute([$index + 1, (int)$id]);
    }
    
    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
