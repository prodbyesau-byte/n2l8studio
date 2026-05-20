<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_client_login();

$pdo = get_pdo();
$site = get_site_content($pdo);
log_visitor($pdo, 'page_view', '/portal/index.php');

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$user_email = $_SESSION['email'] ?? '';

// 1. Fetch user profile info (avatar picture)
$user_stmt = $pdo->prepare('SELECT profile_picture FROM users WHERE id = ?');
$user_stmt->execute([$user_id]);
$profile_pic = $user_stmt->fetchColumn() ?: '';

// 2. Fetch inbox messages and unread count
$msg_stmt = $pdo->prepare('SELECT * FROM messages WHERE recipient_id = ? ORDER BY id DESC');
$msg_stmt->execute([$user_id]);
$messages = $msg_stmt->fetchAll();
$unread_count = count(array_filter($messages, fn($m) => !$m['is_read']));

// 3. Fetch purchased products
$stmt = $pdo->prepare('
    SELECT o.id as order_id, p.* 
    FROM orders o 
    JOIN products p ON o.product_id = p.id 
    WHERE o.customer_email = ? AND o.status = "completed" 
    ORDER BY o.id DESC
');
$stmt->execute([$user_email]);
$purchased_products = $stmt->fetchAll();

// 4. Fetch free products from shop
$free_products = $pdo->query('
    SELECT * FROM products 
    WHERE price = 0.00 AND is_active = 1 
    ORDER BY id DESC
')->fetchAll();

// 5. Form handling
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 5a. Profile Picture Upload
    if (isset($_POST['upload_avatar'])) {
        $avatar = save_upload('avatar_file', ALLOWED_IMAGES);
        if ($avatar) {
            // Delete old avatar from filesystem if any
            if (!empty($profile_pic)) {
                $old_avatar_path = rtrim(UPLOAD_DIR, '/') . '/' . basename($profile_pic);
                if (file_exists($old_avatar_path)) {
                    @unlink($old_avatar_path);
                }
            }
            
            // Save to DB
            $upd_stmt = $pdo->prepare('UPDATE users SET profile_picture = ? WHERE id = ?');
            if ($upd_stmt->execute([$avatar, $user_id])) {
                $profile_pic = $avatar; // update display state
                $success_msg = 'Profile picture updated successfully.';
                log_action($pdo, "User {$username} uploaded a new profile picture.");
            } else {
                $error_msg = 'Failed to save avatar filename in the database.';
            }
        } else {
            $error_msg = 'Invalid image file or upload failed. Supported formats: PNG, JPG, JPEG, WEBP, GIF (Max 2MB).';
        }
    }
    
    // 5b. Password Change
    if (isset($_POST['change_password'])) {
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass     = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
            $error_msg = 'All password fields are required.';
        } elseif ($new_pass !== $confirm_pass) {
            $error_msg = 'New passwords do not match.';
        } elseif (strlen($new_pass) < 6) {
            $error_msg = 'New password must be at least 6 characters.';
        } else {
            // Verify current password
            $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
            $stmt->execute([$user_id]);
            $user_pass = $stmt->fetchColumn();

            if ($user_pass && password_verify($current_pass, $user_pass)) {
                $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                if ($stmt->execute([$new_hash, $user_id])) {
                    $success_msg = 'Password changed successfully.';
                    log_action($pdo, "User {$username} changed their password.");
                } else {
                    $error_msg = 'Failed to update password. Please try again.';
                }
            } else {
                $error_msg = 'Incorrect current password.';
            }
        }
    }
}

// Support Tab
$tab = $_GET['tab'] ?? 'library';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Portal - N2L8 STUDIO</title>
    <link rel="stylesheet" href="/static/style.css?v=8">
    <link rel="icon" type="image/png" href="/static/logo.png">
    <link rel="apple-touch-icon" href="/static/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
    <style>
        .portal-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 2rem 6rem 2rem;
        }
        .portal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 1.5rem;
            margin-bottom: 2.5rem;
        }
        .portal-welcome h2 {
            font-family: 'Syncopate', sans-serif;
            font-size: 1.4rem;
            color: #ffffff;
            margin-bottom: 0.3rem;
        }
        .portal-welcome p {
            color: var(--text-muted);
            font-size: 0.85rem;
            font-family: 'Montserrat', sans-serif;
            font-weight: 500;
        }
        .portal-welcome span {
            color: var(--accent);
            text-shadow: 0 0 10px rgba(192, 21, 42, 0.4);
            font-weight: 700;
        }

        /* Tabs */
        .portal-tabs {
            display: flex;
            gap: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            margin-bottom: 2.5rem;
        }
        .portal-tab-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-family: 'Syncopate', sans-serif;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 1rem 0.5rem;
            cursor: pointer;
            letter-spacing: 0.1em;
            position: relative;
            transition: all 0.25s ease;
        }
        .portal-tab-btn:hover {
            color: #ffffff;
        }
        .portal-tab-btn.active {
            color: var(--accent);
        }
        .portal-tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--accent);
            box-shadow: var(--accent-glow);
        }

        /* Library Cards */
        .library-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 2rem;
        }
        .library-card {
            background: rgba(5, 5, 8, 0.8);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 1.2rem;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        .library-card:hover {
            border-color: rgba(192, 21, 42, 0.4);
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(192, 21, 42, 0.1), var(--accent-glow);
        }
        .library-cover {
            width: 100%;
            aspect-ratio: 1;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 1.2rem;
            background: #000000;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.4);
        }
        .library-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
        }
        .library-card:hover .library-cover img {
            transform: scale(1.04);
        }
        .library-info {
            flex-grow: 1;
            text-align: left;
            margin-bottom: 1.5rem;
        }
        .library-info h3 {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.3rem;
            text-transform: none;
            letter-spacing: 0;
        }
        .library-info .author {
            color: var(--text-muted);
            font-size: 0.75rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        .library-info .tag {
            display: inline-block;
            font-size: 0.62rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--accent);
            background: rgba(192, 21, 42, 0.08);
            border: 1px solid var(--border-color);
            padding: 2px 6px;
            border-radius: 2px;
        }
        .library-card .cta-btn {
            width: 100%;
            font-family: 'Syncopate', sans-serif;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            padding: 0.8rem;
            text-align: center;
            border-radius: 4px;
        }

        /* Empty State */
        .empty-library {
            text-align: center;
            padding: 5rem 2rem;
            border: 1px dashed var(--border-color);
            border-radius: 8px;
            background: rgba(5, 5, 8, 0.4);
        }
        .empty-library h3 {
            font-family: 'Syncopate', sans-serif;
            font-size: 1.1rem;
            color: #ffffff;
            margin-bottom: 0.8rem;
        }
        .empty-library p {
            color: var(--text-muted);
            font-size: 0.85rem;
            max-width: 500px;
            margin: 0 auto 2rem auto;
            line-height: 1.5;
        }

        /* Forms */
        .portal-card {
            background: rgba(5, 5, 8, 0.8);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 3rem 2.5rem;
            max-width: 600px;
            margin: 0 auto;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.5);
        }
        .portal-card h3 {
            font-family: 'Syncopate', sans-serif;
            color: #ffffff;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            letter-spacing: 0.05em;
            text-align: center;
        }
        .portal-card p {
            color: var(--text-muted);
            font-size: 0.8rem;
            margin-bottom: 2rem;
            text-align: center;
        }
        .form-group {
            margin-bottom: 1.5rem;
            text-align: left;
        }
        .form-group label {
            display: block;
            color: var(--text-muted);
            font-family: 'Montserrat', sans-serif;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
        }
        .portal-card input {
            width: 100%;
            padding: 0.85rem 1rem;
            background: #000000;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            color: var(--text-main);
            font-family: 'Montserrat', sans-serif;
            font-size: 0.9rem;
            outline: none;
            transition: all 0.25s ease;
        }
        .portal-card input:focus {
            border-color: var(--accent);
            box-shadow: var(--accent-glow);
        }
        .portal-card .cta-btn {
            width: 100%;
            font-family: 'Syncopate', sans-serif;
            font-weight: 700;
            letter-spacing: 0.1em;
            padding: 0.95rem;
            border-radius: 4px;
            margin-top: 1rem;
        }
        .flash-msg {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.95rem;
            padding: 0.8rem 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            border: 1px solid;
            text-align: left;
        }
        .flash-error {
            color: var(--accent);
            background: rgba(192, 21, 42, 0.08);
            border-color: var(--border-color);
        }
        .flash-success {
            color: #7be1a8;
            background: rgba(123, 225, 168, 0.08);
            border-color: rgba(123, 225, 168, 0.2);
        }

        .portal-tab {
            display: none;
        }
        .portal-tab.active {
            display: block;
        }
    </style>
</head>
<body class="page-home">
    <header class="hero" style="min-height: auto; padding-bottom: 0;">
        <nav>
            <a href="/index.php" class="logo-text" style="text-decoration:none;">N<span>2</span>L8studios</a>
            <ul class="nav-links">
                <li><a href="/index.php">Home</a></li>
                <li><a href="/shop.php">Shop</a></li>
                <li><a href="/pricing.php">Services</a></li>
                <li><a href="/logout.php" style="color: var(--accent);">Disconnect</a></li>
            </ul>
        </nav>
    </header>

    <div class="portal-container">
        
        <div class="portal-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1.5rem;">
            <div style="display:flex; align-items:center; gap:1.2rem;">
                <?php if ($profile_pic): ?>
                    <img src="/static/uploads/<?= h($profile_pic) ?>" alt="Avatar" style="width:64px; height:64px; border-radius:50%; object-fit:cover; border:2px solid var(--accent); box-shadow: var(--accent-glow);">
                <?php else: ?>
                    <div style="width:64px; height:64px; border-radius:50%; background:rgba(255,255,255,0.05); border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; font-family:'Syncopate',sans-serif; font-size:1.5rem; font-weight:700; color:#fff; text-shadow:0 0 10px rgba(255,255,255,0.2);">
                        <?= strtoupper(substr($username, 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div class="portal-welcome">
                    <h2>CLIENT PORTAL</h2>
                    <p>Welcome back, <span><?= h($username) ?></span> &nbsp;|&nbsp; Credentials: <span><?= h($user_email) ?></span></p>
                </div>
            </div>
            <div>
                <a href="/logout.php" class="cta-btn secondary" style="font-size: 0.72rem; padding: 0.6rem 1.2rem;">LOGOUT</a>
            </div>
        </div>

        <div class="portal-tabs">
            <button class="portal-tab-btn <?= $tab === 'library' ? 'active' : '' ?>" onclick="switchTab('library')">My Library (<?= count($purchased_products) ?>)</button>
            <button class="portal-tab-btn <?= $tab === 'free' ? 'active' : '' ?>" onclick="switchTab('free')">Claim Free Kits (<?= count($free_products) ?>)</button>
            <button class="portal-tab-btn <?= $tab === 'inbox' ? 'active' : '' ?>" onclick="switchTab('inbox')">Inbox (<?= $unread_count ?>)</button>
            <button class="portal-tab-btn <?= $tab === 'settings' ? 'active' : '' ?>" onclick="switchTab('settings')">Account Settings</button>
        </div>

        <!-- ── TAB: LIBRARY ── -->
        <div id="tab-library" class="portal-tab <?= $tab === 'library' ? 'active' : '' ?>">
            <?php if (!empty($purchased_products)): ?>
                <div class="library-grid">
                    <?php foreach ($purchased_products as $p): ?>
                        <div class="library-card">
                            <div class="library-cover">
                                <?php if ($p['cover_image']): ?>
                                    <img src="/static/uploads/<?= h($p['cover_image']) ?>" alt="">
                                <?php else: ?>
                                    <div style="width:100%;height:100%;background:rgba(255,255,255,0.02);"></div>
                                <?php endif; ?>
                            </div>
                            <div class="library-info">
                                <h3><?= h($p['title']) ?></h3>
                                <div class="author">By <?= h($p['author'] ?: 'N2L8 STUDIO') ?></div>
                                <span class="tag"><?= h($p['type']) ?></span>
                                                         <?php if ($p['zip_file']): ?>
                                <a href="/static/uploads/<?= h($p['zip_file']) ?>" class="cta-btn" download>DOWNLOAD KIT</a>
                            <?php else: ?>
                                <button class="cta-btn secondary" style="cursor: not-allowed; opacity: 0.6;" disabled>NO FILE LOADED</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-library">
                    <h3>No Purchased Kits</h3>
                    <p>It looks like you haven't purchased any drumkits, loopkits, or beats yet under this account email address (<?= h($user_email) ?>).</p>
                    <p style="font-size:0.8rem;color:rgba(192,21,42,0.8);border:1px dashed var(--border-color);padding:1rem;display:inline-block;border-radius:4px;">
                        💡 <strong>Library Sync TIP</strong>: Any purchases made with your registered email will automatically sync and populate here. If you buy something via PayPal, ensure you register/use the same email address!
                    </p>
                    <div style="margin-top: 2rem;">
                        <a href="/shop.php" class="cta-btn">BROWSE SHOP</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── TAB: FREE KITS ── -->
        <div id="tab-free" class="portal-tab <?= $tab === 'free' ? 'active' : '' ?>">
            <?php if (!empty($free_products)): ?>
                <div class="library-grid">
                    <?php foreach ($free_products as $p): ?>
                        <div class="library-card">
                            <div class="library-cover">
                                <?php if ($p['cover_image']): ?>
                                    <img src="/static/uploads/<?= h($p['cover_image']) ?>" alt="">
                                <?php else: ?>
                                    <div style="width:100%;height:100%;background:rgba(255,255,255,0.02);"></div>
                                <?php endif; ?>
                            </div>
                            <div class="library-info">
                                <h3><?= h($p['title']) ?></h3>
                                <div class="author">By <?= h($p['author'] ?: 'N2L8 STUDIO') ?></div>
                                <span class="tag"><?= h($p['type']) ?></span>
                            </div>
                            <?php if ($p['zip_file']): ?>
                                <a href="/static/uploads/<?= h($p['zip_file']) ?>" class="cta-btn" download>CLAIM FREE KIT</a>
                            <?php else: ?>
                                <button class="cta-btn secondary" style="cursor: not-allowed; opacity: 0.6;" disabled>NO FILE LOADED</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-library">
                    <h3>No Free Kits Available</h3>
                    <p>There are currently no free sample packs active on the shop server. Check back soon!</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── TAB: INBOX ── -->
        <div id="tab-inbox" class="portal-tab <?= $tab === 'inbox' ? 'active' : '' ?>">
            <div class="portal-card" style="padding: 0; background: rgba(5, 5, 8, 0.8); border: 1px solid var(--border-color); border-radius: 6px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
                <div style="padding: 2rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                    <h3 style="font-family:'Syncopate',sans-serif; font-size:1.1rem; letter-spacing:2px; margin:0 0 0.4rem 0; color:#fff;">INBOX</h3>
                    <p style="color:var(--text-muted); font-size:0.82rem; margin:0; font-family:'Montserrat',sans-serif; font-weight:500;">View private messages and community notifications sent by N2L8 Studio.</p>
                </div>
                
                <?php if (empty($messages)): ?>
                    <div style="text-align:center; padding:5rem 2rem; color:var(--text-muted);">
                        <div style="font-size:3rem; margin-bottom:1rem; opacity:0.4;">📬</div>
                        <h4 style="font-family:'Syncopate',sans-serif; color:#fff; font-size:0.95rem; margin-bottom:0.5rem; letter-spacing:1px;">Your inbox is empty</h4>
                        <p style="font-size:0.8rem; margin:0; font-family:'Montserrat',sans-serif;">We will notify you here when you have new messages or updates.</p>
                    </div>
                <?php else: ?>
                    <div style="list-style:none; padding:0; margin:0;">
                        <?php foreach ($messages as $msg): ?>
                            <div class="message-row <?= $msg['is_read'] ? 'read' : 'unread' ?>" onclick="toggleMessage(<?= (int)$msg['id'] ?>)" id="msg-row-<?= (int)$msg['id'] ?>" style="border-bottom:1px solid rgba(255,255,255,0.05); padding:1.2rem 2rem; cursor:pointer; transition:background 0.2s ease; display:grid; grid-template-columns: 24px 1fr auto; align-items:center; gap:1.2rem; text-align:left;">
                                <div>
                                    <?php if (!$msg['is_read']): ?>
                                        <span class="unread-dot" id="dot-<?= (int)$msg['id'] ?>" style="display:inline-block; width:8px; height:8px; background:var(--accent); border-radius:50%; box-shadow:0 0 6px var(--accent);"></span>
                                    <?php else: ?>
                                        <span style="display:inline-block; width:8px; height:8px; background:rgba(255,255,255,0.1); border-radius:50%;"></span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="msg-subject" style="font-family:'Montserrat',sans-serif; font-size:0.92rem; font-weight:<?= $msg['is_read'] ? '600' : '700' ?>; color:#fff; letter-spacing:0.02em; margin-bottom:0.25rem;">
                                        <?= h($msg['subject']) ?>
                                    </div>
                                    <div class="msg-snippet" id="snippet-<?= (int)$msg['id'] ?>" style="font-size:0.78rem; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:650px; font-family:'Montserrat',sans-serif;">
                                        <?= h(substr(strip_tags($msg['message']), 0, 100)) ?>...
                                    </div>
                                </div>
                                <div style="font-size:0.75rem; color:var(--text-muted); font-family:'Montserrat',sans-serif; font-weight:500;">
                                    <?= date('M j, Y H:i', strtotime($msg['created_at'])) ?>
                                </div>
                                
                                <div class="msg-body" id="body-<?= (int)$msg['id'] ?>" style="display:none; grid-column:1/-1; padding:1rem 0 0.5rem 0; border-top:1px dashed rgba(255,255,255,0.05); margin-top:0.8rem; font-family:'Montserrat',sans-serif; font-size:0.88rem; color:#e0e0e0; line-height:1.6; white-space:pre-wrap; text-transform:none; letter-spacing:0.02em;">
                                    <?= h($msg['message']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── TAB: SETTINGS ── -->
        <div id="tab-settings" class="portal-tab <?= $tab === 'settings' ? 'active' : '' ?>">
            
            <?php if ($error_msg): ?>
                <div class="flash-msg flash-error">&gt; <?= h($error_msg) ?></div>
            <?php endif; ?>

            <?php if ($success_msg): ?>
                <div class="flash-msg flash-success">&gt; <?= h($success_msg) ?></div>
            <?php endif; ?>

            <div class="portal-card" style="margin-bottom: 2rem; background: rgba(5, 5, 8, 0.8); border: 1px solid var(--border-color); border-radius: 6px; padding: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
                <h3 style="font-family:'Syncopate',sans-serif; font-size:1.1rem; letter-spacing:2px; margin:0 0 0.4rem 0; color:#fff;">PROFILE PICTURE</h3>
                <p style="color:var(--text-muted); font-size:0.82rem; margin:0 0 1.5rem 0; font-family:'Montserrat',sans-serif; font-weight:500;">Upload an avatar or animated GIF (Max 2MB).</p>
                
                <div style="display:flex; align-items:center; gap:2rem; flex-wrap:wrap; text-align:left;">
                    <?php if ($profile_pic): ?>
                        <img src="/static/uploads/<?= h($profile_pic) ?>" alt="Avatar" style="width:96px; height:96px; border-radius:50%; object-fit:cover; border:2px solid var(--accent); box-shadow: var(--accent-glow);">
                    <?php else: ?>
                        <div style="width:96px; height:96px; border-radius:50%; background:rgba(255,255,255,0.05); border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; font-family:'Syncopate',sans-serif; font-size:2.2rem; font-weight:700; color:#fff; text-shadow:0 0 10px rgba(255,255,255,0.2);">
                            <?= strtoupper(substr($username, 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" enctype="multipart/form-data" style="flex:1; min-width:250px;">
                        <input type="hidden" name="upload_avatar" value="1">
                        <div style="display:flex; flex-direction:column; gap:0.5rem; margin-bottom:1rem;">
                            <input type="file" name="avatar_file" accept=".png,.jpg,.jpeg,.webp,.gif" required style="font-family:'Montserrat',sans-serif; font-size:0.8rem; border:1px dashed var(--border-color); padding:0.8rem; width:100%; border-radius:4px; cursor:pointer; background:var(--bg-dark); color:var(--text-main); outline:none;">
                        </div>
                        <button type="submit" class="cta-btn" style="padding:0.7rem 1.8rem; font-size:0.75rem; width:auto; display:inline-block; margin-top:0;">UPLOAD AVATAR</button>
                    </form>
                </div>
            </div>

            <div class="portal-card" style="background: rgba(5, 5, 8, 0.8); border: 1px solid var(--border-color); border-radius: 6px; padding: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
                <h3 style="font-family:'Syncopate',sans-serif; font-size:1.1rem; letter-spacing:2px; margin:0 0 0.4rem 0; color:#fff;">ACCOUNT SECURITY</h3>
                <p style="color:var(--text-muted); font-size:0.82rem; margin:0 0 1.5rem 0; font-family:'Montserrat',sans-serif; font-weight:500;">Configure security settings and update password.</p>

                <form method="POST">
                    <input type="hidden" name="change_password" value="1">
                    
                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required placeholder="••••••••">
                    </div>

                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" required placeholder="•••••••• (Min 6 chars)">
                    </div>

                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" required placeholder="••••••••">
                    </div>

                    <button type="submit" class="cta-btn">UPDATE PASSWORD</button>
                </form>
            </div>
        </div>

    </div>

    <script>
        function switchTab(tabId) {
            document.querySelectorAll('.portal-tab').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.portal-tab-btn').forEach(el => el.classList.remove('active'));

            const targetTab = document.getElementById('tab-' + tabId);
            if (targetTab) {
                targetTab.classList.add('active');
            }
            
            // Find the active button and activate it
            const activeBtn = Array.from(document.querySelectorAll('.portal-tab-btn')).find(btn => btn.innerText.toLowerCase().includes(tabId));
            if (activeBtn) {
                activeBtn.classList.add('active');
            }

            // Update URL
            history.replaceState(null, '', '/portal/index.php?tab=' + tabId);
        }

        function toggleMessage(id) {
            const body = document.getElementById('body-' + id);
            const snippet = document.getElementById('snippet-' + id);
            const row = document.getElementById('msg-row-' + id);
            const dot = document.getElementById('dot-' + id);
            
            if (!body) return;
            
            const isExpanding = body.style.display === 'none';
            body.style.display = isExpanding ? 'block' : 'none';
            if (snippet) snippet.style.display = isExpanding ? 'none' : 'block';
            if (row) {
                if (isExpanding) {
                    row.style.background = 'rgba(255,255,255,0.02)';
                } else {
                    row.style.background = '';
                }
            }
            
            // Mark as read asynchronously if it's currently unread
            if (isExpanding && row && row.classList.contains('unread')) {
                fetch('/portal/read_message.php?id=' + id)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            row.classList.remove('unread');
                            row.classList.add('read');
                            if (dot) {
                                dot.style.background = 'rgba(255,255,255,0.1)';
                                dot.style.boxShadow = 'none';
                            }
                            
                            // Dynamically update unread count in header tab
                            const inboxBtn = Array.from(document.querySelectorAll('.portal-tab-btn')).find(btn => btn.innerText.toLowerCase().includes('inbox'));
                            if (inboxBtn) {
                                const match = inboxBtn.innerText.match(/\((\d+)\)/);
                                if (match) {
                                    let currentCount = parseInt(match[1]);
                                    if (currentCount > 0) {
                                        currentCount--;
                                        inboxBtn.innerText = 'Inbox (' + currentCount + ')';
                                    }
                                }
                            }
                        }
                    })
                    .catch(err => console.error("Error marking read:", err));
            }
        }
    </script>
</body>
</html>
