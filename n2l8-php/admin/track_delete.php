<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_owner();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/admin/index.php'); }

$pdo        = get_pdo();
$product_id = (int)($_POST['product_id'] ?? 0);
$track_id   = (int)($_POST['track_id']   ?? 0);

$stmt = $pdo->prepare('SELECT * FROM product_tracks WHERE id = ? AND product_id = ?');
$stmt->execute([$track_id, $product_id]);
$track = $stmt->fetch();
if (!$track) { flash('Track not found.'); redirect('/admin/product_edit.php?id='.$product_id); }

// Delete physical file
$file_path = rtrim(UPLOAD_DIR, '/') . '/' . $track['filename'];
if (file_exists($file_path)) {
    @unlink($file_path);
}

$pdo->prepare('DELETE FROM product_tracks WHERE id = ?')->execute([$track_id]);
log_action($pdo, "Deleted track '{$track['title']}' and its file");
flash("Track '{$track['title']}' deleted.");
redirect('/admin/product_edit.php?id='.$product_id);
