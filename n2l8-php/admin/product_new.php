<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/admin/index.php'); }

$pdo = get_pdo();

$title          = trim($_POST['title'] ?? '');
$type           = $_POST['type'] ?? 'loopkit';
$genre          = $_POST['genre'] ?? 'all';
$price          = (float)($_POST['price'] ?? 0);
$original_price = $_POST['original_price'] !== '' ? (float)$_POST['original_price'] : null;
$author         = trim($_POST['author'] ?? '') ?: null;
$description    = trim($_POST['description'] ?? '') ?: null;
$bpm            = trim($_POST['bpm'] ?? '') ?: null;
$key            = trim($_POST['key'] ?? '') ?: null;
$price_premium   = $_POST['price_premium'] !== '' ? (float)$_POST['price_premium'] : null;
$price_exclusive = $_POST['price_exclusive'] !== '' ? (float)$_POST['price_exclusive'] : null;
$is_active       = isset($_POST['is_active']) ? 1 : 0;
$allow_download  = isset($_POST['allow_download']) ? 1 : 0;

$cover_image = save_upload('cover_image', ALLOWED_IMAGES);
$zip_file    = save_upload('zip_file', ALLOWED_FILES);

$stmt = $pdo->prepare('INSERT INTO products (title,type,genre,price,price_premium,price_exclusive,original_price,author,description,bpm,`key`,cover_image,zip_file,is_active,allow_download) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$stmt->execute([$title,$type,$genre,$price,$price_premium,$price_exclusive,$original_price,$author,$description,$bpm,$key,$cover_image,$zip_file,$is_active,$allow_download]);
$product_id = $pdo->lastInsertId();

// Handle multiple preview tracks
$track_count = 0;
if (isset($_FILES['audio_files'])) {
    $files = $_FILES['audio_files'];
    $count = is_array($files['name']) ? count($files['name']) : 1;
    for ($i = 0; $i < $count; $i++) {
        $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
        $name  = is_array($files['name'])  ? $files['name'][$i]  : $files['name'];
        $tmp   = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        if ($error !== UPLOAD_ERR_OK || empty($name)) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_AUDIO, true)) continue;
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name));
        $dest = rtrim(UPLOAD_DIR, '/') . '/' . $safe;
        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
        if (move_uploaded_file($tmp, $dest)) {
            $track_title = pathinfo($name, PATHINFO_FILENAME);
            $track_title = str_replace(['_','-'], ' ', $track_title);
            $track_title = ucwords(strtolower($track_title));
            $pdo->prepare('INSERT INTO product_tracks (product_id,title,filename,position) VALUES (?,?,?,?)')
                ->execute([$product_id, $track_title, $safe, $track_count]);
            $track_count++;
        }
    }
}

log_action($pdo, "Created product: '{$title}' with {$track_count} tracks");
flash("Product '{$title}' created with {$track_count} preview tracks.");
redirect('/admin/index.php?tab=products');
