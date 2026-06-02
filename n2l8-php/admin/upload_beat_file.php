<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

$product_id = $_POST['product_id'] ?? null;
$license_tier = $_POST['license_tier'] ?? null;

if (!$product_id || !$license_tier) {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing product_id or license_tier']));
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit(json_encode(['error' => 'No file uploaded or upload error']));
}

// Convert single file to array format expected by save_upload if necessary,
// but our save_upload handles single files or arrays.
$saved = save_upload($_FILES['file']);
if (empty($saved)) {
    http_response_code(500);
    exit(json_encode(['error' => 'Failed to save file']));
}

$file_info = $saved[0];

$stmt = $pdo->prepare("INSERT INTO product_files (product_id, license_tier, filename, original_name) VALUES (?, ?, ?, ?)");
$stmt->execute([$product_id, $license_tier, $file_info['filename'], $file_info['original']]);
$file_id = $pdo->lastInsertId();

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'id' => $file_id,
    'filename' => $file_info['filename'],
    'original_name' => $file_info['original']
]);
