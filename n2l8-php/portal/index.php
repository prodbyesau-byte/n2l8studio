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

// 2. Fetch received messages and unread count
$msg_stmt = $pdo->prepare('
    SELECT m.*, u.username AS sender_name, u.profile_picture AS sender_avatar
    FROM messages m
    LEFT JOIN users u ON m.sender_id = u.id
    WHERE m.recipient_id = ?
    ORDER BY m.id DESC
');
$msg_stmt->execute([$user_id]);
$received_messages = $msg_stmt->fetchAll();
$unread_count = count(array_filter($received_messages, fn($m) => !$m['is_read']));

// 2b. Fetch sent messages (Outbox)
$sent_stmt = $pdo->prepare('
    SELECT m.*, u.username AS recipient_name, u.profile_picture AS recipient_avatar
    FROM messages m
    LEFT JOIN users u ON m.recipient_id = u.id
    WHERE m.sender_id = ?
    ORDER BY m.id DESC
');
$sent_stmt->execute([$user_id]);
$sent_messages = $sent_stmt->fetchAll();

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

// 4b. Fetch approved community members
$search_q = trim($_GET['q'] ?? '');
if ($search_q !== '') {
    $members_stmt = $pdo->prepare('
        SELECT id, username, profile_picture, role 
        FROM users 
        WHERE is_approved = 1 AND role != "admin" AND id != ? AND username LIKE ?
        ORDER BY username ASC
    ');
    $members_stmt->execute([$user_id, "%{$search_q}%"]);
} else {
    $members_stmt = $pdo->prepare('
        SELECT id, username, profile_picture, role 
        FROM users 
        WHERE is_approved = 1 AND role != "admin" AND id != ?
        ORDER BY username ASC
        LIMIT 50
    ');
    $members_stmt->execute([$user_id]);
}
$members = $members_stmt->fetchAll();


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

        /* Inbox subtabs styling */
        .inbox-subtabs {
            display: flex;
            gap: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            margin-bottom: 2rem;
            padding-bottom: 0.5rem;
        }
        .inbox-subtab-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-family: 'Syncopate', sans-serif;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            letter-spacing: 0.1em;
            padding: 0.5rem 0;
            position: relative;
            transition: all 0.25s ease;
        }
        .inbox-subtab-btn:hover {
            color: #ffffff;
        }
        .inbox-subtab-btn.active {
            color: var(--accent);
        }
        .inbox-subtab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -6px;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--accent);
            box-shadow: var(--accent-glow);
        }

        /* Community directory styling */
        .community-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }
        .community-card {
            background: rgba(5, 5, 8, 0.8);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 1.8rem 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            transition: all 0.3s ease;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
        }
        .community-card:hover {
            border-color: rgba(192, 21, 42, 0.4);
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(192, 21, 42, 0.12), var(--accent-glow);
        }
        .community-avatar-large {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.05);
            margin-bottom: 1.2rem;
            transition: all 0.3s ease;
        }
        .community-card:hover .community-avatar-large {
            border-color: var(--accent);
            box-shadow: var(--accent-glow);
        }
        .community-initial-large {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Syncopate', sans-serif;
            font-size: 1.6rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 1.2rem;
            transition: all 0.3s ease;
        }
        .community-card:hover .community-initial-large {
            border-color: var(--accent);
            background: rgba(192, 21, 42, 0.05);
            box-shadow: var(--accent-glow);
            color: var(--accent);
        }
        .community-username {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.2rem;
        }
        .community-role {
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 1.5rem;
        }

        /* Message details elements */
        .msg-reply-btn {
            background: rgba(192, 21, 42, 0.1);
            border: 1px solid var(--border-color);
            color: #fff;
            font-family: 'Syncopate', sans-serif;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            padding: 0.4rem 1rem;
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .msg-reply-btn:hover {
            background: var(--accent);
            border-color: var(--accent);
            box-shadow: var(--accent-glow);
        }
        .close-modal:hover {
            color: #ffffff !important;
        }

        /* Modal custom select and inputs */
        .form-group select {
            width:100%; 
            padding:0.9rem 1.2rem; 
            background:rgba(5, 5, 8, 0.9); 
            border:1px solid var(--border-color); 
            border-radius:4px; 
            color:#fff; 
            font-family:'Montserrat',sans-serif; 
            outline:none; 
            transition:border-color 0.3s;
        }
        .form-group select:focus {
            border-color: var(--accent);
            box-shadow: var(--accent-glow);
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
            <button class="portal-tab-btn <?= $tab === 'members' ? 'active' : '' ?>" onclick="switchTab('members')">Community</button>
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
                <div style="padding: 2rem; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h3 style="font-family:'Syncopate',sans-serif; font-size:1.1rem; letter-spacing:2px; margin:0 0 0.4rem 0; color:#fff;">COMMUNICATIONS</h3>
                        <p style="color:var(--text-muted); font-size:0.82rem; margin:0; font-family:'Montserrat',sans-serif; font-weight:500;">Direct messaging and community conversations.</p>
                    </div>
                    <button class="cta-btn" onclick="openFreshComposeModal()" style="width: auto; margin: 0; padding: 0.6rem 1.4rem; font-size: 0.72rem;">COMPOSE MESSAGE</button>
                </div>

                <div style="padding: 1.5rem 2rem 0 2rem;">
                    <div class="inbox-subtabs">
                        <button class="inbox-subtab-btn active" id="subtab-received-btn" onclick="switchInboxSubtab('received')">Received (<?= count(array_filter($received_messages, fn($m) => !$m['is_read'])) ?> unread)</button>
                        <button class="inbox-subtab-btn" id="subtab-sent-btn" onclick="switchInboxSubtab('sent')">Sent</button>
                    </div>
                </div>
                
                <!-- Subtab: Received -->
                <div id="inbox-received-container" class="inbox-subtab-content">
                    <?php if (empty($received_messages)): ?>
                        <div style="text-align:center; padding:5rem 2rem; color:var(--text-muted);">
                            <div style="font-size:3rem; margin-bottom:1rem; opacity:0.4;">📬</div>
                            <h4 style="font-family:'Syncopate',sans-serif; color:#fff; font-size:0.95rem; margin-bottom:0.5rem; letter-spacing:1px;">Your inbox is empty</h4>
                            <p style="font-size:0.8rem; margin:0; font-family:'Montserrat',sans-serif;">No incoming messages found. When others message you, they will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div style="list-style:none; padding:0; margin:0;">
                            <?php foreach ($received_messages as $msg): ?>
                                <div class="message-row <?= $msg['is_read'] ? 'read' : 'unread' ?>" onclick="toggleMessage(<?= (int)$msg['id'] ?>)" id="msg-row-<?= (int)$msg['id'] ?>" style="border-bottom:1px solid rgba(255,255,255,0.05); padding:1.2rem 2rem; cursor:pointer; transition:background 0.2s ease; display:grid; grid-template-columns: 24px 100px 1fr auto; align-items:center; gap:1.2rem; text-align:left;">
                                    <div>
                                        <?php if (!$msg['is_read']): ?>
                                            <span class="unread-dot" id="dot-<?= (int)$msg['id'] ?>" style="display:inline-block; width:8px; height:8px; background:var(--accent); border-radius:50%; box-shadow:0 0 6px var(--accent);"></span>
                                        <?php else: ?>
                                            <span style="display:inline-block; width:8px; height:8px; background:rgba(255,255,255,0.1); border-radius:50%;"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-family:'Montserrat',sans-serif; font-size:0.8rem; font-weight:700; color:var(--accent); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        <?= h($msg['sender_name'] ?: 'SYSTEM') ?>
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
                                    
                                    <div class="msg-body" id="body-<?= (int)$msg['id'] ?>" style="display:none; grid-column:1/-1; padding:1.2rem 0 0.5rem 0; border-top:1px dashed rgba(255,255,255,0.05); margin-top:0.8rem; font-family:'Montserrat',sans-serif; font-size:0.88rem; color:#e0e0e0; line-height:1.6; white-space:pre-wrap; text-transform:none; letter-spacing:0.02em;">
                                        <div style="margin-bottom:1.2rem;"><?= h($msg['message']) ?></div>
                                        <?php if ($msg['sender_id']): ?>
                                            <div style="display:flex; justify-content:flex-end;">
                                                <button class="msg-reply-btn" onclick="event.stopPropagation(); openReplyModal(<?= (int)$msg['sender_id'] ?>, '<?= h(addslashes($msg['sender_name'])) ?>', '<?= h(addslashes($msg['subject'])) ?>', '<?= h(addslashes(str_replace(["\r", "\n"], ["", "\\n"], $msg['message']))) ?>', '<?= date('M j, Y H:i', strtotime($msg['created_at'])) ?>')">
                                                    ↩ REPLY
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Subtab: Sent -->
                <div id="inbox-sent-container" class="inbox-subtab-content" style="display:none;">
                    <?php if (empty($sent_messages)): ?>
                        <div style="text-align:center; padding:5rem 2rem; color:var(--text-muted);">
                            <div style="font-size:3rem; margin-bottom:1rem; opacity:0.4;">📨</div>
                            <h4 style="font-family:'Syncopate',sans-serif; color:#fff; font-size:0.95rem; margin-bottom:0.5rem; letter-spacing:1px;">Outbox is empty</h4>
                            <p style="font-size:0.8rem; margin:0; font-family:'Montserrat',sans-serif;">You haven't sent any direct messages yet.</p>
                        </div>
                    <?php else: ?>
                        <div style="list-style:none; padding:0; margin:0;">
                            <?php foreach ($sent_messages as $msg): ?>
                                <div class="message-row read" onclick="toggleSentMessage(<?= (int)$msg['id'] ?>)" id="sent-msg-row-<?= (int)$msg['id'] ?>" style="border-bottom:1px solid rgba(255,255,255,0.05); padding:1.2rem 2rem; cursor:pointer; transition:background 0.2s ease; display:grid; grid-template-columns: 24px 100px 1fr auto; align-items:center; gap:1.2rem; text-align:left;">
                                    <div>
                                        <span style="display:inline-block; width:8px; height:8px; background:rgba(255,255,255,0.15); border-radius:50%;"></span>
                                    </div>
                                    <div style="font-family:'Montserrat',sans-serif; font-size:0.8rem; font-weight:700; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        To: <?= h($msg['recipient_name'] ?: 'SYSTEM') ?>
                                    </div>
                                    <div>
                                        <div class="msg-subject" style="font-family:'Montserrat',sans-serif; font-size:0.92rem; font-weight:600; color:#fff; letter-spacing:0.02em; margin-bottom:0.25rem;">
                                            <?= h($msg['subject']) ?>
                                        </div>
                                        <div class="msg-snippet" id="sent-snippet-<?= (int)$msg['id'] ?>" style="font-size:0.78rem; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:650px; font-family:'Montserrat',sans-serif;">
                                            <?= h(substr(strip_tags($msg['message']), 0, 100)) ?>...
                                        </div>
                                    </div>
                                    <div style="font-size:0.75rem; color:var(--text-muted); font-family:'Montserrat',sans-serif; font-weight:500;">
                                        <?= date('M j, Y H:i', strtotime($msg['created_at'])) ?>
                                    </div>
                                    
                                    <div class="msg-body" id="sent-body-<?= (int)$msg['id'] ?>" style="display:none; grid-column:1/-1; padding:1.2rem 0 0.5rem 0; border-top:1px dashed rgba(255,255,255,0.05); margin-top:0.8rem; font-family:'Montserrat',sans-serif; font-size:0.88rem; color:#e0e0e0; line-height:1.6; white-space:pre-wrap; text-transform:none; letter-spacing:0.02em;">
                                        <?= h($msg['message']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── TAB: COMMUNITY (MEMBERS) ── -->
        <div id="tab-members" class="portal-tab <?= $tab === 'members' ? 'active' : '' ?>">
            <div class="portal-card" style="background: rgba(5, 5, 8, 0.8); border: 1px solid var(--border-color); border-radius: 6px; padding: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);">
                <h3 style="font-family:'Syncopate',sans-serif; font-size:1.1rem; letter-spacing:2px; margin:0 0 0.4rem 0; color:#fff;">COMMUNITY DIRECTORY</h3>
                <p style="color:var(--text-muted); font-size:0.82rem; margin:0 0 2rem 0; font-family:'Montserrat',sans-serif; font-weight:500;">Search and connect with other approved producers and artists in the N2L8 Studio community.</p>

                <!-- Search Input Bar -->
                <form method="GET" action="/portal/index.php" style="display: flex; gap: 0.8rem; margin-bottom: 2rem; max-width: 500px;" onsubmit="handleSearch(event)">
                    <input type="hidden" name="tab" value="members">
                    <input type="text" name="q" id="search-input" value="<?= h($search_q) ?>" placeholder="Search community members..." style="flex:1; padding:0.9rem 1.2rem; background:rgba(0,0,0,0.6); border:1px solid var(--border-color); border-radius:4px; color:#fff; font-family:'Montserrat',sans-serif; outline:none; transition:border-color 0.3s;">
                    <button type="submit" class="cta-btn" style="width:auto; margin:0; padding:0.9rem 1.8rem;">SEARCH</button>
                    <?php if ($search_q !== ''): ?>
                        <a href="/portal/index.php?tab=members" class="cta-btn secondary" style="width:auto; margin:0; padding:0.9rem 1.4rem; display:flex; align-items:center; text-decoration:none;">CLEAR</a>
                    <?php endif; ?>
                </form>

                <?php if (empty($members)): ?>
                    <div style="text-align:center; padding:5rem 2rem; color:var(--text-muted); border: 1px dashed rgba(255,255,255,0.05); border-radius:6px;">
                        <div style="font-size:3rem; margin-bottom:1rem; opacity:0.4;">👥</div>
                        <h4 style="font-family:'Syncopate',sans-serif; color:#fff; font-size:0.95rem; margin-bottom:0.5rem; letter-spacing:1px;">No members found</h4>
                        <p style="font-size:0.8rem; margin:0; font-family:'Montserrat',sans-serif;">Try another query or check back later when more users register.</p>
                    </div>
                <?php else: ?>
                    <div class="community-grid">
                        <?php foreach ($members as $m): ?>
                            <div class="community-card">
                                <?php if ($m['profile_picture']): ?>
                                    <img src="/static/uploads/<?= h($m['profile_picture']) ?>" class="community-avatar-large" alt="">
                                <?php else: ?>
                                    <div class="community-initial-large">
                                        <?= strtoupper(substr($m['username'], 0, 1)) ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="community-username"><?= h($m['username']) ?></div>
                                <div class="community-role"><?= h($m['role']) ?></div>
                                
                                <button class="cta-btn" onclick="openDirectComposeModal(<?= (int)$m['id'] ?>, '<?= h(addslashes($m['username'])) ?>')" style="padding:0.6rem 1.2rem; font-size:0.7rem; width:100%; border-radius:4px;">SEND MESSAGE</button>
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

    <!-- Compose Message Modal -->
    <div id="composeModal" style="display:none; position:fixed; z-index:9000; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.85); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); align-items:center; justify-content:center;">
        <div class="portal-card modal-content" style="max-width:550px; width:90%; margin:auto; background:rgba(5,5,8,0.98); border:1px solid var(--border-color); box-shadow:0 20px 50px rgba(0,0,0,0.8), var(--accent-glow); padding:2.5rem; position:relative; border-radius:8px;">
            <span class="close-modal" onclick="closeComposeModal()" style="position:absolute; top:1.2rem; right:1.5rem; color:var(--text-muted); font-size:1.5rem; cursor:pointer; font-weight:bold; transition:color 0.2s;">&times;</span>
            <h3 id="modalTitle" style="font-family:'Syncopate',sans-serif; font-size:1.1rem; letter-spacing:2px; margin:0 0 1.5rem 0; color:#fff; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:0.8rem;">COMPOSE TRANSMISSION</h3>
            
            <form id="composeForm" action="/portal/send_message.php" method="POST" onsubmit="submitComposeForm(event)">
                <input type="hidden" name="recipient_id" id="modalRecipientId" value="">
                
                <div class="form-group" id="recipientSelectGroup">
                    <label>RECIPIENT</label>
                    <select name="recipient_id_select" id="modalRecipientSelect" onchange="document.getElementById('modalRecipientId').value = this.value" required>
                        <option value="" disabled selected>Select a community member...</option>
                        <?php
                        // Fetch all possible recipients for the dropdown list (all approved users except self and admin)
                        $dropdown_stmt = $pdo->prepare('SELECT id, username FROM users WHERE is_approved = 1 AND role != "admin" AND id != ? ORDER BY username ASC');
                        $dropdown_stmt->execute([$user_id]);
                        $dropdown_users = $dropdown_stmt->fetchAll();
                        foreach ($dropdown_users as $du) {
                            echo '<option value="' . (int)$du['id'] . '">' . h($du['username']) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                
                <div class="form-group" id="recipientNameGroup" style="display:none;">
                    <label>RECIPIENT</label>
                    <input type="text" id="modalRecipientName" readonly style="width:100%; padding:0.9rem 1.2rem; background:rgba(255,255,255,0.02); border:1px solid var(--border-color); border-radius:4px; color:#fff; font-family:'Montserrat',sans-serif; outline:none; opacity:0.8;">
                </div>

                <div class="form-group">
                    <label>SUBJECT</label>
                    <input type="text" name="subject" id="modalSubject" required placeholder="Subject of transmission..." style="width:100%; padding:0.9rem 1.2rem; background:rgba(0,0,0,0.6); border:1px solid var(--border-color); border-radius:4px; color:#fff; font-family:'Montserrat',sans-serif; outline:none; transition:border-color 0.3s;">
                </div>

                <div class="form-group">
                    <label>MESSAGE</label>
                    <textarea name="message" id="modalMessage" required rows="6" placeholder="Construct your transmission..." style="width:100%; padding:0.9rem 1.2rem; background:rgba(0,0,0,0.6); border:1px solid var(--border-color); border-radius:4px; color:#fff; font-family:'Montserrat',sans-serif; outline:none; resize:vertical; transition:border-color 0.3s; line-height:1.6;"></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:1rem; margin-top:1.5rem;">
                    <button type="button" class="cta-btn secondary" onclick="closeComposeModal()" style="width:auto; padding:0.8rem 1.8rem; margin:0;">CANCEL</button>
                    <button type="submit" class="cta-btn" style="width:auto; padding:0.8rem 2.2rem; margin:0;">SEND MESSAGE</button>
                </div>
            </form>
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

        function switchInboxSubtab(sub) {
            document.querySelectorAll('.inbox-subtab-content').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.inbox-subtab-btn').forEach(el => el.classList.remove('active'));
            
            const btn = document.getElementById('subtab-' + sub + '-btn');
            if (btn) btn.classList.add('active');
            
            const container = document.getElementById('inbox-' + sub + '-container');
            if (container) container.style.display = 'block';
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
                row.style.background = isExpanding ? 'rgba(255,255,255,0.02)' : '';
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
                                } else if (inboxBtn.innerText.includes('Inbox')) {
                                    // if it's formatted without parentheses or we need to update the text
                                    const countMatch = inboxBtn.innerText.match(/Inbox\s*(\(\d+\))?/i);
                                    // we can just reload the unread count text
                                }
                            }
                        }
                    })
                    .catch(err => console.error("Error marking read:", err));
            }
        }

        function toggleSentMessage(id) {
            const body = document.getElementById('sent-body-' + id);
            const snippet = document.getElementById('sent-snippet-' + id);
            const row = document.getElementById('sent-msg-row-' + id);
            
            if (!body) return;
            
            const isExpanding = body.style.display === 'none';
            body.style.display = isExpanding ? 'block' : 'none';
            if (snippet) snippet.style.display = isExpanding ? 'none' : 'block';
            if (row) {
                row.style.background = isExpanding ? 'rgba(255,255,255,0.02)' : '';
            }
        }

        // Modals management
        function openFreshComposeModal() {
            // Reset fields
            document.getElementById('modalRecipientId').value = '';
            document.getElementById('modalRecipientSelect').value = '';
            document.getElementById('modalSubject').value = '';
            document.getElementById('modalMessage').value = '';
            
            // Show select element, hide text label
            document.getElementById('recipientSelectGroup').style.display = 'block';
            document.getElementById('recipientNameGroup').style.display = 'none';
            document.getElementById('modalTitle').innerText = 'COMPOSE TRANSMISSION';
            
            document.getElementById('composeModal').style.display = 'flex';
        }

        function openDirectComposeModal(recipientId, recipientName) {
            document.getElementById('modalRecipientId').value = recipientId;
            document.getElementById('modalRecipientName').value = recipientName;
            document.getElementById('modalSubject').value = '';
            document.getElementById('modalMessage').value = '';
            
            // Hide select element, show text label
            document.getElementById('recipientSelectGroup').style.display = 'none';
            document.getElementById('recipientNameGroup').style.display = 'block';
            document.getElementById('modalTitle').innerText = 'MESSAGE TO ' + recipientName.toUpperCase();
            
            document.getElementById('composeModal').style.display = 'flex';
        }

        function openReplyModal(senderId, senderName, originalSubject, originalMessage, originalDate) {
            document.getElementById('modalRecipientId').value = senderId;
            document.getElementById('modalRecipientName').value = senderName;
            
            // Clean/prefix original subject
            let newSubject = originalSubject;
            if (!newSubject.toLowerCase().startsWith('re:')) {
                newSubject = 'Re: ' + newSubject;
            }
            document.getElementById('modalSubject').value = newSubject;
            
            // Clean original message body and insert quote
            let cleanMsg = originalMessage.replace(/\\n/g, '\n');
            let quoted = "\n\n\n───────────────────────────────────────\n";
            quoted += "↩ TRANSMISSION RECEIVED FROM @" + senderName.toUpperCase() + " ON " + originalDate + ":\n";
            quoted += "> " + cleanMsg.split('\n').join('\n> ');
            
            document.getElementById('modalMessage').value = quoted;
            
            // Hide select, show label
            document.getElementById('recipientSelectGroup').style.display = 'none';
            document.getElementById('recipientNameGroup').style.display = 'block';
            document.getElementById('modalTitle').innerText = 'REPLY TO ' + senderName.toUpperCase();
            
            document.getElementById('composeModal').style.display = 'flex';
            
            // Put cursor at the very top of message textarea
            const textarea = document.getElementById('modalMessage');
            textarea.focus();
            textarea.setSelectionRange(0, 0);
        }

        function closeComposeModal() {
            document.getElementById('composeModal').style.display = 'none';
        }

        function submitComposeForm(e) {
            e.preventDefault();
            const form = document.getElementById('composeForm');
            const formData = new FormData(form);
            
            const btnSubmit = form.querySelector('button[type="submit"]');
            const originalText = btnSubmit.innerText;
            btnSubmit.innerText = 'SENDING...';
            btnSubmit.disabled = true;
            
            fetch('/portal/send_message.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                btnSubmit.innerText = originalText;
                btnSubmit.disabled = false;
                
                if (data.success) {
                    closeComposeModal();
                    alert("Transmission successfully routed!");
                    window.location.href = '/portal/index.php?tab=inbox&subtab=sent';
                } else {
                    alert("Error: " + data.error);
                }
            })
            .catch(err => {
                btnSubmit.innerText = originalText;
                btnSubmit.disabled = false;
                console.error("AJAX Error sending message:", err);
                alert("Failed to send transmission. Please check connection.");
            });
        }

        function handleSearch(e) {
            // Let normal form search reload the page with queries
            return true;
        }

        // Handle dynamically switching inbox subtabs if query parameters dictate it
        window.addEventListener('load', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const sub = urlParams.get('subtab');
            if (sub === 'sent') {
                switchInboxSubtab('sent');
            }
        });
    </script>
</body>
</html>
