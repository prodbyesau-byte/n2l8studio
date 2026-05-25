<?php
// /api/product.php?id=X — returns JSON for the shop modal player
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }

$pdo = get_pdo();

$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }


$tracks_stmt = $pdo->prepare('SELECT id, title, filename, preview_start, preview_end FROM product_tracks WHERE product_id = ? ORDER BY position ASC');
$tracks_stmt->execute([$id]);
$tracks = $tracks_stmt->fetchAll();

$is_free = (float)($p['price'] ?? 0) <= 0;
$zip_url = ($p['zip_file'] && $is_free && is_logged_in()) ? UPLOAD_URL . $p['zip_file'] : null;

echo json_encode([
    'id'             => (int)$p['id'],
    'title'          => $p['title'],
    'type'           => $p['type'],
    'genre'          => $p['genre'],
    'price'          => (float)$p['price'],
    'price_premium'  => $p['price_premium'] !== null ? (float)$p['price_premium'] : null,
    'price_exclusive'=> $p['price_exclusive'] !== null ? (float)$p['price_exclusive'] : null,
    'original_price' => $p['original_price'] !== null ? (float)$p['original_price'] : null,
    'author'         => $p['author'],
    'description'    => $p['description'],
    'bpm'            => $p['bpm'],
    'key'            => $p['key'],
    'cover_image'    => $p['cover_image'] ? UPLOAD_URL . $p['cover_image'] : null,
    'zip_file'       => $zip_url,
    'allow_download' => (int)($p['allow_download'] ?? 0),
    'tracks'         => array_map(fn($t) => [
        'id'    => (int)$t['id'],
        'title' => $t['title'],
        'url'   => UPLOAD_URL . $t['filename'],
        'preview_start' => isset($t['preview_start']) ? (float)$t['preview_start'] : 0,
        'preview_end'   => $t['preview_end'] !== null ? (float)$t['preview_end'] : null,
    ], $tracks),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
