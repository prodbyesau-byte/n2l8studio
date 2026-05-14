<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/admin/index.php'); }

$pdo        = get_pdo();
$product_id = (int)($_POST['product_id'] ?? 0);
$track_id   = (int)($_POST['track_id']   ?? 0);
$direction  = $_POST['direction'] ?? 'up';

// Get all tracks sorted by position
$tracks = $pdo->prepare('SELECT * FROM product_tracks WHERE product_id = ? ORDER BY position ASC');
$tracks->execute([$product_id]);
$tracks = $tracks->fetchAll();

$idx = array_search($track_id, array_column($tracks, 'id'));
if ($idx === false) { redirect('/admin/product_edit.php?id='.$product_id); }

if ($direction === 'up' && $idx > 0) {
    $other = $tracks[$idx - 1];
    $curr  = $tracks[$idx];
    $pdo->prepare('UPDATE product_tracks SET position=? WHERE id=?')->execute([$other['position'], $curr['id']]);
    $pdo->prepare('UPDATE product_tracks SET position=? WHERE id=?')->execute([$curr['position'], $other['id']]);
} elseif ($direction === 'down' && $idx < count($tracks) - 1) {
    $other = $tracks[$idx + 1];
    $curr  = $tracks[$idx];
    $pdo->prepare('UPDATE product_tracks SET position=? WHERE id=?')->execute([$other['position'], $curr['id']]);
    $pdo->prepare('UPDATE product_tracks SET position=? WHERE id=?')->execute([$curr['position'], $other['id']]);
}

redirect('/admin/product_edit.php?id='.$product_id);
