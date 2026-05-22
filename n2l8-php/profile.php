<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();
if (is_owner()) {
    redirect('/admin/index.php');
}

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
if (!$user) {
    session_destroy();
    redirect('/login.php');
}

$saved_stmt = $pdo->prepare(
    "SELECT p.*, s.created_at AS saved_at
     FROM user_saved_products s
     JOIN products p ON p.id = s.product_id
     WHERE s.user_id = ?
     ORDER BY s.created_at DESC"
);
$saved_stmt->execute([$user['id']]);
$saved_products = $saved_stmt->fetchAll();

$history_stmt = $pdo->prepare(
    "SELECT a.*, p.title, p.type, p.cover_image, p.price
     FROM user_activity a
     LEFT JOIN products p ON p.id = a.product_id
     WHERE a.user_id = ? AND a.action IN ('view_product', 'save_product', 'unsave_product')
     ORDER BY a.created_at DESC
     LIMIT 20"
);
$history_stmt->execute([$user['id']]);
$history = $history_stmt->fetchAll();

log_visitor($pdo, 'page_view', '/profile.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - n2l8studio</title>
    <link rel="stylesheet" href="/static/style.css?v=3">
    <link rel="icon" type="image/png" href="/static/logo.png">
    <link rel="apple-touch-icon" href="/static/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
    <style>
        .profile-page { min-height:100vh; padding-bottom:4rem; }
        .profile-main { max-width:1180px; margin:0 auto; padding:3rem 2rem; }
        .profile-head { display:flex; align-items:end; justify-content:space-between; gap:1.5rem; margin-bottom:3rem; }
        .profile-kicker { color:var(--accent); font-size:0.72rem; font-weight:700; letter-spacing:0.16em; text-transform:uppercase; margin-bottom:0.6rem; }
        .profile-head h1 { font-size:clamp(1.7rem, 4vw, 3rem); letter-spacing:0.08em; margin:0; text-align:left; }
        .profile-logout { color:var(--text-muted); text-decoration:none; font-size:0.75rem; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; }
        .profile-logout:hover { color:#fff; }
        .profile-section { margin-bottom:3rem; }
        .profile-section-title { font-family:'Syncopate',sans-serif; font-size:1rem; letter-spacing:0.12em; text-transform:uppercase; margin-bottom:1rem; }
        .profile-products { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:1.2rem; }
        .profile-product { border:1px solid var(--border-color); background:rgba(5,5,8,0.65); border-radius:6px; padding:0.9rem; text-decoration:none; color:#fff; transition:all 0.2s ease; }
        .profile-product:hover { border-color:rgba(192,21,42,0.55); transform:translateY(-3px); }
        .profile-cover { width:100%; aspect-ratio:1; object-fit:cover; background:#020203; border-radius:4px; margin-bottom:0.8rem; }
        .profile-product h2 { font-size:0.78rem; letter-spacing:0.08em; margin-bottom:0.35rem; }
        .profile-meta { color:var(--text-muted); font-size:0.75rem; text-transform:uppercase; letter-spacing:0.08em; }
        .history-list { border:1px solid var(--border-color); border-radius:6px; overflow:hidden; }
        .history-row { display:grid; grid-template-columns:70px 1fr auto; align-items:center; gap:1rem; padding:0.85rem 1rem; border-bottom:1px solid rgba(255,255,255,0.05); color:#fff; text-decoration:none; }
        .history-row:last-child { border-bottom:none; }
        .history-thumb { width:54px; height:54px; object-fit:cover; border-radius:4px; background:#020203; }
        .history-action { color:var(--text-muted); font-size:0.72rem; text-transform:uppercase; letter-spacing:0.12em; }
        .history-title { font-weight:700; }
        .history-time { color:var(--text-muted); font-size:0.74rem; white-space:nowrap; }
        .empty-state { color:var(--text-muted); border:1px dashed var(--border-color); border-radius:6px; padding:2rem; text-align:center; }
        @media(max-width:900px){ .profile-products { grid-template-columns:repeat(2,minmax(0,1fr)); } .profile-head { align-items:start; flex-direction:column; } }
        @media(max-width:520px){ .profile-products { grid-template-columns:1fr; } .history-row { grid-template-columns:54px 1fr; } .history-time { grid-column:2; } }
    </style>
</head>
<body class="page-home profile-page">
    <header class="hero" style="min-height:auto;padding-bottom:1rem;">
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
                <li><a href="/forum.php">Forum</a></li>
                <li><a href="/profile.php">Profile</a></li>
            </ul>
        </nav>
    </header>

    <main class="profile-main">
        <div class="profile-head">
            <div>
                <div class="profile-kicker"><?= h($user['username']) ?></div>
                <h1>Profile</h1>
            </div>
            <a class="profile-logout" href="/admin/logout.php">Logout</a>
        </div>

        <section class="profile-section">
            <div class="profile-section-title">Saved Kits</div>
            <?php if (!$saved_products): ?>
                <div class="empty-state">No saved kits yet.</div>
            <?php else: ?>
                <div class="profile-products">
                    <?php foreach ($saved_products as $p): ?>
                    <a class="profile-product" href="/shop.php?preview=<?= (int)$p['id'] ?>">
                        <?php if ($p['cover_image']): ?>
                            <img class="profile-cover" src="/static/uploads/<?= h($p['cover_image']) ?>" alt="<?= h($p['title']) ?>">
                        <?php else: ?>
                            <div class="profile-cover"></div>
                        <?php endif; ?>
                        <h2><?= h($p['title']) ?></h2>
                        <div class="profile-meta"><?= h($p['type']) ?> · <?= $p['price'] > 0 ? '$' . number_format((float)$p['price'], 2) : 'FREE' ?></div>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="profile-section">
            <div class="profile-section-title">History</div>
            <?php if (!$history): ?>
                <div class="empty-state">No history yet.</div>
            <?php else: ?>
                <div class="history-list">
                    <?php foreach ($history as $hrow): ?>
                    <a class="history-row" href="<?= $hrow['product_id'] ? '/shop.php?preview=' . (int)$hrow['product_id'] : '/shop.php' ?>">
                        <?php if ($hrow['cover_image']): ?>
                            <img class="history-thumb" src="/static/uploads/<?= h($hrow['cover_image']) ?>" alt="">
                        <?php else: ?>
                            <div class="history-thumb"></div>
                        <?php endif; ?>
                        <div>
                            <div class="history-action"><?= h(str_replace('_', ' ', $hrow['action'])) ?></div>
                            <div class="history-title"><?= h($hrow['title'] ?: $hrow['metadata']) ?></div>
                        </div>
                        <div class="history-time"><?= h(date('M j, H:i', strtotime($hrow['created_at']))) ?></div>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script>
    const ham = document.getElementById('navHamburger');
    const navLinks = document.getElementById('navLinks');
    if (ham) ham.addEventListener('click', () => { ham.classList.toggle('open'); navLinks.classList.toggle('open'); });
    const dropbtn = document.querySelector('.dropbtn');
    if (dropbtn) dropbtn.addEventListener('click', e => { e.preventDefault(); document.querySelector('.dropdown-content').classList.toggle('show'); });
    </script>
</body>
</html>
