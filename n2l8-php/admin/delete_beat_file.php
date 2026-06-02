<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

$data = json_decode(file_get_contents('php://input'), true);
$file_id = $data['id'] ?? null;

if (!$file_id) {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing file id']));
}

$stmt = $pdo->prepare("SELECT filename FROM product_files WHERE id = ?");
$stmt->execute([$file_id]);
$file = $stmt->fetch();

if ($file) {
    $path = rtrim(UPLOAD_DIR, '/') . '/' . $file['filename'];
    if (file_exists($path)) {
        unlink($path);
    }
    $pdo->prepare("DELETE FROM product_files WHERE id = ?")->execute([$file_id]);
}

header('Content-Type: application/json');
echo json_encode(['success' => true]);
