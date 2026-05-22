<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = get_pdo();
$site = get_site_content($pdo);
log_visitor($pdo, 'page_view', '/forum.php');

// Require login to browse the forum
require_client_login();

$user_id = $_SESSION['user_id'];
$u_stmt = $pdo->prepare('SELECT username, role, profile_picture FROM users WHERE id = ?');
$u_stmt->execute([$user_id]);
$current_user = $u_stmt->fetch();

// Format time ago helper
function format_time_ago($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    if ($diff < 60) return 'Just now';
    $intervals = [
        31536000 => 'year',
        2592000  => 'month',
        604800   => 'week',
        86400    => 'day',
        3600     => 'hour',
        60       => 'minute'
    ];
    foreach ($intervals as $secs => $label) {
        $div = $diff / $secs;
        if ($div >= 1) {
            $round = round($div);
            return $round . ' ' . $label . ($round > 1 ? 's' : '') . ' ago';
        }
    }
    return date('M d, Y', $time);
}

// Avatar HTML Helper
function get_forum_avatar($profile_picture, $username, $size = 40) {
    if ($profile_picture) {
        return '<img src="/static/uploads/' . htmlspecialchars($profile_picture, ENT_QUOTES, 'UTF-8') . '" style="width:' . $size . 'px; height:' . $size . 'px; border-radius:50%; object-fit:cover; border:1px solid var(--accent); box-shadow:0 0 8px rgba(192,21,42,0.4); cursor:pointer;" class="forum-avatar-img">';
    } else {
        return '<div style="width:' . $size . 'px; height:' . $size . 'px; border-radius:50%; background:rgba(255,255,255,0.05); border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; font-family:\'Syncopate\',sans-serif; font-size:' . ($size * 0.4) . 'px; font-weight:700; color:#fff; text-shadow:0 0 5px rgba(255,255,255,0.2); cursor:pointer;" class="forum-avatar-placeholder">' . strtoupper(substr($username, 0, 1)) . '</div>';
    }
}

// Fetch user profile statistics helper for popups and thread cards
function get_user_stats($pdo, $uid) {
    $t_stmt = $pdo->prepare('SELECT COUNT(*) FROM forum_threads WHERE user_id = ?');
    $t_stmt->execute([$uid]);
    $threads = (int)$t_stmt->fetchColumn();

    $r_stmt = $pdo->prepare('SELECT COUNT(*) FROM forum_replies WHERE user_id = ?');
    $r_stmt->execute([$uid]);
    $replies = (int)$r_stmt->fetchColumn();

    return ['threads' => $threads, 'replies' => $replies];
}

// Handle Form Submissions
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_thread') {
        $category_id = (int)($_POST['category_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        
        if ($category_id <= 0) {
            $error = 'Invalid category selected.';
        } elseif (empty($title)) {
            $error = 'Thread title cannot be empty.';
        } elseif (empty($content)) {
            $error = 'Thread content cannot be empty.';
        } else {
            $ins = $pdo->prepare('INSERT INTO forum_threads (category_id, user_id, title, content) VALUES (?, ?, ?, ?)');
            $ins->execute([$category_id, $user_id, $title, $content]);
            $new_thread_id = $pdo->lastInsertId();
            log_action($pdo, "User {$_SESSION['username']} created forum thread ID {$new_thread_id}");
            header("Location: /forum.php?thread_id={$new_thread_id}");
            exit;
        }
    }
    
    elseif ($_POST['action'] === 'create_reply') {
        $thread_id = (int)($_POST['thread_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        
        if ($thread_id <= 0) {
            $error = 'Invalid thread selected.';
        } elseif (empty($content)) {
            $error = 'Reply content cannot be empty.';
        } else {
            $ins = $pdo->prepare('INSERT INTO forum_replies (thread_id, user_id, content) VALUES (?, ?, ?)');
            $ins->execute([$thread_id, $user_id, $content]);
            log_action($pdo, "User {$_SESSION['username']} replied to thread ID {$thread_id}");
            header("Location: /forum.php?thread_id={$thread_id}#reply-section");
            exit;
        }
    }
}

// Determine current view
$category_id = (int)($_GET['category_id'] ?? 0);
$thread_id = (int)($_GET['thread_id'] ?? 0);

$view = 'dashboard';
$category = null;
$thread = null;

if ($thread_id > 0) {
    // Thread view
    $t_stmt = $pdo->prepare('
        SELECT t.*, u.username, u.profile_picture, u.role
        FROM forum_threads t
        JOIN users u ON u.id = t.user_id
        WHERE t.id = ?
    ');
    $t_stmt->execute([$thread_id]);
    $thread = $t_stmt->fetch();
    
    if ($thread) {
        $view = 'thread';
        // Fetch thread replies
        $r_stmt = $pdo->prepare('
            SELECT r.*, u.username, u.profile_picture, u.role
            FROM forum_replies r
            JOIN users u ON u.id = r.user_id
            WHERE r.thread_id = ?
            ORDER BY r.id ASC
        ');
        $r_stmt->execute([$thread_id]);
        $replies = $r_stmt->fetchAll();
        
        // Fetch parent category
        $cat_stmt = $pdo->prepare('SELECT * FROM forum_categories WHERE id = ?');
        $cat_stmt->execute([$thread['category_id']]);
        $category = $cat_stmt->fetch();
    } else {
        $thread_id = 0;
    }
}

if ($thread_id <= 0 && $category_id > 0) {
    // Category view
    $cat_stmt = $pdo->prepare('SELECT * FROM forum_categories WHERE id = ?');
    $cat_stmt->execute([$category_id]);
    $category = $cat_stmt->fetch();
    
    if ($category) {
        $view = 'category';
        // Fetch threads under this category
        $t_stmt = $pdo->prepare('
            SELECT t.*, u.username, u.profile_picture,
                   (SELECT COUNT(*) FROM forum_replies r WHERE r.thread_id = t.id) AS replies_count,
                   (
                       SELECT COALESCE(MAX(r.created_at), t.created_at)
                       FROM forum_replies r
                       WHERE r.thread_id = t.id
                   ) AS last_active
            FROM forum_threads t
            JOIN users u ON u.id = t.user_id
            WHERE t.category_id = ?
            ORDER BY last_active DESC
        ');
        $t_stmt->execute([$category_id]);
        $threads = $t_stmt->fetchAll();
    } else {
        $category_id = 0;
    }
}

if ($view === 'dashboard') {
    // Dashboard view - fetch categories with metrics & last active post details
    $cats_stmt = $pdo->query('SELECT * FROM forum_categories ORDER BY position ASC');
    $categories = $cats_stmt->fetchAll();
    
    foreach ($categories as &$cat) {
        // Count threads
        $t_stmt = $pdo->prepare('SELECT COUNT(*) FROM forum_threads WHERE category_id = ?');
        $t_stmt->execute([$cat['id']]);
        $cat['threads_count'] = (int)$t_stmt->fetchColumn();

        // Count replies
        $r_stmt = $pdo->prepare('
            SELECT COUNT(r.id) 
            FROM forum_replies r
            JOIN forum_threads t ON t.id = r.thread_id
            WHERE t.category_id = ?
        ');
        $r_stmt->execute([$cat['id']]);
        $cat['replies_count'] = (int)$r_stmt->fetchColumn();

        // Fetch last active post details (either last thread or last reply)
        $last_stmt = $pdo->prepare('
            SELECT 
                t.id AS thread_id, 
                t.title AS thread_title, 
                COALESCE(r.created_at, t.created_at) AS active_at,
                COALESCE(ru.username, tu.username) AS username,
                COALESCE(ru.profile_picture, tu.profile_picture) AS profile_picture
            FROM forum_threads t
            JOIN users tu ON tu.id = t.user_id
            LEFT JOIN forum_replies r ON r.thread_id = t.id
            LEFT JOIN users ru ON ru.id = r.user_id
            WHERE t.category_id = ?
            ORDER BY active_at DESC
            LIMIT 1
        ');
        $last_stmt->execute([$cat['id']]);
        $cat['last_post'] = $last_stmt->fetch();
    }
    unset($cat);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Community Forum - N2L8 STUDIO</title>
    <meta name="description" content="N2L8 Studio premium discussion boards and producer circle.">
    <link rel="stylesheet" href="/static/style.css?v=3">
    <link rel="icon" type="image/png" href="/static/logo.png">
    <link rel="apple-touch-icon" href="/static/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* ── SPECIALIZED FORUM STYLES ── */
        .page-forum {
            padding-bottom: 5rem;
        }
        
        .forum-hero {
            padding: 3rem 0;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
            background: linear-gradient(180deg, rgba(192, 21, 42, 0.04) 0%, transparent 100%);
            margin-bottom: 3rem;
        }
        .forum-hero h1 {
            font-size: 2.2rem;
            margin-bottom: 0.8rem;
            text-shadow: 0 0 20px rgba(192, 21, 42, 0.35);
        }
        .forum-hero p {
            color: var(--text-muted);
            max-width: 600px;
            margin: 0 auto;
            font-size: 0.95rem;
            font-weight: 500;
        }

        .forum-container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        .forum-breadcrumb {
            margin-bottom: 2rem;
        }
        .forum-breadcrumb a {
            font-family: 'Syncopate', sans-serif;
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--text-muted);
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .forum-breadcrumb a:hover {
            color: var(--accent);
            text-shadow: var(--accent-glow);
            transform: translateX(-4px);
        }

        /* ── DASHBOARD: CATEGORY CARDS ── */
        .forum-cats-list {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        .forum-cat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 2rem;
            display: grid;
            grid-template-columns: 1fr 180px 280px;
            align-items: center;
            gap: 2rem;
            transition: all 0.3s ease;
        }
        .forum-cat-card:hover {
            border-color: rgba(192, 21, 42, 0.5);
            box-shadow: 0 0 25px rgba(192, 21, 42, 0.08), 0 0 8px rgba(192, 21, 42, 0.15);
            transform: translateY(-2px);
        }
        .forum-cat-info h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }
        .forum-cat-info h3 a {
            color: #ffffff;
            text-decoration: none;
            transition: color 0.25s;
        }
        .forum-cat-info h3 a:hover {
            color: var(--accent);
            text-shadow: var(--accent-glow);
        }
        .forum-cat-info p {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .forum-cat-stats {
            display: flex;
            gap: 1.5rem;
            justify-content: center;
        }
        .forum-cat-stat {
            text-align: center;
        }
        .forum-cat-stat-num {
            font-family: 'Syncopate', sans-serif;
            font-size: 1.1rem;
            color: #ffffff;
            font-weight: 700;
        }
        .forum-cat-stat-lbl {
            font-size: 0.6rem;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 0.2rem;
        }

        .forum-cat-last-post {
            border-left: 1px solid rgba(255, 255, 255, 0.05);
            padding-left: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.8rem;
        }
        .forum-cat-last-post-details {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
            max-width: 200px;
        }
        .forum-last-post-title {
            color: #ffffff;
            text-decoration: none;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: color 0.2s;
        }
        .forum-last-post-title:hover {
            color: var(--accent);
        }
        .forum-last-post-meta {
            color: var(--text-muted);
            font-size: 0.72rem;
            font-weight: 500;
        }
        .forum-last-post-meta span {
            color: #ffffff;
            cursor: pointer;
            font-weight: 600;
            transition: color 0.2s;
        }
        .forum-last-post-meta span:hover {
            color: var(--accent);
        }
        .forum-cat-no-post {
            color: var(--text-muted);
            font-style: italic;
            font-size: 0.8rem;
        }

        @media (max-width: 900px) {
            .forum-cat-card {
                grid-template-columns: 1fr;
                gap: 1.2rem;
            }
            .forum-cat-stats {
                justify-content: flex-start;
            }
            .forum-cat-last-post {
                border-left: none;
                border-top: 1px solid rgba(255, 255, 255, 0.05);
                padding-left: 0;
                padding-top: 1.2rem;
            }
        }

        /* ── CATEGORY VIEW: THREADS LIST ── */
        .forum-view-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 2.5rem;
            border-bottom: 1px dashed rgba(192, 21, 42, 0.15);
            padding-bottom: 1.5rem;
        }
        .forum-view-header h2 {
            font-size: 1.6rem;
            text-shadow: 0 0 15px rgba(192, 21, 42, 0.25);
            margin-bottom: 0.4rem;
        }
        .forum-view-header p {
            color: var(--text-muted);
            font-size: 0.88rem;
            font-weight: 500;
        }

        .forum-threads-list {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 3.5rem;
        }
        .forum-thread-row {
            display: grid;
            grid-template-columns: 60px 1fr 120px 220px;
            align-items: center;
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            transition: background 0.2s;
        }
        .forum-thread-row:last-child {
            border-bottom: none;
        }
        .forum-thread-row:hover {
            background: rgba(255, 255, 255, 0.015);
        }
        .forum-thread-title-col h4 {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
            text-transform: none;
            letter-spacing: 0;
        }
        .forum-thread-title-col h4 a {
            color: #ffffff;
            text-decoration: none;
            transition: color 0.2s;
        }
        .forum-thread-title-col h4 a:hover {
            color: var(--accent);
            text-shadow: var(--accent-glow);
        }
        .forum-thread-meta {
            font-size: 0.72rem;
            color: var(--text-muted);
            font-weight: 500;
        }
        .forum-thread-meta span {
            color: #ffffff;
            font-weight: 600;
            cursor: pointer;
            transition: color 0.2s;
        }
        .forum-thread-meta span:hover {
            color: var(--accent);
        }

        .forum-thread-replies-col {
            text-align: center;
        }
        .forum-replies-badge {
            display: inline-block;
            background: rgba(192, 21, 42, 0.08);
            border: 1px solid var(--border-color);
            color: #ffffff;
            font-size: 0.75rem;
            font-weight: 700;
            font-family: 'Syncopate', sans-serif;
            padding: 4px 10px;
            border-radius: 4px;
            transition: all 0.25s;
        }
        .forum-thread-row:hover .forum-replies-badge {
            background: var(--accent);
            box-shadow: var(--accent-glow);
        }

        .forum-thread-activity-col {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 500;
            text-align: right;
            border-left: 1px solid rgba(255, 255, 255, 0.03);
            padding-left: 1.5rem;
        }

        .forum-no-threads {
            padding: 3.5rem;
            text-align: center;
            color: var(--text-muted);
            font-style: italic;
            font-size: 0.9rem;
        }

        @media (max-width: 800px) {
            .forum-thread-row {
                grid-template-columns: 50px 1fr 80px;
                gap: 1rem;
                padding: 1rem;
            }
            .forum-thread-activity-col {
                display: none;
            }
        }

        /* ── CREATORS INFO & USER BLOCKS ── */
        .user-block {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 0.5rem;
            padding-right: 1.5rem;
            border-right: 1px solid rgba(255, 255, 255, 0.04);
            min-width: 140px;
        }
        .user-block-name {
            font-family: 'Syncopate', sans-serif;
            font-size: 0.75rem;
            font-weight: 700;
            color: #ffffff;
            cursor: pointer;
            letter-spacing: 0.05em;
            transition: color 0.2s;
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .user-block-name:hover {
            color: var(--accent);
            text-shadow: var(--accent-glow);
        }
        .user-block-role {
            font-size: 0.55rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--accent);
            background: rgba(192, 21, 42, 0.08);
            border: 1px solid var(--border-color);
            padding: 2px 6px;
            border-radius: 2px;
        }
        .user-block-stats {
            font-size: 0.65rem;
            color: var(--text-muted);
            font-weight: 500;
            margin-top: 0.4rem;
            line-height: 1.4;
        }

        /* ── THREAD VIEW: MESSAGES ── */
        .forum-thread-title-area {
            margin-bottom: 2rem;
            border-bottom: 1px dashed rgba(192, 21, 42, 0.15);
            padding-bottom: 1.5rem;
        }
        .forum-thread-title-area h2 {
            font-size: 1.7rem;
            text-shadow: 0 0 20px rgba(192, 21, 42, 0.35);
            margin-bottom: 0.3rem;
            text-transform: none;
            letter-spacing: 0;
        }
        .forum-thread-title-area p {
            color: var(--text-muted);
            font-size: 0.78rem;
            font-weight: 500;
        }

        .forum-post-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            display: grid;
            grid-template-columns: 180px 1fr;
            padding: 2rem;
            gap: 2rem;
            margin-bottom: 2rem;
            position: relative;
        }
        .forum-post-card.op-card {
            border-left: 3px solid var(--accent);
            box-shadow: var(--accent-glow);
        }
        .forum-post-content {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .forum-post-text {
            font-size: 0.92rem;
            color: #ffffff;
            line-height: 1.7;
            white-space: pre-wrap;
            font-family: 'Montserrat', sans-serif;
            font-weight: 400;
        }
        .forum-post-footer {
            margin-top: 2rem;
            font-size: 0.72rem;
            color: var(--text-muted);
            display: flex;
            justify-content: space-between;
            border-top: 1px solid rgba(255, 255, 255, 0.03);
            padding-top: 0.8rem;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .forum-post-card {
                grid-template-columns: 1fr;
                gap: 1.5rem;
                padding: 1.5rem;
            }
            .user-block {
                flex-direction: row;
                text-align: left;
                padding-right: 0;
                border-right: none;
                border-bottom: 1px solid rgba(255, 255, 255, 0.04);
                padding-bottom: 1rem;
                justify-content: flex-start;
                align-items: center;
                gap: 1.2rem;
            }
            .user-block-stats {
                display: none;
            }
        }

        /* ── EDITORS (NEW THREAD / REPLY) ── */
        .forum-editor-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 2.2rem;
            margin-top: 3.5rem;
            box-shadow: 0 0 25px rgba(192, 21, 42, 0.04);
        }
        .forum-editor-card h3 {
            font-size: 1.1rem;
            margin-bottom: 1.5rem;
            text-shadow: 0 0 10px rgba(192, 21, 42, 0.25);
        }
        .forum-form-group {
            margin-bottom: 1.5rem;
        }
        .forum-form-group label {
            display: block;
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.6rem;
        }
        .forum-form-input {
            width: 100%;
            background: #000000;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            color: #ffffff;
            padding: 0.8rem 1rem;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.9rem;
            font-weight: 500;
            outline: none;
            transition: all 0.25s ease;
        }
        .forum-form-input:focus {
            border-color: var(--accent);
            box-shadow: var(--accent-glow);
        }
        textarea.forum-form-input {
            resize: vertical;
            min-height: 140px;
            line-height: 1.6;
        }
        
        .forum-error-banner {
            background: rgba(192, 21, 42, 0.12);
            border: 1px solid var(--accent);
            color: #ff8c94;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* ── GLOBAL SOCIAL MODALS ── */
        .profile-modal {
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
        .profile-modal.open {
            opacity: 1;
            pointer-events: auto;
        }
        .profile-modal-card {
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
        .profile-modal.open .profile-modal-card {
            transform: scale(1);
        }
        .profile-modal-close {
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
        .profile-modal-close:hover {
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
    </style>
</head>
<body class="page-forum">
    <!-- NAVIGATION BAR -->
    <header class="hero" style="min-height: auto; padding-bottom: 0;">
        <nav>
            <a href="/index.php" class="logo-text" style="text-decoration:none;">N<span>2</span>L8studios</a>
            <button class="nav-hamburger" id="navHamburger" aria-label="Menu">
                <span></span><span></span><span></span>
            </button>
            <ul class="nav-links" id="navLinks">
                <li class="dropdown">
                    <a href="javascript:void(0)" class="dropbtn">Shop</a>
                    <div class="dropdown-content">
                        <a href="/shop.php">Kits</a>
                        <a href="/graphics.php">Graphics</a>
                        <a href="/beats.php">Beats</a>
                    </div>
                </li>
                <li><a href="/pricing.php">Services</a></li>
                <li><a href="/forum.php" class="active" style="color: #fff; text-shadow: var(--accent-glow);">Forum</a></li>
                
                <li class="dropdown">
                    <a href="javascript:void(0)" class="dropbtn" style="color: var(--accent); display: inline-flex; align-items: center; gap: 4px; padding-top: 4px; padding-bottom: 4px;">
                        <?= get_user_avatar_nav($pdo) ?>
                        <span>Portal</span>
                    </a>
                    <div class="dropdown-content">
                        <a href="/portal/index.php?tab=settings">Account Settings</a>
                        <a href="/portal/index.php">Client Portal</a>
                        <?php if (is_owner()): ?>
                            <a href="/admin/index.php">Admin Portal</a>
                        <?php endif; ?>
                        <a href="/logout.php" style="color: var(--accent) !important;">Disconnect</a>
                    </div>
                </li>
            </ul>
        </nav>
    </header>

    <!-- FORUM HERO HEADER -->
    <div class="forum-hero">
        <div class="forum-container">
            <h1>COMMUNITY DISCUSSION BOARDS</h1>
            <p>Connect, share knowledge, download drumkits, collaborate on beats, and show off your final mixes with members worldwide.</p>
        </div>
    </div>

    <!-- MAIN FORUM CONTAINER -->
    <div class="forum-container">
        
        <?php if (!empty($error)): ?>
            <div class="forum-error-banner"><?= h($error) ?></div>
        <?php endif; ?>

        <!-- ── VIEW: MAIN DASHBOARD (CATEGORIES) ── -->
        <?php if ($view === 'dashboard'): ?>
            <div class="forum-cats-list">
                <?php foreach ($categories as $cat): ?>
                    <div class="forum-cat-card">
                        <div class="forum-cat-info">
                            <h3><a href="/forum.php?category_id=<?= $cat['id'] ?>"><?= h($cat['name']) ?></a></h3>
                            <p><?= h($cat['description']) ?></p>
                        </div>
                        <div class="forum-cat-stats">
                            <div class="forum-cat-stat">
                                <div class="forum-cat-stat-num"><?= $cat['threads_count'] ?></div>
                                <div class="forum-cat-stat-lbl">Threads</div>
                            </div>
                            <div class="forum-cat-stat">
                                <div class="forum-cat-stat-num"><?= $cat['replies_count'] ?></div>
                                <div class="forum-cat-stat-lbl">Replies</div>
                            </div>
                        </div>
                        <div class="forum-cat-last-post">
                            <?php if (!empty($cat['last_post'])): ?>
                                <div onclick="openUserProfile(<?= (int)$cat['last_post']['thread_id'] ? (int)get_thread_user_id($pdo, $cat['last_post']['thread_id']) : 0 ?>)">
                                    <?= get_forum_avatar($cat['last_post']['profile_picture'], $cat['last_post']['username'], 36) ?>
                                </div>
                                <div class="forum-cat-last-post-details">
                                    <a href="/forum.php?thread_id=<?= $cat['last_post']['thread_id'] ?>" class="forum-last-post-title" title="<?= h($cat['last_post']['thread_title']) ?>"><?= h($cat['last_post']['thread_title']) ?></a>
                                    <div class="forum-last-post-meta">
                                        Active <?= format_time_ago($cat['last_post']['active_at']) ?><br>
                                        by <span onclick="openUserProfile(<?= (int)get_user_id_by_username($pdo, $cat['last_post']['username']) ?>)"><?= h($cat['last_post']['username']) ?></span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="forum-cat-no-post">No discussions yet</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <!-- ── VIEW: CATEGORY (THREADS LISTING) ── -->
        <?php elseif ($view === 'category'): ?>
            <div class="forum-breadcrumb">
                <a href="/forum.php">← Back to Categories</a>
            </div>

            <div class="forum-view-header">
                <div>
                    <h2><?= h($category['name']) ?></h2>
                    <p><?= h($category['description']) ?></p>
                </div>
            </div>

            <div class="forum-threads-list">
                <?php if (!empty($threads)): ?>
                    <?php foreach ($threads as $t): ?>
                        <div class="forum-thread-row">
                            <div onclick="openUserProfile(<?= $t['user_id'] ?>)">
                                <?= get_forum_avatar($t['profile_picture'], $t['username'], 38) ?>
                            </div>
                            <div class="forum-thread-title-col">
                                <h4><a href="/forum.php?thread_id=<?= $t['id'] ?>"><?= h($t['title']) ?></a></h4>
                                <div class="forum-thread-meta">
                                    Started by <span onclick="openUserProfile(<?= $t['user_id'] ?>)"><?= h($t['username']) ?></span> &bull; <?= format_time_ago($t['created_at']) ?>
                                </div>
                            </div>
                            <div class="forum-thread-replies-col">
                                <span class="forum-replies-badge"><?= $t['replies_count'] ?> replies</span>
                            </div>
                            <div class="forum-thread-activity-col">
                                Last active<br><strong><?= format_time_ago($t['last_active']) ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="forum-no-threads">No threads started in this category yet. Be the first to start a discussion!</div>
                <?php endif; ?>
            </div>

            <!-- CREATE THREAD FORM -->
            <div class="forum-editor-card">
                <h3>START A NEW DISCUSSION</h3>
                <form action="/forum.php?category_id=<?= $category_id ?>" method="POST">
                    <input type="hidden" name="action" value="create_thread">
                    <input type="hidden" name="category_id" value="<?= $category_id ?>">
                    
                    <div class="forum-form-group">
                        <label for="thread-title">Topic Title</label>
                        <input type="text" id="thread-title" name="title" class="forum-form-input" placeholder="What are we talking about?" required>
                    </div>

                    <div class="forum-form-group">
                        <label for="thread-content">Discussion Content</label>
                        <textarea id="thread-content" name="content" class="forum-form-input" placeholder="Write your topic post details here... Use plain text or spacing." required></textarea>
                    </div>

                    <button type="submit" class="cta-btn" style="border:none;">POST NEW THREAD</button>
                </form>
            </div>

        <!-- ── VIEW: THREAD (OP + REPLIES CHRONO) ── -->
        <?php elseif ($view === 'thread'): ?>
            <div class="forum-breadcrumb">
                <a href="/forum.php?category_id=<?= $category['id'] ?>">← Back to <?= h($category['name']) ?></a>
            </div>

            <div class="forum-thread-title-area">
                <h2><?= h($thread['title']) ?></h2>
                <p>In category: <strong><?= h($category['name']) ?></strong> &bull; Started <?= format_time_ago($thread['created_at']) ?></p>
            </div>

            <div class="forum-thread-conversation">
                <!-- ORIGINAL POST CARD -->
                <div class="forum-post-card op-card">
                    <div class="user-block">
                        <div onclick="openUserProfile(<?= $thread['user_id'] ?>)">
                            <?= get_forum_avatar($thread['profile_picture'], $thread['username'], 60) ?>
                        </div>
                        <div class="user-block-name" onclick="openUserProfile(<?= $thread['user_id'] ?>)"><?= h($thread['username']) ?></div>
                        <span class="user-block-role"><?= h($thread['role']) ?></span>
                        <div class="user-block-stats">
                            <?php $stats = get_user_stats($pdo, $thread['user_id']); ?>
                            Threads: <?= $stats['threads'] ?><br>
                            Replies: <?= $stats['replies'] ?>
                        </div>
                    </div>
                    <div class="forum-post-content">
                        <div class="forum-post-text"><?= nl2br(h($thread['content'])) ?></div>
                        <div class="forum-post-footer">
                            <div>Original Post</div>
                            <div><?= date('F j, Y, g:i a', strtotime($thread['created_at'])) ?></div>
                        </div>
                    </div>
                </div>

                <!-- CHRONOLOGICAL REPLIES -->
                <div id="reply-section">
                    <?php if (!empty($replies)): ?>
                        <?php foreach ($replies as $index => $rep): ?>
                            <div class="forum-post-card">
                                <div class="user-block">
                                    <div onclick="openUserProfile(<?= $rep['user_id'] ?>)">
                                        <?= get_forum_avatar($rep['profile_picture'], $rep['username'], 60) ?>
                                    </div>
                                    <div class="user-block-name" onclick="openUserProfile(<?= $rep['user_id'] ?>)"><?= h($rep['username']) ?></div>
                                    <span class="user-block-role"><?= h($rep['role']) ?></span>
                                    <div class="user-block-stats">
                                        <?php $stats = get_user_stats($pdo, $rep['user_id']); ?>
                                        Threads: <?= $stats['threads'] ?><br>
                                        Replies: <?= $stats['replies'] ?>
                                    </div>
                                </div>
                                <div class="forum-post-content">
                                    <div class="forum-post-text"><?= nl2br(h($rep['content'])) ?></div>
                                    <div class="forum-post-footer">
                                        <div>Reply #<?= $index + 1 ?></div>
                                        <div><?= date('F j, Y, g:i a', strtotime($rep['created_at'])) ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- REPLY EDITOR CARD -->
                <div class="forum-editor-card">
                    <h3>POST A REPLY</h3>
                    <form action="/forum.php?thread_id=<?= $thread_id ?>" method="POST">
                        <input type="hidden" name="action" value="create_reply">
                        <input type="hidden" name="thread_id" value="<?= $thread_id ?>">

                        <div class="forum-form-group">
                            <label for="reply-content">Your Reply</label>
                            <textarea id="reply-content" name="content" class="forum-form-input" placeholder="Type your reply to this topic discussion..." required></textarea>
                        </div>

                        <button type="submit" class="cta-btn" style="border:none;">SUBMIT REPLY</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

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

    <!-- FOOTER -->
    <footer style="margin-top: 5rem; padding: 2rem 0; border-top: 1px solid rgba(255, 255, 255, 0.05); text-align: center;">
        <p><?= h($site['footer_text'] ?? '© 2026 n2l8studio. All rights reserved.') ?></p>
    </footer>

    <!-- INTERACTIVE JAVASCRIPT FOR PROFILE MODALS & INTERACTION -->
    <script>
        // Hamburger toggle for mobile
        const ham = document.getElementById('navHamburger');
        const nl  = document.getElementById('navLinks');
        if (ham) {
            ham.addEventListener('click', () => { ham.classList.toggle('open'); nl.classList.toggle('open'); });
        }

        // Dropdown toggle for mobile
        const dropbtns = document.querySelectorAll('.dropbtn');
        dropbtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropbtns.forEach(other => {
                    if (other !== btn) {
                        const sibling = other.nextElementSibling;
                        if (sibling && sibling.classList.contains('dropdown-content')) {
                            sibling.classList.remove('show');
                        }
                    }
                });
                const sibling = btn.nextElementSibling;
                if (sibling && sibling.classList.contains('dropdown-content')) {
                    sibling.classList.toggle('show');
                }
            });
        });

        // Close dropdown when clicking outside
        window.onclick = function(event) {
            if (!event.target.matches('.dropbtn')) {
                const dropdowns = document.getElementsByClassName("dropdown-content");
                for (let i = 0; i < dropdowns.length; i++) {
                    const openDropdown = dropdowns[i];
                    if (openDropdown.classList.contains('show')) {
                        openDropdown.classList.remove('show');
                    }
                }
            }
        }

        // ── USER PROFILE MODAL (GLOBAL POPUP) ──

        function openUserProfile(userId) {
            if (userId <= 0) return;
            fetch(`/portal/friends_api.php?action=get_profile&user_id=${userId}`)
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
                        alert("Error loading profile: " + res.error);
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
                        // Refresh profile modal
                        openUserProfile(userId);
                    } else {
                        alert("Action failed: " + res.error);
                    }
                });
        }

        function sendProfileModalQuickDM() {
            const btn = document.getElementById('pm-dm-send-btn');
            const recId = btn.getAttribute('data-recipient-id');
            const recName = btn.getAttribute('data-recipient-name');
            const text = document.getElementById('pm-dm-text').value.trim();

            if (!text) {
                alert("Message cannot be empty.");
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
                        alert("Message sent successfully!");
                        closeUserProfile();
                    } else {
                        alert("Failed to send message: " + res.error);
                    }
                });
        }
    </script>
</body>
</html>
<?php
// Core backend SQL helpers specifically for the forum page
function get_thread_user_id(PDO $pdo, $thread_id) {
    try {
        $stmt = $pdo->prepare('SELECT user_id FROM forum_threads WHERE id = ?');
        $stmt->execute([$thread_id]);
        return (int)$stmt->fetchColumn();
    } catch(Throwable $e) {
        return 0;
    }
}

function get_user_id_by_username(PDO $pdo, $username) {
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        return (int)$stmt->fetchColumn();
    } catch(Throwable $e) {
        return 0;
    }
}
?>
