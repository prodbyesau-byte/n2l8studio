<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_owner();
$pdo = get_pdo();

// Stats
$products = $pdo->query('SELECT * FROM products ORDER BY id DESC')->fetchAll();
$orders   = $pdo->query('SELECT o.*, p.title as product_title FROM orders o LEFT JOIN products p ON o.product_id = p.id ORDER BY o.id DESC')->fetchAll();
$logs     = $pdo->query('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 30')->fetchAll();
$contents = $pdo->query('SELECT * FROM content ORDER BY page, section_key')->fetchAll();

$all_users = $pdo->query('SELECT id, username, email, role, is_approved FROM users ORDER BY id DESC')->fetchAll();
$pending_users = array_filter($all_users, fn($u) => !$u['is_approved'] && $u['role'] !== 'admin');
$approved_users = array_filter($all_users, fn($u) => $u['is_approved'] && $u['role'] !== 'admin');
$sent_messages = $pdo->query('
    SELECT m.*, u.username as recipient_username 
    FROM messages m 
    JOIN users u ON m.recipient_id = u.id 
    ORDER BY m.id DESC
')->fetchAll();

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
    $vs_top_beats = $pdo->query("SELECT SUBSTRING_INDEX(action, ':', -1) as name, COUNT(*) as hits FROM visitor_log WHERE action LIKE 'play_beat:%' OR action LIKE 'click_buy_beat:%' GROUP BY name ORDER BY hits DESC LIMIT 10")->fetchAll();
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
    <link rel="stylesheet" href="/static/style.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { background-attachment:fixed; }
        .admin-topbar { background:rgba(10,10,12,0.85); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border-bottom:1px solid rgba(192,21,42,0.3); padding:0.8rem 2rem; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:100; }
        .admin-topbar .logo-text { font-family:'Syncopate',sans-serif; font-weight:700; font-size:1.1rem; letter-spacing:3px; text-shadow: 0 0 10px rgba(192,21,42,0.6); }
        /* ── TABS: sliding indicator ── */
        .admin-tabs-wrap { position:relative; margin:2rem 0 0 0; border-bottom:2px solid var(--text-muted); }
        .admin-tabs { display:flex; gap:0; position:relative; }
        .tab-slider {
            position:absolute; bottom:-2px; left:0;
            height:2px; background:var(--accent);
            box-shadow:0 0 8px rgba(192,21,42,0.6);
            transition:left 0.28s cubic-bezier(.4,0,.2,1), width 0.28s cubic-bezier(.4,0,.2,1);
            pointer-events:none;
        }
        .admin-tab-btn {
            padding:0.65rem 1.4rem;
            background:transparent; color:var(--text-muted);
            border:none; border-bottom:2px solid transparent;
            font-family:'Montserrat',sans-serif; font-weight:600; font-size:0.9rem;
            text-transform:uppercase; cursor:pointer; letter-spacing:1px;
            transition:all 0.25s cubic-bezier(0.25, 0.8, 0.25, 1);
            white-space:nowrap;
        }
        .admin-tab-btn:hover { color:var(--text-main); }
        .admin-tab-btn.active { color:var(--accent); }

        /* ── COMMUNICATIONS DROPDOWN ── */
        .admin-tab-dropdown-comms {
            position: relative;
            display: inline-block;
        }
        .comms-dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background: rgba(18, 18, 21, 0.98);
            border: 1px solid rgba(192, 21, 42, 0.3);
            box-shadow: 0 10px 30px rgba(0,0,0,0.8), 0 0 15px rgba(192, 21, 42, 0.15);
            z-index: 1000;
            min-width: 200px;
            border-radius: 4px;
            padding: 0.5rem 0;
            backdrop-filter: blur(10px);
            transition: all 0.25s cubic-bezier(0.25, 0.8, 0.25, 1);
            transform: translateY(10px);
            opacity: 0;
        }
        .admin-tab-dropdown-comms:hover .comms-dropdown-menu,
        .admin-tab-dropdown-comms.open .comms-dropdown-menu {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }
        .dropdown-item-btn {
            width: 100%;
            padding: 0.7rem 1.2rem;
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-family: 'Montserrat', sans-serif;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            text-align: left;
            cursor: pointer;
            letter-spacing: 1px;
            transition: all 0.2s ease;
        }
        .dropdown-item-btn:hover {
            color: var(--text-main);
            background: rgba(192, 21, 42, 0.1);
            padding-left: 1.5rem;
        }
        .dropdown-item-btn.active {
            color: var(--accent);
            background: rgba(192, 21, 42, 0.15);
            border-left: 3px solid var(--accent);
            padding-left: 1.4rem;
        }
        /* Mobile: dropdown */
        .admin-tab-dropdown { display:none; }
        .admin-tab-toggle {
            width:100%; padding:0.8rem 1rem;
            background:rgba(26,26,31,0.95); border:1px solid var(--text-muted);
            color:var(--text-main); font-family:'Montserrat',sans-serif; font-weight:600; font-size:0.95rem;
            text-transform:uppercase; letter-spacing:1px; cursor:pointer;
            display:flex; justify-content:space-between; align-items:center;
            margin-top:1rem;
        }
        .admin-tab-toggle::after { content:'▼'; font-size:0.8rem; color:var(--accent); transition:transform 0.2s; }
        .admin-tab-toggle.open::after { transform:rotate(180deg); }
        .admin-tab-menu {
            display:none; flex-direction:column;
            background:rgba(18,18,21,0.98);
            border:1px solid var(--text-muted); border-top:none;
        }
        .admin-tab-menu.open { display:flex; }
        .admin-tab-menu-item {
            padding:0.9rem 1.2rem; border-bottom:1px dashed rgba(192,21,42,0.1);
            color:var(--text-muted); font-family:'Montserrat',sans-serif; font-weight:600;
            font-size:0.95rem; text-transform:uppercase; cursor:pointer;
            background:transparent; border-left:none; border-right:none; border-top:none; text-align:left;
        }
        .admin-tab-menu-item.active { color:var(--accent); border-left:3px solid var(--accent); padding-left:0.9rem; }
        .admin-tab-menu-item:hover { color:var(--text-main); background:rgba(192,21,42,0.06); }
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
        .section-title { font-family:'Syncopate',sans-serif; font-weight:700; color:var(--accent); font-size:1.3rem; margin-bottom:1.5rem; letter-spacing:2px; text-transform:uppercase; border-bottom:1px dashed var(--text-muted); padding-bottom:0.5rem; }
        .form-card {
            background: rgba(22, 22, 26, 0.65);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.06);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.4), inset 0 1px 0 0 rgba(255,255,255,0.05);
            padding: 2rem;
            margin-bottom: 2rem;
            border-radius: 8px;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        .form-card:hover {
            border-color: rgba(192, 21, 42, 0.35);
            box-shadow: 0 12px 40px rgba(192, 21, 42, 0.12), inset 0 1px 0 0 rgba(255,255,255,0.08);
            transform: translateY(-2px);
        }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
        .form-full { grid-column:1/-1; }
        .form-group { display:flex; flex-direction:column; gap:0.4rem; }
        .form-group label { color:var(--text-muted); font-size:0.8rem; font-family:'Montserrat',sans-serif; font-weight:600; letter-spacing:1px; text-transform:uppercase; }
        .form-group input, .form-group select, .form-group textarea {
            background: rgba(18, 18, 21, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: var(--text-main);
            font-family: 'Montserrat', sans-serif;
            font-size: 0.95rem;
            padding: 0.6rem 0.8rem;
            outline: none;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            border-radius: 4px;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 12px rgba(192, 21, 42, 0.4);
            background: rgba(22, 22, 26, 0.95);
        }
        .form-group input[type="file"] { cursor:pointer; }
        .form-group textarea { resize:vertical; min-height:80px; }
        .checkbox-row { display:flex; align-items:center; gap:0.8rem; flex-direction:row; }
        .checkbox-row input[type="checkbox"] { width:18px; height:18px; cursor:pointer; accent-color:var(--text-main); }
        .admin-table { width:100%; border-collapse:collapse; font-size:1.05rem; }
        .admin-table th { font-family:'Montserrat',sans-serif; font-weight:700; color:var(--accent); text-align:left; padding:0.7rem 1rem; border-bottom:2px solid var(--text-muted); text-transform:uppercase; letter-spacing:1px; font-size:0.8rem; }
        .admin-table td { padding:0.7rem 1rem; border-bottom:1px dashed rgba(192,21,42,0.15); color:var(--text-main); vertical-align:middle; font-family:'Montserrat',sans-serif; font-size:0.9rem; }
        .admin-table tr:hover td { background:rgba(192,21,42,0.04); }
        .pill { display:inline-block; padding:0.2rem 0.6rem; font-size:0.75rem; font-family:'Montserrat',sans-serif; font-weight:700; border-radius:2px; text-transform:uppercase; letter-spacing:1px; }
        .pill-active { background:rgba(192,21,42,0.12); color:var(--text-main); border:1px solid var(--accent); }
        .pill-inactive { background:rgba(255,92,92,0.1); color:#ff5c5c; border:1px solid #ff5c5c; }
        .action-btns { display:flex; gap:0.4rem; flex-wrap:wrap; }
        .btn { padding:0.4rem 0.8rem; font-family:'Montserrat',sans-serif; font-weight:600; font-size:0.8rem; cursor:pointer; border:1px solid; background:transparent; transition:all 0.2s; text-decoration:none; display:inline-block; text-transform:uppercase; letter-spacing:1px; border-radius:4px; }
        .btn-green { color:var(--text-main); border-color:var(--text-main); }
        .btn-green:hover { background:var(--text-main); color:var(--bg-dark); }
        .btn-amber { color:var(--accent); border-color:var(--accent); }
        .btn-amber:hover { background:var(--accent); color:var(--bg-dark); }
        .btn-red { color:#ff5c5c; border-color:#ff5c5c; }
        .btn-red:hover { background:#ff5c5c; color:var(--bg-dark); }
        .btn-muted { color:var(--text-muted); border-color:var(--text-muted); }
        .btn-muted:hover { background:var(--text-muted); color:var(--bg-dark); }
        .stats-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:1.5rem; margin-bottom:2rem; }
        .stat-card {
            background: rgba(22, 22, 26, 0.65);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.06);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.4), inset 0 1px 0 0 rgba(255,255,255,0.05);
            padding: 1.5rem;
            text-align: center;
            border-radius: 8px;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        .stat-card:hover {
            border-color: rgba(192, 21, 42, 0.35);
            box-shadow: 0 12px 40px rgba(192, 21, 42, 0.12), inset 0 1px 0 0 rgba(255,255,255,0.08);
            transform: translateY(-4px);
        }
        .stat-num { font-family:'Syncopate',sans-serif; font-weight:700; font-size:1.8rem; color:var(--accent); line-height:1; }
        .stat-label { color:var(--text-muted); font-size:1rem; margin-top:0.3rem; }
        .flash-box { background:rgba(192,21,42,0.1); border:1px solid var(--accent); color:var(--text-main); padding:0.8rem 1.2rem; margin-bottom:1.5rem; font-size:1.1rem; }
        .log-list { list-style:none; padding:0; }
        .log-list li { padding:0.5rem 0; border-bottom:1px dashed rgba(192,21,42,0.1); font-size:0.95rem; line-height:1.4; }
        .log-ts { color:var(--text-muted); font-size:0.85rem; display:block; }
        .thumb { width:48px; height:48px; object-fit:cover; border:1px solid var(--text-muted); }
        #loadingOverlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.9); z-index:2000; align-items:center; justify-content:center; flex-direction:column; color:var(--text-main); }
        #loadingOverlay.active { display:flex; }
        .spinner { width:60px; height:60px; border:4px solid var(--text-muted); border-top-color:var(--text-main); border-radius:50%; animation:spin 1s linear infinite; margin-bottom:1.5rem; }
        @keyframes spin { to { transform:rotate(360deg); } }
        .content-page-label { font-family:'Syncopate',sans-serif; font-weight:700; color:var(--text-muted); font-size:0.95rem; text-transform:uppercase; letter-spacing:2px; margin:1.5rem 0 0.8rem 0; border-left:3px solid var(--text-muted); padding-left:0.8rem; }
        .content-row { display:grid; grid-template-columns:200px 1fr auto; gap:0.8rem; align-items:center; padding:0.6rem 0; border-bottom:1px dashed rgba(192,21,42,0.1); }
        .content-key-label { color:var(--text-muted); font-size:0.8rem; font-family:'Montserrat',sans-serif; font-weight:600; text-transform:uppercase; }
        .content-row input, .content-row textarea { background:var(--bg-dark); border:1px solid var(--text-muted); color:var(--text-main); font-family:'Montserrat',sans-serif; font-size:0.9rem; padding:0.4rem 0.6rem; outline:none; width:100%; border-radius:4px; }
        .content-row textarea { resize:vertical; min-height:60px; }
        @media(max-width:900px){ .stats-grid{grid-template-columns:1fr 1fr;} .form-grid{grid-template-columns:1fr;} .content-row{grid-template-columns:1fr;} }

        /* ── EMAIL PORTAL REDESIGN ── */
        .preset-btn { margin-bottom: 0.3rem; text-transform: none !important; font-size: 0.8rem !important; border-radius: 4px; padding: 0.4rem 0.8rem; }
        .preset-btn:hover { background: var(--accent) !important; color: #fff !important; border-color: var(--accent) !important; }
        .email-portal-layout { display:grid; grid-template-columns:1.2fr 1fr; gap:2rem; }
        @media(max-width:992px){
            .email-portal-layout { grid-template-columns: 1fr; gap: 1.5rem; }
        }
        .mock-inbox-wrapper { border: 1px solid var(--text-muted); border-radius: 8px; background: rgba(18,18,21,0.95); box-shadow: 0 4px 20px rgba(0,0,0,0.5); overflow:hidden; }
        .mock-inbox-wrapper code { background: rgba(192,21,42,0.15); color: var(--accent); padding: 0.1rem 0.3rem; border-radius: 3px; font-size: 0.85rem; font-family: monospace; }
        #emailMessage:focus, #emailSubject:focus, #customEmailInput:focus, #recipientType:focus, #singleUserSelect:focus, #templateType:focus {
            border-color: var(--accent) !important;
            box-shadow: 0 0 10px rgba(192,21,42,0.3) !important;
        }
    </style>
</head>
<body class="page-home">

<div id="loadingOverlay">
    <div class="spinner"></div>
    <div style="font-family:'Syncopate',sans-serif;font-weight:700;font-size:1.4rem;letter-spacing:4px;">INITIALIZING DEPLOYMENT...</div>
    <div style="font-family:'Montserrat',sans-serif;font-weight:500;color:var(--text-muted);font-size:0.95rem;margin-top:1rem;letter-spacing:1px;">UPLOADING MEDIA ASSETS — PLEASE STAND BY</div>
</div>

<div class="admin-topbar">
    <div class="logo-text">⚙ N2L8 TERMINAL</div>
    <div style="display:flex;gap:0.8rem;">
        <a href="/index.php" class="btn btn-muted" style="font-family:'Syncopate',sans-serif;font-size:0.8rem;letter-spacing:1px;font-weight:700;">View Site</a>
        <a href="/admin/logout.php" class="btn btn-red" style="font-family:'Syncopate',sans-serif;font-size:0.8rem;letter-spacing:1px;font-weight:700;">Disconnect</a>
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
            
            <!-- Communications Dropdown -->
            <div class="admin-tab-dropdown-comms" id="commsDropdownContainer">
                <button class="admin-tab-btn dropdown-trigger" id="tab-btn-comms">Communications <span style="font-size:0.75rem;margin-left:0.3rem;">▼</span></button>
                <div class="comms-dropdown-menu">
                    <button class="dropdown-item-btn" id="tab-btn-email">📬 Email</button>
                    <button class="dropdown-item-btn" id="tab-btn-messages">💬 Messages</button>
                </div>
            </div>
            
            <button class="admin-tab-btn" id="tab-btn-users">Users</button>
            <button class="admin-tab-btn" id="tab-btn-content">Content Editor</button>
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
            
            <!-- Mobile Communications grouping -->
            <div style="background:rgba(0,0,0,0.2);padding:0.4rem 0;border-bottom:1px dashed rgba(192,21,42,0.15);border-top:1px dashed rgba(192,21,42,0.15);">
                <div style="font-family:'Syncopate',sans-serif;font-size:0.75rem;font-weight:700;color:var(--accent);letter-spacing:1px;padding:0.4rem 1.2rem 0.2rem 1.2rem;text-transform:uppercase;">Communications</div>
                <button class="admin-tab-menu-item" data-tab="email" style="padding-left:2rem;border-bottom:none;width:100%;">📬 Email</button>
                <button class="admin-tab-menu-item" data-tab="messages" style="padding-left:2rem;border-bottom:none;width:100%;">💬 Messages</button>
            </div>

            <button class="admin-tab-menu-item" data-tab="users">Users</button>
            <button class="admin-tab-menu-item" data-tab="content">Content Editor</button>
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
                            <option value="graphics">Graphic Art</option>
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
                        <div class="checkbox-row" style="margin-bottom:0.5rem;">
                            <input type="checkbox" name="is_active" id="new_active" checked>
                            <label for="new_active" style="cursor:pointer;margin-bottom:0;">Active (visible on shop)</label>
                        </div>
                        <div class="checkbox-row">
                            <input type="checkbox" name="allow_download" id="new_allow_download">
                            <label for="new_allow_download" style="cursor:pointer;margin-bottom:0;">Enable Direct Download Button (for Free Kits)</label>
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
                                <span id="del-confirm-<?= (int)$p['id'] ?>" style="display:none;color:#ff5c5c;font-family:'Montserrat',sans-serif;font-weight:600;font-size:0.85rem;">
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

    <!-- ── MESSAGES ── -->
    <div id="tab-messages" class="admin-panel">
        <div class="section-title">Send Message to User</div>
        <div class="form-card">
            <form action="/admin/send_message.php" method="POST">
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label>Recipient *</label>
                        <select name="recipient_id" required>
                            <option value="">-- Select Recipient --</option>
                            <?php foreach ($all_users as $u): ?>
                                <option value="<?= (int)$u['id'] ?>"><?= h($u['username']) ?> (<?= h($u['email'] ?: 'No email') ?>) [<?= h($u['role']) ?>]</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group form-full">
                        <label>Subject *</label>
                        <input type="text" name="subject" required placeholder="e.g. Welcome to N2L8 Studio Premium!">
                    </div>
                    <div class="form-group form-full">
                        <label>Message *</label>
                        <textarea name="message" required placeholder="Type your message here..." style="min-height: 150px;"></textarea>
                    </div>
                </div>
                <br>
                <button type="submit" class="cta-btn" style="font-size:1.1rem;padding:0.8rem 3rem;">Send Message</button>
            </form>
        </div>

        <div class="section-title">Sent Messages History</div>
        <div class="form-card" style="padding:0;overflow-x:auto;">
            <table class="admin-table">
                <thead><tr><th>Recipient</th><th>Subject</th><th>Sent Date</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if (empty($sent_messages)): ?>
                        <tr><td colspan="4" style="color:var(--text-muted);text-align:center;padding:2rem;">No messages sent yet.</td></tr>
                    <?php else: foreach ($sent_messages as $msg): ?>
                        <tr>
                            <td><strong><?= h($msg['recipient_username']) ?></strong></td>
                            <td><?= h($msg['subject']) ?></td>
                            <td style="color:var(--text-muted);font-size:0.9rem;"><?= h($msg['created_at']) ?></td>
                            <td>
                                <?php if ($msg['is_read']): ?>
                                    <span class="pill pill-active">Read</span>
                                <?php else: ?>
                                    <span class="pill pill-inactive" style="background:rgba(255,194,92,0.1);color:#ffc25c;border-color:#ffc25c;">Unread</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
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

        <div style="display:grid;grid-template-columns:1fr;gap:1.5rem;margin-bottom:1.5rem;">

            <!-- Countries -->
            <div class="form-card" style="margin-bottom:0;">
                <div class="section-title" style="font-size:1.1rem;">Top Countries</div>
                <?php if ($vs_countries): $max_c = max(array_column($vs_countries,'hits')); foreach($vs_countries as $c): ?>
                <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.5rem;">
                    <span style="font-size:1.3rem;line-height:1;"><?= flag_emoji($c['country_code']) ?></span>
                    <span style="color:var(--text-muted);font-family:'Montserrat',sans-serif;font-weight:500;font-size:0.85rem;width:130px;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($c['country']) ?></span>
                    <div style="flex:1;height:6px;background:rgba(255,194,92,0.15);border-radius:2px;">
                        <div style="width:<?= round(($c['hits']/$max_c)*100) ?>%;height:100%;background:var(--accent);border-radius:2px;"></div>
                    </div>
                    <span style="color:var(--accent);font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.85rem;flex-shrink:0;"><?= $c['hits'] ?></span>
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
                    <span style="color:var(--text-main);font-family:'Montserrat',sans-serif;font-weight:500;font-size:0.85rem;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($k['name']) ?></span>
                    <span style="color:var(--accent);font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.8rem;text-transform:uppercase;letter-spacing:0.5px;"><?= $k['hits'] ?> views</span>
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
                    <span style="color:var(--accent);font-family:'Montserrat',sans-serif;font-weight:500;font-size:0.85rem;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($b['name']) ?></span>
                    <span style="color:var(--text-main);font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.8rem;text-transform:uppercase;letter-spacing:0.5px;"><?= $b['hits'] ?> plays</span>
                </div>
                <?php endforeach; else: ?>
                <p style="color:var(--text-muted);">No beat activity yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="section-title">Live Action Feed <span style="font-size:0.75rem;color:var(--text-muted);font-family:'Montserrat',sans-serif;font-weight:500;letter-spacing:0px;">— Last 50 events</span></div>
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
        <div class="section-title">All Visitors <span style="font-size:0.75rem;color:var(--text-muted);font-family:'Montserrat',sans-serif;font-weight:500;letter-spacing:0px;">— click a row to view their full timeline</span></div>
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
                    <td style="font-family:'Montserrat',sans-serif;font-weight:700;color:var(--accent);font-size:0.9rem;">
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
                    <td style="text-align:center;font-family:'Montserrat',sans-serif;font-weight:600;color:var(--text-main);font-size:0.9rem;"><?= $v['hits'] ?></td>
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

        <div class="section-title" style="margin-top:2rem;">Full Audit Log</div>
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

    </div><!-- /tab-stats -->

    <!-- ── USERS (APPROVAL & MANAGEMENT) ── -->
    <div id="tab-users" class="admin-panel">
        <div class="section-title">Pending User Approvals (<?= count($pending_users) ?>)</div>
        <div class="form-card" style="margin-bottom: 2rem;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_users as $u): ?>
                    <tr>
                        <td style="font-family:'Montserrat',sans-serif;font-weight:700;color:var(--text-main);font-size:0.9rem;"><?= h($u['username']) ?></td>
                        <td><?= h($u['email'] ?? '—') ?></td>
                        <td><span class="pill pill-inactive">Pending</span></td>
                        <td>
                            <div class="action-btns">
                                <a href="/admin/user_action.php?action=approve&id=<?= $u['id'] ?>" class="btn btn-green">Approve</a>
                                <a href="/admin/user_action.php?action=reject&id=<?= $u['id'] ?>" class="btn btn-red" onclick="return confirm('Er du sikker på, at du vil afvise og slette denne bruger registrering?');">Reject</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($pending_users)): ?>
                    <tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:2rem;">No pending approvals at the moment. All clear!</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="section-title">Approved User Accounts (<?= count($approved_users) ?>)</div>
        <div class="form-card">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approved_users as $u): ?>
                    <tr>
                        <td style="font-family:'Montserrat',sans-serif;font-weight:700;color:var(--text-main);font-size:0.9rem;"><?= h($u['username']) ?></td>
                        <td><?= h($u['email'] ?? '—') ?></td>
                        <td style="font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.8rem;text-transform:uppercase;color:var(--accent);"><?= h($u['role']) ?></td>
                        <td><span class="pill pill-active">Approved</span></td>
                        <td>
                            <div class="action-btns">
                                <a href="/admin/user_action.php?action=deactivate&id=<?= $u['id'] ?>" class="btn btn-amber">Deactivate</a>
                                <a href="/admin/user_action.php?action=reject&id=<?= $u['id'] ?>" class="btn btn-red" onclick="return confirm('Er du sikker på, at du vil slette denne brugerkonto permanent?');">Delete</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($approved_users)): ?>
                    <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:2rem;">No approved user accounts found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div><!-- /tab-users -->

    <!-- ── EMAIL PORTAL ── -->
    <div id="tab-email" class="admin-panel">
        <div class="section-title">Email Transmission Center</div>
        <p style="color:var(--text-muted); margin-bottom:1.5rem; font-family:'Montserrat',sans-serif; font-size:0.95rem;">
            Send direct email notifications and newsletters from <strong>admin@n2l8studios.com</strong>. All transmissions are fully aligned with SPF parameters to guarantee delivery.
        </p>

        <!-- Template Preset Bar -->
        <div class="preset-container" style="margin-bottom: 1.5rem;">
            <div style="font-family:'Montserrat',sans-serif; font-size:0.8rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px; margin-bottom:0.5rem;">Quick Templates / Draft Presets</div>
            <div class="preset-buttons" style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                <button type="button" class="preset-btn btn btn-muted" data-preset="community" style="font-family:'Montserrat',sans-serif; font-weight:600; font-size:0.75rem;">👥 Community Update</button>
                <button type="button" class="preset-btn btn btn-muted" data-preset="discount" style="font-family:'Montserrat',sans-serif; font-weight:600; font-size:0.75rem;">🔥 Special Discount</button>
                <button type="button" class="preset-btn btn btn-muted" data-preset="system" style="font-family:'Montserrat',sans-serif; font-weight:600; font-size:0.75rem;">⚠️ System Announcement</button>
                <button type="button" class="preset-btn btn btn-muted" data-preset="direct" style="font-family:'Montserrat',sans-serif; font-weight:600; font-size:0.75rem;">✉️ Direct Inquiry</button>
            </div>
        </div>

        <div class="email-portal-layout">
            
            <!-- Composer Panel -->
            <div class="composer-panel form-card" style="margin-bottom:0;">
                <form action="/admin/send_email.php" method="POST" id="emailPortalForm" onsubmit="showLoading()">
                    
                    <div class="form-group" style="margin-bottom:1.2rem;">
                        <label>Recipient Target</label>
                        <select name="recipient_type" id="recipientType" onchange="toggleRecipientFields(); updateLivePreview();" required style="width:100%; padding:0.8rem; background:rgba(26,26,31,0.9); border:1px solid var(--border-color); color:var(--text-main); border-radius:4px; font-family:'Montserrat',sans-serif; font-size:0.9rem; font-weight:600;">
                            <option value="custom">Custom Email Address</option>
                            <option value="single_user">Specific Registered User</option>
                            <option value="all_approved">Broadcast to All Approved Users (<?= count($approved_users) ?> users)</option>
                        </select>
                    </div>

                    <!-- Custom Email Field -->
                    <div class="form-group" id="groupCustomEmail" style="margin-bottom:1.2rem;">
                        <label>Custom Email Address</label>
                        <input type="email" name="custom_email" id="customEmailInput" placeholder="e.g. buyer@gmail.com" oninput="updateLivePreview()" style="width:100%; padding:0.8rem; background:rgba(26,26,31,0.9); border:1px solid var(--border-color); color:var(--text-main); border-radius:4px; font-family:'Montserrat',sans-serif; font-size:0.9rem;">
                    </div>

                    <!-- Specific Registered User -->
                    <div class="form-group" id="groupSingleUser" style="display:none; margin-bottom:1.2rem;">
                        <label>Select User</label>
                        <select name="user_id" id="singleUserSelect" onchange="updateLivePreview()" style="width:100%; padding:0.8rem; background:rgba(26,26,31,0.9); border:1px solid var(--border-color); color:var(--text-main); border-radius:4px; font-family:'Montserrat',sans-serif; font-size:0.9rem;">
                            <?php foreach ($all_users as $u): ?>
                                <option value="<?= (int)$u['id'] ?>" data-username="<?= h($u['username']) ?>" data-email="<?= h($u['email']) ?>"><?= h($u['username']) ?> (<?= h($u['email'] ?: 'No email') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Broadcast Safeguard Confirmation Checkbox -->
                    <div class="form-group" id="groupBroadcastWarning" style="display:none; padding:1.2rem; background:rgba(192, 21, 42, 0.1); border:1px solid var(--accent); border-radius:4px; margin-bottom:1.2rem;">
                        <div style="color:var(--accent); font-weight:700; font-family:'Syncopate',sans-serif; font-size:0.8rem; letter-spacing:1px; display:inline-block; margin-bottom:0.5rem; text-transform:uppercase;">⚠️ MASS TRANSMISSION WARNING</div><br>
                        <span style="color:var(--text-muted); font-size:0.85rem; line-height:1.5; display:inline-block; margin-bottom:0.8rem;">This action will broadcast this email to all <?= count($approved_users) ?> approved, non-admin users. Ensure the content is finalized before proceeding.</span>
                        <label class="checkbox-row" style="margin-top:0.4rem; cursor:pointer;">
                            <input type="checkbox" id="confirmBroadcast" style="cursor:pointer; accent-color:var(--accent);">
                            <span style="color:var(--text-main); font-size:0.85rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">I authorize this mass transmission</span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-bottom:1.2rem;">
                        <label>Email Subject</label>
                        <input type="text" name="subject" id="emailSubject" placeholder="Enter transmission subject" required oninput="updateLivePreview()" style="width:100%; padding:0.8rem; background:rgba(26,26,31,0.9); border:1px solid var(--border-color); color:var(--text-main); border-radius:4px; font-family:'Montserrat',sans-serif; font-size:0.9rem;">
                    </div>

                    <div class="form-group" style="margin-bottom:1.2rem;">
                        <label>Styling Template</label>
                        <select name="template_type" id="templateType" onchange="updateLivePreview()" style="width:100%; padding:0.8rem; background:rgba(26,26,31,0.9); border:1px solid var(--border-color); color:var(--text-main); border-radius:4px; font-family:'Montserrat',sans-serif; font-size:0.9rem; font-weight:600;">
                            <option value="premium_dark">N2L8 Cyberpunk Premium (Dark Mode, Glowing red accents)</option>
                            <option value="plain">Minimal Plain Text (Safe / Developer-mode)</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:0.5rem;">
                        <label>Transmission Message Body</label>
                        <textarea name="message" id="emailMessage" rows="10" placeholder="Write your transmission message here... Use {username} to personalize." required oninput="updateLivePreview()" style="width:100%; padding:0.8rem; background:rgba(26,26,31,0.9); border:1px solid var(--border-color); color:var(--text-main); border-radius:4px; font-family:'Montserrat',sans-serif; font-size:0.9rem; line-height:1.6;"></textarea>
                    </div>

                    <!-- Word & Character Counter -->
                    <div style="display:flex; justify-content:space-between; align-items:center; font-family:'Montserrat',sans-serif; font-size:0.75rem; color:var(--text-muted); margin-bottom:1.5rem;">
                        <span>Use <code>{username}</code> to dynamically render name.</span>
                        <span><span id="charCount">0</span> chars | <span id="wordCount">0</span> words</span>
                    </div>

                    <div>
                        <button type="submit" class="cta-btn" style="width:100%; padding:1rem; font-family:'Syncopate',sans-serif; font-weight:700; font-size:1rem; background:var(--accent); color:#fff; border:none; border-radius:4px; cursor:pointer; box-shadow:0 0 15px rgba(192,21,42,0.4); text-transform:uppercase; letter-spacing:2px; transition:all 0.2s;">
                            INJECT TRANSMISSION
                        </button>
                    </div>
                </form>
            </div>

            <!-- Live Real-Time Preview Panel -->
            <div class="preview-panel" style="display:flex; flex-direction:column; gap:1rem;">
                <div style="font-family:'Syncopate',sans-serif; font-size:0.8rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px;">Live Transmission Preview</div>
                
                <!-- Mock Inbox Container -->
                <div class="mock-inbox-wrapper">
                    
                    <!-- Inbox header -->
                    <div style="background: rgba(26,26,31,0.9); padding: 0.8rem 1.2rem; border-bottom: 1px solid var(--border-color); display:flex; align-items:center; gap:0.6rem;">
                        <div style="display:flex; gap:0.3rem;">
                            <span style="width:10px; height:10px; border-radius:50%; background:#ff5f56; display:inline-block;"></span>
                            <span style="width:10px; height:10px; border-radius:50%; background:#ffbd2e; display:inline-block;"></span>
                            <span style="width:10px; height:10px; border-radius:50%; background:#27c93f; display:inline-block;"></span>
                        </div>
                        <div style="flex:1; text-align:center; font-family:'Montserrat',sans-serif; font-size:0.75rem; color:var(--text-muted); font-weight:600; letter-spacing:0.5px; text-transform:uppercase; display:flex; justify-content:center; align-items:center; gap:0.4rem;">
                            <span>📬 Transmission Preview</span>
                        </div>
                    </div>

                    <!-- Envelope Fields -->
                    <div style="padding: 1rem 1.2rem; border-bottom: 1px solid var(--border-color); font-family:'Montserrat',sans-serif; font-size:0.8rem; display:flex; flex-direction:column; gap:0.5rem;">
                        <div>
                            <span style="color:var(--text-muted); font-weight:600; width:60px; display:inline-block;">From:</span>
                            <span style="color:var(--text-main); font-weight:500;">N2L8 STUDIO &lt;admin@n2l8studios.com&gt;</span>
                        </div>
                        <div>
                            <span style="color:var(--text-muted); font-weight:600; width:60px; display:inline-block;">To:</span>
                            <span style="color:var(--accent); font-weight:600;" id="previewRecipient">Custom Email Address</span>
                        </div>
                        <div>
                            <span style="color:var(--text-muted); font-weight:600; width:60px; display:inline-block;">Subject:</span>
                            <span style="color:var(--text-main); font-weight:700;" id="previewSubject">(No Subject)</span>
                        </div>
                    </div>

                    <!-- Rendered Email Body -->
                    <div class="rendered-email-outer" id="previewEmailOuter" style="background:#0a0a0a; padding: 2rem; min-height: 250px; transition: all 0.3s ease; position:relative; overflow-y:auto; max-height: 400px;">
                        
                        <!-- Inside Premium Dark Mode Template -->
                        <div id="premiumDarkTemplate" style="background:#111115; border:1px solid #c0152a; border-radius:6px; box-shadow:0 0 20px rgba(192,21,42,0.15); font-family:'Montserrat',sans-serif; color:#ffffff; max-width:500px; margin:0 auto; overflow:hidden;">
                            <div style="background:#1a1a20; padding:1.5rem; text-align:center; border-bottom:1px solid rgba(192,21,42,0.3);">
                                <h1 style="font-family:'Syncopate',sans-serif; font-weight:700; font-size:1.1rem; color:#c0152a; margin:0; letter-spacing:3px;">N2L8 STUDIO</h1>
                                <p style="font-size:0.75rem; color:#88888e; margin:5px 0 0 0; text-transform:uppercase; letter-spacing:1px;">Transmission Channel</p>
                            </div>
                            <div style="padding:1.8rem; line-height:1.6; font-size:0.9rem;" id="premiumDarkBody">
                                Hello customer, welcome to the dark side.
                            </div>
                            <div style="background:#1a1a20; padding:1.2rem; text-align:center; border-top:1px solid rgba(192,21,42,0.1); font-size:0.75rem; color:#88888e;">
                                © 2026 N2L8 STUDIO. All Rights Reserved.<br>
                                <span style="font-size:0.7rem; color:#55555c;">To unsubscribe, click the link in your user portal.</span>
                            </div>
                        </div>

                        <!-- Inside Plain Text Template -->
                        <div id="plainTemplate" style="display:none; font-family:monospace; font-size:0.9rem; color:#cccccc; background:#000; border:1px solid #333; padding:1.5rem; border-radius:4px; white-space:pre-wrap; line-height:1.5;">
                        </div>

                    </div>
                </div>

            </div>

        </div>
    </div><!-- /tab-email -->

</div><!-- /container -->

<script>
const INITIAL_TAB = '<?= h($tab) ?>';
const TAB_NAMES = { dashboard:'Dashboard', products:'Products', orders:'Orders', messages:'Messages', users:'Users', content:'Content Editor', stats:'Analytics', email:'Email' };

function toggleRecipientFields() {
    const type = document.getElementById('recipientType').value;
    document.getElementById('groupCustomEmail').style.display = (type === 'custom') ? 'block' : 'none';
    document.getElementById('groupSingleUser').style.display = (type === 'single_user') ? 'block' : 'none';
    document.getElementById('groupBroadcastWarning').style.display = (type === 'all_approved') ? 'block' : 'none';
}

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
    document.querySelectorAll('.dropdown-item-btn').forEach(b => b.classList.remove('active'));

    const panel  = document.getElementById('tab-' + name);
    let deskBtn = document.getElementById('tab-btn-' + name);
    
    if (panel) panel.classList.add('active');
    
    // If it's a sub-tab of Communications
    if (name === 'email' || name === 'messages') {
        const triggerBtn = document.getElementById('tab-btn-comms');
        if (triggerBtn) {
            triggerBtn.classList.add('active');
            deskBtn = triggerBtn; // We align the sliding bar with the main dropdown trigger!
        }
        const subBtn = document.getElementById('tab-btn-' + name);
        if (subBtn) subBtn.classList.add('active');
    }

    if (deskBtn) { 
        deskBtn.classList.add('active'); 
        moveSlider(deskBtn); 
    }

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

// Desktop tab click (excluding the communications dropdown trigger)
document.querySelectorAll('.admin-tab-btn:not(.dropdown-trigger)').forEach(btn => {
    btn.addEventListener('click', () => showTab(btn.id.replace('tab-btn-','')));
});

// Dropdown sub-tab click
document.querySelectorAll('.dropdown-item-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        showTab(btn.id.replace('tab-btn-',''));
        document.getElementById('commsDropdownContainer')?.classList.remove('open');
    });
});

// Communications trigger click
const commsTrigger = document.getElementById('tab-btn-comms');
if (commsTrigger) {
    commsTrigger.addEventListener('click', (e) => {
        e.stopPropagation();
        document.getElementById('commsDropdownContainer')?.classList.toggle('open');
    });
}

// Close communications dropdown on click outside
document.addEventListener('click', () => {
    document.getElementById('commsDropdownContainer')?.classList.remove('open');
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

// Quick Templates Presets Data
const PRESETS = {
    community: {
        subject: "⚡ N2L8 Transmission: Fresh Soundkits & Studio Progress",
        template: "premium_dark",
        body: `Hey {username},\n\nWe've been working day and night in the shadows of the studio, crafting some of the most hard-hitting, dark atmospheric soundscapes yet.\n\nHere's what just dropped:\n- "NOIR CRUNCH V2" - Ultra-dirty basslines and gritty analog drums.\n- "VORTEX SYNTHS" - Premium detuned leads to carry your melodies into late-night realms.\n\nLog into your user portal at n2l8studios.com to listen to the new loops and download your updated licensing terms.\n\nKeep creating,\nEsau // N2L8 STUDIO`
    },
    discount: {
        subject: "🔥 EXCLUSIVE: 30% Off Lifetime Soundkit Vault Access",
        template: "premium_dark",
        body: `Greetings {username},\n\nFor the next 48 hours, we are unlocking a vault exclusive to our registered community members.\n\nUse code "LATE30" at checkout on the beats & shop portal to receive an immediate 30% discount on any drumkit, loops, or licensing plans.\n\nThis is a one-time transmission. Don't let this expire.\n\nClaim your access now at:\nhttps://n2l8studios.com/shop.php\n\nBest regards,\nN2L8 STUDIO ADMIN`
    },
    system: {
        subject: "🚨 ACTION REQUIRED: Security Sync & Infrastructure Update",
        template: "plain",
        body: `Attention {username},\n\nWe have successfully migrated our user database to our new high-speed enterprise servers to ensure 100% security and high-fidelity streaming.\n\nPlease take a moment to:\n1. Log into your dashboard.\n2. Verify your email address is fully confirmed.\n3. Check your order history to download any previously purchased licenses.\n\nIf you encounter any glitches or latency, reply directly to this notification or contact us at admin@n2l8studios.com.\n\nThank you for being part of N2L8,\nSystem Operations Center`
    },
    direct: {
        subject: "✉️ Project Discussion: Custom Beat Inquiry",
        template: "premium_dark",
        body: `Hello {username},\n\nI was reviewing your active visitor activity on our portal, and wanted to reach out personally to see if you have any active musical projects or custom beat inquiries.\n\nWe offer bespoke musical arrangements, professional mixes, and custom-tailored exclusive licenses.\n\nLet me know what you are currently working on, and let's construct a sonic masterpiece.\n\nSpeak soon,\nEsau // n2l8studio`
    }
};

// Word & Character count helper
function updateCounter() {
    const msg = document.getElementById('emailMessage')?.value || '';
    const chars = msg.length;
    const words = msg.trim() ? msg.trim().split(/\s+/).length : 0;
    
    const charCountEl = document.getElementById('charCount');
    const wordCountEl = document.getElementById('wordCount');
    if (charCountEl) charCountEl.textContent = chars;
    if (wordCountEl) wordCountEl.textContent = words;
}

// Live preview renderer
function updateLivePreview() {
    const recipientType = document.getElementById('recipientType')?.value || 'custom';
    const customEmail = document.getElementById('customEmailInput')?.value || '';
    const subject = document.getElementById('emailSubject')?.value || '';
    const templateType = document.getElementById('templateType')?.value || 'premium_dark';
    const message = document.getElementById('emailMessage')?.value || '';
    
    // 1. Update Recipient Preview
    const previewRecipient = document.getElementById('previewRecipient');
    if (previewRecipient) {
        if (recipientType === 'custom') {
            previewRecipient.textContent = customEmail ? customEmail : 'Custom Email Address';
        } else if (recipientType === 'single_user') {
            const select = document.getElementById('singleUserSelect');
            if (select && select.options.length > 0) {
                const opt = select.options[select.selectedIndex];
                const name = opt.getAttribute('data-username') || 'user';
                const email = opt.getAttribute('data-email') || '';
                previewRecipient.textContent = `${name} <${email}>`;
            } else {
                previewRecipient.textContent = 'Selected User';
            }
        } else {
            previewRecipient.textContent = 'ALL APPROVED USERS (Broadcast Mode)';
        }
    }
    
    // 2. Update Subject Preview
    const previewSubject = document.getElementById('previewSubject');
    if (previewSubject) {
        previewSubject.textContent = subject ? subject : '(No Subject)';
    }
    
    // 3. Get personalized user name for placeholder replacement
    let mockUser = 'guest_producer';
    if (recipientType === 'single_user') {
        const select = document.getElementById('singleUserSelect');
        if (select && select.options.length > 0) {
            mockUser = select.options[select.selectedIndex].getAttribute('data-username') || 'guest_producer';
        }
    }
    
    // Replace placeholder {username}
    const personalizedText = message.replace(/{username}/g, mockUser);
    
    // 4. Update Template Preview
    const premiumDark = document.getElementById('premiumDarkTemplate');
    const plain = document.getElementById('plainTemplate');
    const emailOuter = document.getElementById('previewEmailOuter');
    
    if (templateType === 'premium_dark') {
        if (premiumDark) premiumDark.style.display = 'block';
        if (plain) plain.style.display = 'none';
        if (emailOuter) emailOuter.style.background = '#0a0a0a';
        
        const darkBody = document.getElementById('premiumDarkBody');
        if (darkBody) {
            darkBody.innerHTML = personalizedText ? personalizedText.replace(/\n/g, '<br>') : 'Hello customer, welcome to the dark side.';
        }
    } else {
        if (premiumDark) premiumDark.style.display = 'none';
        if (plain) plain.style.display = 'block';
        if (emailOuter) emailOuter.style.background = '#020202';
        
        if (plain) {
            plain.textContent = personalizedText ? personalizedText : 'Hello customer, welcome to the dark side.';
        }
    }
    
    updateCounter();
    
    // If broadcast is checked, require confirmation
    const confirmBroadcast = document.getElementById('confirmBroadcast');
    if (recipientType === 'all_approved') {
        confirmBroadcast.setAttribute('required', 'required');
    } else {
        confirmBroadcast.removeAttribute('required');
    }
}

// Attach preset template events
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const presetKey = btn.dataset.preset;
            const data = PRESETS[presetKey];
            if (!data) return;
            
            const subInput = document.getElementById('emailSubject');
            const templateSelect = document.getElementById('templateType');
            const msgTextarea = document.getElementById('emailMessage');
            
            if (subInput) subInput.value = data.subject;
            if (templateSelect) templateSelect.value = data.template;
            if (msgTextarea) msgTextarea.value = data.body;
            
            updateLivePreview();
            
            // Subtle highlight micro-animation on fields
            [subInput, templateSelect, msgTextarea].forEach(el => {
                if (!el) return;
                el.style.borderColor = 'var(--accent)';
                setTimeout(() => {
                    el.style.borderColor = 'var(--text-muted)';
                }, 800);
            });
        });
    });
    
    // Attach input listeners
    ['emailSubject', 'customEmailInput', 'emailMessage'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', updateLivePreview);
        }
    });
});

// Init — wait for layout so slider can measure button positions
window.addEventListener('load', () => {
    showTab(INITIAL_TAB);
    if (document.getElementById('recipientType')) {
        toggleRecipientFields();
        updateLivePreview();
    }
});
</script>
</body>
</html>
