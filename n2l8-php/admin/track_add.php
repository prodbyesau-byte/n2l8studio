<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/admin/index.php'); }

$pdo        = get_pdo();
$product_id = (int)($_POST['product_id'] ?? 0);

// Verify product exists
$p = $pdo->prepare('SELECT title FROM products WHERE id = ?');
$p->execute([$product_id]);
$product = $p->fetch();
if (!$product) { flash('Product not found.'); redirect('/admin/index.php?tab=products'); }

$count = 0;
if (isset($_FILES['audio_files'])) {
    $files = $_FILES['audio_files'];
    $num   = is_array($files['name']) ? count($files['name']) : 1;

    // Current max position
    $max_pos = (int)$pdo->prepare('SELECT COALESCE(MAX(position),0) FROM product_tracks WHERE product_id = ?')
        ->execute([$product_id]) ? $pdo->query("SELECT COALESCE(MAX(position),-1) FROM product_tracks WHERE product_id = {$product_id}")->fetchColumn() : -1;
    $pos = $max_pos + 1;

    for ($i = 0; $i < $num; $i++) {
        $error = is_array($files['error'])    ? $files['error'][$i]    : $files['error'];
        $name  = is_array($files['name'])     ? $files['name'][$i]     : $files['name'];
        $tmp   = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];

        if ($error !== UPLOAD_ERR_OK || empty($name)) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_AUDIO, true)) continue;

        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name));
        $dest = rtrim(UPLOAD_DIR, '/') . '/' . $safe;
        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
        if (move_uploaded_file($tmp, $dest)) {
            $track_title = ucwords(strtolower(str_replace(['_','-'], ' ', pathinfo($name, PATHINFO_FILENAME))));
            $pdo->prepare('INSERT INTO product_tracks (product_id,title,filename,position) VALUES (?,?,?,?)')
                ->execute([$product_id, $track_title, $safe, $pos]);
            $pos++;
            $count++;
        }
    }
}

if ($count > 0) {
    log_action($pdo, "Added {$count} tracks to '{$product['title']}'");
    flash("Successfully uploaded {$count} preview tracks.");
} else {
    flash('Upload failed — please ensure files are WAV, MP3, OGG, or FLAC.');
}
redirect('/admin/product_edit.php?id=' . $product_id);
