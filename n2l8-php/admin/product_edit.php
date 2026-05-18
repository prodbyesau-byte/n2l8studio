<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
$pdo = get_pdo();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) { flash('Product not found.'); redirect('/admin/index.php?tab=products'); }

// Fetch tracks sorted by position
$tracks = $pdo->prepare('SELECT * FROM product_tracks WHERE product_id = ? ORDER BY position ASC');
$tracks->execute([$id]);
$tracks = $tracks->fetchAll();

// Handle POST (save product settings)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title          = trim($_POST['title'] ?? $product['title']);
    $type           = $_POST['type'] ?? $product['type'];
    $genre          = $_POST['genre'] ?? $product['genre'];
    $price          = (float)($_POST['price'] ?? $product['price']);
    $op             = trim($_POST['original_price'] ?? '');
    $original_price = $op !== '' ? (float)$op : null;
    $author         = trim($_POST['author'] ?? '') ?: null;
    $description    = trim($_POST['description'] ?? '') ?: null;
    $bpm            = trim($_POST['bpm'] ?? '') ?: null;
    $key            = trim($_POST['key'] ?? '') ?: null;
    $price_premium   = $_POST['price_premium'] !== '' ? (float)$_POST['price_premium'] : null;
    $price_exclusive = $_POST['price_exclusive'] !== '' ? (float)$_POST['price_exclusive'] : null;
    $is_active       = isset($_POST['is_active']) ? 1 : 0;
    $allow_download  = isset($_POST['allow_download']) ? 1 : 0;

    $new_cover = save_upload('cover_image', ALLOWED_IMAGES);
    $new_zip   = save_upload('zip_file', ALLOWED_FILES);

    $cover = $new_cover ?? $product['cover_image'];
    $zip   = $new_zip   ?? $product['zip_file'];

    $pdo->prepare('UPDATE products SET title=?,type=?,genre=?,price=?,price_premium=?,price_exclusive=?,original_price=?,author=?,description=?,bpm=?,`key`=?,cover_image=?,zip_file=?,is_active=?,allow_download=? WHERE id=?')
        ->execute([$title,$type,$genre,$price,$price_premium,$price_exclusive,$original_price,$author,$description,$bpm,$key,$cover,$zip,$is_active,$allow_download,$id]);

    log_action($pdo, "Edited product: '{$title}'");
    flash("Product '{$title}' updated.");
    redirect('/admin/product_edit.php?id='.$id);
}

$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - n2l8studio Admin</title>
    <link rel="stylesheet" href="/static/style.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Righteous&family=VT323&display=swap" rel="stylesheet">
    <style>
        .edit-box { max-width:900px; margin:3rem auto; background:rgba(26,26,31,0.9); border:1px solid var(--text-muted); padding:2.5rem; }
        .form-group { display:flex; flex-direction:column; gap:0.4rem; margin-bottom:1rem; }
        .form-group label { color:var(--text-muted); font-family:'VT323',monospace; font-size:1rem; text-transform:uppercase; letter-spacing:1px; }
        .form-group input, .form-group select, .form-group textarea { background:var(--bg-dark); border:1px solid var(--text-muted); color:var(--text-main); font-family:'VT323',monospace; font-size:1.1rem; padding:0.5rem 0.8rem; outline:none; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:var(--text-main); }
        .form-group textarea { resize:vertical; min-height:80px; }
        .checkbox-row { display:flex; align-items:center; gap:0.8rem; }
        .checkbox-row input { width:18px; height:18px; accent-color:var(--text-main); }
        .thumb { width:120px; height:120px; object-fit:cover; border:1px solid var(--text-muted); display:block; margin-bottom:0.5rem; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; }
        .section-divider { border:none; border-top:1px dashed var(--text-muted); margin:2.5rem 0; }
        .section-label { font-family:'Righteous',cursive; color:var(--accent); font-size:1.3rem; text-transform:uppercase; letter-spacing:2px; margin-bottom:1.2rem; }
        .top-actions { position:sticky; top:0; z-index:100; background:var(--bg-dark); padding:1rem 0; border-bottom:1px solid rgba(123,225,168,0.2); margin-bottom:2rem; display:flex; gap:1rem; }
        .track-row { border:1px solid rgba(123,225,168,0.15); background:rgba(0,0,0,0.3); margin-bottom:1rem; padding:1rem; }
        .track-header { display:flex; align-items:center; gap:1rem; margin-bottom:0.7rem; }
        .track-num { color:var(--accent); font-family:'Righteous',cursive; font-size:1.4rem; min-width:32px; }
        .track-title-display { flex:1; color:var(--text-main); font-family:'VT323',monospace; font-size:1.2rem; }
        .track-body { display:flex; align-items:center; gap:0.8rem; flex-wrap:wrap; }
        audio { flex:1; height:36px; min-width:200px; }
        .btn { padding:0.4rem 1rem; font-family:'VT323',monospace; font-size:1rem; cursor:pointer; border:1px solid; background:transparent; transition:all 0.2s; text-decoration:none; display:inline-block; text-transform:uppercase; }
        .btn-green { color:var(--text-main); border-color:var(--text-main); }
        .btn-green:hover { background:var(--text-main); color:var(--bg-dark); }
        .btn-red { color:#ff5c5c; border-color:#ff5c5c; }
        .btn-red:hover { background:#ff5c5c; color:var(--bg-dark); }
        .btn-small { padding:0.2rem 0.7rem; font-size:0.95rem; }
        .confirm-bar { display:none; background:rgba(255,92,92,0.15); border:1px solid #ff5c5c; padding:0.7rem 1rem; margin-top:0.6rem; align-items:center; gap:1rem; font-family:'VT323',monospace; font-size:1.1rem; color:#ff5c5c; }
        .confirm-bar.show { display:flex; }
        .upload-box { border:2px dashed var(--text-muted); padding:1.5rem; margin-top:1rem; background:rgba(57,255,20,0.03); }
        #loadingOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:3000; align-items:center; justify-content:center; flex-direction:column; color:var(--text-main); }
        #loadingOverlay.active { display:flex; }
        .spinner { width:50px; height:50px; border:3px solid var(--text-muted); border-top-color:var(--text-main); border-radius:50%; animation:spin 1s linear infinite; margin-bottom:1rem; }
        @keyframes spin { to { transform:rotate(360deg); } }
    </style>
</head>
<body class="page-home">

<div id="loadingOverlay">
    <div class="spinner"></div>
    <div style="font-family:'VT323';font-size:1.5rem;">SYNCING MEDIA ASSETS...</div>
</div>

<div class="container">
<div class="edit-box">

    <div class="top-actions">
        <a href="/admin/index.php?tab=products" class="btn" style="color:var(--text-muted);border-color:var(--text-muted);">&larr; BACK TO TERMINAL</a>
        <a href="/shop.php?preview=<?= (int)$product['id'] ?>" target="_blank" class="btn" style="color:var(--accent);border-color:var(--accent);">👁 PREVIEW POPUP</a>
    </div>

    <h2 style="color:var(--accent);margin-bottom:1.5rem;font-family:'Righteous',cursive;text-transform:uppercase;">EDITING: <?= h($product['title']) ?></h2>

    <?php foreach ($flash_msgs as $m): ?>
    <div style="background:rgba(57,255,20,0.1);border:1px solid var(--text-main);color:var(--text-main);padding:0.8rem;margin-bottom:1rem;font-family:'VT323';font-size:1.2rem;">&gt; <?= h($m) ?></div>
    <?php endforeach; ?>

    <!-- Product Settings -->
    <form action="/admin/product_edit.php?id=<?= (int)$product['id'] ?>" method="POST" enctype="multipart/form-data">
        <div class="form-grid">
            <div class="form-group"><label>Product Title</label><input type="text" name="title" value="<?= h($product['title']) ?>" required></div>
            <div class="form-group"><label>Author</label><input type="text" name="author" value="<?= h($product['author'] ?? '') ?>"></div>
            <div class="form-group">
                <label>Category</label>
                <select name="type">
                    <option value="loopkit" <?= $product['type']==='loopkit'?'selected':'' ?>>Loop Kit</option>
                    <option value="drumkit"  <?= $product['type']==='drumkit' ?'selected':'' ?>>Drumkit</option>
                    <option value="graphics" <?= $product['type']==='graphics'?'selected':'' ?>>Graphic Art</option>
                    <option value="beat"     <?= $product['type']==='beat'    ?'selected':'' ?>>Beat</option>
                </select>
            </div>
            <div class="form-group">
                <label>Genre</label>
                <select name="genre">
                    <option value="trap"    <?= $product['genre']==='trap'    ?'selected':'' ?>>Trap</option>
                    <option value="melodic" <?= $product['genre']==='melodic' ?'selected':'' ?>>Melodic</option>
                    <option value="drill"   <?= $product['genre']==='drill'   ?'selected':'' ?>>Drill</option>
                    <option value="rnb"     <?= $product['genre']==='rnb'     ?'selected':'' ?>>R&amp;B</option>
                    <option value="all"     <?= $product['genre']==='all'     ?'selected':'' ?>>Multi</option>
                </select>
            </div>
            <div class="form-group"><label>Price ($)</label><input type="number" step="0.01" name="price" value="<?= h($product['price']) ?>"></div>
            <div class="form-group"><label>Premium Price (Stems) ($)</label><input type="number" step="0.01" name="price_premium" value="<?= h($product['price_premium'] ?? '') ?>" placeholder="Leave empty for 2x"></div>
            <div class="form-group"><label>Exclusive Price ($)</label><input type="number" step="0.01" name="price_exclusive" value="<?= h($product['price_exclusive'] ?? '') ?>" placeholder="Leave empty for 10x"></div>
            <div class="form-group"><label>Original Price ($)</label><input type="number" step="0.01" name="original_price" value="<?= h($product['original_price'] ?? '') ?>"></div>
            <div class="form-group"><label>BPM</label><input type="text" name="bpm" value="<?= h($product['bpm'] ?? '') ?>"></div>
            <div class="form-group"><label>Key</label><input type="text" name="key" value="<?= h($product['key'] ?? '') ?>"></div>
        </div>
        <div class="form-group"><label>Popup Description</label><textarea name="description"><?= h($product['description'] ?? '') ?></textarea></div>
        <div class="form-grid">
            <div class="form-group">
                <label>Cover Art</label>
                <?php if ($product['cover_image']): ?>
                <img src="/static/uploads/<?= h($product['cover_image']) ?>" class="thumb" alt="">
                <?php endif; ?>
                <input type="file" name="cover_image" accept=".jpg,.jpeg,.png,.webp">
            </div>
            <div class="form-group">
                <label>Full Product ZIP</label>
                <?php if ($product['zip_file']): ?><p style="color:var(--text-muted);font-size:0.9rem;"><?= h($product['zip_file']) ?></p><?php endif; ?>
                <input type="file" name="zip_file" accept=".zip,.rar,.7z">
            </div>
        </div>
        <div class="checkbox-row" style="margin-bottom:0.8rem;">
            <input type="checkbox" name="is_active" id="edit_active" <?= $product['is_active'] ? 'checked' : '' ?>>
            <label for="edit_active" style="cursor:pointer;color:var(--text-main);">Visible on Public Shop</label>
        </div>
        <div class="checkbox-row" style="margin-bottom:1.5rem;">
            <input type="checkbox" name="allow_download" id="edit_allow_download" <?= ($product['allow_download'] ?? 0) ? 'checked' : '' ?>>
            <label for="edit_allow_download" style="cursor:pointer;color:var(--text-main);">Enable Direct Download Button (for Free Kits)</label>
        </div>
        <button type="submit" class="cta-btn" style="width:100%;padding:1rem;font-size:1.2rem;">💾 SAVE PRODUCT SETTINGS</button>
    </form>

    <hr class="section-divider">

    <!-- Tracks -->
    <div class="section-label">Media Player Preview Tracks</div>

    <?php if (empty($tracks)): ?>
    <div style="padding:2rem;border:2px dashed rgba(123,225,168,0.1);text-align:center;color:var(--text-muted);font-family:'VT323';font-size:1.2rem;margin-bottom:1rem;">NO PREVIEW TRACKS YET. UPLOAD BELOW.</div>
    <?php else: ?>
    <?php foreach ($tracks as $i => $t): ?>
    <div class="track-row" id="track-<?= (int)$t['id'] ?>">
        <div class="track-header">
            <span class="track-num">#<?= $i+1 ?></span>
            <span class="track-title-display"><?= h($t['title']) ?></span>
            <div style="display:flex;gap:0.4rem;">
                <form action="/admin/track_move.php" method="POST" style="display:inline;">
                    <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                    <input type="hidden" name="track_id" value="<?= (int)$t['id'] ?>">
                    <input type="hidden" name="direction" value="up">
                    <button type="submit" class="btn btn-small" <?= $i===0?'disabled style="opacity:0.2;"':'' ?>>&uarr;</button>
                </form>
                <form action="/admin/track_move.php" method="POST" style="display:inline;">
                    <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                    <input type="hidden" name="track_id" value="<?= (int)$t['id'] ?>">
                    <input type="hidden" name="direction" value="down">
                    <button type="submit" class="btn btn-small" <?= $i===count($tracks)-1?'disabled style="opacity:0.2;"':'' ?>>&darr;</button>
                </form>
            </div>
        </div>
        <div class="track-body">
            <audio src="/static/uploads/<?= h($t['filename']) ?>" controls></audio>
            <small style="color:var(--text-muted);"><?= h($t['filename']) ?></small>
            <button type="button" class="btn btn-red btn-small" onclick="showConfirm(<?= (int)$t['id'] ?>)">DELETE</button>
        </div>
        <div class="confirm-bar" id="confirm-<?= (int)$t['id'] ?>">
            ⚠ Confirm permanent deletion of "<?= h($t['title']) ?>"?
            <form action="/admin/track_delete.php" method="POST" style="display:inline;margin:0;">
                <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                <input type="hidden" name="track_id" value="<?= (int)$t['id'] ?>">
                <button type="submit" class="btn btn-red btn-small">YES, DELETE</button>
            </form>
            <button type="button" class="btn btn-small" style="color:var(--text-muted);border-color:var(--text-muted);" onclick="hideConfirm(<?= (int)$t['id'] ?>)">Cancel</button>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Upload Tracks -->
    <div class="upload-box">
        <h3 style="color:var(--text-main);margin-bottom:1rem;font-family:'VT323';font-size:1.4rem;">UPLOAD PREVIEW TRACKS (WAV / MP3)</h3>
        <form action="/admin/track_add.php" method="POST" enctype="multipart/form-data" onsubmit="document.getElementById('loadingOverlay').classList.add('active')">
            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
            <div class="form-group">
                <label>Select audio files (hold Ctrl for multiple)</label>
                <input type="file" name="audio_files[]" accept=".mp3,.wav,.ogg,.flac" multiple required style="padding:1rem;border-style:dashed;">
            </div>
            <button type="submit" class="cta-btn" style="font-size:1.1rem;padding:0.7rem 2rem;">START UPLOAD</button>
        </form>
    </div>

</div>
</div>

<script>
function showConfirm(id) {
    document.querySelectorAll('.confirm-bar').forEach(b => b.classList.remove('show'));
    document.getElementById('confirm-' + id).classList.add('show');
}
function hideConfirm(id) { document.getElementById('confirm-' + id).classList.remove('show'); }
</script>
</body>
</html>
