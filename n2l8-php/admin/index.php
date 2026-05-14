<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
$pdo = get_pdo();

// Stats
$products = $pdo->query('SELECT * FROM products ORDER BY id DESC')->fetchAll();
$orders   = $pdo->query('SELECT o.*, p.title as product_title FROM orders o LEFT JOIN products p ON o.product_id = p.id ORDER BY o.id DESC')->fetchAll();
$logs     = $pdo->query('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 30')->fetchAll();
$contents = $pdo->query('SELECT * FROM content ORDER BY page, section_key')->fetchAll();

$active_count = count(array_filter($products, fn($p) => $p['is_active']));
$pages = array_unique(array_column($contents, 'page'));
sort($pages);

$flash_msgs = get_flash();

// Which tab to show (from URL hash redirect trick)
$tab = $_GET['tab'] ?? 'dashboard';

// Visitor analytics
try {
    $vs_total   = (int)$pdo->query('SELECT COUNT(*) FROM visitor_log')->fetchColumn();
    $vs_unique  = (int)$pdo->query('SELECT COUNT(DISTINCT ip) FROM visitor_log')->fetchColumn();
    $vs_today   = (int)$pdo->query("SELECT COUNT(DISTINCT ip) FROM visitor_log WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $vs_countries = $pdo->query('SELECT country, country_code, COUNT(*) as hits FROM visitor_log WHERE country != "" GROUP BY country,country_code ORDER BY hits DESC LIMIT 20')->fetchAll();
    $vs_actions   = $pdo->query('SELECT action, COUNT(*) as hits FROM visitor_log GROUP BY action ORDER BY hits DESC LIMIT 20')->fetchAll();
    $vs_visitors  = $pdo->query('SELECT ip, MAX(country) as country, MAX(country_code) as country_code, MAX(city) as city, COUNT(*) as hits, MIN(created_at) as first_at, MAX(created_at) as last_at FROM visitor_log GROUP BY ip ORDER BY last_at DESC LIMIT 200')->fetchAll();

    // Popularity metrics
    $vs_top_kits = $pdo->query("SELECT SUBSTRING_INDEX(action, ':', -1) as name, COUNT(*) as hits FROM visitor_log WHERE action LIKE 'modal_open:%' OR action LIKE 'click_buy_kit:%' GROUP BY name ORDER BY hits DESC LIMIT 10")->fetchAll();
    $vs_top_beats = $pdo->query("SELECT SUBSTRING_INDEX(action, ':', -1) as name, COUNT(*) as hits FROM visitor_log WHERE action LIKE 'play_beat:%' OR action LIKE 'play_track:%' OR action LIKE 'click_buy_beat:%' GROUP BY name ORDER BY hits DESC LIMIT 10")->fetchAll();
    $vs_recent = $pdo->query("SELECT * FROM visitor_log ORDER BY created_at DESC LIMIT 50")->fetchAll();
} catch (\Throwable $e) {
    $vs_total = $vs_unique = $vs_today = 0;
    $vs_countries = $vs_actions = $vs_visitors = $vs_top_kits = $vs_top_beats = $vs_recent = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Terminal - n2l8studio</title>
    <link rel="stylesheet" href="/static/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Righteous&family=VT323&display=swap" rel="stylesheet">
    <style>
        body { background-attachment:fixed; }
        .admin-topbar { background:rgba(5,10,5,0.97); border-bottom:2px solid var(--brand-dark-red); padding:0.8rem 2rem; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:100; }
        .admin-topbar .logo-text { font-size:1.3rem; letter-spacing:3px; }
        /* ── TABS: sliding indicator ── */
        .admin-tabs-wrap { position:relative; margin:2rem 0 0 0; border-bottom:2px solid var(--text-muted); }
        .admin-tabs { display:flex; gap:0; position:relative; }
        .tab-slider {
            position:absolute; bottom:-2px; left:0;
            height:2px; background:var(--accent);
            box-shadow:0 0 8px rgba(255,194,92,0.7);
            transition:left 0.28s cubic-bezier(.4,0,.2,1), width 0.28s cubic-bezier(.4,0,.2,1);
            pointer-events:none;
        }
        .admin-tab-btn {
            padding:0.65rem 1.4rem;
            background:transparent; color:var(--text-muted);
            border:none; border-bottom:2px solid transparent;
            font-family:'Righteous',cursive; font-size:1.05rem;
            text-transform:uppercase; cursor:pointer; letter-spacing:1px;
            transition:color 0.2s ease;
            white-space:nowrap;
        }
        .admin-tab-btn:hover { color:var(--text-main); }
        .admin-tab-btn.active { color:var(--accent); }
        /* Mobile: dropdown */
        .admin-tab-dropdown { display:none; }
        .admin-tab-toggle {
            width:100%; padding:0.8rem 1rem;
            background:rgba(10,15,10,0.95); border:1px solid var(--text-muted);
            color:var(--text-main); font-family:'Righteous',cursive; font-size:1.1rem;
            text-transform:uppercase; letter-spacing:1px; cursor:pointer;
            display:flex; justify-content:space-between; align-items:center;
            margin-top:1rem;
        }
        .admin-tab-toggle::after { content:'▼'; font-size:0.8rem; color:var(--accent); transition:transform 0.2s; }
        .admin-tab-toggle.open::after { transform:rotate(180deg); }
        .admin-tab-menu {
            display:none; flex-direction:column;
            background:rgba(7,10,7,0.98);
            border:1px solid var(--text-muted); border-top:none;
        }
        .admin-tab-menu.open { display:flex; }
        .admin-tab-menu-item {
            padding:0.9rem 1.2rem; border-bottom:1px dashed rgba(123,225,168,0.1);
            color:var(--text-muted); font-family:'Righteous',cursive;
            font-size:1.1rem; text-transform:uppercase; cursor:pointer;
            background:transparent; border-left:none; border-right:none; border-top:none; text-align:left;
        }
        .admin-tab-menu-item.active { color:var(--accent); border-left:3px solid var(--accent); padding-left:0.9rem; }
        .admin-tab-menu-item:hover { color:var(--text-main); background:rgba(57,255,20,0.05); }
        .admin-panel { display:none; padding:2rem 0; }
        .admin-panel.active { display:block; }
        @media(max-width:768px){
            .admin-tabs-wrap { display:none; }
            .admin-tab-dropdown { display:block; }
            .stats-grid { grid-template-columns:1fr 1fr; }
            .form-grid { grid-template-columns:1fr; }
            .content-row { grid-template-columns:1fr; }
            .admin-topbar { padding:0.7rem 1rem; }
            .admin-topbar .logo-text { font-size:1rem; }
            .admin-table { font-size:0.9rem; }
            .admin-table th, .admin-table td { padding:0.5rem 0.6rem; }
            .form-card { padding:1rem; }
            .section-title { font-size:1.2rem; }
            .stat-num { font-size:2rem; }
        }
        @media(max-width:480px){
            .stats-grid { grid-template-columns:1fr 1fr; gap:0.8rem; }
            .stat-card { padding:1rem 0.7rem; }
            .action-btns { flex-direction:column; gap:0.3rem; }
        }
        .section-title { font-family:'Righteous',cursive; color:var(--accent); font-size:1.6rem; margin-bottom:1.5rem; letter-spacing:2px; text-transform:uppercase; border-bottom:1px dashed var(--text-muted); padding-bottom:0.5rem; }
        .form-card { background:rgba(10,15,10,0.85); border:1px solid var(--text-muted); padding:2rem; margin-bottom:2rem; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
        .form-full { grid-column:1/-1; }
        .form-group { display:flex; flex-direction:column; gap:0.4rem; }
        .form-group label { color:var(--text-muted); font-size:1rem; font-family:'VT323',monospace; letter-spacing:1px; text-transform:uppercase; }
        .form-group input, .form-group select, .form-group textarea { background:var(--bg-dark); border:1px solid var(--text-muted); color:var(--text-main); font-family:'VT323',monospace; font-size:1.1rem; padding:0.5rem 0.8rem; outline:none; transition:border-color 0.2s; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:var(--text-main); box-shadow:0 0 8px rgba(57,255,20,0.15); }
        .form-group input[type="file"] { cursor:pointer; }
        .form-group textarea { resize:vertical; min-height:80px; }
        .checkbox-row { display:flex; align-items:center; gap:0.8rem; flex-direction:row; }
        .checkbox-row input[type="checkbox"] { width:18px; height:18px; cursor:pointer; accent-color:var(--text-main); }
        .admin-table { width:100%; border-collapse:collapse; font-size:1.05rem; }
        .admin-table th { font-family:'Righteous',cursive; color:var(--accent); text-align:left; padding:0.7rem 1rem; border-bottom:2px solid var(--text-muted); text-transform:uppercase; letter-spacing:1px; font-size:0.95rem; }
        .admin-table td { padding:0.7rem 1rem; border-bottom:1px dashed rgba(123,225,168,0.2); color:var(--text-main); vertical-align:middle; }
        .admin-table tr:hover td { background:rgba(57,255,20,0.03); }
        .pill { display:inline-block; padding:0.15rem 0.6rem; font-size:0.85rem; font-family:'Righteous',cursive; border-radius:2px; text-transform:uppercase; letter-spacing:1px; }
        .pill-active { background:rgba(57,255,20,0.15); color:var(--text-main); border:1px solid var(--text-main); }
        .pill-inactive { background:rgba(255,92,92,0.1); color:#ff5c5c; border:1px solid #ff5c5c; }
        .action-btns { display:flex; gap:0.4rem; flex-wrap:wrap; }
        .btn { padding:0.3rem 0.7rem; font-family:'VT323',monospace; font-size:1rem; cursor:pointer; border:1px solid; background:transparent; transition:all 0.2s; text-decoration:none; display:inline-block; text-transform:uppercase; }
        .btn-green { color:var(--text-main); border-color:var(--text-main); }
        .btn-green:hover { background:var(--text-main); color:var(--bg-dark); }
        .btn-amber { color:var(--accent); border-color:var(--accent); }
        .btn-amber:hover { background:var(--accent); color:var(--bg-dark); }
        .btn-red { color:#ff5c5c; border-color:#ff5c5c; }
        .btn-red:hover { background:#ff5c5c; color:var(--bg-dark); }
        .btn-muted { color:var(--text-muted); border-color:var(--text-muted); }
        .btn-muted:hover { background:var(--text-muted); color:var(--bg-dark); }
        .stats-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:1.5rem; margin-bottom:2rem; }
        .stat-card { background:rgba(10,15,10,0.85); border:1px solid var(--text-muted); padding:1.5rem; text-align:center; }
        .stat-num { font-family:'Righteous',cursive; font-size:2.5rem; color:var(--accent); line-height:1; }
        .stat-label { color:var(--text-muted); font-size:1rem; margin-top:0.3rem; }
        .flash-box { background:rgba(57,255,20,0.1); border:1px solid var(--text-main); color:var(--text-main); padding:0.8rem 1.2rem; margin-bottom:1.5rem; font-size:1.1rem; }
        .log-list { list-style:none; padding:0; }
        .log-list li { padding:0.5rem 0; border-bottom:1px dashed rgba(123,225,168,0.1); font-size:0.95rem; line-height:1.4; }
        .log-ts { color:var(--text-muted); font-size:0.85rem; display:block; }
        .thumb { width:48px; height:48px; object-fit:cover; border:1px solid var(--text-muted); }
        #loadingOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.9); z-index:2000; align-items:center; justify-content:center; flex-direction:column; color:var(--text-main); }
        #loadingOverlay.active { display:flex; }
        .spinner { width:60px; height:60px; border:4px solid var(--text-muted); border-top-color:var(--text-main); border-radius:50%; animation:spin 1s linear infinite; margin-bottom:1.5rem; }
        @keyframes spin { to { transform:rotate(360deg); } }
        .content-page-label { font-family:'Righteous',cursive; color:var(--text-muted); font-size:1.1rem; text-transform:uppercase; letter-spacing:2px; margin:1.5rem 0 0.8rem 0; border-left:3px solid var(--text-muted); padding-left:0.8rem; }
        .content-row { display:grid; grid-template-columns:200px 1fr auto; gap:0.8rem; align-items:center; padding:0.6rem 0; border-bottom:1px dashed rgba(123,225,168,0.1); }
        .content-key-label { color:var(--text-muted); font-size:0.95rem; font-family:'VT323',monospace; }
        .content-row input, .content-row textarea { background:var(--bg-dark); border:1px solid var(--text-muted); color:var(--text-main); font-family:'VT323',monospace; font-size:1rem; padding:0.4rem 0.6rem; outline:none; width:100%; }
        .content-row textarea { resize:vertical; min-height:60px; }
        @media(max-width:900px){ .stats-grid{grid-template-columns:1fr 1fr;} .form-grid{grid-template-columns:1fr;} .content-row{grid-template-columns:1fr;} }
    </style>
</head>
<body class="page-home">

<div id="loadingOverlay">
    <div class="spinner"></div>
    <div style="font-family:'Righteous';font-size:1.8rem;letter-spacing:4px;">INITIALIZING DEPLOYMENT...</div>
    <div style="font-family:'VT323';color:var(--text-muted);font-size:1.2rem;margin-top:1rem;">UPLOADING MEDIA ASSETS — PLEASE STAND BY</div>
</div>

<div class="admin-topbar">
    <div class="logo-text">⚙ N2L8 TERMINAL</div>
    <div style="display:flex;gap:0.8rem;">
        <a href="/index.php" class="btn btn-muted" style="font-family:'Righteous',cursive;font-size:1rem;">View Site</a>
        <a href="/admin/logout.php" class="btn btn-red" style="font-family:'Righteous',cursive;font-size:1rem;">Disconnect</a>
    </div>
</div>

<div class="container" style="max-width:1200px;padding-bottom:4rem;">

    <?php if ($flash_msgs): ?>
    <div style="margin-top:1rem;">
        <?php foreach ($flash_msgs as $m): ?>
        <div class="flash-box">&gt; <?= h($m) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- TABS: desktop sliding indicator -->
    <div class="admin-tabs-wrap">
        <div class="admin-tabs" id="adminTabs">
            <div class="tab-slider" id="tabSlider"></div>
            <button class="admin-tab-btn" id="tab-btn-dashboard">Dashboard</button>
            <button class="admin-tab-btn" id="tab-btn-products">Products</button>
            <button class="admin-tab-btn" id="tab-btn-orders">Orders</button>
            <button class="admin-tab-btn" id="tab-btn-content">Content Editor</button>
            <button class="admin-tab-btn" id="tab-btn-logs">Audit Log</button>
            <button class="admin-tab-btn" id="tab-btn-stats">Analytics</button>
        </div>
    </div>
    <!-- TABS: mobile dropdown -->
    <div class="admin-tab-dropdown">
        <button class="admin-tab-toggle" id="tabToggle">Dashboard <span id="tabToggleLabel"></span></button>
        <div class="admin-tab-menu" id="tabMenu">
            <button class="admin-tab-menu-item" data-tab="dashboard">Dashboard</button>
            <button class="admin-tab-menu-item" data-tab="products">Products</button>
            <button class="admin-tab-menu-item" data-tab="orders">Orders</button>
            <button class="admin-tab-menu-item" data-tab="content">Content Editor</button>
            <button class="admin-tab-menu-item" data-tab="logs">Audit Log</button>
            <button class="admin-tab-menu-item" data-tab="stats">Analytics</button>
        </div>
    </div>

    <!-- ── DASHBOARD ── -->
    <div id="tab-dashboard" class="admin-panel">
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-num"><?= count($products) ?></div><div class="stat-label">Total Products</div></div>
            <div class="stat-card"><div class="stat-num"><?= $active_count ?></div><div class="stat-label">Active Products</div></div>
            <div class="stat-card"><div class="stat-num"><?= count($orders) ?></div><div class="stat-label">Total Orders</div></div>
            <div class="stat-card"><div class="stat-num"><?= count($contents) ?></div><div class="stat-label">Content Blocks</div></div>
        </div>
        <div class="section-title">Recent System Activity</div>
        <div class="form-card">
            <ul class="log-list">
                <?php foreach (array_slice($logs, 0, 15) as $log): ?>
                <li>
                    <span class="log-ts"><?= h($log['created_at']) ?></span>
                    &gt; <?= h($log['action']) ?>
                </li>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?>
                <li style="color:var(--text-muted)">No activity recorded.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <!-- ── PRODUCTS ── -->
    <div id="tab-products" class="admin-panel">
        <div class="section-title">Deploy New Kit</div>
        <div class="form-card">
            <form action="/admin/product_new.php" method="POST" enctype="multipart/form-data" onsubmit="showLoading()">
                <div class="form-grid">
                    <div class="form-group"><label>Title *</label><input type="text" name="title" required placeholder="e.g. OBSIDIAN Vol.2"></div>
                    <div class="form-group"><label>Author / Artist</label><input type="text" name="author" placeholder="n2l8studio"></div>
                    <div class="form-group">
                        <label>Category *</label>
                        <select name="type">
                            <option value="loopkit">Loop &amp; Melody Pack</option>
                            <option value="drumkit">Drumkit</option>
                            <option value="beat">Beat</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Genre Style *</label>
                        <select name="genre">
                            <option value="trap">Trap</option>
                            <option value="melodic">Melodic</option>
                            <option value="drill">Drill</option>
                            <option value="rnb">R&amp;B</option>
                            <option value="all">All Genres</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Price ($) — 0 for Free</label><input type="number" step="0.01" min="0" name="price" value="0"></div>
                    <div class="form-group"><label>Premium Price (Stems) ($)</label><input type="number" step="0.01" min="0" name="price_premium" placeholder="Leave empty for 2x"></div>
                    <div class="form-group"><label>Exclusive Price ($)</label><input type="number" step="0.01" min="0" name="price_exclusive" placeholder="Leave empty for 10x"></div>
                    <div class="form-group"><label>Original Price ($) — for sale</label><input type="number" step="0.01" min="0" name="original_price" placeholder="49.99"></div>
                    <div class="form-group"><label>BPM</label><input type="text" name="bpm" placeholder="140"></div>
                    <div class="form-group"><label>Key</label><input type="text" name="key" placeholder="F Minor"></div>
                    <div class="form-group form-full"><label>Popup Description</label><textarea name="description" placeholder="Describe the contents..."></textarea></div>
                    <div class="form-group"><label style="color:var(--accent);">1. Cover Image (JPG/PNG)</label><input type="file" name="cover_image" accept=".jpg,.jpeg,.png,.webp"></div>
                    <div class="form-group"><label style="color:var(--accent);">2. Full Product ZIP</label><input type="file" name="zip_file" accept=".zip,.rar,.7z"></div>
                    <div class="form-group form-full" style="background:rgba(57,255,20,0.03);padding:1rem;border:1px dashed var(--text-muted);">
                        <label style="color:var(--text-main);font-size:1.1rem;">3. Preview Tracks (WAV / MP3)</label>
                        <p style="font-size:0.9rem;color:var(--text-muted);margin-bottom:0.5rem;">Select one or more audio files for the media player preview.</p>
                        <input type="file" name="audio_files[]" accept=".mp3,.wav,.ogg,.flac" multiple style="border:none;padding:1rem 0;">
                    </div>
                    <div class="form-group form-full">
                        <div class="checkbox-row">
                            <input type="checkbox" name="is_active" id="new_active" checked>
                            <label for="new_active" style="cursor:pointer;margin-bottom:0;">Active (visible on shop)</label>
                        </div>
                    </div>
                </div>
                <br>
                <button type="submit" class="cta-btn" style="font-size:1.1rem;padding:0.8rem 3rem;">Deploy Product</button>
            </form>
        </div>

        <div class="section-title">All Products</div>
        <div class="form-card" style="padding:0;overflow-x:auto;">
            <table class="admin-table">
                <thead><tr><th></th><th>Title</th><th>Type</th><th>Genre</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                    <tr>
                        <td>
                            <?php if ($p['cover_image']): ?>
                            <img src="/static/uploads/<?= h($p['cover_image']) ?>" class="thumb" alt="">
                            <?php else: ?>
                            <div class="thumb" style="background:var(--bg-card);"></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= h($p['title']) ?></strong>
                            <?php if ($p['author']): ?><br><span style="color:var(--text-muted);font-size:0.9rem;"><?= h($p['author']) ?></span><?php endif; ?>
                        </td>
                        <td><?= h($p['type']) ?></td>
                        <td><?= h($p['genre']) ?></td>
                        <td><?= $p['price'] > 0 ? '$'.number_format((float)$p['price'],2) : '<span style="color:var(--text-main);">FREE</span>' ?></td>
                        <td>
                            <?php if ($p['is_active']): ?>
                            <span class="pill pill-active">Active</span>
                            <?php else: ?>
                            <span class="pill pill-inactive">Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-btns">
                                <a href="/admin/product_edit.php?id=<?= (int)$p['id'] ?>" class="btn btn-amber">Edit &amp; Tracks</a>
                                <form action="/admin/product_toggle.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <button class="btn btn-muted" type="submit"><?= $p['is_active'] ? 'Disable' : 'Enable' ?></button>
                                </form>
                                <form action="/admin/product_delete.php" method="POST" style="display:inline;" id="del-form-<?= (int)$p['id'] ?>">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <button class="btn btn-red" type="button" onclick="showDelConfirm(<?= (int)$p['id'] ?>)">X</button>
                                </form>
                                <span id="del-confirm-<?= (int)$p['id'] ?>" style="display:none;color:#ff5c5c;font-family:'VT323',monospace;font-size:1rem;">
                                    Sure? <button class="btn btn-red" type="button" onclick="document.getElementById('del-form-<?= (int)$p['id'] ?>').submit()">YES</button>
                                    <button class="btn btn-muted" type="button" onclick="hideDelConfirm(<?= (int)$p['id'] ?>)">No</button>
                                </span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── ORDERS ── -->
    <div id="tab-orders" class="admin-panel">
        <div class="section-title">Customer Orders</div>
        <div class="form-card" style="padding:0;overflow-x:auto;">
            <table class="admin-table">
                <thead><tr><th>Order ID</th><th>Customer</th><th>Product</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                    <tr>
                        <td>#<?= (int)$o['id'] ?></td>
                        <td><?= h($o['customer_email']) ?></td>
                        <td><?= h($o['product_title'] ?? '(deleted)') ?></td>
                        <td><span class="pill pill-active"><?= h($o['status']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($orders)): ?>
                    <tr><td colspan="4" style="color:var(--text-muted);text-align:center;padding:2rem;">No orders yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── CONTENT EDITOR ── -->
    <div id="tab-content" class="admin-panel">
        <div class="section-title">Global Content Editor</div>
        <?php foreach ($pages as $page): ?>
        <div class="content-page-section">
            <div class="content-page-label">— <?= h($page) ?> Page —</div>
            <?php foreach ($contents as $c): ?>
            <?php if ($c['page'] !== $page) continue; ?>
            <form action="/admin/content_update.php" method="POST">
                <input type="hidden" name="section_key" value="<?= h($c['section_key']) ?>">
                <div class="content-row">
                    <span class="content-key-label"><?= h($c['label']) ?></span>
                    <?php if (strlen($c['text']) > 80 || strpos($c['text'], "\n") !== false): ?>
                    <textarea name="text" rows="3"><?= h($c['text']) ?></textarea>
                    <?php else: ?>
                    <input type="text" name="text" value="<?= h($c['text']) ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-green">Save</button>
                </div>
            </form>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── AUDIT LOG ── -->
    <div id="tab-logs" class="admin-panel">
        <div class="section-title">Full Audit Log</div>
        <div class="form-card">
            <ul class="log-list">
                <?php foreach ($logs as $log): ?>
                <li><span class="log-ts"><?= h($log['created_at']) ?></span>&gt; <?= h($log['action']) ?></li>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?>
                <li style="color:var(--text-muted)">No activity recorded.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <!-- ── ANALYTICS ── -->
    <div id="tab-stats" class="admin-panel">

        <!-- Overview cards -->
        <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);">
            <div class="stat-card">
                <div class="stat-num"><?= number_format($vs_total) ?></div>
                <div class="stat-label">Total Page Views</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?= number_format($vs_unique) ?></div>
                <div class="stat-label">Unique Visitors</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?= number_format($vs_today) ?></div>
                <div class="stat-label">Unique Today</div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">

            <!-- Countries -->
            <div class="form-card" style="margin-bottom:0;">
                <div class="section-title" style="font-size:1.1rem;">Top Countries</div>
                <?php if ($vs_countries): $max_c = max(array_column($vs_countries,'hits')); foreach($vs_countries as $c): ?>
                <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.5rem;">
                    <span style="font-size:1.3rem;line-height:1;"><?= flag_emoji($c['country_code']) ?></span>
                    <span style="color:var(--text-muted);font-family:'VT323',monospace;font-size:1rem;width:130px;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($c['country']) ?></span>
                    <div style="flex:1;height:6px;background:rgba(255,194,92,0.15);border-radius:2px;">
                        <div style="width:<?= round(($c['hits']/$max_c)*100) ?>%;height:100%;background:var(--accent);border-radius:2px;"></div>
                    </div>
                    <span style="color:var(--accent);font-family:'Righteous',cursive;font-size:0.9rem;flex-shrink:0;"><?= $c['hits'] ?></span>
                </div>
                <?php endforeach; else: ?>
                <p style="color:var(--text-muted);">No data yet.</p>
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <div class="form-card" style="margin-bottom:0;">
                <div class="section-title" style="font-size:1.1rem;">Top Actions</div>
                <?php if ($vs_actions): $max_a = max(array_column($vs_actions,'hits')); foreach($vs_actions as $a): ?>
                <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.5rem;">
                    <span style="color:var(--text-muted);font-family:'VT323',monospace;font-size:1rem;width:170px;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($a['action']) ?></span>
                    <div style="flex:1;height:6px;background:rgba(51,255,153,0.1);border-radius:2px;">
                        <div style="width:<?= round(($a['hits']/$max_a)*100) ?>%;height:100%;background:var(--text-main);border-radius:2px;"></div>
                    </div>
                    <span style="color:var(--text-main);font-family:'Righteous',cursive;font-size:0.9rem;flex-shrink:0;"><?= $a['hits'] ?></span>
                </div>
                <?php endforeach; else: ?>
                <p style="color:var(--text-muted);">No data yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;margin-top:1.5rem;">
            <!-- Top Kits -->
            <div class="form-card" style="margin-bottom:0; border-color:var(--accent);">
                <div class="section-title" style="font-size:1.1rem; color:var(--accent);">🔥 Most Popular Kits</div>
                <?php if ($vs_top_kits): $max_k = max(array_column($vs_top_kits,'hits')); foreach($vs_top_kits as $k): ?>
                <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.5rem;">
                    <span style="color:var(--text-main);font-family:'VT323',monospace;font-size:1rem;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($k['name']) ?></span>
                    <span style="color:var(--accent);font-family:'Righteous',cursive;font-size:0.95rem;"><?= $k['hits'] ?> views</span>
                </div>
                <?php endforeach; else: ?>
                <p style="color:var(--text-muted);">No kit activity yet.</p>
                <?php endif; ?>
            </div>

            <!-- Top Beats -->
            <div class="form-card" style="margin-bottom:0; border-color:var(--text-main);">
                <div class="section-title" style="font-size:1.1rem; color:var(--text-main);">🎧 Most Played Beats</div>
                <?php if ($vs_top_beats): $max_b = max(array_column($vs_top_beats,'hits')); foreach($vs_top_beats as $b): ?>
                <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.5rem;">
                    <span style="color:var(--accent);font-family:'VT323',monospace;font-size:1rem;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($b['name']) ?></span>
                    <span style="color:var(--text-main);font-family:'Righteous',cursive;font-size:0.95rem;"><?= $b['hits'] ?> plays</span>
                </div>
                <?php endforeach; else: ?>
                <p style="color:var(--text-muted);">No beat activity yet.</p>
                <?php endif; ?>
            </div>
        </div>

            </div>
        </div>

        <div class="section-title">Live Action Feed <span style="font-size:0.8rem;color:var(--text-muted);font-family:'VT323',monospace;">— Last 50 events</span></div>
        <div class="form-card" style="max-height:400px;overflow-y:auto;padding:0.5rem;">
            <ul class="log-list" style="font-size:0.9rem;">
                <?php foreach ($vs_recent as $r): ?>
                <li style="border-bottom:1px solid rgba(123,225,168,0.05);padding:4px 0;">
                    <span class="log-ts" style="width:140px;"><?= substr($r['created_at'],11) ?></span>
                    <span style="color:var(--accent);width:100px;display:inline-block;"><?= h($r['ip']) ?></span>
                    <span style="color:var(--text-main);"><?= h($r['action']) ?></span>
                    <span style="color:var(--text-muted);font-size:0.8rem;float:right;"><?= h($r['page']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Visitors table (click to drill down) -->
        <div class="section-title">All Visitors <span style="font-size:0.8rem;color:var(--text-muted);font-family:'VT323',monospace;">— click a row to view their full timeline</span></div>
        <div class="form-card" style="padding:0;overflow-x:auto;">
            <table class="admin-table">
                <thead><tr>
                    <th>IP Address</th>
                    <th>Location</th>
                    <th style="text-align:center;">Actions</th>
                    <th>First Visit</th>
                    <th>Last Seen</th>
                </tr></thead>
                <tbody>
                <?php foreach ($vs_visitors as $v): ?>
                <tr style="cursor:pointer;" onclick="window.open('/admin/visitor.php?ip=<?= urlencode($v['ip']) ?>','_blank')">
                    <td style="font-family:'Righteous',cursive;color:var(--accent);">
                        <?= h($v['ip']) ?>
                    </td>
                    <td>
                        <?php if ($v['country']): ?>
                        <span style="font-size:1.1rem;"><?= flag_emoji($v['country_code']) ?></span>
                        <span style="color:var(--text-muted);font-size:0.9rem;">
                            <?= h($v['city'] ? $v['city'] . ', ' : '') ?><?= h($v['country']) ?>
                        </span>
                        <?php else: ?>
                        <span style="color:var(--text-muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-family:'Righteous',cursive;color:var(--text-main);"><?= $v['hits'] ?></td>
                    <td style="color:var(--text-muted);font-size:0.85rem;"><?= h(substr($v['first_at'],0,16)) ?></td>
                    <td style="color:var(--text-muted);font-size:0.85rem;"><?= h(substr($v['last_at'],0,16)) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($vs_visitors)): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:2rem;">No visitors logged yet. Visit the site to start tracking.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div><!-- /tab-stats -->

</div><!-- /container -->

<script>
const INITIAL_TAB = '<?= h($tab) ?>';
const TAB_NAMES = { dashboard:'Dashboard', products:'Products', orders:'Orders', content:'Content Editor', logs:'Audit Log', stats:'Analytics' };

function moveSlider(btn) {
    const slider = document.getElementById('tabSlider');
    const rail   = document.getElementById('adminTabs');
    if (!slider || !btn || !rail) return;
    const railRect = rail.getBoundingClientRect();
    const btnRect  = btn.getBoundingClientRect();
    slider.style.left  = (btnRect.left - railRect.left) + 'px';
    slider.style.width = btnRect.width + 'px';
}

function showTab(name) {
    document.querySelectorAll('.admin-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.admin-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.admin-tab-menu-item').forEach(b => b.classList.remove('active'));

    const panel  = document.getElementById('tab-' + name);
    const deskBtn = document.getElementById('tab-btn-' + name);
    if (panel)   panel.classList.add('active');
    if (deskBtn) { deskBtn.classList.add('active'); moveSlider(deskBtn); }

    // mobile dropdown
    document.querySelectorAll('.admin-tab-menu-item').forEach(b => {
        if (b.dataset.tab === name) b.classList.add('active');
    });
    const toggle = document.getElementById('tabToggle');
    if (toggle) toggle.textContent = TAB_NAMES[name] || name;
    // close menu
    document.getElementById('tabMenu')?.classList.remove('open');
    document.getElementById('tabToggle')?.classList.remove('open');

    history.replaceState(null,'','/admin/index.php?tab=' + name);
}

function showLoading() { document.getElementById('loadingOverlay').classList.add('active'); }
function showDelConfirm(id) { document.getElementById('del-confirm-' + id).style.display = 'inline'; }
function hideDelConfirm(id) { document.getElementById('del-confirm-' + id).style.display = 'none'; }

// Desktop tab click
document.querySelectorAll('.admin-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => showTab(btn.id.replace('tab-btn-','')));
});

// Mobile dropdown toggle
const tabToggle = document.getElementById('tabToggle');
const tabMenu   = document.getElementById('tabMenu');
if (tabToggle) {
    tabToggle.addEventListener('click', () => {
        tabToggle.classList.toggle('open');
        tabMenu.classList.toggle('open');
    });
}
document.querySelectorAll('.admin-tab-menu-item').forEach(item => {
    item.addEventListener('click', () => showTab(item.dataset.tab));
});

// Init — wait for layout so slider can measure button positions
window.addEventListener('load', () => showTab(INITIAL_TAB));
</script>
</body>
</html>
