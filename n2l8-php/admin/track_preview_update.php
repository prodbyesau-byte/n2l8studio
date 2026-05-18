<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_owner();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/admin/index.php'); }

$pdo        = get_pdo();
$product_id = (int)($_POST['product_id'] ?? 0);
$track_id   = (int)($_POST['track_id'] ?? 0);

$start_raw = trim($_POST['preview_start'] ?? '0');
$end_raw   = trim($_POST['preview_end'] ?? '');

$preview_start = max(0, (float)($start_raw !== '' ? $start_raw : 0));
$preview_end   = $end_raw !== '' ? max(0, (float)$end_raw) : null;

if ($preview_end !== null && $preview_end <= $preview_start) {
    $preview_end = null;
    flash('Preview end must be after preview start. End was cleared so the preview plays from start to the file end.');
}

$stmt = $pdo->prepare('SELECT title FROM product_tracks WHERE id = ? AND product_id = ?');
$stmt->execute([$track_id, $product_id]);
$track = $stmt->fetch();
if (!$track) {
    flash('Preview track not found.');
    redirect('/admin/product_edit.php?id=' . $product_id);
}

$pdo->prepare('UPDATE product_tracks SET preview_start = ?, preview_end = ? WHERE id = ? AND product_id = ?')
    ->execute([$preview_start, $preview_end, $track_id, $product_id]);

log_action($pdo, "Updated preview range for track: '{$track['title']}'");
flash("Preview range updated for '{$track['title']}'.");
redirect('/admin/product_edit.php?id=' . $product_id . '#track-' . $track_id);
