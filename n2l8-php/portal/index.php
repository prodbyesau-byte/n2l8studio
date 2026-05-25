<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_client_login();

// Prevent browser caching of the portal page to ensure scripts, counts and dynamic templates are always fresh
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

$pdo = get_pdo();
$site = get_site_content($pdo);
log_visitor($pdo, 'page_view', '/portal/index.php');

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$user_email = $_SESSION['email'] ?? '';

// 1. Fetch user profile info (avatar picture and privacy preference)
$user_stmt = $pdo->prepare('SELECT profile_picture, is_private FROM users WHERE id = ?');
$user_stmt->execute([$user_id]);
$user_info = $user_stmt->fetch();
$profile_pic = $user_info['profile_picture'] ?? '';
$is_private = (int)($user_info['is_private'] ?? 0);

// 2. Fetch inbox messages and unread count
$msg_stmt = $pdo->prepare('SELECT * FROM messages WHERE recipient_id = ? ORDER BY id DESC');
$msg_stmt->execute([$user_id]);
$messages = $msg_stmt->fetchAll();
$unread_count = count(array_filter($messages, fn($m) => !$m['is_read']));

// 2b. Fetch pending friend requests count
$friend_req_stmt = $pdo->prepare('
    SELECT COUNT(*) 
    FROM friendships 
    WHERE status = "pending" 
      AND (user_id1 = ? OR user_id2 = ?) 
      AND action_user_id != ?
');
$friend_req_stmt->execute([$user_id, $user_id, $user_id]);
$pending_friends_count = (int)$friend_req_stmt->fetchColumn();

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

// 4a. Fetch user's custom playlists and their products
$playlists_stmt = $pdo->prepare('SELECT * FROM playlists WHERE user_id = ? ORDER BY created_at DESC');
$playlists_stmt->execute([$user_id]);
$user_playlists = $playlists_stmt->fetchAll();

$playlist_items_stmt = $pdo->prepare('
    SELECT pi.playlist_id, p.* 
    FROM playlist_items pi
    JOIN products p ON pi.product_id = p.id
    JOIN playlists pl ON pi.playlist_id = pl.id
    WHERE pl.user_id = ?
    ORDER BY pi.created_at DESC
');
$playlist_items_stmt->execute([$user_id]);
$all_playlist_items = $playlist_items_stmt->fetchAll();

$playlists_with_items = [];
foreach ($user_playlists as $pl) {
    $playlists_with_items[$pl['id']] = [
        'id' => $pl['id'],
        'name' => $pl['name'],
        'created_at' => $pl['created_at'],
        'items' => []
    ];
}
foreach ($all_playlist_items as $item) {
    if (isset($playlists_with_items[$item['playlist_id']])) {
        $playlists_with_items[$item['playlist_id']]['items'][] = $item;
    }
}

// 4b. Fetch user's upvoted (liked) products
$upvotes_stmt = $pdo->prepare('
    SELECT p.* 
    FROM product_upvotes pu
    JOIN products p ON pu.product_id = p.id
    WHERE pu.user_id = ?
    ORDER BY pu.id DESC
');
$upvotes_stmt->execute([$user_id]);
$liked_products = $upvotes_stmt->fetchAll();


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
    
    // 5c. Update Profile Visibility/Privacy settings
    if (isset($_POST['update_privacy'])) {
        $privacy_val = $_POST['profile_privacy'] ?? 'public';
        $new_is_private = ($privacy_val === 'private') ? 1 : 0;
        
        $upd_stmt = $pdo->prepare('UPDATE users SET is_private = ? WHERE id = ?');
        if ($upd_stmt->execute([$new_is_private, $user_id])) {
            $is_private = $new_is_private; // update display state
            $success_msg = 'Profile visibility preference saved successfully.';
            log_action($pdo, "User {$username} set their profile to " . ($new_is_private ? 'private' : 'public') . ".");
        } else {
            $error_msg = 'Failed to save visibility preference.';
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
    <title>Client Profile - N2L8 STUDIO</title>
    <link rel="stylesheet" href="/static/style.css?v=20">
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

        /* ── PREMIUM FOLDER STYLES ── */
        .premium-folder {
            position: relative;
            width: 80px;
            height: 60px;
            margin: 1rem auto;
            transition: all 0.3s ease;
        }
        .folder-tab {
            position: absolute;
            top: -8px;
            left: 5px;
            width: 32px;
            height: 10px;
            background: var(--accent);
            border: 1px solid var(--border-color);
            border-radius: 3px 3px 0 0;
            z-index: 1;
            transition: all 0.3s ease;
        }
        .folder-body {
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.02), rgba(255,255,255,0.08));
            border: 1px solid var(--border-color);
            border-radius: 0 5px 5px 5px;
            box-shadow: inset 0 1px 3px rgba(255,255,255,0.05), var(--accent-glow);
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        .premium-folder:hover .folder-body {
            border-color: rgba(255, 255, 255, 0.4);
            transform: scale(1.02);
            box-shadow: inset 0 1px 3px rgba(255,255,255,0.1), var(--accent-glow), 0 0 12px rgba(192, 21, 42, 0.2);
        }
        .premium-folder:hover .folder-tab {
            background: var(--accent-hover);
            transform: translateY(-1px);
        }

        /* ── MODAL OVERLAY ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(5,5,8,0.92);
            z-index: 1000;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 1rem 0;
            backdrop-filter: blur(10px);
        }
        .modal-overlay.open { display: block; }
        .modal-box {
            background: rgba(5, 5, 8, 0.95);
            border: 1px solid var(--border-color);
            box-shadow: var(--purple-glow);
            width: 92%;
            max-width: 450px;
            margin: 5rem auto;
            position: relative;
            padding: 2.5rem 2rem;
            border-radius: 6px;
            text-align: center;
        }
        .modal-close {
            position: absolute;
            top: 0.8rem; right: 0.8rem;
            background: rgba(0,0,0,0.6);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
            line-height: 1;
            transition: all 0.2s;
            font-family: 'Montserrat', sans-serif;
            font-weight: 300;
            width: 38px; height: 38px;
            display: flex; align-items: center; justify-content: center;
            z-index: 10;
            border-radius: 50%;
        }
        .modal-close:hover { color:#ff5c5c; border-color: #ff5c5c; }

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

        /* ── DUAL-PANE DIRECT MESSAGING CLIENT ── */
        .dm-client-grid {
            display: grid;
            grid-template-columns: 240px 320px 1fr;
            background: rgba(5, 5, 8, 0.85);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.6), var(--accent-glow);
            min-height: 620px;
            max-height: 720px;
            overflow: hidden;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        .dm-sidebar {
            background: rgba(0, 0, 0, 0.4);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
            padding: 1.5rem 1.2rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            text-align: left;
        }
        .dm-compose-btn {
            background: var(--accent);
            border: none;
            color: #ffffff;
            font-family: 'Syncopate', sans-serif;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            padding: 0.85rem;
            border-radius: 4px;
            cursor: pointer;
            box-shadow: var(--accent-glow);
            transition: all 0.3s ease;
            width: 100%;
        }
        .dm-compose-btn:hover {
            background: var(--accent-hover);
            transform: translateY(-2px);
            box-shadow: 0 0 15px rgba(192, 21, 42, 0.6);
        }
        .dm-folder-list {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }
        .dm-folder-item {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-family: 'Montserrat', sans-serif;
            font-size: 0.82rem;
            font-weight: 600;
            padding: 0.75rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.2s ease;
            width: 100%;
            text-align: left;
        }
        .dm-folder-item:hover, .dm-folder-item.active {
            background: rgba(192, 21, 42, 0.08);
            color: #ffffff;
        }
        .dm-folder-item.active {
            border-left: 3px solid var(--accent);
            padding-left: calc(1rem - 3px);
        }
        .dm-folder-item .icon {
            margin-right: 0.6rem;
            font-size: 0.95rem;
        }
        .dm-folder-item .badge {
            background: var(--accent);
            color: #ffffff;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 10px;
            box-shadow: var(--accent-glow);
            display: none;
        }
        .dm-folder-item .badge.visible {
            display: inline-block;
        }
        .dm-friends-section {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
            min-height: 0;
        }
        .dm-member-search-container {
            position: relative;
            margin-bottom: 1.2rem;
            padding: 0 0.5rem;
        }
        .dm-member-search-container input {
            width: 100%;
            background: #000000;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 0.55rem 0.75rem;
            color: #ffffff;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.78rem;
            outline: none;
            transition: all 0.25s ease;
        }
        .dm-member-search-container input:focus {
            border-color: var(--accent);
            box-shadow: var(--accent-glow);
        }
        .dm-member-search-results {
            display: none;
            position: absolute;
            top: 100%;
            left: 0.5rem;
            right: 0.5rem;
            background: #0a0a0a;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            z-index: 100;
            max-height: 200px;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.85);
            margin-top: 4px;
        }
        .dm-search-result-item {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.6rem 0.8rem;
            cursor: pointer;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            transition: all 0.2s ease;
        }
        .dm-search-result-item:hover {
            background: rgba(192, 21, 42, 0.12);
        }
        .dm-search-result-item:last-child {
            border-bottom: none;
        }
        .dm-search-result-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .dm-search-result-avatar-placeholder {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--accent);
            color: #ffffff;
            font-size: 0.65rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            text-transform: uppercase;
        }
        .dm-search-result-name {
            font-size: 0.78rem;
            color: #ffffff;
            font-weight: 500;
        }
        .dm-section-title {
            font-family: 'Syncopate', sans-serif;
            font-size: 0.65rem;
            color: var(--text-muted);
            letter-spacing: 0.1em;
            margin-bottom: 0.8rem;
            padding-left: 0.5rem;
        }
        .dm-friends-list {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            overflow-y: auto;
            max-height: 240px;
            padding-right: 0.2rem;
        }
        .dm-friend-item {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.4rem 0.5rem;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .dm-friend-item:hover {
            background: rgba(255, 255, 255, 0.03);
        }
        .dm-friend-avatar {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .dm-friend-avatar-placeholder {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Syncopate', sans-serif;
            font-size: 0.6rem;
            font-weight: 700;
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .dm-friend-name {
            font-size: 0.78rem;
            color: var(--text-muted);
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 140px;
        }
        .dm-friend-item:hover .dm-friend-name {
            color: #ffffff;
        }

        .dm-conversations-col {
            background: rgba(0, 0, 0, 0.15);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .dm-search-bar {
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        .dm-search-bar input {
            width: 100%;
            background: #000000;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 0.65rem 0.8rem;
            color: #ffffff;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.82rem;
            outline: none;
            transition: all 0.25s ease;
        }
        .dm-search-bar input:focus {
            border-color: var(--accent);
            box-shadow: var(--accent-glow);
        }
        .dm-conversations-list {
            overflow-y: auto;
            flex-grow: 1;
        }
        .dm-convo-item {
            display: grid;
            grid-template-columns: 40px 1fr;
            gap: 0.8rem;
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: left;
        }
        .dm-convo-item:hover {
            background: rgba(255, 255, 255, 0.02);
        }
        .dm-convo-item.active {
            background: rgba(192, 21, 42, 0.05);
            border-left: 3px solid var(--accent);
            padding-left: calc(1rem - 3px);
        }
        .dm-convo-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .dm-convo-avatar-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Syncopate', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .dm-convo-info {
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .dm-convo-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.15rem;
        }
        .dm-convo-name {
            font-size: 0.85rem;
            font-weight: 700;
            color: #ffffff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .dm-convo-time {
            font-size: 0.68rem;
            color: var(--text-muted);
            font-weight: 500;
        }
        .dm-convo-body {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.5rem;
        }
        .dm-convo-lastmsg {
            font-size: 0.78rem;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex-grow: 1;
        }
        .dm-convo-badge {
            background: var(--accent);
            color: #ffffff;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 10px;
            box-shadow: var(--accent-glow);
        }
        .dm-convo-item.unread .dm-convo-name {
            color: var(--accent);
        }
        .dm-convo-item.unread .dm-convo-lastmsg {
            color: #ffffff;
            font-weight: 600;
        }

        .dm-thread-pane {
            background: rgba(0, 0, 0, 0.2);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            height: 100%;
        }
        .dm-thread-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-grow: 1;
            padding: 3rem;
            color: var(--text-muted);
            text-align: center;
        }
        .dm-thread-empty h3 {
            font-family: 'Syncopate', sans-serif;
            color: #ffffff;
            font-size: 0.95rem;
            margin-bottom: 0.4rem;
            letter-spacing: 1px;
        }
        .dm-thread-empty p {
            font-size: 0.8rem;
            max-width: 320px;
            margin: 0;
        }
        .dm-thread-header {
            background: rgba(0, 0, 0, 0.35);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .dm-thread-partner {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            cursor: pointer;
        }
        .dm-thread-partner:hover .dm-thread-partner-name {
            color: var(--accent);
            text-shadow: var(--accent-glow);
        }
        .dm-thread-partner-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid var(--accent);
            box-shadow: var(--accent-glow);
        }
        .dm-thread-partner-avatar-placeholder {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Syncopate', sans-serif;
            font-size: 0.9rem;
            font-weight: 700;
            color: #fff;
            border: 1px solid var(--accent);
            box-shadow: var(--accent-glow);
        }
        .dm-thread-partner-name {
            font-family: 'Syncopate', sans-serif;
            font-size: 0.85rem;
            color: #ffffff;
            font-weight: 700;
            letter-spacing: 0.05em;
            transition: color 0.2s ease;
            text-transform: uppercase;
        }
        .dm-thread-actions {
            display: flex;
            gap: 0.6rem;
        }
        .dm-thread-btn {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-muted);
            font-family: 'Montserrat', sans-serif;
            font-size: 0.72rem;
            font-weight: 600;
            padding: 0.4rem 0.8rem;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .dm-thread-btn:hover {
            border-color: var(--accent);
            color: #ffffff;
        }
        .dm-thread-btn.starred {
            border-color: #ffb300;
            color: #ffb300;
            background: rgba(255, 179, 0, 0.05);
        }
        .dm-messages-container {
            flex-grow: 1;
            overflow-y: auto;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .dm-msg-bubble {
            max-width: 70%;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            font-size: 0.85rem;
            line-height: 1.5;
            position: relative;
            text-align: left;
            word-wrap: break-word;
        }
        .dm-msg-bubble.received {
            background: rgba(255, 255, 255, 0.05);
            color: #e0e0e0;
            align-self: flex-start;
            border-left: 3px solid rgba(255, 255, 255, 0.2);
        }
        .dm-msg-bubble.sent {
            background: rgba(192, 21, 42, 0.12);
            color: #ffffff;
            align-self: flex-end;
            border-right: 3px solid var(--accent);
            box-shadow: 0 4px 15px rgba(192, 21, 42, 0.05);
        }
        .dm-msg-info {
            font-size: 0.62rem;
            color: var(--text-muted);
            margin-top: 0.35rem;
            text-align: right;
        }
        .dm-msg-bubble.sent .dm-msg-info {
            color: rgba(255, 255, 255, 0.4);
        }
        .dm-input-area {
            background: rgba(0, 0, 0, 0.3);
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            padding: 1rem;
            display: flex;
            gap: 0.8rem;
            align-items: center;
        }
        .dm-input-area textarea {
            flex-grow: 1;
            background: #000000;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            color: #ffffff;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.85rem;
            padding: 0.7rem 0.9rem;
            resize: none;
            height: 40px;
            outline: none;
            transition: all 0.25s ease;
        }
        .dm-input-area textarea:focus {
            border-color: var(--accent);
            box-shadow: var(--accent-glow);
        }
        .dm-input-area button {
            background: var(--accent);
            border: none;
            color: #ffffff;
            font-family: 'Syncopate', sans-serif;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            padding: 0.7rem 1.3rem;
            border-radius: 4px;
            cursor: pointer;
            box-shadow: var(--accent-glow);
            transition: all 0.3s ease;
        }
        .dm-input-area button:hover {
            background: var(--accent-hover);
            box-shadow: 0 0 12px rgba(192, 21, 42, 0.6);
        }

        /* ── GLOBAL SOCIAL MODALS ── */
        .profile-modal, .compose-modal {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }
        .profile-modal.open, .compose-modal.open {
            opacity: 1;
            pointer-events: auto;
        }
        .profile-modal-card, .compose-modal-card {
            background: #050508;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 2.5rem;
            max-width: 460px;
            width: 90%;
            box-shadow: 0 0 35px rgba(192, 21, 42, 0.2), var(--accent-glow);
            text-align: center;
            position: relative;
            transform: scale(0.92);
            transition: transform 0.3s ease;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        .profile-modal.open .profile-modal-card, .compose-modal.open .compose-modal-card {
            transform: scale(1);
        }
        .profile-modal-close, .compose-modal-close {
            position: absolute;
            top: 0.8rem;
            right: 1.1rem;
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 1.6rem;
            cursor: pointer;
            transition: color 0.2s;
        }
        .profile-modal-close:hover, .compose-modal-close:hover {
            color: #ffffff;
        }
        .profile-modal-avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent);
            box-shadow: var(--accent-glow);
            margin: 0 auto 1.2rem auto;
        }
        .profile-modal-avatar-placeholder {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Syncopate', sans-serif;
            font-size: 2.2rem;
            font-weight: 700;
            color: #ffffff;
            margin: 0 auto 1.2rem auto;
        }
        .profile-modal-username {
            font-family: 'Syncopate', sans-serif;
            font-size: 1.2rem;
            color: #ffffff;
            margin-bottom: 0.3rem;
            letter-spacing: 0.05em;
        }
        .profile-modal-role {
            display: inline-block;
            font-size: 0.62rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--accent);
            background: rgba(192, 21, 42, 0.08);
            border: 1px solid var(--border-color);
            padding: 3px 8px;
            border-radius: 2px;
            margin-bottom: 1.5rem;
        }
        .profile-modal-stats {
            display: flex;
            justify-content: space-around;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding: 1rem 0;
            margin-bottom: 1.5rem;
        }
        .profile-modal-stat-val {
            font-family: 'Syncopate', sans-serif;
            font-size: 1.1rem;
            color: #ffffff;
            font-weight: 700;
        }
        .profile-modal-stat-lbl {
            font-size: 0.65rem;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 0.2rem;
            letter-spacing: 0.05em;
        }
        .profile-modal-btn {
            width: 100%;
            font-family: 'Syncopate', sans-serif;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            padding: 0.8rem;
            border-radius: 4px;
            cursor: pointer;
            text-transform: uppercase;
            transition: all 0.3s ease;
        }
        .profile-modal-btn.primary {
            background: var(--accent);
            border: none;
            color: #ffffff;
            box-shadow: var(--accent-glow);
        }
        .profile-modal-btn.primary:hover {
            background: var(--accent-hover);
            box-shadow: 0 0 12px var(--accent);
        }
        .profile-modal-btn.secondary {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
        }
        .profile-modal-btn.secondary:hover {
            border-color: var(--accent);
            color: #ffffff;
        }
        .profile-modal-btn.disabled {
            background: rgba(255, 255, 255, 0.03) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            color: rgba(255, 255, 255, 0.25) !important;
            cursor: not-allowed !important;
            box-shadow: none !important;
        }
        .profile-modal-dm-field {
            text-align: left;
            margin-top: 1.5rem;
            border-top: 1px dashed rgba(255, 255, 255, 0.05);
            padding-top: 1.2rem;
            display: none;
        }
        .profile-modal-dm-field.visible {
            display: block;
        }
        .profile-modal-dm-field label {
            display: block;
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 0.5rem;
            letter-spacing: 0.05em;
        }
        .profile-modal-dm-field textarea {
            width: 100%;
            background: #000000;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 0.7rem 0.8rem;
            color: #ffffff;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.85rem;
            resize: none;
            height: 60px;
            outline: none;
            transition: border-color 0.25s;
            margin-bottom: 0.8rem;
        }
        .profile-modal-dm-field textarea:focus {
            border-color: var(--accent);
        }
        
        /* Compose DM Modal styling */
        .compose-modal-card {
            text-align: left;
        }
        .compose-modal-card h3 {
            font-family: 'Syncopate', sans-serif;
            font-size: 1.1rem;
            color: #ffffff;
            margin-bottom: 1.5rem;
            letter-spacing: 1px;
            text-align: center;
        }
        .compose-select-friend {
            width: 100%;
            background: #000000;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            color: #ffffff;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.88rem;
            padding: 0.75rem 0.9rem;
            outline: none;
            margin-bottom: 1.2rem;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg fill='white' height='24' viewBox='0 0 24 24' width='24' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5z'/><path d='M0 0h24v24H0z' fill='none'/></svg>");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }
        .compose-select-friend:focus {
            border-color: var(--accent);
            box-shadow: var(--accent-glow);
        }

        /* ── MOBILE OPTIMIZATION OVERLAYS ── */
        .dm-mobile-folders-btn {
            display: none;
        }
        .dm-sidebar-mobile-header {
            display: none;
        }
        .dm-mobile-back-btn {
            display: none;
        }

        /* Friends Tab Redesign & Optimization Styles */
        .friends-tab-grid {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 2rem;
        }
        @media(max-width: 768px) {
            .friends-tab-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
        }
        .friends-grid-layout {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
            gap: 1.2rem;
        }
        .friends-search-item:hover {
            background: rgba(168, 85, 247, 0.08) !important;
        }
        .social-stats-hub {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.8rem;
            margin-bottom: 2rem;
            width: 100%;
        }
        .stat-hub-card {
            background: rgba(255, 255, 255, 0.01);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 1rem 0.6rem;
            text-align: center;
            transition: all 0.25s ease;
        }
        .stat-hub-card:hover {
            background: rgba(255, 255, 255, 0.03);
            border-color: rgba(168, 85, 247, 0.3);
            transform: translateY(-2px);
        }
        .stat-hub-num {
            font-family: 'Syncopate', sans-serif;
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--accent);
            margin-bottom: 0.25rem;
            line-height: 1.1;
        }
        .stat-hub-label {
            font-size: 0.65rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        .social-profile-card {
            background: linear-gradient(180deg, rgba(168,85,247,0.03) 0%, rgba(5,5,8,0.85) 100%);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1.8rem 1.2rem 1.5rem 1.2rem;
            text-align: center;
            margin-bottom: 1.5rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
        }
        .social-profile-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent);
            box-shadow: var(--accent-glow);
            margin: 0 auto 1.2rem auto;
            display: block;
        }
        .social-profile-avatar-placeholder {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: rgba(255,255,255,0.03);
            border: 2px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Syncopate', sans-serif;
            font-size: 1.6rem;
            font-weight: 700;
            color: #fff;
            margin: 0 auto 1.2rem auto;
        }
        .social-profile-name {
            font-family: 'Syncopate', sans-serif;
            font-weight: 700;
            font-size: 0.9rem;
            color: #fff;
            margin: 0 0 0.25rem 0;
            word-break: break-all;
            line-height: 1.2;
        }
        .social-profile-role {
            font-size: 0.68rem;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 700;
            margin-bottom: 1.2rem;
            display: block;
        }
        .friend-list-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            background: linear-gradient(180deg, rgba(255,255,255,0.01) 0%, rgba(5,5,8,0.75) 100%);
            border: 1px solid var(--border-color);
            padding: 1.6rem 1rem 1rem 1rem;
            border-radius: 8px;
            position: relative;
            transition: transform 0.3s cubic-bezier(0.25, 0.8, 0.25, 1), border-color 0.3s ease, box-shadow 0.3s ease;
            backdrop-filter: blur(6px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            min-height: 260px;
            justify-content: space-between;
            will-change: transform, box-shadow;
        }
        .friend-list-card:hover {
            border-color: rgba(168, 85, 247, 0.45);
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(168, 85, 247, 0.12), var(--accent-glow);
        }
        .friend-tab-avatar-wrap {
            position: relative;
            margin-bottom: 0.8rem;
            cursor: pointer;
            width: 68px;
            height: 68px;
            flex-shrink: 0;
        }
        .friend-tab-avatar {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.08);
            transition: all 0.3s ease;
            display: block;
        }
        .friend-list-card:hover .friend-tab-avatar {
            border-color: var(--accent);
            box-shadow: var(--accent-glow);
            transform: scale(1.05);
        }
        .friend-tab-avatar-placeholder {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: rgba(255,255,255,0.04);
            border: 2px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Syncopate', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: #fff;
            transition: all 0.3s ease;
        }
        .friend-list-card:hover .friend-tab-avatar-placeholder {
            border-color: var(--accent);
            box-shadow: var(--accent-glow);
            transform: scale(1.05);
        }
        .friend-status-dot {
            width: 10px;
            height: 10px;
            background: #7be1a8;
            border: 2px solid #050508;
            border-radius: 50%;
            position: absolute;
            bottom: 2px;
            right: 4px;
            box-shadow: 0 0 8px #7be1a8;
        }
        .friend-card-username {
            font-weight: 700;
            font-size: 0.82rem;
            color: #fff;
            font-family: 'Syncopate', sans-serif;
            letter-spacing: 0.5px;
            cursor: pointer;
            word-break: break-all;
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 0.25rem;
            line-height: 1.2;
            height: 1.2rem;
        }
        .friend-card-role {
            font-size: 0.65rem;
            color: var(--text-muted);
            font-family: 'Montserrat', sans-serif;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 1rem;
            line-height: 1.2;
            height: 1.2rem;
            display: block;
        }
        .friend-card-actions {
            display: flex;
            gap: 0.4rem;
            width: 100%;
            margin-top: auto;
            flex-shrink: 0;
        }
        .friend-card-btn {
            flex: 1;
            font-size: 0.62rem !important;
            padding: 0.45rem 0.3rem !important;
            font-family: 'Syncopate', sans-serif;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-align: center;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .friend-card-btn.primary {
            background: var(--accent);
            color: #fff;
            border: 1px solid var(--accent);
        }
        .friend-card-btn.primary:hover {
            background: #b57cff;
            border-color: #b57cff;
            box-shadow: 0 4px 12px rgba(168, 85, 247, 0.3);
        }
        .friend-card-btn.secondary {
            background: rgba(255, 255, 255, 0.02);
            color: var(--text-muted);
            border: 1px solid var(--border-color);
        }
        .friend-card-btn.secondary:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
            border-color: rgba(255, 255, 255, 0.2);
        }
        .friend-card-unfriend-btn {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 1.1rem;
            cursor: pointer;
            line-height: 1;
            padding: 0.2rem;
            transition: all 0.2s;
            opacity: 0;
        }
        .friend-list-card:hover .friend-card-unfriend-btn {
            opacity: 0.6;
        }
        .friend-card-unfriend-btn:hover {
            opacity: 1 !important;
            color: #ff5c5c;
        }

        /* Toast Container & Notification styling (Global for both Desktop & Mobile) */
        #toast-container {
            position: fixed;
            top: 24px;
            right: 24px;
            z-index: 100000;
            display: flex;
            flex-direction: column;
            gap: 12px;
            pointer-events: none;
        }
        .custom-toast {
            pointer-events: auto;
            background: rgba(10, 10, 12, 0.96);
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--accent);
            color: #fff;
            padding: 14px 22px;
            border-radius: 6px;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.82rem;
            font-weight: 500;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            min-width: 280px;
            max-width: 380px;
            transform: translateX(130%);
            transition: transform 0.35s cubic-bezier(0.68, -0.55, 0.265, 1.35);
            backdrop-filter: blur(8px);
        }
        .custom-toast.show {
            transform: translateX(0);
        }
        .custom-toast.success {
            border-left-color: #7be1a8;
            box-shadow: 0 10px 25px rgba(123, 225, 168, 0.15), 0 15px 35px rgba(0, 0, 0, 0.7);
        }
        .custom-toast.error {
            border-left-color: #ff3860;
            box-shadow: 0 10px 25px rgba(255, 56, 96, 0.15), 0 15px 35px rgba(0, 0, 0, 0.7);
        }
        .custom-toast.info {
            border-left-color: var(--accent);
            box-shadow: 0 10px 25px rgba(0, 198, 255, 0.15), 0 15px 35px rgba(0, 0, 0, 0.7);
        }

        /* ── RESPONSIVE / MOBILE OPTIMIZATION ── */
        @media (max-width: 768px) {
            .portal-container {
                padding: 1rem 1rem 4rem 1rem;
            }
            .portal-header {
                flex-direction: column;
                gap: 1.2rem;
                align-items: stretch;
                text-align: center;
                margin-bottom: 1.5rem;
                padding-bottom: 1rem;
            }
            .portal-header > div:first-child {
                flex-direction: column !important;
                align-items: center !important;
                text-align: center;
                gap: 0.8rem !important;
            }
            .portal-header .cta-btn {
                align-self: center;
                width: 100%;
                max-width: 320px;
                text-align: center;
            }
            .portal-welcome h2 {
                font-size: 1.2rem;
            }
            .portal-tabs {
                flex-wrap: wrap;
                justify-content: center;
                gap: 0.5rem;
                margin-bottom: 1.5rem;
            }
            .portal-tab-btn {
                font-size: 0.72rem;
                padding: 0.6rem 0.4rem;
                letter-spacing: 0.05em;
            }
            .portal-card {
                padding: 1.8rem 1.2rem;
            }
            
            /* Library grid columns on mobile */
            .library-grid {
                grid-template-columns: 1fr !important;
                gap: 1rem;
            }

            /* Dual-pane direct messaging layout on mobile */
            .dm-client-grid {
                grid-template-columns: 1fr !important;
                min-height: 480px;
                max-height: calc(100vh - 180px);
                border-radius: 4px;
            }
            .dm-sidebar {
                display: none;
                padding: 1rem 0.8rem;
                gap: 1rem;
                overflow-y: auto !important;
                max-height: calc(100vh - 120px) !important;
                min-height: 0;
            }
            .dm-conversations-col {
                display: flex;
            }
            .dm-thread-pane {
                display: none;
            }
            
            /* Grid active states */
            .dm-client-grid.show-sidebar .dm-sidebar {
                display: flex !important;
                border-right: none;
            }
            .dm-client-grid.show-sidebar .dm-conversations-col,
            .dm-client-grid.show-sidebar .dm-thread-pane {
                display: none !important;
            }
            
            .dm-client-grid.show-thread .dm-thread-pane {
                display: flex !important;
            }
            .dm-client-grid.show-thread .dm-sidebar,
            .dm-client-grid.show-thread .dm-conversations-col {
                display: none !important;
            }
            
            /* Back button on mobile thread header */
            .dm-mobile-back-btn {
                display: inline-flex !important;
                align-items: center;
                justify-content: center;
                background: rgba(255, 255, 255, 0.05);
                border: 1px solid var(--border-color);
                color: #ffffff;
                font-size: 0.9rem;
                width: 32px;
                height: 32px;
                border-radius: 4px;
                cursor: pointer;
                margin-right: 0.6rem;
                transition: all 0.25s ease;
            }
            .dm-mobile-back-btn:hover {
                background: rgba(192, 21, 42, 0.2);
                border-color: var(--accent);
            }
            
            .dm-mobile-folders-btn {
                display: inline-block !important;
            }
            .dm-sidebar-mobile-header {
                display: flex !important;
            }
            
            /* iOS safari zoom prevention on mobile fields */
            .portal-card input,
            .dm-input-area textarea,
            .dm-member-search-container input,
            .dm-search-bar input,
            .compose-select-friend,
            .profile-modal-dm-field textarea,
            .form-group textarea {
                font-size: 16px !important;
            }
            
            /* Modal / dialog optimizations */
            .profile-modal-card, .compose-modal-card {
                padding: 1.5rem 1.2rem !important;
                width: 92% !important;
                max-width: 380px !important;
            }
            .dm-messages-container {
                padding: 0.8rem !important;
            }
            .dm-msg-bubble {
                max-width: 85% !important;
            }
            .friends-tab-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
            .friends-search-container {
                max-width: 100% !important;
            }
        }
    </style>
</head>
<body class="page-home <?= ($site['site_theme'] ?? 'dark') === 'beige' ? 'theme-beige' : '' ?>">
    <header class="hero" style="min-height: auto; padding-bottom: 0;">
        <nav>
            <a href="/index.php" class="logo-text" style="text-decoration:none;">N<span>2</span>L8studios</a>
            <button class="nav-hamburger" id="navHamburger" aria-label="Menu">
                <span></span><span></span><span></span>
            </button>
            <ul class="nav-links" id="navLinks">
                <li><a href="/index.php">Home</a></li>
                <li><a href="/shop.php">Shop</a></li>
                <li><a href="/pricing.php">Services</a></li>
                <li><a href="/forum.php">Forum</a></li>
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
                    <h2>CLIENT PROFILE</h2>
                    <p>Welcome back, <span><?= h($username) ?></span> &nbsp;|&nbsp; Credentials: <span><?= h($user_email) ?></span></p>
                </div>
            </div>
        </div>

        <div class="portal-tabs">
            <button class="portal-tab-btn <?= $tab === 'library' ? 'active' : '' ?>" onclick="switchTab('library')">My Library (<?= count($purchased_products) ?>)</button>
            <button class="portal-tab-btn <?= $tab === 'liked' ? 'active' : '' ?>" onclick="switchTab('liked')">Playlists &amp; Liked</button>
            <button class="portal-tab-btn <?= $tab === 'friends' ? 'active' : '' ?>" onclick="switchTab('friends')">
                Friends
                <span id="friends-tab-badge" style="background:var(--accent); color:#fff; font-size:0.62rem; padding:0.15rem 0.4rem; border-radius:10px; font-family:'Montserrat',sans-serif; font-weight:700; margin-left:4px; <?= $pending_friends_count > 0 ? '' : 'display:none;' ?>"><?= $pending_friends_count ?></span>
            </button>
            <button class="portal-tab-btn <?= $tab === 'inbox' ? 'active' : '' ?>" onclick="switchTab('inbox')">
                Inbox
                <span id="inbox-tab-badge" style="background:var(--accent); color:#fff; font-size:0.62rem; padding:0.15rem 0.4rem; border-radius:10px; font-family:'Montserrat',sans-serif; font-weight:700; margin-left:4px; <?= $unread_count > 0 ? '' : 'display:none;' ?>"><?= $unread_count ?></span>
            </button>
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

        <!-- ── TAB: LIKED & SAVED ── -->
        <div id="tab-liked" class="portal-tab <?= $tab === 'liked' ? 'active' : '' ?>">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem; flex-wrap:wrap; gap:1rem;">
                <div>
                    <h3 style="margin:0; font-family:'Syncopate', sans-serif; color:var(--accent); font-size:1.2rem;">PLAYLISTS &amp; LIKED KITS</h3>
                    <p style="margin:0.2rem 0 0 0; color:var(--text-muted); font-size:0.85rem;">Manage your custom collections and see your upvoted products.</p>
                </div>
                <button class="cta-btn" onclick="openCreatePlaylistModal()" style="font-size:0.75rem; padding:0.6rem 1.2rem;">CREATE NEW PLAYLIST</button>
            </div>

            <!-- Custom Playlists Grid -->
            <h4 style="font-family:'Syncopate', sans-serif; font-size:0.85rem; letter-spacing:0.05em; margin-bottom:1rem; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom:0.5rem;">MY CUSTOM PLAYLISTS</h4>
            
            <?php if (empty($playlists_with_items)): ?>
                <div class="empty-library" id="empty-playlists-notice" style="padding: 2rem; margin-bottom: 2rem;">
                    <h3>No Playlists Yet</h3>
                    <p>Create a playlist using the button above or by clicking ➕ on products in the Shop.</p>
                </div>
            <?php else: ?>
                <div class="library-grid" id="playlists-grid" style="margin-bottom:3rem;">
                    <?php foreach ($playlists_with_items as $pl_id => $pl): ?>
                        <div class="library-card" id="playlist-card-<?= $pl_id ?>">
                            <div class="library-cover" style="cursor:pointer; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.3); position:relative;" onclick="togglePlaylistDetails(<?= $pl_id ?>)">
                                <div class="premium-folder">
                                    <div class="folder-tab"></div>
                                    <div class="folder-body">
                                        <span style="font-family:'Syncopate', sans-serif; font-size:0.55rem; color:#fff; font-weight:700; opacity:0.8; letter-spacing:1px;">KITS</span>
                                    </div>
                                </div>
                                <span style="position:absolute; bottom:10px; right:10px; background:var(--accent); color:#fff; font-size:0.7rem; font-weight:700; padding:2px 6px; border-radius:3px;">
                                    <?= count($pl['items']) ?> items
                                </span>
                            </div>
                            <div class="library-info" style="cursor:pointer;" onclick="togglePlaylistDetails(<?= $pl_id ?>)">
                                <h3><?= h($pl['name']) ?></h3>
                                <div class="author">Created: <?= date('Y-m-d', strtotime($pl['created_at'])) ?></div>
                            </div>
                            <div style="display:flex; gap:0.5rem; width:100%;">
                                <button class="cta-btn secondary" style="flex:1; font-size:0.65rem; padding:0.5rem;" onclick="togglePlaylistDetails(<?= $pl_id ?>)">VIEW ITEMS</button>
                                <button class="cta-btn secondary" style="border-color:rgba(192,21,42,0.4); color:rgba(255,255,255,0.6); font-size:0.65rem; padding:0.5rem; min-width:40px;" onclick="deletePlaylist(<?= $pl_id ?>)" title="Delete Playlist">🗑️</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Playlist Items Detailed View -->
                <?php foreach ($playlists_with_items as $pl_id => $pl): ?>
                    <div id="playlist-details-<?= $pl_id ?>" class="playlist-details-section" style="display:none; background:rgba(5,5,8,0.9); border:1px solid var(--border-color); border-radius:6px; padding:1.5rem; margin-bottom:2rem;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:0.5rem;">
                            <h4 style="margin:0; font-family:'Syncopate', sans-serif; color:var(--accent); font-size:0.9rem;"><?= strtoupper(h($pl['name'])) ?> ITEMS</h4>
                            <button class="cta-btn secondary" style="font-size:0.65rem; padding:0.3rem 0.8rem;" onclick="togglePlaylistDetails(<?= $pl_id ?>)">CLOSE</button>
                        </div>
                        <?php if (empty($pl['items'])): ?>
                            <p style="color:var(--text-muted); font-size:0.85rem; text-align:center;">This playlist has no items. Visit the Shop to add products!</p>
                        <?php else: ?>
                            <div class="library-grid">
                                <?php foreach ($pl['items'] as $p): ?>
                                    <div class="library-card" id="playlist-item-card-<?= $pl_id ?>-<?= $p['id'] ?>">
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
                                        <div style="display:flex; gap:0.5rem;">
                                            <a href="/shop.php" class="cta-btn" style="flex:1; font-size:0.65rem; padding:0.5rem; text-align:center;">GO TO SHOP</a>
                                            <button class="cta-btn secondary" style="border-color:rgba(192,21,42,0.4); font-size:0.65rem; padding:0.5rem;" onclick="removeFromPlaylist(<?= $pl_id ?>, <?= (int)$p['id'] ?>, this)">REMOVE</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Liked/Upvoted Products Section -->
            <h4 style="font-family:'Syncopate', sans-serif; font-size:0.85rem; letter-spacing:0.05em; margin-bottom:1rem; margin-top:2rem; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom:0.5rem;">LIKED &amp; UPVOTED</h4>
            
            <?php if (empty($liked_products)): ?>
                <div class="empty-library" id="empty-liked-notice" style="padding: 2rem;">
                    <h3>No Liked Products</h3>
                    <p>Upvote products in the Shop by clicking the star (☆) button!</p>
                </div>
            <?php else: ?>
                <div class="library-grid" id="liked-products-grid">
                    <?php foreach ($liked_products as $p): ?>
                        <div class="library-card" id="liked-card-<?= $p['id'] ?>">
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
                            <div style="display:flex; gap:0.5rem;">
                                <a href="/shop.php" class="cta-btn" style="flex:1; font-size:0.65rem; padding:0.5rem; text-align:center;">GO TO SHOP</a>
                                <button class="cta-btn secondary" style="border-color:rgba(192,21,42,0.4); font-size:0.65rem; padding:0.5rem;" onclick="unlikeProduct(<?= (int)$p['id'] ?>, this)">UNLIKE</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── TAB: INBOX ── -->
        <div id="tab-inbox" class="portal-tab <?= $tab === 'inbox' ? 'active' : '' ?>">
            <div class="dm-client-grid">
                <!-- DM Sidebar -->
                <div class="dm-sidebar">
                    <div class="dm-sidebar-mobile-header" style="display: none; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255, 255, 255, 0.05); padding-bottom: 0.8rem; margin-bottom: 0.5rem;">
                        <span style="font-family: 'Syncopate', sans-serif; font-size: 0.75rem; font-weight: 700; color: #ffffff;">FOLDERS & VENNER</span>
                        <button onclick="toggleMobileSidebar(false)" style="background: transparent; border: none; color: var(--accent); font-weight: 700; font-size: 1.1rem; cursor: pointer;">✕</button>
                    </div>
                    <button class="dm-compose-btn" onclick="openComposeModal()"><span style="margin-right:8px;">+</span> COMPOSE</button>
                    
                    <div class="dm-folder-list">
                        <button class="dm-folder-item active" onclick="switchDMFolder('primary')" id="dm-folder-primary">
                            <span><span class="icon">👥</span> Primary</span>
                            <span class="badge" id="dm-badge-primary">0</span>
                        </button>
                        <button class="dm-folder-item" onclick="switchDMFolder('general')" id="dm-folder-general">
                            <span><span class="icon">📬</span> Inbox</span>
                            <span class="badge" id="dm-badge-general">0</span>
                        </button>
                        <button class="dm-folder-item" onclick="switchDMFolder('important')" id="dm-folder-important">
                            <span><span class="icon">⭐</span> Important</span>
                            <span class="badge" id="dm-badge-important">0</span>
                        </button>
                        <button class="dm-folder-item" onclick="switchDMFolder('sent')" id="dm-folder-sent">
                            <span><span class="icon">📤</span> Sent</span>
                        </button>
                        <button class="dm-folder-item" onclick="switchDMFolder('deleted')" id="dm-folder-deleted">
                            <span><span class="icon">🗑️</span> Deleted</span>
                        </button>
                    </div>
                    
                    <div class="dm-friends-section">
                        <h4 class="dm-section-title">SEARCH MEMBERS</h4>
                        <div class="dm-member-search-container">
                            <input type="text" id="dm-member-search-input" placeholder="Search by username..." oninput="searchMembers()">
                            <div id="dm-member-search-results" class="dm-member-search-results"></div>
                        </div>
                        
                        <h4 class="dm-section-title">FRIENDS</h4>
                        <div id="dm-friends-list" class="dm-friends-list">
                            <!-- Populated dynamically -->
                        </div>
                    </div>
                </div>

                <!-- DM Conversations List Column -->
                <div class="dm-conversations-col">
                    <div class="dm-search-bar" style="display: flex; gap: 0.5rem; align-items: center;">
                        <button class="dm-mobile-folders-btn" onclick="toggleMobileSidebar(true)" style="display: none; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: #ffffff; padding: 0.55rem 0.8rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer; transition: all 0.2s ease;">📂 Folders</button>
                        <input type="text" id="dm-search-input" placeholder="Search conversations..." onkeyup="filterConversations()" style="flex-grow: 1;">
                    </div>
                    <div class="dm-conversations-list" id="dm-conversations-list">
                        <!-- Populated dynamically -->
                    </div>
                </div>

                <!-- DM Reading/Thread Pane -->
                <div class="dm-thread-pane" id="dm-thread-pane">
                    <div class="dm-thread-empty">
                        <div style="font-size:3rem; margin-bottom:1rem; opacity:0.3;">💬</div>
                        <h3>SELECT A CHAT</h3>
                        <p>Choose a chat partner or folder from the sidebar to view messages.</p>
                    </div>
                </div>
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

            <div class="portal-card" style="margin-top: 2rem; background: rgba(5, 5, 8, 0.8); border: 1px solid var(--border-color); border-radius: 6px; padding: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
                <h3 style="font-family:'Syncopate',sans-serif; font-size:1.1rem; letter-spacing:2px; margin:0 0 0.4rem 0; color:#fff; text-align:center;">PROFILE VISIBILITY</h3>
                <p style="color:var(--text-muted); font-size:0.82rem; margin:0 0 1.5rem 0; font-family:'Montserrat',sans-serif; font-weight:500; text-align:center;">Configure whether your profile is visible in the Community Directory and Member Search.</p>

                <form method="POST">
                    <input type="hidden" name="update_privacy" value="1">
                    
                    <div style="display:flex; flex-direction:column; gap:1.2rem; margin-bottom:2rem; text-align:left;">
                        <label style="display:flex; align-items:center; gap:0.8rem; cursor:pointer; font-family:'Montserrat',sans-serif; font-size:0.88rem; color:#fff;">
                            <input type="radio" name="profile_privacy" value="public" <?= !$is_private ? 'checked' : '' ?> style="width:1.1rem; height:1.1rem; accent-color:var(--accent);">
                            <span><strong>Public Profile</strong> (Visible to all members in Community Directory)</span>
                        </label>
                        <label style="display:flex; align-items:center; gap:0.8rem; cursor:pointer; font-family:'Montserrat',sans-serif; font-size:0.88rem; color:#fff;">
                            <input type="radio" name="profile_privacy" value="private" <?= $is_private ? 'checked' : '' ?> style="width:1.1rem; height:1.1rem; accent-color:var(--accent);">
                            <span><strong>Private Profile</strong> (Hidden from Community Directory & Search)</span>
                        </label>
                    </div>

                    <button type="submit" class="cta-btn" style="width:100%;">SAVE PREFERENCE</button>
                </form>
            </div>
        </div>

        <!-- ── TAB: FRIENDS ── -->
        <div id="tab-friends" class="portal-tab <?= $tab === 'friends' ? 'active' : '' ?>">
            <div class="portal-card" style="margin-bottom: 2rem; background: rgba(5, 5, 8, 0.8); border: 1px solid var(--border-color); border-radius: 6px; padding: 2.5rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5); max-width: 1200px; width: 100%;">
                
                <!-- Tab Header Area -->
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1.5rem; margin-bottom:2rem; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:1.5rem;">
                    <div>
                        <h3 style="font-family:'Syncopate',sans-serif; font-size:1.1rem; letter-spacing:2px; margin:0 0 0.4rem 0; color:#fff;">COMMUNITY FRIENDS</h3>
                        <p style="color:var(--text-muted); font-size:0.82rem; margin:0; font-family:'Montserrat',sans-serif; font-weight:500;">Search for members, view your friends, and manage pending requests.</p>
                    </div>
                    
                    <!-- Search Input -->
                    <div class="friends-search-container" style="position:relative; width:100%; max-width:320px;">
                        <input type="text" id="friends-tab-search-input" placeholder="Search members..." oninput="searchTabMembers()" style="width:100%; background:#000000; border:1px solid var(--border-color); border-radius:4px; padding:0.65rem 1rem 0.65rem 2.3rem; color:#ffffff; font-family:'Montserrat',sans-serif; font-size:0.82rem; outline:none; transition:all 0.25s ease;">
                        <svg style="position:absolute; left:0.8rem; top:50%; transform:translateY(-50%); width:12px; height:12px; fill:var(--text-muted); pointer-events:none;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M416 208c0 45.9-14.9 88.3-40 122.7L502.6 457.4c12.5 12.5 12.5 32.8 0 45.3s-32.8 12.5-45.3 0L330.7 376c-34.4 25.2-76.8 40-122.7 40C93.1 416 0 322.9 0 208S93.1 0 208 0S416 93.1 416 208zM208 352a144 144 0 1 0 0-288 144 144 0 1 0 0 288z"/></svg>
                        
                        <div id="friends-tab-search-results" style="display:none; position:absolute; top:110%; left:0; right:0; background:#0c0c0f; border:1px solid var(--border-color); border-radius:4px; z-index:100; max-height:280px; overflow-y:auto; box-shadow:0 10px 30px rgba(0,0,0,0.95); padding:0.5rem 0;"></div>
                    </div>
                </div>

                <!-- Grid Columns -->
                <div class="friends-tab-grid">
                    
                    <!-- Left Sidebar Column: Profile Card + Incoming Requests -->
                    <div class="friends-requests-column">
                        <!-- Miniature Profile Card -->
                        <div class="social-profile-card">
                            <?php if ($profile_pic): ?>
                                <img src="/static/uploads/<?= rawurlencode($profile_pic) ?>" class="social-profile-avatar" alt="Avatar">
                            <?php else: ?>
                                <div class="social-profile-avatar-placeholder"><?= strtoupper(substr($username, 0, 1)) ?></div>
                            <?php endif; ?>
                            <h4 class="social-profile-name"><?= h($username) ?></h4>
                            <span class="social-profile-role"><?= h(current_user_role()) ?></span>
                            <button onclick="switchTab('settings')" class="cta-btn secondary" style="width:100%; font-size:0.65rem; padding:0.45rem 0.8rem; margin:0;">Edit Profile</button>
                        </div>

                        <!-- Incoming Requests List -->
                        <h4 style="font-family:'Syncopate',sans-serif; font-size:0.85rem; letter-spacing:1px; color:#fff; margin:0 0 1.2rem 0; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:0.6rem; display:flex; align-items:center; gap:0.6rem;">
                            <span>REQUESTS</span>
                            <span id="friends-req-badge" style="background:var(--accent); color:#fff; font-size:0.62rem; padding:0.15rem 0.4rem; border-radius:10px; font-family:'Montserrat',sans-serif; font-weight:700; display:none;">0</span>
                        </h4>
                        <div id="friends-requests-list" style="display:flex; flex-direction:column; gap:0.8rem;"></div>
                    </div>
                    
                    <!-- Right Main Area Column: Social Stats Hub + Friends Directory Grid -->
                    <div class="friends-list-column">
                        <!-- Social Stats Hub -->
                        <div class="social-stats-hub">
                            <div class="stat-hub-card">
                                <div id="stats-friends-count" class="stat-hub-num">0</div>
                                <div class="stat-hub-label">Friends</div>
                            </div>
                            <div class="stat-hub-card">
                                <div id="stats-pending-count" class="stat-hub-num">0</div>
                                <div class="stat-hub-label">Requests</div>
                            </div>
                            <div class="stat-hub-card">
                                <div id="stats-messages-count" class="stat-hub-num"><?= $unread_count ?></div>
                                <div class="stat-hub-label">Unread DMs</div>
                            </div>
                        </div>

                        <!-- Friends Directory Header -->
                        <h4 style="font-family:'Syncopate',sans-serif; font-size:0.85rem; letter-spacing:1px; color:#fff; margin:0 0 1.2rem 0; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:0.6rem; display:flex; align-items:center; gap:0.6rem;">
                            <span>FRIENDS DIRECTORY</span>
                            <span id="friends-count-badge" style="background:rgba(255,255,255,0.1); color:var(--text-muted); font-size:0.62rem; padding:0.15rem 0.4rem; border-radius:10px; font-family:'Montserrat',sans-serif; font-weight:700; display:none;">0</span>
                        </h4>
                        <div id="friends-grid-list" class="friends-grid-layout"></div>
                    </div>
                    
                </div>

                <!-- Explore Community / Discover Section -->
                <div style="margin-top: 3.5rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 2.5rem;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1.5rem; margin-bottom:2rem;">
                        <div>
                            <h3 style="font-family:'Syncopate',sans-serif; font-size:1.1rem; letter-spacing:2px; margin:0 0 0.4rem 0; color:#fff;">EXPLORE COMMUNITY</h3>
                            <p style="color:var(--text-muted); font-size:0.82rem; margin:0; font-family:'Montserrat',sans-serif; font-weight:500;">Discover other approved producers, artists, and beatmakers. Connect to build your network.</p>
                        </div>
                        
                        <!-- Search Box for Discover -->
                        <div style="position:relative; width:100%; max-width:320px;">
                            <input type="text" id="discover-search-input" placeholder="Search community members..." oninput="filterDiscoverMembers()" style="width:100%; background:#000000; border:1px solid var(--border-color); border-radius:4px; padding:0.65rem 1rem 0.65rem 2.3rem; color:#ffffff; font-family:'Montserrat',sans-serif; font-size:0.82rem; outline:none; transition:all 0.25s ease;">
                            <svg style="position:absolute; left:0.8rem; top:50%; transform:translateY(-50%); width:12px; height:12px; fill:var(--text-muted); pointer-events:none;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M416 208c0 45.9-14.9 88.3-40 122.7L502.6 457.4c12.5 12.5 12.5 32.8 0 45.3s-32.8 12.5-45.3 0L330.7 376c-34.4 25.2-76.8 40-122.7 40C93.1 416 0 322.9 0 208S93.1 0 208 0S416 93.1 416 208zM208 352a144 144 0 1 0 0-288 144 144 0 1 0 0 288z"/></svg>
                        </div>
                    </div>
                    
                    <div id="discover-grid-list" class="community-grid" style="margin-top: 1.5rem;">
                        <!-- Will be populated by JS -->
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ── GLOBAL USER PROFILE POPUP MODAL ── -->
    <div id="profileModal" class="profile-modal" onclick="if(event.target===this) closeUserProfile()">
        <div class="profile-modal-card">
            <button class="profile-modal-close" onclick="closeUserProfile()">&times;</button>
            <div id="pm-avatar-container"></div>
            <h3 id="pm-username" class="profile-modal-username"></h3>
            <span id="pm-role" class="profile-modal-role"></span>
            
            <div class="profile-modal-stats">
                <div class="profile-modal-stat">
                    <div id="pm-stat-threads" class="profile-modal-stat-val">0</div>
                    <div class="profile-modal-stat-lbl">Threads</div>
                </div>
                <div class="profile-modal-stat">
                    <div id="pm-stat-replies" class="profile-modal-stat-val">0</div>
                    <div class="profile-modal-stat-lbl">Replies</div>
                </div>
            </div>
            
            <div class="profile-modal-actions" id="pm-actions-container">
                <!-- Action buttons loaded dynamically -->
            </div>
            
            <div class="profile-modal-dm-field" id="pm-dm-field">
                <label for="pm-dm-text">Send Quick Message</label>
                <textarea id="pm-dm-text" placeholder="Type a private message..."></textarea>
                <button class="profile-modal-btn primary" id="pm-dm-send-btn" onclick="sendProfileModalQuickDM()">Send private message</button>
            </div>
        </div>
    </div>

    <!-- ── COMPOSE DM MODAL ── -->
    <div id="composeModal" class="compose-modal" onclick="if(event.target===this) closeComposeModal()">
        <div class="compose-modal-card">
            <button class="compose-modal-close" onclick="closeComposeModal()">&times;</button>
            <h3>COMPOSE NEW MESSAGE</h3>
            
            <div class="form-group">
                <label>Select Friend</label>
                <select id="compose-recipient" class="compose-select-friend">
                    <option value="">-- Choose a friend --</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Message Content</label>
                <textarea id="compose-text" style="width:100%; background:#000; border:1px solid var(--border-color); border-radius:4px; padding:0.8rem; color:#fff; font-family:'Montserrat',sans-serif; font-size:0.88rem; min-height:100px; resize:none; outline:none; transition:border-color 0.25s;" placeholder="Write your message here..."></textarea>
            </div>
            
            <button class="profile-modal-btn primary" onclick="submitCompose()" style="margin-top:1rem;">SEND MESSAGE</button>
        </div>
    </div>

    <script>
        let currentDMFolder = 'primary';
        let currentActivePartner = null;
        let dmPollingInterval = null;

        function switchTab(tabId) {
            document.querySelectorAll('.portal-tab').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.portal-tab-btn').forEach(el => el.classList.remove('active'));

            const targetTab = document.getElementById('tab-' + tabId);
            if (targetTab) {
                targetTab.classList.add('active');
            }
            
            const activeBtn = Array.from(document.querySelectorAll('.portal-tab-btn')).find(btn => {
                const text = btn.innerText.toLowerCase();
                return text.includes(tabId);
            });
            if (activeBtn) {
                activeBtn.classList.add('active');
            }

            history.replaceState(null, '', '/portal/index.php?tab=' + tabId);

            if (tabId === 'inbox') {
                loadConversations();
                loadFriendsList();
                startDMPolling();
            } else if (tabId === 'friends') {
                initFriendsTab();
                stopDMPolling();
            } else {
                stopDMPolling();
            }
        }

        // ── PLAYLISTS & LIKED PRODUCTS JS LOGIC ──
        function openCreatePlaylistModal() {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay open';
            overlay.id = 'createPlaylistPortalModal';
            overlay.style.zIndex = '9999';
            overlay.innerHTML = `
                <div class="modal-box" style="max-width:400px; padding:2rem; text-align:center;">
                    <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">×</button>
                    <h3 style="margin-top:0; font-family:'Syncopate', sans-serif; color:var(--accent);">CREATE PLAYLIST</h3>
                    <div style="margin:1.5rem 0;">
                        <input type="text" id="newPortalPlaylistName" placeholder="Playlist Name" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid var(--border-color); color:#fff; padding:0.5rem; border-radius:4px; font-family:'Montserrat', sans-serif; font-size:0.95rem; box-sizing:border-box;">
                    </div>
                    <button class="cta-btn" onclick="submitCreatePlaylist()" style="width:100%; padding:0.6rem;">CREATE</button>
                </div>
            `;
            document.body.appendChild(overlay);
        }

        function submitCreatePlaylist() {
            const input = document.getElementById('newPortalPlaylistName');
            if (!input || !input.value.trim()) return;
            const name = input.value.trim();

            fetch('/api/product_actions.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=create_playlist&name=' + encodeURIComponent(name)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const modal = document.getElementById('createPlaylistPortalModal');
                    if (modal) modal.remove();
                    
                    const pl = data.playlist; // Contains id and name
                    
                    // Check if grid exists, if not create it
                    let grid = document.getElementById('playlists-grid');
                    const notice = document.getElementById('empty-playlists-notice');
                    if (!grid) {
                        if (notice) notice.remove();
                        grid = document.createElement('div');
                        grid.className = 'library-grid';
                        grid.id = 'playlists-grid';
                        grid.style.marginBottom = '3rem';
                        
                        // Insert grid before "LIKED & UPVOTED" heading
                        const heading = Array.from(document.querySelectorAll('#tab-liked h4')).find(el => el.textContent.includes('MY CUSTOM PLAYLISTS'));
                        if (heading) {
                            heading.after(grid);
                        } else {
                            const tab = document.getElementById('tab-liked');
                            tab.insertBefore(grid, tab.lastElementChild);
                        }
                    }
                    
                    // Create playlist card
                    const card = document.createElement('div');
                    card.className = 'library-card';
                    card.id = 'playlist-card-' + pl.id;
                    
                    const safeName = pl.name.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
                    
                    card.innerHTML = `
                        <div class="library-cover" style="cursor:pointer; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.3); position:relative;" onclick="togglePlaylistDetails(${pl.id})">
                            <div class="premium-folder">
                                <div class="folder-tab"></div>
                                <div class="folder-body">
                                    <span style="font-family:'Syncopate', sans-serif; font-size:0.55rem; color:#fff; font-weight:700; opacity:0.8; letter-spacing:1px;">KITS</span>
                                </div>
                            </div>
                            <span style="position:absolute; bottom:10px; right:10px; background:var(--accent); color:#fff; font-size:0.7rem; font-weight:700; padding:2px 6px; border-radius:3px;">
                                0 items
                            </span>
                        </div>
                        <div class="library-info" style="cursor:pointer;" onclick="togglePlaylistDetails(${pl.id})">
                            <h3>${safeName}</h3>
                            <div class="author">Created: ${new Date().toISOString().split('T')[0]}</div>
                        </div>
                        <div style="display:flex; gap:0.5rem; width:100%;">
                            <button class="cta-btn secondary" style="flex:1; font-size:0.65rem; padding:0.5rem;" onclick="togglePlaylistDetails(${pl.id})">VIEW ITEMS</button>
                            <button class="cta-btn secondary" style="border-color:rgba(192,21,42,0.4); color:rgba(255,255,255,0.6); font-size:0.65rem; padding:0.5rem; min-width:40px;" onclick="deletePlaylist(${pl.id})" title="Delete Playlist">🗑️</button>
                        </div>
                    `;
                    
                    grid.insertBefore(card, grid.firstChild);
                    
                    // Create details section
                    const details = document.createElement('div');
                    details.id = 'playlist-details-' + pl.id;
                    details.className = 'playlist-details-section';
                    details.style.display = 'none';
                    details.style.background = 'rgba(5,5,8,0.9)';
                    details.style.border = '1px solid var(--border-color)';
                    details.style.borderRadius = '6px';
                    details.style.padding = '1.5rem';
                    details.style.marginBottom = '2rem';
                    
                    details.innerHTML = `
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:0.5rem;">
                            <h4 style="margin:0; font-family:'Syncopate', sans-serif; color:var(--accent); font-size:0.9rem;">${safeName.toUpperCase()} ITEMS</h4>
                            <button class="cta-btn secondary" style="font-size:0.65rem; padding:0.3rem 0.8rem;" onclick="togglePlaylistDetails(${pl.id})">CLOSE</button>
                        </div>
                        <p style="color:var(--text-muted); font-size:0.85rem; text-align:center;">This playlist has no items. Visit the Shop to add products!</p>
                    `;
                    
                    grid.after(details);
                }
            });
        }

        function deletePlaylist(playlistId) {
            if (!confirm('Are you sure you want to delete this playlist?')) return;
            
            fetch('/api/product_actions.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete_playlist&playlist_id=${playlistId}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const card = document.getElementById('playlist-card-' + playlistId);
                    if (card) card.remove();
                    const details = document.getElementById('playlist-details-' + playlistId);
                    if (details) details.remove();
                    
                    const grid = document.getElementById('playlists-grid');
                    if (grid && grid.children.length === 0) {
                        window.location.reload();
                    }
                }
            });
        }

        function togglePlaylistDetails(playlistId) {
            document.querySelectorAll('.playlist-details-section').forEach(el => {
                if (el.id !== 'playlist-details-' + playlistId) {
                    el.style.display = 'none';
                }
            });
            
            const target = document.getElementById('playlist-details-' + playlistId);
            if (target) {
                if (target.style.display === 'none') {
                    target.style.display = 'block';
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    target.style.display = 'none';
                }
            }
        }

        function removeFromPlaylist(playlistId, productId, btn) {
            fetch('/api/product_actions.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=toggle_playlist_item&playlist_id=${playlistId}&product_id=${productId}`
            })
            .then(r => r.json())
            .then(res => {
                if (res.success && !res.is_active) {
                    const card = document.getElementById(`playlist-item-card-${playlistId}-${productId}`);
                    if (card) {
                        card.remove();
                    }
                    const detailsSection = document.getElementById('playlist-details-' + playlistId);
                    if (detailsSection) {
                        const itemsGrid = detailsSection.querySelector('.library-grid');
                        if (itemsGrid && itemsGrid.children.length === 0) {
                            detailsSection.innerHTML = `
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:0.5rem;">
                                    <h4 style="margin:0; font-family:'Syncopate', sans-serif; color:var(--accent); font-size:0.9rem;">ITEMS</h4>
                                    <button class="cta-btn secondary" style="font-size:0.65rem; padding:0.3rem 0.8rem;" onclick="togglePlaylistDetails(${playlistId})">CLOSE</button>
                                </div>
                                <p style="color:var(--text-muted); font-size:0.85rem; text-align:center;">This playlist has no items. Visit the Shop to add products!</p>
                            `;
                        }
                    }
                }
            });
        }

        function unlikeProduct(productId, btn) {
            fetch('/api/product_actions.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=toggle_upvote&product_id=${productId}`
            })
            .then(r => r.json())
            .then(res => {
                if (res.success && !res.is_active) {
                    const card = document.getElementById('liked-card-' + productId);
                    if (card) card.remove();
                    
                    const grid = document.getElementById('liked-products-grid');
                    if (grid && grid.children.length === 0) {
                        const container = grid.parentElement;
                        grid.remove();
                        const notice = document.createElement('div');
                        notice.className = 'empty-library';
                        notice.style.padding = '2rem';
                        notice.id = 'empty-liked-notice';
                        notice.innerHTML = `
                            <h3>No Liked Products</h3>
                            <p>Upvote products in the Shop by clicking the star (☆) button!</p>
                        `;
                        container.appendChild(notice);
                    }
                }
            });
        }

        // ── DIRECT MESSAGING JS LOGIC ──

        function switchDMFolder(folder) {
            currentDMFolder = folder;
            document.querySelectorAll('.dm-folder-item').forEach(el => el.classList.remove('active'));
            const activeFolderBtn = document.getElementById('dm-folder-' + folder);
            if (activeFolderBtn) activeFolderBtn.classList.add('active');
            
            loadConversations();
            
            // Clear reading pane
            const threadPane = document.getElementById('dm-thread-pane');
            threadPane.innerHTML = `
                <div class="dm-thread-empty">
                    <div style="font-size:3rem; margin-bottom:1rem; opacity:0.3;">💬</div>
                    <h3>${folder.toUpperCase()} FOLDER</h3>
                    <p>Select a message thread to read.</p>
                </div>
            `;
            currentActivePartner = null;

            // Clear mobile classes to show chats view
            const clientGrid = document.querySelector('.dm-client-grid');
            if (clientGrid) {
                clientGrid.classList.remove('show-sidebar');
                clientGrid.classList.remove('show-thread');
            }
        }

        function loadConversations() {
            const listContainer = document.getElementById('dm-conversations-list');
            
            if (currentDMFolder === 'important') {
                fetch(`/portal/portal_messages_api.php?action=fetch_important&t=${Date.now()}`)
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) renderIndividualMessages(res.messages, listContainer);
                    });
                return;
            }
            
            if (currentDMFolder === 'deleted') {
                fetch(`/portal/portal_messages_api.php?action=fetch_deleted&t=${Date.now()}`)
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) renderIndividualMessages(res.messages, listContainer);
                    });
                return;
            }

            fetch(`/portal/portal_messages_api.php?action=fetch_conversations&t=${Date.now()}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const conversations = currentDMFolder === 'primary' ? res.primary : res.general;
                        
                        // Update badges
                        const pBadge = document.getElementById('dm-badge-primary');
                        const gBadge = document.getElementById('dm-badge-general');
                        
                        const pUnread = res.primary.reduce((acc, c) => acc + parseInt(c.unread_count), 0);
                        const gUnread = res.general.reduce((acc, c) => acc + parseInt(c.unread_count), 0);
                        
                        if (pUnread > 0) {
                            pBadge.innerText = pUnread;
                            pBadge.classList.add('visible');
                        } else {
                            pBadge.classList.remove('visible');
                        }
                        
                        if (gUnread > 0) {
                            gBadge.innerText = gUnread;
                            gBadge.classList.add('visible');
                        } else {
                            gBadge.classList.remove('visible');
                        }

                        // Also update header tab unread count
                        const totalUnread = pUnread + gUnread;
                        const inboxBadge = document.getElementById('inbox-tab-badge');
                        if (inboxBadge) {
                            inboxBadge.innerText = totalUnread;
                            inboxBadge.style.display = totalUnread > 0 ? 'inline-block' : 'none';
                        }

                        if (conversations.length === 0) {
                            listContainer.innerHTML = `
                                <div style="text-align:center; padding:3rem 1rem; color:var(--text-muted); font-size:0.8rem;">
                                    No conversations found in ${currentDMFolder}.
                                </div>
                            `;
                            return;
                        }

                        listContainer.innerHTML = '';
                        conversations.forEach(c => {
                            const isSelected = currentActivePartner === parseInt(c.partner_id) ? 'active' : '';
                            const isUnread = parseInt(c.unread_count) > 0 ? 'unread' : '';
                            
                            const avatarHtml = c.partner_avatar 
                                ? `<img src="/static/uploads/${encodeURIComponent(c.partner_avatar)}" class="dm-convo-avatar" alt="">`
                                : `<div class="dm-convo-avatar-placeholder">${c.partner_username.substr(0,1).toUpperCase()}</div>`;
                                
                            const badgeHtml = parseInt(c.unread_count) > 0 
                                ? `<span class="dm-convo-badge">${c.unread_count}</span>` 
                                : '';

                            const timeStr = formatConvoTime(c.last_msg_time);

                            const itemHtml = `
                                <div class="dm-convo-item ${isSelected} ${isUnread}" onclick="openConversation(${c.partner_id}, '${escapeHtml(c.partner_username)}', '${c.partner_avatar}')">
                                    <div>${avatarHtml}</div>
                                    <div class="dm-convo-info">
                                        <div class="dm-convo-header">
                                            <span class="dm-convo-name">${escapeHtml(c.partner_username)}</span>
                                            <span class="dm-convo-time">${timeStr}</span>
                                        </div>
                                        <div class="dm-convo-body">
                                            <span class="dm-convo-lastmsg">${escapeHtml(c.last_msg_text)}</span>
                                            ${badgeHtml}
                                        </div>
                                    </div>
                                </div>
                            `;
                            listContainer.insertAdjacentHTML('beforeend', itemHtml);
                        });
                    }
                });
        }

        function renderIndividualMessages(messages, container) {
            if (messages.length === 0) {
                container.innerHTML = `
                    <div style="text-align:center; padding:3rem 1rem; color:var(--text-muted); font-size:0.8rem;">
                        No messages found in this folder.
                    </div>
                `;
                return;
            }
            container.innerHTML = '';
            messages.forEach(m => {
                const isSent = parseInt(m.sender_id) === <?= (int)$user_id ?>;
                const partnerName = isSent ? m.recipient_username : m.sender_username;
                const partnerAvatar = isSent ? m.recipient_avatar : m.sender_avatar;
                
                const avatarHtml = partnerAvatar 
                    ? `<img src="/static/uploads/${encodeURIComponent(partnerAvatar)}" class="dm-convo-avatar" alt="">`
                    : `<div class="dm-convo-avatar-placeholder">${partnerName.substr(0,1).toUpperCase()}</div>`;

                const timeStr = formatConvoTime(m.created_at);

                const itemHtml = `
                    <div class="dm-convo-item" onclick="openConversation(${isSent ? m.recipient_id : m.sender_id}, '${escapeHtml(partnerName)}', '${partnerAvatar}')">
                        <div>${avatarHtml}</div>
                        <div class="dm-convo-info">
                            <div class="dm-convo-header">
                                <span class="dm-convo-name">${escapeHtml(partnerName)}</span>
                                <span class="dm-convo-time">${timeStr}</span>
                            </div>
                            <div class="dm-convo-body">
                                <span class="dm-convo-lastmsg">${isSent ? 'You: ' : ''}${escapeHtml(m.message)}</span>
                            </div>
                        </div>
                    </div>
                `;
                container.insertAdjacentHTML('beforeend', itemHtml);
            });
        }

        function openConversation(partnerId, partnerName, partnerAvatar) {
            currentActivePartner = parseInt(partnerId);
            
            // Mark active convo row
            document.querySelectorAll('.dm-convo-item').forEach(el => el.classList.remove('active'));
            
            // Highlight active convo
            const conversationsList = document.getElementById('dm-conversations-list');
            loadThread(partnerId, partnerName, partnerAvatar);

            // Add mobile pane state
            const clientGrid = document.querySelector('.dm-client-grid');
            if (clientGrid) {
                clientGrid.classList.add('show-thread');
                clientGrid.classList.remove('show-sidebar');
            }
        }

        function loadThread(partnerId, partnerName, partnerAvatar) {
            fetch(`/portal/portal_messages_api.php?action=fetch_thread&partner_id=${partnerId}&t=${Date.now()}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const threadPane = document.getElementById('dm-thread-pane');
                        
                        const partnerAvatarHtml = partnerAvatar && partnerAvatar !== 'null' && partnerAvatar !== ''
                            ? `<img src="/static/uploads/${encodeURIComponent(partnerAvatar)}" class="dm-thread-partner-avatar" alt="">`
                            : `<div class="dm-thread-partner-avatar-placeholder">${partnerName.substr(0,1).toUpperCase()}</div>`;

                        threadPane.innerHTML = `
                            <div class="dm-thread-header">
                                <button class="dm-mobile-back-btn" onclick="closeMobileThread()">←</button>
                                <div class="dm-thread-partner" onclick="openUserProfile(${partnerId})">
                                    ${partnerAvatarHtml}
                                    <span class="dm-thread-partner-name">${escapeHtml(partnerName)}</span>
                                </div>
                                <div class="dm-thread-actions">
                                    <button class="dm-thread-btn" onclick="deleteDMConversation(${partnerId})">Delete conversation</button>
                                </div>
                            </div>
                            
                            <div class="dm-messages-container" id="dm-messages-container">
                                <!-- Messages inserted here -->
                            </div>
                            
                            <div class="dm-input-area">
                                <textarea id="dm-message-input" placeholder="Type a message to ${escapeHtml(partnerName)}..." onkeydown="handleDMInputKey(event)"></textarea>
                                <button onclick="sendDMMessage()">SEND</button>
                            </div>
                        `;

                        const msgContainer = document.getElementById('dm-messages-container');
                        res.messages.forEach(m => {
                            const isSent = parseInt(m.sender_id) === <?= (int)$user_id ?>;
                            const isStarred = isSent ? parseInt(m.is_flagged_by_sender) : parseInt(m.is_flagged_by_recipient);
                            const starClass = isStarred ? 'starred' : '';
                            
                            const bubbleHtml = `
                                <div class="dm-msg-bubble ${isSent ? 'sent' : 'received'}">
                                    <div style="display:flex; justify-content:space-between; gap:2rem; align-items:flex-start;">
                                        <div style="white-space:pre-wrap;">${escapeHtml(m.message)}</div>
                                        <span style="cursor:pointer; font-size:0.8rem; margin-top:-2px;" onclick="toggleDMStar(${m.id}, this)" class="${starClass}">
                                            ${isStarred ? '★' : '☆'}
                                        </span>
                                    </div>
                                    <div class="dm-msg-info">
                                        ${formatBubbleTime(m.created_at)}
                                    </div>
                                </div>
                            `;
                            msgContainer.insertAdjacentHTML('beforeend', bubbleHtml);
                        });

                        // Scroll to bottom
                        msgContainer.scrollTop = msgContainer.scrollHeight;
                        
                        // Reload convo list badges
                        loadConversations();
                    }
                });
        }

        function handleDMInputKey(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendDMMessage();
            }
        }

        function sendDMMessage() {
            const input = document.getElementById('dm-message-input');
            const message = input.value.trim();
            if (!message || !currentActivePartner) return;

            const fd = new FormData();
            fd.append('action', 'send_message');
            fd.append('recipient_id', currentActivePartner);
            fd.append('message', message);

            fetch('/portal/portal_messages_api.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        input.value = '';
                        // Reload thread
                        const convo = res.message;
                        const msgContainer = document.getElementById('dm-messages-container');
                        
                        const bubbleHtml = `
                            <div class="dm-msg-bubble sent">
                                <div style="display:flex; justify-content:space-between; gap:2rem; align-items:flex-start;">
                                    <div style="white-space:pre-wrap;">${escapeHtml(convo.message)}</div>
                                    <span style="cursor:pointer; font-size:0.8rem; margin-top:-2px;" onclick="toggleDMStar(${convo.id}, this)">
                                        ☆
                                    </span>
                                </div>
                                <div class="dm-msg-info">
                                    Just Now
                                </div>
                            </div>
                        `;
                        msgContainer.insertAdjacentHTML('beforeend', bubbleHtml);
                        msgContainer.scrollTop = msgContainer.scrollHeight;
                        
                        // Refresh side list
                        loadConversations();
                    }
                });
        }

        function toggleDMStar(messageId, starEl) {
            const fd = new FormData();
            fd.append('action', 'toggle_star');
            fd.append('message_id', messageId);

            fetch('/portal/portal_messages_api.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        if (res.is_starred) {
                            starEl.innerText = '★';
                            starEl.classList.add('starred');
                        } else {
                            starEl.innerText = '☆';
                            starEl.classList.remove('starred');
                        }
                    }
                });
        }

        function deleteDMConversation(partnerId) {
            if (!confirm("Are you sure you want to delete this conversation? This will clear all messages in this thread for you.")) return;
            
            fetch(`/portal/portal_messages_api.php?action=fetch_thread&partner_id=${partnerId}&t=${Date.now()}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const promises = res.messages.map(m => {
                            const fd = new FormData();
                            fd.append('action', 'delete_message');
                            fd.append('message_id', m.id);
                            return fetch('/portal/portal_messages_api.php', { method: 'POST', body: fd });
                        });
                        
                        Promise.all(promises).then(() => {
                            switchDMFolder(currentDMFolder);
                        });
                    }
                });
        }

        let searchTimeout = null;

        function searchMembers() {
            const input = document.getElementById('dm-member-search-input');
            const resultsDiv = document.getElementById('dm-member-search-results');
            const query = input.value.trim();

            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }

            if (query.length < 2) {
                resultsDiv.innerHTML = '';
                resultsDiv.style.display = 'none';
                return;
            }

            searchTimeout = setTimeout(() => {
                fetch(`/portal/friends_api.php?action=search_users&query=${encodeURIComponent(query)}&t=${Date.now()}`)
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            if (res.users.length === 0) {
                                resultsDiv.innerHTML = `<div style="padding:0.75rem; font-size:0.75rem; color:var(--text-muted); text-align:center;">No members found.</div>`;
                                resultsDiv.style.display = 'block';
                                return;
                            }

                            resultsDiv.innerHTML = '';
                            res.users.forEach(u => {
                                const avatarHtml = u.profile_picture
                                    ? `<img src="/static/uploads/${encodeURIComponent(u.profile_picture)}" class="dm-search-result-avatar" alt="">`
                                    : `<div class="dm-search-result-avatar-placeholder">${u.username.substr(0,1).toUpperCase()}</div>`;
                                
                                const uHtml = `
                                    <div class="dm-search-result-item" onclick="openUserProfile(${u.id})">
                                        ${avatarHtml}
                                        <span class="dm-search-result-name">${escapeHtml(u.username)}</span>
                                    </div>
                                `;
                                resultsDiv.insertAdjacentHTML('beforeend', uHtml);
                            });
                            resultsDiv.style.display = 'block';
                        }
                    });
            }, 300);
        }

        // Close search results when clicking outside
        document.addEventListener('click', function(e) {
            const container = document.querySelector('.dm-member-search-container');
            const resultsDiv = document.getElementById('dm-member-search-results');
            if (container && !container.contains(e.target) && resultsDiv) {
                resultsDiv.style.display = 'none';
            }
        });

        function loadFriendsList() {
            const container = document.getElementById('dm-friends-list');
            fetch(`/portal/friends_api.php?action=list_friends&t=${Date.now()}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        if (res.friends.length === 0) {
                            container.innerHTML = `<div style="padding:0 0.5rem; font-size:0.72rem; color:var(--text-muted);">No friends yet. Add friends via the Community Forum!</div>`;
                            return;
                        }
                        container.innerHTML = '';
                        
                        // Populate select options for compose dropdown
                        const selectEl = document.getElementById('compose-recipient');
                        selectEl.innerHTML = '<option value="">-- Choose a friend --</option>';

                        res.friends.forEach(f => {
                            const avatarHtml = f.profile_picture
                                ? `<img src="/static/uploads/${encodeURIComponent(f.profile_picture)}" class="dm-friend-avatar" alt="">`
                                : `<div class="dm-friend-avatar-placeholder">${f.username.substr(0,1).toUpperCase()}</div>`;
                                
                            const friendHtml = `
                                <div class="dm-friend-item" onclick="openUserProfile(${f.id})">
                                    ${avatarHtml}
                                    <span class="dm-friend-name">${escapeHtml(f.username)}</span>
                                </div>
                            `;
                            container.insertAdjacentHTML('beforeend', friendHtml);
                            
                            // Option for compose dropdown
                            selectEl.insertAdjacentHTML('beforeend', `<option value="${f.id}">${escapeHtml(f.username)}</option>`);
                        });
                    }
                });
        }

        // ── COMPOSE MODAL ──

        function openComposeModal() {
            document.getElementById('composeModal').classList.add('open');
            loadFriendsList(); // Ensure fresh friends list
        }

        function closeComposeModal() {
            document.getElementById('composeModal').classList.remove('open');
            document.getElementById('compose-text').value = '';
            document.getElementById('compose-recipient').value = '';
        }

        function submitCompose() {
            const recSel = document.getElementById('compose-recipient');
            const recId = recSel.value;
            const recName = recSel.options[recSel.selectedIndex].text;
            const text = document.getElementById('compose-text').value.trim();
            
            if (!recId) {
                showToast("Please select a friend to send a message to.", "error");
                return;
            }
            if (!text) {
                showToast("Message cannot be empty.", "error");
                return;
            }

            const fd = new FormData();
            fd.append('action', 'send_message');
            fd.append('recipient_id', recId);
            fd.append('message', text);

            fetch('/portal/portal_messages_api.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        closeComposeModal();
                        showToast("Message sent successfully!", "success");
                        switchDMFolder('primary');
                        openConversation(recId, recName, '');
                    } else {
                        showToast("Error: " + res.error, "error");
                    }
                });
        }

        // ── USER PROFILE MODAL (GLOBAL POPUP) ──

        function openUserProfile(userId) {
            const searchResultsDiv = document.getElementById('dm-member-search-results');
            if (searchResultsDiv) {
                searchResultsDiv.style.display = 'none';
            }
            fetch(`/portal/friends_api.php?action=get_profile&user_id=${userId}&t=${Date.now()}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const u = res.user;
                        document.getElementById('pm-username').innerText = u.username;
                        document.getElementById('pm-role').innerText = u.role;
                        document.getElementById('pm-stat-threads').innerText = u.threads_count;
                        document.getElementById('pm-stat-replies').innerText = u.replies_count;
                        
                        const avatarContainer = document.getElementById('pm-avatar-container');
                        if (u.avatar) {
                            avatarContainer.innerHTML = `<img src="/static/uploads/${encodeURIComponent(u.avatar)}" class="profile-modal-avatar" alt="Avatar">`;
                        } else {
                            avatarContainer.innerHTML = `<div class="profile-modal-avatar-placeholder">${u.username.substr(0,1).toUpperCase()}</div>`;
                        }

                        const actionsContainer = document.getElementById('pm-actions-container');
                        const dmField = document.getElementById('pm-dm-field');
                        
                        if (res.is_self) {
                            actionsContainer.innerHTML = `<button class="profile-modal-btn disabled" disabled>This is you</button>`;
                            dmField.classList.remove('visible');
                        } else {
                            dmField.classList.add('visible');
                            document.getElementById('pm-dm-text').value = '';
                            
                            // Friendship status actions
                            const fStatus = res.friendship_status;
                            if (fStatus === 'none') {
                                actionsContainer.innerHTML = `
                                    <button class="profile-modal-btn primary" onclick="handleFriendshipAction(${u.id}, 'send_request')">Add Friend</button>
                                `;
                            } else if (fStatus === 'sent_pending') {
                                actionsContainer.innerHTML = `
                                    <button class="profile-modal-btn secondary" onclick="handleFriendshipAction(${u.id}, 'cancel_request')">Cancel Request</button>
                                `;
                            } else if (fStatus === 'received_pending') {
                                actionsContainer.innerHTML = `
                                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.8rem;">
                                        <button class="profile-modal-btn primary" onclick="handleFriendshipAction(${u.id}, 'accept_request')">Accept</button>
                                        <button class="profile-modal-btn secondary" onclick="handleFriendshipAction(${u.id}, 'decline_request')">Decline</button>
                                    </div>
                                `;
                            } else if (fStatus === 'accepted') {
                                actionsContainer.innerHTML = `
                                    <div style="display:flex; flex-direction:column; gap:0.5rem;">
                                        <div style="font-size:0.85rem; color:#7be1a8; font-weight:700; font-family:'Syncopate',sans-serif; margin-bottom:0.4rem;">✓ Friends</div>
                                        <button class="profile-modal-btn secondary" onclick="handleFriendshipAction(${u.id}, 'unfriend')">Remove Friend</button>
                                    </div>
                                `;
                            }
                        }
                        
                        // Direct DM Send button target
                        document.getElementById('pm-dm-send-btn').setAttribute('data-recipient-id', u.id);
                        document.getElementById('pm-dm-send-btn').setAttribute('data-recipient-name', u.username);

                        document.getElementById('profileModal').classList.add('open');
                    } else {
                        showToast("Error loading profile: " + res.error, "error");
                    }
                });
        }

        function closeUserProfile() {
            document.getElementById('profileModal').classList.remove('open');
        }

        function handleFriendshipAction(userId, action) {
            const fd = new FormData();
            fd.append('action', action);
            fd.append('user_id', userId);

            fetch('/portal/friends_api.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        // Refresh profile modal if open
                        if (document.getElementById('profileModal').classList.contains('open')) {
                            openUserProfile(userId);
                        }
                        // Refresh friends lists if portal DM is open
                        loadFriendsList();
                        loadConversations();
                        
                        // Refresh friends tab lists
                        loadTabFriends();
                        loadTabPending();

                        let actionLabel = "Action successful!";
                        if (action === 'send_request') actionLabel = "Friend request sent successfully!";
                        else if (action === 'accept_request') actionLabel = "Friend request accepted!";
                        else if (action === 'decline_request') actionLabel = "Friend request declined.";
                        else if (action === 'cancel_request') actionLabel = "Friend request cancelled.";
                        else if (action === 'unfriend') actionLabel = "Removed from friends.";

                        showToast(actionLabel, "success");
                    } else {
                        showToast("Action failed: " + res.error, "error");
                    }
                });
        }

        // ── FRIENDS TAB FRONTEND CONTROLLER ──
        
        function initFriendsTab() {
            const searchInput = document.getElementById('friends-tab-search-input');
            const searchResults = document.getElementById('friends-tab-search-results');
            const discoverInput = document.getElementById('discover-search-input');
            if (searchInput) searchInput.value = '';
            if (discoverInput) discoverInput.value = '';
            if (searchResults) {
                searchResults.innerHTML = '';
                searchResults.style.display = 'none';
            }
            loadTabFriends();
            loadTabPending();
            loadDiscoverMembers();
        }

        let discoverSearchTimeout = null;

        function filterDiscoverMembers() {
            const input = document.getElementById('discover-search-input');
            const query = input.value.trim();
            
            if (discoverSearchTimeout) {
                clearTimeout(discoverSearchTimeout);
            }
            
            discoverSearchTimeout = setTimeout(() => {
                loadDiscoverMembers(query);
            }, 300);
        }

        function loadDiscoverMembers(query = '') {
            const gridList = document.getElementById('discover-grid-list');
            if (!gridList) return;

            let url = '/portal/friends_api.php?action=list_discover_members';
            if (query) {
                url += '&query=' + encodeURIComponent(query);
            }

            fetch(url)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        if (res.users.length === 0) {
                            gridList.innerHTML = `<div style="grid-column: 1 / -1; padding:2.5rem; text-align:center; color:var(--text-muted); border:1px dashed var(--border-color); border-radius:6px; background:rgba(255,255,255,0.01);">
                                <div style="font-size:2rem; margin-bottom:0.8rem; opacity:0.3;">👥</div>
                                <span style="font-family:'Montserrat',sans-serif; font-size:0.82rem;">No other members found. Check back later!</span>
                            </div>`;
                            return;
                        }

                        gridList.innerHTML = '';
                        res.users.forEach(u => {
                            const avatarHtml = u.profile_picture
                                ? `<img src="/static/uploads/${encodeURIComponent(u.profile_picture)}" class="friend-tab-avatar" alt="">`
                                : `<div class="friend-tab-avatar-placeholder">${u.username.substr(0,1).toUpperCase()}</div>`;
                            
                            let actionButton = '';
                            if (u.friendship_status === 'accepted') {
                                actionButton = `<button class="friend-card-btn secondary" style="width:100%; font-size:0.7rem; padding:0.6rem; opacity:0.8; cursor:default;" disabled>✓ FRIENDS</button>`;
                            } else if (u.friendship_status === 'pending') {
                                if (parseInt(u.action_user_id) === parseInt(<?= (int)$user_id ?>)) {
                                    actionButton = `<button class="friend-card-btn secondary" style="width:100%; font-size:0.7rem; padding:0.6rem; opacity:0.7;" onclick="handleDiscoverFriendAction(${u.id}, 'cancel_request')">REQUEST SENT</button>`;
                                } else {
                                    actionButton = `<button class="friend-card-btn primary" style="width:100%; font-size:0.7rem; padding:0.6rem; background:#7be1a8; color:#000;" onclick="handleDiscoverFriendAction(${u.id}, 'accept_request')">ACCEPT REQUEST</button>`;
                                }
                            } else {
                                actionButton = `<button class="friend-card-btn primary" style="width:100%; font-size:0.7rem; padding:0.6rem;" onclick="handleDiscoverFriendAction(${u.id}, 'send_request')">ADD FRIEND</button>`;
                            }

                            const itemHtml = `
                                <div class="friend-list-card">
                                    <div class="friend-tab-avatar-wrap" onclick="openUserProfile(${u.id})">
                                        ${avatarHtml}
                                    </div>
                                    <span class="friend-card-username" onclick="openUserProfile(${u.id})" title="${escapeHtml(u.username)}">${escapeHtml(u.username)}</span>
                                    <span class="friend-card-role">${escapeHtml(u.role)}</span>
                                    <div class="friend-card-actions">
                                        ${actionButton}
                                    </div>
                                </div>
                            `;
                            gridList.insertAdjacentHTML('beforeend', itemHtml);
                        });
                    }
                });
        }

        function handleDiscoverFriendAction(userId, action) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('user_id', userId);

            fetch('/portal/friends_api.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    loadTabFriends();
                    loadTabPending();
                    loadDiscoverMembers(document.getElementById('discover-search-input').value.trim());
                } else {
                    alert(res.error || "Action failed");
                }
            });
        }

        function loadTabFriends() {
            const gridList = document.getElementById('friends-grid-list');
            const badge = document.getElementById('friends-count-badge');
            const statsFriendsCount = document.getElementById('stats-friends-count');
            if (!gridList) return;

            fetch(`/portal/friends_api.php?action=list_friends&t=${Date.now()}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const count = res.friends.length;
                        if (badge) {
                            badge.innerText = count;
                            badge.style.display = count > 0 ? 'inline-block' : 'none';
                        }
                        if (statsFriendsCount) {
                            statsFriendsCount.innerText = count;
                        }
                        
                        if (count === 0) {
                            gridList.innerHTML = `<div style="grid-column: 1 / -1; padding:2.5rem; text-align:center; color:var(--text-muted); border:1px dashed var(--border-color); border-radius:6px; background:rgba(255,255,255,0.01);">
                                <div style="font-size:2rem; margin-bottom:0.8rem; opacity:0.3;">👥</div>
                                <span style="font-family:'Montserrat',sans-serif; font-size:0.82rem;">No friends added yet. Search for members or connect via the Community Forum!</span>
                            </div>`;
                            return;
                        }
                        
                        gridList.innerHTML = '';
                        res.friends.forEach(f => {
                            const avatarHtml = f.profile_picture
                                ? `<img src="/static/uploads/${encodeURIComponent(f.profile_picture)}" class="friend-tab-avatar" alt="">`
                                : `<div class="friend-tab-avatar-placeholder">${f.username.substr(0,1).toUpperCase()}</div>`;
                            
                            const roleText = f.role ? f.role : 'Member';

                            const itemHtml = `
                                <div class="friend-list-card">
                                    <button onclick="handleFriendshipAction(${f.id}, 'unfriend')" class="friend-card-unfriend-btn" title="Remove Friend">&times;</button>
                                    <div class="friend-tab-avatar-wrap" onclick="openUserProfile(${f.id})">
                                        ${avatarHtml}
                                        <div class="friend-status-dot"></div>
                                    </div>
                                    <span class="friend-card-username" onclick="openUserProfile(${f.id})" title="${escapeHtml(f.username)}">${escapeHtml(f.username)}</span>
                                    <span class="friend-card-role">${escapeHtml(roleText)}</span>
                                    <div class="friend-card-actions">
                                        <button onclick="openDirectMessage(${f.id}, '${escapeHtml(f.username)}', '${f.profile_picture ? encodeURIComponent(f.profile_picture) : ''}')" class="friend-card-btn primary">MESSAGE</button>
                                        <button onclick="openUserProfile(${f.id})" class="friend-card-btn secondary">PROFILE</button>
                                    </div>
                                </div>
                            `;
                            gridList.insertAdjacentHTML('beforeend', itemHtml);
                        });
                    }
                });
        }

        function loadTabPending() {
            const listEl = document.getElementById('friends-requests-list');
            const badge = document.getElementById('friends-req-badge');
            const tabBadge = document.getElementById('friends-tab-badge');
            const statsPendingCount = document.getElementById('stats-pending-count');
            if (!listEl) return;

            fetch(`/portal/friends_api.php?action=list_pending_requests&t=${Date.now()}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const count = res.pending.length;
                        if (badge) {
                            badge.innerText = count;
                            badge.style.display = count > 0 ? 'inline-block' : 'none';
                        }
                        if (tabBadge) {
                            tabBadge.innerText = count;
                            tabBadge.style.display = count > 0 ? 'inline-block' : 'none';
                        }
                        if (statsPendingCount) {
                            statsPendingCount.innerText = count;
                        }
                        
                        if (count === 0) {
                            listEl.innerHTML = `<div style="padding:1.5rem; text-align:center; color:var(--text-muted); border:1px dashed var(--border-color); border-radius:6px; background:rgba(255,255,255,0.01); font-family:'Montserrat',sans-serif; font-size:0.8rem;">
                                No pending friend requests.
                            </div>`;
                            return;
                        }
                        
                        listEl.innerHTML = '';
                        res.pending.forEach(r => {
                            const avatarHtml = r.profile_picture
                                ? `<img src="/static/uploads/${encodeURIComponent(r.profile_picture)}" class="dm-friend-avatar" style="width:36px; height:36px;" alt="">`
                                : `<div class="dm-friend-avatar-placeholder" style="width:36px; height:36px; font-size:0.9rem;">${r.username.substr(0,1).toUpperCase()}</div>`;
                            
                            const itemHtml = `
                                <div class="friend-request-card" style="display:flex; align-items:center; gap:0.8rem; background:rgba(255,255,255,0.02); border:1px solid var(--border-color); padding:0.8rem 1rem; border-radius:6px; transition:all 0.25s ease;">
                                    ${avatarHtml}
                                    <div style="display:flex; flex-direction:column; flex-grow:1; min-width:0;">
                                        <span style="font-weight:600; font-size:0.85rem; color:#fff; font-family:'Montserrat',sans-serif; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:pointer;" onclick="openUserProfile(${r.id})">${escapeHtml(r.username)}</span>
                                        <span style="font-size:0.7rem; color:var(--text-muted); font-family:'Montserrat',sans-serif;">Incoming request</span>
                                    </div>
                                    <div style="display:flex; gap:0.4rem; flex-shrink:0;">
                                        <button onclick="handleFriendshipAction(${r.id}, 'accept_request')" class="cta-btn" style="padding:0.4rem 0.6rem; font-size:0.65rem; margin:0; background:#7be1a8; color:#000;">Accept</button>
                                        <button onclick="handleFriendshipAction(${r.id}, 'decline_request')" class="cta-btn secondary" style="padding:0.4rem 0.6rem; font-size:0.65rem; margin:0;">Decline</button>
                                    </div>
                                </div>
                            `;
                            listEl.insertAdjacentHTML('beforeend', itemHtml);
                        });
                    }
                });
        }

        function openDirectMessage(partnerId, partnerName, partnerAvatar) {
            switchTab('inbox');
            setTimeout(() => {
                openConversation(partnerId, partnerName, partnerAvatar);
            }, 150);
        }

        let friendsTabSearchTimeout = null;

        function searchTabMembers() {
            const input = document.getElementById('friends-tab-search-input');
            const resultsDiv = document.getElementById('friends-tab-search-results');
            const query = input.value.trim();

            if (friendsTabSearchTimeout) {
                clearTimeout(friendsTabSearchTimeout);
            }

            if (query.length < 2) {
                resultsDiv.innerHTML = '';
                resultsDiv.style.display = 'none';
                return;
            }

            friendsTabSearchTimeout = setTimeout(() => {
                fetch(`/portal/friends_api.php?action=search_users&query=${encodeURIComponent(query)}&t=${Date.now()}`)
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            if (res.users.length === 0) {
                                resultsDiv.innerHTML = `<div style="padding:1rem; font-size:0.8rem; color:var(--text-muted); text-align:center; font-family:'Montserrat',sans-serif;">No members found.</div>`;
                                resultsDiv.style.display = 'block';
                                return;
                            }

                            resultsDiv.innerHTML = '';
                            res.users.forEach(u => {
                                const avatarHtml = u.profile_picture
                                    ? `<img src="/static/uploads/${encodeURIComponent(u.profile_picture)}" class="dm-friend-avatar" style="width:36px; height:36px;" alt="">`
                                    : `<div class="dm-friend-avatar-placeholder" style="width:36px; height:36px; font-size:0.9rem;">${u.username.substr(0,1).toUpperCase()}</div>`;
                                
                                const uHtml = `
                                    <div class="friends-search-item" onclick="openUserProfile(${u.id})" style="display:flex; align-items:center; gap:0.8rem; padding:0.7rem 1.2rem; cursor:pointer; transition:background 0.2s ease;">
                                        ${avatarHtml}
                                        <div style="display:flex; flex-direction:column; flex-grow:1; min-width:0;">
                                            <span style="font-weight:600; font-size:0.85rem; color:#fff; font-family:'Montserrat',sans-serif; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(u.username)}</span>
                                            <span style="font-size:0.7rem; color:var(--text-muted); font-family:'Montserrat',sans-serif; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">${escapeHtml(u.role)}</span>
                                        </div>
                                        <button class="cta-btn secondary" style="padding:0.4rem 0.8rem; font-size:0.68rem; margin:0; flex-shrink:0;">View Profile</button>
                                    </div>
                                `;
                                resultsDiv.insertAdjacentHTML('beforeend', uHtml);
                            });
                            resultsDiv.style.display = 'block';
                        }
                    });
            }, 300);
        }

        // Close friends search results when clicking outside
        document.addEventListener('click', function(e) {
            const container = document.querySelector('.friends-search-container');
            const resultsDiv = document.getElementById('friends-tab-search-results');
            if (container && !container.contains(e.target) && resultsDiv) {
                resultsDiv.style.display = 'none';
            }
        });

        function sendProfileModalQuickDM() {
            const btn = document.getElementById('pm-dm-send-btn');
            const recId = btn.getAttribute('data-recipient-id');
            const recName = btn.getAttribute('data-recipient-name');
            const text = document.getElementById('pm-dm-text').value.trim();

            if (!text) {
                showToast("Message cannot be empty.", "error");
                return;
            }

            const fd = new FormData();
            fd.append('action', 'send_message');
            fd.append('recipient_id', recId);
            fd.append('message', text);

            fetch('/portal/portal_messages_api.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showToast("Message sent successfully!", "success");
                        closeUserProfile();
                        // If we are currently on the inbox tab, reload it and open conversation
                        const inboxTab = document.getElementById('tab-inbox');
                        if (inboxTab && inboxTab.classList.contains('active')) {
                            switchDMFolder('primary');
                            openConversation(recId, recName, '');
                        }
                    } else {
                        showToast("Failed to send message: " + res.error, "error");
                    }
                });
        }

        // ── HELPERS ──

        function showToast(message, type = 'success') {
            let container = document.getElementById('toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast-container';
                document.body.appendChild(container);
            }
            const toast = document.createElement('div');
            toast.className = `custom-toast ${type}`;
            toast.innerHTML = `
                <span>${escapeHtml(message)}</span>
                <button style="background:none; border:none; color:rgba(255,255,255,0.4); cursor:pointer; font-size:1.1rem; padding:0; margin:0;" onclick="this.parentElement.remove()">&times;</button>
            `;
            container.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 50);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 350);
            }, 4000);
        }

        let previousPendingRequestsCount = null;
        let previousPendingRequestsIds = [];
        let previousUnreadDMsCount = null;

        function runGlobalPoll() {
            // 1. Fetch pending friend requests
            fetch(`/portal/friends_api.php?action=list_pending_requests&t=${Date.now()}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const pending = res.pending || [];
                        const currentCount = pending.length;
                        
                        // Update badge elements
                        const tabBadge = document.getElementById('friends-tab-badge');
                        if (tabBadge) {
                            tabBadge.innerText = currentCount;
                            tabBadge.style.display = currentCount > 0 ? 'inline-block' : 'none';
                        }
                        
                        const reqBadge = document.getElementById('friends-req-badge');
                        if (reqBadge) {
                            reqBadge.innerText = currentCount;
                            reqBadge.style.display = currentCount > 0 ? 'inline-block' : 'none';
                        }
                        
                        // Check if any new friend request was received
                        if (previousPendingRequestsCount !== null) {
                            const currentIds = pending.map(u => parseInt(u.id));
                            pending.forEach(u => {
                                const uid = parseInt(u.id);
                                if (!previousPendingRequestsIds.includes(uid)) {
                                    showToast(`New friend request from ${u.username}!`, 'success');
                                    
                                    // Dynamically refresh the pending list UI if it's currently visible
                                    const listEl = document.getElementById('friends-requests-list');
                                    if (listEl && listEl.offsetHeight > 0) {
                                        loadTabPending();
                                    }
                                }
                            });
                            previousPendingRequestsIds = currentIds;
                        } else {
                            previousPendingRequestsIds = pending.map(u => parseInt(u.id));
                        }
                        previousPendingRequestsCount = currentCount;
                    }
                })
                .catch(err => console.error("Friend request poll failed:", err));

            // 2. Fetch unread DMs count
            fetch(`/portal/portal_messages_api.php?action=fetch_global_unread&t=${Date.now()}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const currentUnread = parseInt(res.unread_count || 0);
                        
                        // Update badge element
                        const inboxBadge = document.getElementById('inbox-tab-badge');
                        if (inboxBadge) {
                            inboxBadge.innerText = currentUnread;
                            inboxBadge.style.display = currentUnread > 0 ? 'inline-block' : 'none';
                        }
                        
                        const inboxTab = document.getElementById('tab-inbox');
                        const isInboxActive = inboxTab && inboxTab.classList.contains('active');
                        
                        if (previousUnreadDMsCount !== null && currentUnread > previousUnreadDMsCount) {
                            if (!isInboxActive || !currentActivePartner) {
                                showToast("You received a new message!", "info");
                            } else {
                                // Fetch conversations to see if the unread is from someone else
                                fetch(`/portal/portal_messages_api.php?action=fetch_conversations&t=${Date.now()}`)
                                    .then(r => r.json())
                                    .then(convosRes => {
                                        if (convosRes.success) {
                                            const allConvos = [...convosRes.primary, ...convosRes.general];
                                            let hasOtherUnread = false;
                                            let senderName = "someone";
                                            for (let c of allConvos) {
                                                if (parseInt(c.partner_id) !== currentActivePartner && parseInt(c.unread_count) > 0) {
                                                    hasOtherUnread = true;
                                                    senderName = c.partner_username;
                                                    break;
                                                }
                                            }
                                            if (hasOtherUnread) {
                                                showToast(`New message from ${senderName}!`, "info");
                                            }
                                        }
                                    });
                            }
                            
                            if (isInboxActive) {
                                loadConversations();
                            }
                        }
                        
                        previousUnreadDMsCount = currentUnread;
                    }
                })
                .catch(err => console.error("DM unread poll failed:", err));
        }

        function startGlobalPolling() {
            runGlobalPoll();
            setInterval(runGlobalPoll, 4000);
        }

        function formatConvoTime(dateStr) {
            const d = new Date(dateStr);
            const now = new Date();
            if (d.toDateString() === now.toDateString()) {
                return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }
            return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
        }

        function formatBubbleTime(dateStr) {
            const d = new Date(dateStr);
            return d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        }

        function filterConversations() {
            const query = document.getElementById('dm-search-input').value.toLowerCase();
            document.querySelectorAll('.dm-convo-item').forEach(item => {
                const name = item.querySelector('.dm-convo-name').innerText.toLowerCase();
                const last = item.querySelector('.dm-convo-lastmsg').innerText.toLowerCase();
                if (name.includes(query) || last.includes(query)) {
                    item.style.display = 'grid';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function escapeHtml(text) {
            if (!text) return '';
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return text.replace(/[&<>"']/g, m => map[m]);
        }

        function startDMPolling() {
            stopDMPolling();
            dmPollingInterval = setInterval(() => {
                loadConversations();
                if (currentActivePartner) {
                    // Update current thread (silently without resetting inputs)
                    fetch(`/portal/portal_messages_api.php?action=fetch_thread&partner_id=${currentActivePartner}&t=${Date.now()}`)
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                const msgContainer = document.getElementById('dm-messages-container');
                                if (!msgContainer) return;
                                
                                const countBefore = msgContainer.childElementCount;
                                if (res.messages.length > countBefore) {
                                    // Append only new messages
                                    msgContainer.innerHTML = '';
                                    res.messages.forEach(m => {
                                        const isSent = parseInt(m.sender_id) === <?= (int)$user_id ?>;
                                        const isStarred = isSent ? parseInt(m.is_flagged_by_sender) : parseInt(m.is_flagged_by_recipient);
                                        const starClass = isStarred ? 'starred' : '';
                                        const bubbleHtml = `
                                            <div class="dm-msg-bubble ${isSent ? 'sent' : 'received'}">
                                                <div style="display:flex; justify-content:space-between; gap:2rem; align-items:flex-start;">
                                                    <div style="white-space:pre-wrap;">${escapeHtml(m.message)}</div>
                                                    <span style="cursor:pointer; font-size:0.8rem; margin-top:-2px;" onclick="toggleDMStar(${m.id}, this)" class="${starClass}">
                                                        ${isStarred ? '★' : '☆'}
                                                    </span>
                                                </div>
                                                <div class="dm-msg-info">
                                                    ${formatBubbleTime(m.created_at)}
                                                </div>
                                            </div>
                                        `;
                                        msgContainer.insertAdjacentHTML('beforeend', bubbleHtml);
                                    });
                                    msgContainer.scrollTop = msgContainer.scrollHeight;
                                }
                            }
                        });
                }
            }, 3000);
        }

        function stopDMPolling() {
            if (dmPollingInterval) {
                clearInterval(dmPollingInterval);
                dmPollingInterval = null;
            }
        }

        function closeMobileThread() {
            const clientGrid = document.querySelector('.dm-client-grid');
            if (clientGrid) {
                clientGrid.classList.remove('show-thread');
            }
            currentActivePartner = null;
            document.querySelectorAll('.dm-convo-item').forEach(el => el.classList.remove('active'));
        }

        function toggleMobileSidebar(show) {
            const clientGrid = document.querySelector('.dm-client-grid');
            if (clientGrid) {
                if (show) {
                    clientGrid.classList.add('show-sidebar');
                    clientGrid.classList.remove('show-thread');
                } else {
                    clientGrid.classList.remove('show-sidebar');
                }
            }
        }

        // Initialize polling or lists if Inbox or Friends tab starts active
        document.addEventListener('DOMContentLoaded', () => {
            // Start the global polling loop for real-time background notification toasts & badge syncing
            startGlobalPolling();

            const activeTabBtn = document.querySelector('.portal-tab-btn.active');
            if (activeTabBtn) {
                const tabText = activeTabBtn.innerText.toLowerCase();
                if (tabText.includes('inbox')) {
                    loadConversations();
                    loadFriendsList();
                    startDMPolling();
                } else if (tabText.includes('friends')) {
                    initFriendsTab();
                }
            }

            // Site-wide header mobile toggle
            const ham = document.getElementById('navHamburger');
            const nl = document.getElementById('navLinks');
            if (ham && nl) {
                ham.addEventListener('click', () => {
                    ham.classList.toggle('open');
                    nl.classList.toggle('open');
                });
            }
        });
    </script>
</body>
</html>
