<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_owner();
$pdo = get_pdo();

// Process Theme Switcher POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_theme'])) {
    $theme = $_POST['active_theme'] ?? 'dark';
    if (!in_array($theme, ['dark', 'beige'])) $theme = 'dark';
    
    // Check if the setting already exists in content table
    $stmt = $pdo->prepare("SELECT id FROM content WHERE section_key = 'site_theme'");
    $stmt->execute();
    $exists = $stmt->fetch();
    
    if ($exists) {
        $stmt = $pdo->prepare("UPDATE content SET text = ? WHERE section_key = 'site_theme'");
        $stmt->execute([$theme]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO content (section_key, label, page, text) VALUES ('site_theme', 'Active Site Theme (dark or beige)', 'global', ?)");
        $stmt->execute([$theme]);
    }
    
    log_action($pdo, "Admin changed active site theme to: " . $theme);
    flash('Theme updated successfully to ' . ucfirst($theme) . '!');
    
    header('Location: /admin/index.php?tab=dashboard');
    exit;
}

// Fetch active theme
$theme_stmt = $pdo->prepare("SELECT text FROM content WHERE section_key = 'site_theme'");
$theme_stmt->execute();
$site_theme = $theme_stmt->fetchColumn() ?: 'dark';
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

        /* ── GMAIL-STYLE EMAIL CLIENT ── */
        .email-client { 
            display: grid; 
            grid-template-columns: 240px 1fr; 
            min-height: 650px; 
            border: 1px solid rgba(192, 21, 42, 0.15); 
            border-radius: 12px; 
            overflow: hidden; 
            background: rgba(10, 10, 14, 0.65); 
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 10px 45px rgba(0, 0, 0, 0.8), 0 0 25px rgba(192, 21, 42, 0.05);
        }
        .email-sidebar { 
            background: rgba(14, 14, 18, 0.75); 
            border-right: 1px solid rgba(255, 255, 255, 0.05); 
            display: flex; 
            flex-direction: column; 
            padding: 1.5rem 0; 
        }
        .email-compose-btn { 
            margin: 0 1.2rem 1.5rem; 
            padding: 0.95rem 1.5rem; 
            background: linear-gradient(135deg, var(--accent), #df1f37); 
            color: #fff; 
            border: none; 
            border-radius: 8px; 
            font-family: 'Montserrat', sans-serif; 
            font-weight: 700; 
            font-size: 0.85rem; 
            cursor: pointer; 
            text-transform: uppercase; 
            letter-spacing: 1.5px; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            display: flex; 
            align-items: center; 
            gap: 0.6rem; 
            justify-content: center; 
            box-shadow: 0 4px 15px rgba(192, 21, 42, 0.35); 
        }
        .email-compose-btn:hover { 
            background: linear-gradient(135deg, #df1f37, #ff334b); 
            transform: translateY(-2px); 
            box-shadow: 0 8px 25px rgba(192, 21, 42, 0.55); 
        }
        .email-folder-list { list-style: none; padding: 0; margin: 0; flex: 1; }
        .email-folder-item { 
            padding: 0.8rem 1.5rem; 
            display: flex; 
            align-items: center; 
            gap: 0.9rem; 
            cursor: pointer; 
            font-family: 'Montserrat', sans-serif; 
            font-size: 0.88rem; 
            font-weight: 500; 
            color: var(--text-muted); 
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); 
            border-left: 3px solid transparent; 
        }
        .email-folder-item:hover { 
            background: rgba(255, 255, 255, 0.025); 
            color: var(--text-main); 
            padding-left: 1.7rem; 
        }
        .email-folder-item.active { 
            background: linear-gradient(90deg, rgba(192, 21, 42, 0.12) 0%, rgba(192, 21, 42, 0.02) 100%); 
            color: var(--text-main); 
            border-left-color: var(--accent); 
            font-weight: 700; 
            text-shadow: 0 0 10px rgba(192, 21, 42, 0.3);
        }
        .email-folder-item .folder-icon { font-size: 1.1rem; width: 22px; text-align: center; }
        .email-folder-item .folder-label { flex: 1; }
        .email-folder-item .folder-badge { 
            background: var(--accent); 
            color: #fff; 
            font-size: 0.72rem; 
            font-weight: 700; 
            padding: 0.2rem 0.6rem; 
            border-radius: 10px; 
            min-width: 22px; 
            text-align: center; 
            box-shadow: 0 0 8px rgba(192, 21, 42, 0.4);
        }
        .email-main { display: flex; flex-direction: column; background: rgba(6, 6, 8, 0.2); }
        .email-toolbar { 
            display: flex; 
            align-items: center; 
            gap: 0.8rem; 
            padding: 1rem 1.5rem; 
            border-bottom: 1px solid rgba(255, 255, 255, 0.05); 
            background: rgba(14, 14, 18, 0.4); 
            flex-shrink: 0; 
        }
        .email-toolbar-btn { 
            background: rgba(255, 255, 255, 0.02); 
            border: 1px solid rgba(255, 255, 255, 0.06); 
            color: var(--text-muted); 
            padding: 0.5rem 0.9rem; 
            border-radius: 6px; 
            cursor: pointer; 
            font-family: 'Montserrat', sans-serif; 
            font-size: 0.78rem; 
            font-weight: 600; 
            transition: all 0.2s ease; 
            text-transform: uppercase; 
            letter-spacing: 0.5px; 
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .email-toolbar-btn:hover { 
            background: rgba(192, 21, 42, 0.08); 
            color: var(--text-main); 
            border-color: rgba(192, 21, 42, 0.3); 
            box-shadow: 0 0 10px rgba(192, 21, 42, 0.15);
        }
        .email-search { 
            flex: 1; 
            background: rgba(255, 255, 255, 0.03); 
            border: 1px solid rgba(255, 255, 255, 0.08); 
            color: var(--text-main); 
            padding: 0.55rem 1rem; 
            border-radius: 6px; 
            font-family: 'Montserrat', sans-serif; 
            font-size: 0.88rem; 
            outline: none; 
            transition: all 0.3s ease; 
        }
        .email-search:focus { 
            border-color: var(--accent); 
            background: rgba(255, 255, 255, 0.05);
            box-shadow: 0 0 15px rgba(192, 21, 42, 0.25); 
        }
        .email-search::placeholder { color: var(--text-muted); opacity: 0.6; }
        .email-content-area { display: grid; grid-template-columns: 400px 1fr; flex: 1; min-height: 0; }
        .email-list { 
            border-right: 1px solid rgba(255, 255, 255, 0.05); 
            overflow-y: auto; 
            max-height: 560px; 
            background: rgba(8, 8, 10, 0.2);
        }
        /* Custom sleek scrollbar for email components */
        .email-list::-webkit-scrollbar, .email-reading-pane::-webkit-scrollbar, .compose-body::-webkit-scrollbar {
            width: 6px;
        }
        .email-list::-webkit-scrollbar-track, .email-reading-pane::-webkit-scrollbar-track, .compose-body::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.15);
        }
        .email-list::-webkit-scrollbar-thumb, .email-reading-pane::-webkit-scrollbar-thumb, .compose-body::-webkit-scrollbar-thumb {
            background: rgba(192, 21, 42, 0.3);
            border-radius: 3px;
        }
        .email-list::-webkit-scrollbar-thumb:hover, .email-reading-pane::-webkit-scrollbar-thumb:hover, .compose-body::-webkit-scrollbar-thumb:hover {
            background: rgba(192, 21, 42, 0.6);
        }
        .email-list-item { 
            display: flex; 
            gap: 1rem; 
            padding: 1.1rem 1.2rem; 
            border-bottom: 1px solid rgba(255, 255, 255, 0.02); 
            cursor: pointer; 
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); 
            align-items: flex-start; 
            position: relative; 
        }
        .email-list-item:hover { 
            background: rgba(255, 255, 255, 0.025); 
            transform: translateX(4px);
        }
        .email-list-item.active { 
            background: rgba(192, 21, 42, 0.06); 
            border-left: 4px solid var(--accent); 
            box-shadow: inset 4px 0 0 var(--accent);
        }
        .email-list-item.unread { 
            background: rgba(192, 21, 42, 0.02); 
        }
        .email-list-item.unread::before {
            content: '●';
            color: #ff4060;
            position: absolute;
            left: 6px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 8px;
            text-shadow: 0 0 8px #ff4060;
        }
        .email-list-item.unread .email-item-from { font-weight: 700; color: var(--text-main); }
        .email-list-item.unread .email-item-subject { font-weight: 600; color: var(--text-main); }
        .email-avatar { 
            width: 40px; 
            height: 40px; 
            border-radius: 50%; 
            background: linear-gradient(135deg, var(--accent), #ff4060); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-family: 'Syncopate', sans-serif; 
            font-weight: 700; 
            font-size: 0.8rem; 
            color: #fff; 
            flex-shrink: 0; 
            margin-top: 2px; 
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
        }
        .email-item-content { flex: 1; min-width: 0; }
        .email-item-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
        .email-item-from { 
            font-family: 'Montserrat', sans-serif; 
            font-size: 0.88rem; 
            font-weight: 500; 
            color: var(--text-muted); 
            white-space: nowrap; 
            overflow: hidden; 
            text-overflow: ellipsis; 
            max-width: 220px; 
        }
        .email-item-date { font-family: 'Montserrat', sans-serif; font-size: 0.75rem; color: var(--text-muted); white-space: nowrap; flex-shrink: 0; }
        .email-item-subject { 
            font-family: 'Montserrat', sans-serif; 
            font-size: 0.86rem; 
            font-weight: 500; 
            color: rgba(255, 255, 255, 0.8); 
            white-space: nowrap; 
            overflow: hidden; 
            text-overflow: ellipsis; 
            margin-bottom: 4px; 
        }
        .email-item-snippet { font-family: 'Montserrat', sans-serif; font-size: 0.8rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .email-reading-pane { overflow-y: auto; max-height: 560px; display: flex; flex-direction: column; background: rgba(6, 6, 8, 0.1); }
        .email-read-header { padding: 1.8rem 1.8rem 1.2rem; border-bottom: 1px solid rgba(255, 255, 255, 0.05); flex-shrink: 0; }
        .email-read-subject { font-family: 'Montserrat', sans-serif; font-size: 1.25rem; font-weight: 700; color: var(--text-main); margin-bottom: 1rem; line-height: 1.35; text-shadow: 0 0 15px rgba(255,255,255,0.05); }
        .email-read-meta { display: flex; gap: 1.2rem; align-items: center; }
        .email-read-avatar { 
            width: 44px; 
            height: 44px; 
            border-radius: 50%; 
            background: linear-gradient(135deg, var(--accent), #ff4060); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-family: 'Syncopate', sans-serif; 
            font-weight: 700; 
            font-size: 0.85rem; 
            color: #fff; 
            flex-shrink: 0; 
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
        }
        .email-read-sender { flex: 1; }
        .email-read-from { font-family: 'Montserrat', sans-serif; font-size: 0.95rem; font-weight: 600; color: var(--text-main); }
        .email-read-email { font-family: 'Montserrat', sans-serif; font-size: 0.82rem; color: var(--text-muted); margin-top: 2px; }
        .email-read-date { font-family: 'Montserrat', sans-serif; font-size: 0.8rem; color: var(--text-muted); text-align: right; }
        .email-read-body { padding: 1.8rem; flex: 1; font-family: 'Montserrat', sans-serif; font-size: 0.92rem; color: rgba(255, 255, 255, 0.9); line-height: 1.75; }
        .email-read-body img { max-width: 100%; height: auto; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.5); }
        .email-read-actions { padding: 1.2rem 1.8rem; border-top: 1px solid rgba(255, 255, 255, 0.05); display: flex; gap: 0.6rem; flex-shrink: 0; flex-wrap: wrap; background: rgba(14, 14, 18, 0.3); }
        .email-empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: var(--text-muted); font-family: 'Montserrat', sans-serif; gap: 0.5rem; padding: 4rem; text-align: center; }
        .email-empty-state .empty-icon { font-size: 3.5rem; opacity: 0.25; margin-bottom: 0.5rem; }
        .email-empty-state .empty-text { font-size: 0.95rem; font-weight: 600; color: var(--text-main); }
        .email-empty-state .sync-badge {
            font-size: 0.78rem;
            color: rgba(255, 255, 255, 0.4);
            background: rgba(192, 21, 42, 0.1);
            border: 1px solid rgba(192, 21, 42, 0.2);
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            margin-top: 0.6rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            letter-spacing: 0.5px;
        }
        .email-loading { text-align: center; padding: 4rem; color: var(--text-muted); font-family: 'Montserrat', sans-serif; }
        .email-loading .loading-spinner { width: 32px; height: 32px; border: 3px solid rgba(255, 255, 255, 0.05); border-top-color: var(--accent); border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 1.2rem; }
        /* Compose Modal */
        .compose-overlay { display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.75); z-index: 2000; backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); align-items: center; justify-content: center; }
        .compose-overlay.open { display: flex; }
        .compose-modal { 
            background: rgba(16, 16, 20, 0.95); 
            border: 1px solid rgba(192, 21, 42, 0.25); 
            border-radius: 14px; 
            width: 95%; 
            max-width: 680px; 
            max-height: 85vh; 
            display: flex; 
            flex-direction: column; 
            box-shadow: 0 25px 65px rgba(0, 0, 0, 0.95), 0 0 40px rgba(192, 21, 42, 0.15); 
            overflow: hidden;
        }
        .compose-header { display: flex; justify-content: space-between; align-items: center; padding: 1.2rem 1.8rem; border-bottom: 1px solid rgba(255, 255, 255, 0.05); background: rgba(10, 10, 13, 0.65); }
        .compose-title { font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.95rem; color: var(--text-main); text-transform: uppercase; letter-spacing: 1.5px; }
        .compose-close { background: transparent; border: none; color: var(--text-muted); font-size: 1.5rem; cursor: pointer; padding: 0.2rem; transition: color 0.15s; line-height: 1; }
        .compose-close:hover { color: var(--accent); }
        .compose-body { padding: 1.5rem 1.8rem; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 1rem; }
        .compose-field { display: flex; align-items: center; gap: 0.8rem; border-bottom: 1px solid rgba(255, 255, 255, 0.05); padding-bottom: 0.8rem; }
        .compose-field label { font-family: 'Montserrat', sans-serif; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; width: 60px; flex-shrink: 0; }
        .compose-field input, .compose-field select { flex: 1; background: transparent; border: none; color: var(--text-main); font-family: 'Montserrat', sans-serif; font-size: 0.92rem; outline: none; padding: 0.4rem 0; }
        .compose-field input::placeholder { color: rgba(255, 255, 255, 0.25); }
        .compose-textarea { flex: 1; min-height: 240px; background: transparent; border: none; color: var(--text-main); font-family: 'Montserrat', sans-serif; font-size: 0.92rem; line-height: 1.7; resize: none; outline: none; padding: 0.5rem 0; }
        .compose-textarea::placeholder { color: rgba(255, 255, 255, 0.25); }
        .compose-footer { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.8rem; border-top: 1px solid rgba(255, 255, 255, 0.05); background: rgba(10, 10, 13, 0.45); }
        .compose-send-btn { padding: 0.8rem 2.5rem; background: linear-gradient(135deg, var(--accent), #df1f37); color: #fff; border: none; border-radius: 6px; font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.85rem; cursor: pointer; text-transform: uppercase; letter-spacing: 1.5px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 4px 15px rgba(192, 21, 42, 0.3); }
        .compose-send-btn:hover { background: linear-gradient(135deg, #df1f37, #ff334b); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(192, 21, 42, 0.5); }
        .compose-send-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
        .compose-template-select { background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.1); color: var(--text-muted); padding: 0.5rem 0.8rem; border-radius: 6px; font-family: 'Montserrat', sans-serif; font-size: 0.8rem; cursor: pointer; transition: all 0.2s ease; }
        .compose-template-select:hover { border-color: rgba(255, 255, 255, 0.2); color: var(--text-main); }
        @media(max-width:900px){
            .email-client { grid-template-columns: 1fr; min-height: auto; }
            .email-sidebar { flex-direction: row; overflow-x: auto; padding: 0.6rem; border-right: none; border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
            .email-compose-btn { margin: 0 0.6rem 0 0; white-space: nowrap; padding: 0.6rem 1.2rem; font-size: 0.78rem; }
            .email-folder-list { display: flex; gap: 0; }
            .email-folder-item { padding: 0.6rem 1rem; border-left: none; border-bottom: 3px solid transparent; white-space: nowrap; font-size: 0.82rem; }
            .email-folder-item:hover { padding-left: 1rem; }
            .email-folder-item.active { border-left-color: transparent; border-bottom-color: var(--accent); }
            .email-content-area { grid-template-columns: 1fr; }
            .email-list { max-height: 320px; border-right: none; border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
            .email-reading-pane { max-height: 450px; }
        }
    </style>
</head>
<body class="page-home <?= $site_theme === 'beige' ? 'theme-beige' : '' ?>">

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
            <button class="admin-tab-btn" id="tab-btn-email">📧 Emails</button>
            <button class="admin-tab-btn" id="tab-btn-messages">💬 Messages</button>
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
            <button class="admin-tab-menu-item" data-tab="email">📧 Emails</button>
            <button class="admin-tab-menu-item" data-tab="messages">💬 Messages</button>
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

        <div class="section-title">Site Appearance &amp; Custom Skins</div>
        <div class="form-card" style="margin-bottom: 2rem;">
            <form action="" method="POST" style="display:flex; align-items:center; gap:1.5rem; flex-wrap:wrap; width: 100%;">
                <input type="hidden" name="update_theme" value="1">
                <div style="flex:1; min-width:200px;">
                    <label style="display:block; font-family:'Montserrat', sans-serif; font-weight:700; font-size:0.8rem; color:var(--accent); text-transform:uppercase; letter-spacing:1px; margin-bottom:0.5rem;">Select active site theme</label>
                    <select name="active_theme" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid var(--border-color); color:#fff; padding:0.6rem; border-radius:4px; font-family:'Montserrat', sans-serif; font-size:0.9rem;">
                        <option value="dark" <?= ($site_theme === 'dark') ? 'selected' : '' ?>>Luxury Noir Red (Classic Dark)</option>
                        <option value="beige" <?= ($site_theme === 'beige') ? 'selected' : '' ?>>Luxury Beige &amp; Copper (Premium Light)</option>
                    </select>
                </div>
                <button class="cta-btn" type="submit" style="margin-top:1.5rem; padding:0.6rem 2rem;">APPLY THEME</button>
            </form>
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
                                <a href="/admin/user_action.php?action=reject&id=<?= $u['id'] ?>" class="btn btn-red" onclick="return confirm('Are you sure you want to permanently delete this user account?');">Delete</a>
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
    <!-- ── COMPOSE MODAL ── -->
    <div class="compose-overlay" id="composeOverlay">
        <div class="compose-modal">
            <div class="compose-header">
                <span class="compose-title">✏️ New Email</span>
                <button class="compose-close" onclick="closeCompose()">&times;</button>
            </div>
            <form id="composeForm" class="compose-body">
                <div class="compose-field">
                    <label>To</label>
                    <select name="recipient_type" id="composeRecipientType" onchange="toggleComposeFields()" style="flex:0.4;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:4px;padding:0.35rem 0.5rem;color:var(--text-main);font-size:0.82rem;cursor:pointer;">
                        <option value="custom">Custom Email</option>
                        <option value="single_user">Registered User</option>
                        <option value="all_approved">All Users (<?= count($approved_users) ?>)</option>
                    </select>
                    <input type="email" name="custom_email" id="composeCustomEmail" placeholder="recipient@email.com" style="flex:1;">
                    <select name="user_id" id="composeUserId" style="display:none;flex:1;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:4px;padding:0.35rem 0.5rem;color:var(--text-main);font-size:0.82rem;">
                        <?php foreach ($all_users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= h($u['username']) ?> (<?= h($u['email'] ?: 'No email') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="compose-field">
                    <label>Subject</label>
                    <input type="text" name="subject" id="composeSubject" placeholder="Email subject..." required>
                </div>
                <textarea class="compose-textarea" name="message" id="composeMessage" placeholder="Write your message here..." required></textarea>
            </form>
            <div class="compose-footer">
                <select class="compose-template-select" id="composeTemplate">
                    <option value="premium_dark">N2L8 Premium Template</option>
                    <option value="plain">Plain Text</option>
                </select>
                <button class="compose-send-btn" id="composeSendBtn" onclick="sendCompose()">Send</button>
            </div>
        </div>
    </div>

    <div id="tab-email" class="admin-panel">
        <div class="email-client">
            <!-- Sidebar -->
            <div class="email-sidebar">
                <button class="email-compose-btn" onclick="openCompose()">✏️ Compose</button>
                <ul class="email-folder-list" id="emailFolderList">
                    <li class="email-folder-item active" data-folder="INBOX" onclick="switchFolder('INBOX')">
                        <span class="folder-icon">📥</span>
                        <span class="folder-label">Inbox</span>
                        <span class="folder-badge" id="badge-INBOX" style="display:none;"></span>
                    </li>
                    <li class="email-folder-item" data-folder="PRIMARY" onclick="switchFolder('PRIMARY')">
                        <span class="folder-icon">✉️</span>
                        <span class="folder-label">Primary</span>
                        <span class="folder-badge" id="badge-PRIMARY" style="display:none;"></span>
                    </li>
                    <li class="email-folder-item" data-folder="IMPORTANT" onclick="switchFolder('IMPORTANT')">
                        <span class="folder-icon">⭐</span>
                        <span class="folder-label">Important</span>
                    </li>
                    <li class="email-folder-item" data-folder="SENT" onclick="switchFolder('SENT')">
                        <span class="folder-icon">📤</span>
                        <span class="folder-label">Sent</span>
                    </li>
                    <li class="email-folder-item" data-folder="SPAM" onclick="switchFolder('SPAM')">
                        <span class="folder-icon">🚫</span>
                        <span class="folder-label">Spam</span>
                    </li>
                    <li class="email-folder-item" data-folder="TRASH" onclick="switchFolder('TRASH')">
                        <span class="folder-icon">🗑️</span>
                        <span class="folder-label">Deleted</span>
                    </li>
                    <li class="email-folder-item" data-folder="ARCHIVE" onclick="switchFolder('ARCHIVE')">
                        <span class="folder-icon">📁</span>
                        <span class="folder-label">Archive</span>
                    </li>
                </ul>
            </div>

            <!-- Main Content -->
            <div class="email-main">
                <!-- Toolbar -->
                <div class="email-toolbar">
                    <button class="email-toolbar-btn" onclick="refreshEmails()" title="Refresh">🔄 Refresh</button>
                    <button class="email-toolbar-btn" id="btnMarkRead" onclick="emailAction('mark_read')" title="Mark Read">✓ Read</button>
                    <button class="email-toolbar-btn" id="btnDelete" onclick="emailAction('delete')" title="Delete">🗑️ Delete</button>
                    <button class="email-toolbar-btn" id="btnSpam" onclick="emailAction('move', 'Spam')" title="Move to Spam">🚫 Spam</button>
                    <input type="text" class="email-search" id="emailSearchInput" placeholder="Search emails..." onkeydown="if(event.key==='Enter'){searchEmails();}">
                </div>

                <!-- Content: List + Reading Pane -->
                <div class="email-content-area">
                    <!-- Email List -->
                    <div class="email-list" id="emailListContainer">
                        <div class="email-loading" id="emailListLoading">
                            <div class="loading-spinner"></div>
                            Loading emails...
                        </div>
                    </div>

                    <!-- Reading Pane -->
                    <div class="email-reading-pane" id="emailReadingPane">
                        <div class="email-empty-state" id="emailEmptyRead">
                            <div class="empty-icon">📧</div>
                            <div class="empty-text">Select an email to read</div>
                            <div class="sync-badge">🛡️ Synced with admin@n2l8studios.com</div>
                        </div>
                        <div id="emailReadContent" style="display:none;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- /tab-email -->

</div><!-- /container -->

<script>
const INITIAL_TAB = '<?= h($tab) ?>';
const TAB_NAMES = { dashboard:'Dashboard', products:'Products', orders:'Orders', messages:'Messages', users:'Users', content:'Content Editor', stats:'Analytics', email:'Emails' };

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

    const panel = document.getElementById('tab-' + name);
    const deskBtn = document.getElementById('tab-btn-' + name);
    
    if (panel) panel.classList.add('active');
    if (deskBtn) { deskBtn.classList.add('active'); moveSlider(deskBtn); }

    // mobile dropdown
    document.querySelectorAll('.admin-tab-menu-item').forEach(b => {
        if (b.dataset.tab === name) b.classList.add('active');
    });
    const toggle = document.getElementById('tabToggle');
    if (toggle) toggle.textContent = TAB_NAMES[name] || name;
    document.getElementById('tabMenu')?.classList.remove('open');
    document.getElementById('tabToggle')?.classList.remove('open');

    history.replaceState(null,'','/admin/index.php?tab=' + name);

    // Auto-load emails when switching to email tab
    if (name === 'email' && !emailsLoaded) { refreshEmails(); emailsLoaded = true; }
}

function showLoading() { document.getElementById('loadingOverlay').classList.add('active'); }
function showDelConfirm(id) { document.getElementById('del-confirm-' + id).style.display = 'inline'; }
function hideDelConfirm(id) { document.getElementById('del-confirm-' + id).style.display = 'none'; }

// Tab clicks
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

/* ═══════════════════════════════════════════════════════════
   EMAIL CLIENT — IMAP AJAX FUNCTIONS
   ═══════════════════════════════════════════════════════════ */
let emailsLoaded = false;
let currentFolder = 'INBOX';
let currentEmails = [];
let currentReadUid = null;
let emailRefreshTimer = null;

function getInitials(name) {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return name.substring(0, 2).toUpperCase();
}

function switchFolder(folder) {
    currentFolder = folder;
    currentReadUid = null;
    document.querySelectorAll('.email-folder-item').forEach(f => f.classList.remove('active'));
    const el = document.querySelector(`.email-folder-item[data-folder="${folder}"]`);
    if (el) el.classList.add('active');
    document.getElementById('emailReadContent').style.display = 'none';
    document.getElementById('emailEmptyRead').style.display = 'flex';
    refreshEmails();
}

function refreshEmails() {
    const listContainer = document.getElementById('emailListContainer');
    const search = document.getElementById('emailSearchInput')?.value || '';
    listContainer.innerHTML = '<div class="email-loading"><div class="loading-spinner"></div>Loading emails...</div>';

    fetch(`/admin/imap_fetch.php?folder=${currentFolder}&limit=30&search=${encodeURIComponent(search)}`)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                listContainer.innerHTML = `<div class="email-empty-state"><div class="empty-icon">⚠️</div><div class="empty-text">${data.error}</div></div>`;
                return;
            }
            currentEmails = data.emails || [];
            // Update unread badge
            if ((currentFolder === 'INBOX' || currentFolder === 'PRIMARY') && data.unread > 0) {
                const badgeInbox = document.getElementById('badge-INBOX');
                const badgePrimary = document.getElementById('badge-PRIMARY');
                if (badgeInbox) { badgeInbox.textContent = data.unread; badgeInbox.style.display = 'inline-block'; }
                if (badgePrimary) { badgePrimary.textContent = data.unread; badgePrimary.style.display = 'inline-block'; }
            } else if (currentFolder === 'INBOX' || currentFolder === 'PRIMARY') {
                const badgeInbox = document.getElementById('badge-INBOX');
                const badgePrimary = document.getElementById('badge-PRIMARY');
                if (badgeInbox) badgeInbox.style.display = 'none';
                if (badgePrimary) badgePrimary.style.display = 'none';
            }
            renderEmailList(currentEmails);
        })
        .catch(err => {
            listContainer.innerHTML = `<div class="email-empty-state"><div class="empty-icon">❌</div><div class="empty-text">Failed to load emails</div></div>`;
        });
}

function renderEmailList(emails) {
    const container = document.getElementById('emailListContainer');
    if (!emails || emails.length === 0) {
        container.innerHTML = '<div class="email-empty-state"><div class="empty-icon">📭</div><div class="empty-text">No emails in this folder</div></div>';
        return;
    }
    let html = '';
    emails.forEach(e => {
        const unreadClass = e.is_read ? '' : ' unread';
        const activeClass = e.uid === currentReadUid ? ' active' : '';
        const initials = getInitials(e.from_name);
        html += `<div class="email-list-item${unreadClass}${activeClass}" data-uid="${e.uid}" onclick="readEmail(${e.uid})">
            <div class="email-avatar">${initials}</div>
            <div class="email-item-content">
                <div class="email-item-top">
                    <span class="email-item-from">${escHtml(e.from_name || e.from_email)}</span>
                    <span class="email-item-date">${escHtml(e.date)}</span>
                </div>
                <div class="email-item-subject">${escHtml(e.subject)}</div>
                <div class="email-item-snippet">${escHtml(e.snippet)}</div>
            </div>
        </div>`;
    });
    container.innerHTML = html;
}

function readEmail(uid) {
    currentReadUid = uid;
    // Highlight in list
    document.querySelectorAll('.email-list-item').forEach(el => el.classList.remove('active'));
    const activeEl = document.querySelector(`.email-list-item[data-uid="${uid}"]`);
    if (activeEl) { activeEl.classList.add('active'); activeEl.classList.remove('unread'); }

    const readPane = document.getElementById('emailReadContent');
    const emptyPane = document.getElementById('emailEmptyRead');
    readPane.style.display = 'none';
    emptyPane.innerHTML = '<div class="email-loading"><div class="loading-spinner"></div>Loading...</div>';
    emptyPane.style.display = 'flex';

    fetch(`/admin/imap_read.php?uid=${uid}&folder=${currentFolder}`)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                emptyPane.innerHTML = `<div class="email-empty-state"><div class="empty-icon">⚠️</div><div class="empty-text">${data.error}</div></div>`;
                return;
            }
            emptyPane.style.display = 'none';
            readPane.style.display = 'flex';
            readPane.style.flexDirection = 'column';

            const initials = getInitials(data.from_name);
            const bodyContent = data.body_html || ('<pre style="white-space:pre-wrap;font-family:Montserrat,sans-serif;">' + escHtml(data.body_text || '(No content)') + '</pre>');
            const attachHtml = (data.attachments && data.attachments.length > 0)
                ? `<div style="padding:0.8rem 1.5rem;border-top:1px solid rgba(255,255,255,0.06);font-family:Montserrat,sans-serif;font-size:0.82rem;color:var(--text-muted);">📎 ${data.attachments.length} attachment(s): ${data.attachments.map(a => escHtml(a.filename)).join(', ')}</div>`
                : '';

            readPane.innerHTML = `
                <div class="email-read-header">
                    <div class="email-read-subject">${escHtml(data.subject)}</div>
                    <div class="email-read-meta">
                        <div class="email-read-avatar">${initials}</div>
                        <div class="email-read-sender">
                            <div class="email-read-from">${escHtml(data.from_name)}</div>
                            <div class="email-read-email">${escHtml(data.from_email)} → ${escHtml((data.to || []).join(', '))}</div>
                        </div>
                        <div class="email-read-date">${escHtml(data.date)}</div>
                    </div>
                </div>
                <div class="email-read-body">${bodyContent}</div>
                ${attachHtml}
                <div class="email-read-actions">
                    <button class="email-toolbar-btn" onclick="replyToEmail()">↩️ Reply</button>
                    <button class="email-toolbar-btn" onclick="emailAction('flag')">⭐ Flag</button>
                    <button class="email-toolbar-btn" onclick="emailAction('mark_unread')">📩 Mark Unread</button>
                    <button class="email-toolbar-btn" onclick="emailAction('delete')">🗑️ Delete</button>
                    <button class="email-toolbar-btn" onclick="emailAction('move','Spam')">🚫 Spam</button>
                </div>`;
        })
        .catch(err => {
            emptyPane.innerHTML = '<div class="email-empty-state"><div class="empty-icon">❌</div><div class="empty-text">Failed to load email</div></div>';
        });
}

function emailAction(action, target) {
    if (!currentReadUid) return;
    const fd = new FormData();
    fd.append('action', action);
    fd.append('uid', currentReadUid);
    fd.append('folder', currentFolder);
    if (target) fd.append('target_folder', target);

    fetch('/admin/imap_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Remove from list and reset reading pane
                if (action === 'delete' || action === 'move') {
                    currentReadUid = null;
                    document.getElementById('emailReadContent').style.display = 'none';
                    document.getElementById('emailEmptyRead').style.display = 'flex';
                    document.getElementById('emailEmptyRead').innerHTML = '<div class="email-empty-state"><div class="empty-icon">📧</div><div class="empty-text">Select an email to read</div><div class="sync-badge">🛡️ Synced with admin@n2l8studios.com</div></div>';
                }
                refreshEmails();
            }
        });
}

function searchEmails() { refreshEmails(); }

function escHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

/* ── Compose Modal ── */
function openCompose() {
    document.getElementById('composeOverlay').classList.add('open');
}
function closeCompose() {
    document.getElementById('composeOverlay').classList.remove('open');
}
function toggleComposeFields() {
    const type = document.getElementById('composeRecipientType').value;
    document.getElementById('composeCustomEmail').style.display = (type === 'custom') ? 'block' : 'none';
    document.getElementById('composeUserId').style.display = (type === 'single_user') ? 'block' : 'none';
}
function replyToEmail() {
    // Find current email data
    const email = currentEmails.find(e => e.uid === currentReadUid);
    if (!email) return;
    openCompose();
    document.getElementById('composeRecipientType').value = 'custom';
    toggleComposeFields();
    document.getElementById('composeCustomEmail').value = email.from_email;
    document.getElementById('composeSubject').value = 'Re: ' + email.subject;
    document.getElementById('composeMessage').focus();
}
function sendCompose() {
    const btn = document.getElementById('composeSendBtn');
    btn.disabled = true;
    btn.textContent = 'Sending...';

    const fd = new FormData();
    fd.append('recipient_type', document.getElementById('composeRecipientType').value);
    fd.append('custom_email', document.getElementById('composeCustomEmail').value);
    fd.append('user_id', document.getElementById('composeUserId').value);
    fd.append('subject', document.getElementById('composeSubject').value);
    fd.append('message', document.getElementById('composeMessage').value);
    fd.append('template_type', document.getElementById('composeTemplate').value);

    fetch('/admin/send_email.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.textContent = 'Send';
        if (data.success) {
            closeCompose();
            document.getElementById('composeForm').reset();
            alert('✅ ' + data.message);
        } else {
            alert('❌ ' + data.message);
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.textContent = 'Send';
        alert('❌ Network error');
    });
}

// Auto-refresh emails every 60 seconds when on the email tab
setInterval(() => {
    const emailPanel = document.getElementById('tab-email');
    if (emailPanel && emailPanel.classList.contains('active')) {
        refreshEmails();
    }
}, 60000);

// Init — wait for layout so slider can measure button positions
window.addEventListener('load', () => {
    showTab(INITIAL_TAB);
});
</script>
</body>
</html>
