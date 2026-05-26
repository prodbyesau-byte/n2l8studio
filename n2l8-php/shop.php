<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/config.php';

$pdo  = get_pdo();
$site = get_site_content($pdo);
$shop_page_type = $shop_page_type ?? 'kits';
$is_graphics_page = $shop_page_type === 'graphics';

$user_id_for_query = is_logged_in() ? $_SESSION['user_id'] : 0;
$query_base = "SELECT p.*, 
    (SELECT COUNT(*) FROM product_upvotes WHERE product_id = p.id) as upvotes,
    (SELECT 1 FROM product_upvotes WHERE product_id = p.id AND user_id = " . (int)$user_id_for_query . ") as user_upvoted
    FROM products p WHERE p.is_active = 1 ";

$stmt = $is_graphics_page
    ? $pdo->query($query_base . "AND p.type = 'graphics' ORDER BY p.position ASC, p.id DESC")
    : $pdo->query($query_base . "AND p.type IN ('loopkit', 'drumkit') ORDER BY p.position ASC, p.id DESC");
$products = $stmt->fetchAll();

$saved_ids = [];
$playlist_product_ids = [];
if (is_logged_in()) {
    $saved_stmt = $pdo->prepare('SELECT product_id FROM user_saved_products WHERE user_id = ?');
    $saved_stmt->execute([$_SESSION['user_id']]);
    $saved_ids = array_map('intval', array_column($saved_stmt->fetchAll(), 'product_id'));

    $pl_stmt = $pdo->prepare('SELECT DISTINCT product_id FROM playlist_items pi JOIN playlists pl ON pi.playlist_id = pl.id WHERE pl.user_id = ?');
    $pl_stmt->execute([$_SESSION['user_id']]);
    $playlist_product_ids = array_map('intval', array_column($pl_stmt->fetchAll(), 'product_id'));
}
log_visitor($pdo, 'page_view', $is_graphics_page ? '/graphics.php' : '/shop.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_graphics_page ? 'Graphics' : 'Shop' ?> - N2L8 STUDIO</title>
    <meta name="description" content="<?= $is_graphics_page ? 'Graphic art from n2l8studio.' : 'Shop loopkits and drumkits from n2l8studio.' ?>">
    <link rel="stylesheet" href="/static/style.css?v=21">
    <link rel="icon" type="image/png" href="/static/logo.png">
    <link rel="apple-touch-icon" href="/static/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
    <?php
    // Only load PayPal SDK when real credentials are configured
    $pp_ready = defined('PAYPAL_CLIENT_ID')
             && PAYPAL_CLIENT_ID !== ''
             && strpos(PAYPAL_CLIENT_ID, 'REPLACE_WITH') === false;
    if ($pp_ready):
    ?>
    <script src="https://www.paypal.com/sdk/js?client-id=<?= h(PAYPAL_CLIENT_ID) ?>&currency=USD&intent=capture" data-sdk-integration-source="button-factory"></script>
    <?php endif; ?>
    <style>
        /* ── MODAL OVERLAY ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(5,5,8,0.92);
            z-index: 1000;
            /* scroll the overlay itself on mobile so modal content is reachable */
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 1rem 0;
            backdrop-filter: blur(10px);
        }
        .modal-overlay.open { display: block; }
        .modal-box {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            box-shadow: var(--pop-shadow), var(--accent-glow);
            width: 92%;
            max-width: 780px;
            margin: 3rem auto;
            position: relative;
            display: grid;
            grid-template-columns: 260px 1fr;
            /* allow content inside box to scroll independently */
            overflow: visible;
            overscroll-behavior: contain;
            border-radius: 8px;
        }
        .modal-cover-col { background:rgba(5,5,8,0.5); display:flex; flex-direction:column; align-items:center; padding:2rem 1.5rem; border-right:1px solid var(--border-color); }
        .modal-cover-img { width:100%; max-width:200px; aspect-ratio:1; object-fit:cover; border:1px solid var(--border-color); margin-bottom:1.2rem; border-radius:4px; box-shadow: 0 4px 15px rgba(0,0,0,0.4); }
        .modal-cover-placeholder { width:200px; height:200px; background:#121217; border:1px solid var(--border-color); margin-bottom:1.2rem; border-radius:4px; }
        .modal-price { font-family:'Syncopate',sans-serif; font-weight:700; color:var(--accent); font-size:1.8rem; text-align:center; line-height:1; }
        .modal-price-orig { color:var(--text-muted); text-decoration:line-through; font-size:0.95rem; text-align:center; margin-bottom:1rem; font-family:'Montserrat',sans-serif; }
        .modal-buy-btn { width:100%; margin-top:auto; }
        .modal-info-col { padding:2rem; display:flex; flex-direction:column; gap:0.5rem; }
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
            width: 38px; height: 38px; /* big tap target */
            display: flex; align-items: center; justify-content: center;
            z-index: 10;
            border-radius: 50%;
        }
        .modal-close:hover { color:#ff5c5c; border-color: #ff5c5c; }
        .modal-title { font-family:'Syncopate',sans-serif; font-weight:700; font-size:1.4rem; color:var(--text-main); text-transform:uppercase; letter-spacing:1px; margin-bottom:0.2rem; }
        .modal-author { color:var(--text-muted); font-size:0.9rem; margin-bottom:0.5rem; font-family:'Montserrat',sans-serif; font-weight:500; }
        .modal-tags { display:flex; flex-wrap:wrap; gap:0.4rem; margin-bottom:0.8rem; }
        .modal-tag { background:rgba(168,85,247,0.05); border:1px solid rgba(168,85,247,0.2); color:var(--accent); padding:0.25rem 0.7rem; font-family:'Montserrat',sans-serif; font-size:0.75rem; text-transform:uppercase; letter-spacing:1px; font-weight:600; border-radius:3px; }
        .modal-tag.accent { color:var(--accent); border-color:var(--accent); background:rgba(168,85,247,0.1); }
        .modal-desc { color:var(--text-muted); font-size:0.9rem; line-height:1.6; margin-bottom:1rem; border-top:1px dashed rgba(255,255,255,0.05); padding-top:0.8rem; font-family:'Montserrat',sans-serif; }
        .player-section { border-top:1px dashed rgba(255,255,255,0.05); padding-top:1rem; }
        .player-section-label { font-family:'Syncopate',sans-serif; font-weight:700; color:var(--accent); font-size:0.85rem; text-transform:uppercase; letter-spacing:1px; margin-bottom:0.8rem; }
        .track-list { list-style:none; padding:0; margin-bottom:1rem; }
        .track-item {
            display:flex; align-items:center; gap:0.8rem;
            padding:0.7rem 0.6rem; /* taller for touch */
            cursor:pointer; border-left:2px solid transparent;
            transition:all 0.15s ease;
            min-height: 44px; /* iOS minimum tap target */
        }
        .track-item:hover { background:rgba(168,85,247,0.04); border-left-color:var(--accent); }
        .track-item.playing { border-left-color:var(--accent); background:rgba(168,85,247,0.08); }
        .track-item.playing .track-item-name { color:var(--text-main); font-weight:600; }
        .track-num { font-family:'Syncopate',sans-serif; font-weight:700; color:var(--text-muted); min-width:20px; font-size:0.75rem; }
        .track-item-name { flex:1; font-size:0.85rem; color:var(--text-muted); font-family:'Montserrat',sans-serif; }
        .play-icon { color:var(--accent); font-size:1rem; min-width:18px; text-align:center; }
        .player-controls { display:flex; flex-direction:column; gap:0.5rem; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.03); padding:0.8rem 1rem; border-radius:4px; }
        .player-now-playing { font-family:'Montserrat',sans-serif; color:var(--text-muted); font-size:0.8rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .player-now-playing span { color:var(--text-main); font-weight:600; }
        .player-row { display:flex; align-items:center; gap:0.8rem; }
        .player-btn {
            background:transparent; border:none; color:var(--text-main);
            font-size:1.1rem; cursor:pointer;
            padding:0.4rem 0.6rem; /* bigger touch target */
            min-width: 44px; min-height: 44px;
            transition:all 0.2s;
            font-family:'Montserrat',sans-serif; line-height:1;
            display:flex; align-items:center; justify-content:center;
        }
        .player-btn:hover { color:var(--accent); text-shadow:var(--accent-glow); }
        .player-btn:disabled { opacity:0.25; cursor:default; pointer-events:none; }
        /* Progress bar: tall invisible hit area for easy touch */
        .progress-wrap {
            flex:1;
            height: 20px; /* tall touch target */
            display: flex;
            align-items: center;
            cursor:pointer;
            position:relative;
            touch-action: none; /* prevent scroll interference */
        }
        .progress-track {
            position:absolute; left:0; right:0;
            height: 4px;
            background:rgba(255,255,255,0.08);
            pointer-events: none;
            border-radius: 2px;
        }
        .progress-bar {
            height: 4px;
            background:var(--accent);
            width:0%;
            transition:width 0.1s linear;
            box-shadow:var(--accent-glow);
            pointer-events: none;
            border-radius: 2px;
        }
        .player-time { font-family:'Montserrat',sans-serif; color:var(--text-muted); font-size:0.75rem; min-width:70px; text-align:right; font-weight:500; }
        .no-tracks-msg { color:var(--text-muted); font-size:0.85rem; font-style:italic; padding:0.5rem 0; font-family:'Montserrat',sans-serif; }
        @media(max-width:640px){
            .modal-box { grid-template-columns:1fr; width:96%; margin: 1rem auto; }
            .modal-cover-col { border-right:none; border-bottom:1px solid var(--border-color); padding:1.5rem 1rem; }
            .modal-info-col { padding:1.2rem; }
            .modal-title { font-size:1.2rem; }
        }
        /* ── ADMIN DRAG & DROP ── */
        .kit-card.admin-dragging {
            opacity: 0.4;
            border: 2px dashed var(--accent) !important;
            box-shadow: var(--accent-glow) !important;
            transform: scale(0.97);
            cursor: grabbing !important;
        }
        .kit-card.admin-drag-over {
            border: 2px solid var(--gold) !important;
            box-shadow: 0 0 15px rgba(184, 155, 94, 0.4) !important;
            transform: scale(1.02);
            transition: all 0.2s ease;
        }
    </style>
</head>
<body class="page-shop <?= get_active_theme($pdo) === 'beige' ? 'theme-beige' : '' ?>">
    <header class="hero" style="min-height:auto;padding-bottom:2rem;">
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
                <!-- <li><a href="/pricing.php">Services</a></li> -->
                <li><a href="/forum.php">Forum</a></li>
                <?php if (is_logged_in()): ?>
                    <li class="dropdown">
                        <a href="javascript:void(0)" class="dropbtn" style="color: var(--accent); display: inline-flex; align-items: center; gap: 4px; padding-top: 4px; padding-bottom: 4px;">
                            <?= get_user_avatar_nav($pdo) ?>
                            <span>Profile</span>
                        </a>
                        <div class="dropdown-content">
                            <a href="/portal/index.php?tab=settings">Account Settings</a>
                            <a href="/portal/index.php"><?= is_owner() ? 'Client Portal' : 'Profile' ?></a>
                            <?php if (is_owner()): ?>
                                <a href="/admin/index.php">Admin Portal</a>
                            <?php endif; ?>
                            <a href="/logout.php" style="color: var(--accent) !important;">Disconnect</a>
                        </div>
                    </li>
                <?php else: ?>
                    <li class="dropdown">
                        <a href="javascript:void(0)" class="dropbtn">Login</a>
                        <div class="dropdown-content">
                            <a href="/login.php">User Login</a>
                            <a href="/admin/login.php">Admin Login</a>
                        </div>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <section id="shop" class="kits">
        <div class="container">
            <h2><?= $is_graphics_page ? 'Graphics' : h($site['shop_h2'] ?? 'Kits') ?></h2>
            <p class="section-desc"><?= $is_graphics_page ? 'Cover art, visual packs and design assets from N2L8studios.' : h($site['shop_desc'] ?? '') ?></p>

            <?php if (is_owner()): ?>
            <!-- ADMIN ORDER BAR -->
            <div class="admin-order-bar" style="background:rgba(26,26,31,0.65); backdrop-filter:blur(10px); border:1px solid var(--border-color); padding:1.2rem; border-radius:6px; margin-bottom:2rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; box-shadow:var(--red-glow);">
                <div style="display:flex; align-items:center; gap:0.8rem;">
                    <span style="font-size:1.4rem;">⚙️</span>
                    <div>
                        <strong style="font-family:'Syncopate',sans-serif; font-size:0.75rem; color:#fff; display:block; letter-spacing:1px;" id="adminOrderNotice">ADMIN PORTAL: DRAG & DROP TO REORDER</strong>
                        <span style="font-size:0.75rem; color:var(--text-muted); font-family:'Montserrat',sans-serif;">Drag any kit to customize its placement. Click Save Order to publish.</span>
                    </div>
                </div>
                <div style="display:flex; gap:0.8rem; margin-left:auto;">
                    <button id="adminSaveOrderBtn" class="cta-btn" style="margin-top:0; font-size:0.8rem; padding:0.6rem 1.2rem; font-family:'Syncopate',sans-serif; font-weight:700; letter-spacing:1px; background:#A44A5E; display:none;" onclick="saveAdminOrder()">💾 SAVE ORDER</button>
                    <button class="cta-btn" style="margin-top:0; font-size:0.8rem; padding:0.6rem 1.2rem; font-family:'Syncopate',sans-serif; font-weight:700; letter-spacing:1px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:#fff;" onclick="window.location.reload()">Reset</button>
                </div>
            </div>
            <?php endif; ?>



            <?php if (!$is_graphics_page): ?>
            <div class="kits-filter" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:2rem;">
                <div class="filter-group">
                    <span class="filter-label">Type</span>
                    <button class="filter-tab active" data-value="all">All</button>
                    <button class="filter-tab" data-value="loopkit">Loop Kits</button>
                    <button class="filter-tab" data-value="drumkit">Drumkits</button>
                </div>
                <div class="search-group" style="position:relative; width:100%; max-width:300px;">
                    <input type="text" id="shopSearchInput" placeholder="Search kits..." style="width:100%; padding:0.6rem 1rem 0.6rem 2.2rem; background:rgba(26,26,31,0.6); border:1px solid var(--border-color); border-radius:4px; color:var(--text-main); font-family:'Montserrat',sans-serif; font-size:0.85rem; outline:none; transition:all 0.3s ease; box-shadow:inset 0 1px 3px rgba(0,0,0,0.2);">
                    <span style="position:absolute; left:0.8rem; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.9rem; pointer-events:none;">🔍</span>
                </div>
            </div>
            <?php else: ?>
            <div class="kits-filter" style="display:flex; justify-content:flex-end; align-items:center; margin-bottom:2rem;">
                <div class="search-group" style="position:relative; width:100%; max-width:300px;">
                    <input type="text" id="shopSearchInput" placeholder="Search graphics..." style="width:100%; padding:0.6rem 1rem 0.6rem 2.2rem; background:rgba(26,26,31,0.6); border:1px solid var(--border-color); border-radius:4px; color:var(--text-main); font-family:'Montserrat',sans-serif; font-size:0.85rem; outline:none; transition:all 0.3s ease; box-shadow:inset 0 1px 3px rgba(0,0,0,0.2);">
                    <span style="position:absolute; left:0.8rem; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.9rem; pointer-events:none;">🔍</span>
                </div>
            </div>
            <?php endif; ?>

            <div class="kits-grid">
                <?php if (empty($products)): ?>
                <p style="color:var(--text-muted);grid-column:1/-1;text-align:center;padding:3rem 0;">No products available. Check back soon.</p>
                <?php else: foreach ($products as $p): ?>
                <div class="kit-card" data-type="<?= h($p['type']) ?>" data-genre="<?= h($p['genre']) ?>" data-id="<?= (int)$p['id'] ?>">
                    <div class="kit-cover <?= $p['cover_image'] ? '' : 'placeholder-1' ?>" style="position:relative;">
                        <?php if (!empty($p['is_preorder']) && (!empty($p['release_date']) && strtotime($p['release_date']) > time())): ?>
                        <div class="preorder-badge-overlay" style="position:absolute; top:12px; left:12px; background:rgba(168, 85, 247, 0.95); color:#fff; border:1px solid rgba(168,85,247,0.4); box-shadow:0 0 10px rgba(168,85,247,0.5); font-family:'Syncopate',sans-serif; font-size:0.62rem; font-weight:700; padding:4px 8px; border-radius:3px; z-index:10; letter-spacing:1.5px; text-transform:uppercase;">PRE-ORDER</div>
                        <?php endif; ?>
                        <?php if ($p['cover_image']): ?>
                        <img src="/static/uploads/<?= h($p['cover_image']) ?>" alt="<?= h($p['title']) ?> Cover" class="kit-image">
                        <?php endif; ?>
                    </div>
                    <div class="kit-info">
                        <h3><?= h($p['title']) ?></h3>
                        <?php if ($p['author']): ?><p class="kit-author"><?= h($p['author']) ?></p><?php endif; ?>
                        <?php if ($p['bpm'] || $p['key']): ?>
                        <p class="kit-meta">
                            <?= $p['bpm'] ? h($p['bpm']) . ' BPM' : '' ?>
                            <?= ($p['bpm'] && $p['key']) ? ' &middot; ' : '' ?>
                            <?= $p['key'] ? h($p['key']) : '' ?>
                        </p>
                        <?php endif; ?>
                        <div class="kit-price-row">
                            <span class="kit-price"><?= $p['price'] > 0 ? '$' . number_format((float)$p['price'], 2) : 'FREE' ?></span>
                            <?php if ($p['original_price']): ?>
                            <span class="kit-price-original">$<?= number_format((float)$p['original_price'], 2) ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1rem; gap:0.5rem;">
                            <button class="cta-btn kit-btn" style="flex:1; margin-top:0;" onclick="openModal(<?= (int)$p['id'] ?>)">Preview &amp; Buy</button>
                            <button class="kit-save-btn <?= !empty($p['user_upvoted']) ? 'saved' : '' ?>" type="button" onclick="event.stopPropagation(); event.preventDefault(); toggleUpvote(this, <?= (int)$p['id'] ?>)" title="Upvote" style="width:40px;height:40px;padding:0;display:flex;align-items:center;justify-content:center;font-size:1.4rem;">
                                <?= !empty($p['user_upvoted']) ? '★' : '☆' ?>
                            </button>
                            <button class="kit-save-btn playlist-btn-<?= $p['id'] ?> <?= in_array((int)$p['id'], $playlist_product_ids, true) ? 'saved' : '' ?>" type="button" onclick="event.stopPropagation(); event.preventDefault(); togglePlaylist(this, <?= (int)$p['id'] ?>)" title="Add to Playlist" style="width:40px;height:40px;padding:0;display:flex;align-items:center;justify-content:center;font-size:1.2rem;">
                                ➕
                            </button>
                        </div>
                        <div style="text-align:right; font-size:0.8rem; color:var(--text-muted); margin-top:0.3rem;"><span id="upvotes-<?= $p['id'] ?>"><?= (int)$p['upvotes'] ?></span> upvotes</div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
                <p id="noFilterResults" style="display:none;color:var(--text-muted);grid-column:1/-1;text-align:center;padding:3rem 0;">No products in this category yet.</p>
            </div>
        </div>
    </section>

    <footer>
        <p><?= h($site['footer_text'] ?? '© 2026 n2l8studio.') ?></p>
    </footer>

    <!-- MODAL -->
    <div class="modal-overlay" id="productModal">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal()" title="Close">&times;</button>
            <div class="modal-cover-col">
                <div id="modalCoverWrap"></div>
                <div class="modal-price" id="modalPrice"></div>
                <div class="modal-price-orig" id="modalPriceOrig"></div>
                <!-- Terms of Agreement Checkbox -->
                <div id="modalTermsSection" style="margin-top:1.5rem; text-align:left; font-family:'Montserrat',sans-serif; font-size:0.8rem; border-top:1px solid rgba(255,255,255,0.05); padding-top:1rem; width:100%; box-sizing:border-box;">
                    <div id="modalPdfLinkWrap" style="margin-bottom:0.8rem;"></div>
                    <label style="display:flex; align-items:flex-start; gap:0.5rem; color:var(--text-muted); cursor:pointer; line-height:1.3; font-weight:500;">
                        <input type="checkbox" id="termsCheckbox" style="margin-top:2px; cursor:pointer; accent-color:var(--accent); width:15px; height:15px; flex-shrink:0;">
                        <span>I agree to the terms of agreement and rights for this product.</span>
                    </label>
                </div>
                <!-- Pre-order Notice Banner -->
                <div id="preorder-notice-wrap" style="width:100%;margin-top:1rem;display:none;"></div>
                
                <!-- PayPal button (paid) or direct download (free) -->
                <div id="paypal-btn-wrap" style="width:100%;margin-top:1rem;"></div>
            </div>
            <div class="modal-info-col">
                <div class="modal-title" id="modalTitle">—</div>
                <div class="modal-author" id="modalAuthor"></div>
                <div class="modal-tags" id="modalTags"></div>
                <div class="modal-desc" id="modalDesc"></div>
                <div class="player-section">
                    <div class="player-section-label">Preview Tracks</div>
                    <ul class="track-list" id="modalTrackList"></ul>
                    <div class="player-controls" id="playerControls" style="display:none;">
                        <div class="player-now-playing">Now playing: <span id="nowPlayingName">—</span></div>
                        <div class="player-row">
                            <button class="player-btn" id="btnPrev" title="Previous">&#9664;&#9664;</button>
                            <button class="player-btn" id="btnPlayPause" title="Play/Pause">&#9654;</button>
                            <button class="player-btn" id="btnNext" title="Next">&#9654;&#9654;</button>
                            <div class="progress-wrap" id="progressWrap"><div class="progress-track"></div><div class="progress-bar" id="progressBar"></div></div>
                            <div class="player-time" id="playerTime">0:00 / 0:00</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <audio id="audioEl" preload="metadata"></audio>

    <script>
    const isLoggedIn = <?= is_logged_in() ? 'true' : 'false' ?>;
    const IS_LOGGED_IN = isLoggedIn;
    function logAction(action, metadata = '', productId = '') {
        fetch('/api/log_action.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=' + encodeURIComponent(action) + '&metadata=' + encodeURIComponent(metadata) + '&product_id=' + encodeURIComponent(productId)
        });
    }
    function toggleUpvote(btn, productId) {
        fetch('/api/product_actions.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=toggle_upvote&product_id=' + encodeURIComponent(productId)
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                window.location.href = '/login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
                return;
            }
            btn.classList.toggle('saved', data.is_active);
            btn.textContent = data.is_active ? '★' : '☆';
            if (data.count !== undefined) {
                document.getElementById('upvotes-' + productId).textContent = data.count;
            }
        });
    }

    function togglePlaylist(btn, productId) {
        if (!IS_LOGGED_IN) {
            window.location.href = '/login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
            return;
        }
        
        // Open playlist selection modal
        const wrap = document.createElement('div');
        wrap.className = 'modal-overlay open';
        wrap.id = 'playlistSelectModal';
        wrap.style.zIndex = '9999';
        
        wrap.innerHTML = `
            <div class="modal-box" style="max-width:400px; padding:2rem; text-align:center;">
                <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">×</button>
                <h3 style="margin-top:0; font-family:'Syncopate', sans-serif; color:var(--accent);">ADD TO PLAYLIST</h3>
                <div id="playlistList" style="margin:1.5rem 0; display:flex; flex-direction:column; gap:0.5rem; max-height:250px; overflow-y:auto; text-align:left;">
                    <div style="text-align:center; color:var(--text-muted); font-size:0.8rem;">Loading...</div>
                </div>
                <div style="display:flex; gap:0.5rem; margin-top:1rem;">
                    <input type="text" id="newPlaylistName" placeholder="New Playlist Name" style="flex:1; background:rgba(0,0,0,0.5); border:1px solid var(--border-color); color:#fff; padding:0.5rem; border-radius:4px; font-family:'Montserrat', sans-serif; font-size:0.9rem;">
                    <button class="cta-btn" onclick="createNewPlaylist(${productId})" style="padding:0.5rem 1rem;">CREATE</button>
                </div>
            </div>
        `;
        document.body.appendChild(wrap);
        
        fetchPlaylistsForProduct(productId);
    }

    function updatePlaylistButtonState(productId) {
        const list = document.getElementById('playlistList');
        if (!list) return;
        const buttons = list.querySelectorAll('button');
        let isInAny = false;
        buttons.forEach(btn => {
            if (btn.textContent.includes('(Added)')) {
                isInAny = true;
            }
        });
        const pageBtns = document.querySelectorAll('.playlist-btn-' + productId);
        pageBtns.forEach(pBtn => {
            pBtn.classList.toggle('saved', isInAny);
        });
    }

    function fetchPlaylistsForProduct(productId) {
        fetch('/api/product_actions.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=fetch_playlists&product_id=' + productId
        })
        .then(r => r.json())
        .then(data => {
            const list = document.getElementById('playlistList');
            if (!list) return;
            list.innerHTML = '';
            if (!data.playlists || data.playlists.length === 0) {
                list.innerHTML = '<div style="text-align:center; color:var(--text-muted); font-size:0.8rem;">No playlists found. Create one below!</div>';
                updatePlaylistButtonState(productId);
                return;
            }
            
            data.playlists.forEach(pl => {
                const btn = document.createElement('button');
                btn.className = 'cta-btn';
                btn.style.width = '100%';
                btn.style.textAlign = 'left';
                btn.style.background = 'rgba(255,255,255,0.05)';
                if (pl.is_in_playlist == 1) {
                    btn.style.borderColor = 'var(--accent)';
                    btn.style.color = 'var(--accent)';
                    btn.textContent = pl.name + ' (Added)';
                } else {
                    btn.style.borderColor = 'var(--border-color)';
                    btn.style.color = 'var(--text-muted)';
                    btn.textContent = pl.name;
                }
                
                btn.onclick = () => {
                    fetch('/api/product_actions.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `action=toggle_playlist_item&playlist_id=${pl.id}&product_id=${productId}`
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            if (res.is_active) {
                                btn.style.borderColor = 'var(--accent)';
                                btn.style.color = 'var(--accent)';
                                btn.textContent = pl.name + ' (Added)';
                            } else {
                                btn.style.borderColor = 'var(--border-color)';
                                btn.style.color = 'var(--text-muted)';
                                btn.textContent = pl.name;
                            }
                            updatePlaylistButtonState(productId);
                        } else {
                            alert('Error: ' + (res.error || 'Could not toggle playlist item'));
                        }
                    })
                    .catch(err => {
                        console.error('Error toggling playlist item:', err);
                        alert('Failed to update playlist. Please try again.');
                    });
                };
                list.appendChild(btn);
            });
            updatePlaylistButtonState(productId);
        });
    }

    function createNewPlaylist(productId) {
        const nameInput = document.getElementById('newPlaylistName');
        if (!nameInput || !nameInput.value.trim()) return;
        
        fetch('/api/product_actions.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=create_playlist&name=' + encodeURIComponent(nameInput.value.trim())
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const playlistId = data.playlist.id;
                nameInput.value = '';
                // Auto-add product to the newly created playlist!
                fetch('/api/product_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=toggle_playlist_item&playlist_id=${playlistId}&product_id=${productId}`
                })
                .then(r => r.json())
                .then(res => {
                    fetchPlaylistsForProduct(productId);
                });
            } else {
                alert('Error creating playlist: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error('Error creating playlist:', err);
            alert('Failed to create playlist. Please try again.');
        });
    }

    /* ── FILTER & SEARCH ── */
    const typeTabs = document.querySelectorAll('.filter-tab');
    const cards = document.querySelectorAll('.kit-card');
    const searchInput = document.getElementById('shopSearchInput');
    let currentType = '<?= $is_graphics_page ? 'graphics' : 'all' ?>';

    function filterKits() {
        let visibleCount = 0;
        const query = searchInput ? searchInput.value.toLowerCase().trim() : '';

        cards.forEach(card => {
            const typeMatch = currentType === 'all' || card.dataset.type === currentType;

            // Search match matches title, author, key, bpm
            const title = card.querySelector('h3')?.textContent.toLowerCase() || '';
            const author = card.querySelector('.kit-author')?.textContent.toLowerCase() || '';
            const meta = card.querySelector('.kit-meta')?.textContent.toLowerCase() || '';

            const searchMatch = !query || 
                                title.includes(query) || 
                                author.includes(query) || 
                                meta.includes(query);

            const match = typeMatch && searchMatch;

            card.style.opacity = match ? '1' : '0';
            card.style.display = match ? 'flex' : 'none';
            if (match) visibleCount++;
        });

        const emptyState = document.getElementById('noFilterResults');
        if (emptyState) {
            emptyState.style.display = visibleCount ? 'none' : 'block';
            emptyState.textContent = query ? 'No matching products found.' : 'No products in this category yet.';
        }
    }

    typeTabs.forEach(b => b.classList.toggle('active', b.dataset.value === currentType));
    filterKits();
    
    typeTabs.forEach(t => t.addEventListener('click', () => {
        currentType = t.dataset.value;
        typeTabs.forEach(b => b.classList.toggle('active', b.dataset.value === currentType));
        filterKits();
    }));

    if (searchInput) {
        searchInput.addEventListener('input', filterKits);
    }

    /* ── MODAL + PLAYER ── */
    const audio = document.getElementById('audioEl');
    const modal = document.getElementById('productModal');
    let tracks = [], currentIdx = -1, _currentProductId = null;

    /* ── PAYPAL ── */
    let _ppButtons = null; // store rendered instance to destroy on reopen

    function renderPayPalButtons(productId, price, isPreorderPending = false) {
        const wrap = document.getElementById('paypal-btn-wrap');
        wrap.innerHTML = '';
        if (price <= 0) return;

        // ── Stripe Credit Card Button ──
        const stripeBtn = document.createElement('button');
        stripeBtn.className = 'cta-btn modal-buy-btn';
        stripeBtn.style.background = '#A44A5E';
        stripeBtn.style.color = '#ffffff';
        stripeBtn.style.border = 'none';
        stripeBtn.style.padding = '0.85rem';
        stripeBtn.style.fontSize = '0.9rem';
        stripeBtn.style.fontFamily = "'VT323', monospace";
        stripeBtn.style.fontWeight = '700';
        stripeBtn.style.letterSpacing = '1px';
        stripeBtn.style.borderRadius = '4px';
        stripeBtn.style.cursor = 'pointer';
        stripeBtn.style.marginBottom = '1rem';
        stripeBtn.style.width = '100%';
        stripeBtn.style.display = 'block';
        stripeBtn.style.transition = 'all 0.3s';
        stripeBtn.innerHTML = isPreorderPending ? '💳 PRE-ORDER WITH CARD' : '💳 PAY WITH CARD';
        
        stripeBtn.addEventListener('click', () => {
            stripeBtn.textContent = 'SECURE REDIRECT...';
            stripeBtn.disabled = true;
            stripeBtn.style.opacity = '0.7';
            
            const form = new FormData();
            form.append('product_id', productId);
            
            fetch('/payment/create-stripe-session.php', {
                method: 'POST',
                body: form
            })
            .then(r => r.json())
            .then(data => {
                if (data.url) {
                    window.location.href = data.url;
                } else {
                    alert(data.error || 'Failed to create checkout session.');
                    stripeBtn.textContent = '💳 PAY WITH CARD';
                    stripeBtn.disabled = false;
                    stripeBtn.style.opacity = '1';
                }
            })
            .catch(err => {
                console.error(err);
                stripeBtn.textContent = '💳 PAY WITH CARD';
                stripeBtn.disabled = false;
                stripeBtn.style.opacity = '1';
            });
        });
        
        wrap.appendChild(stripeBtn);

        // Separator
        const sep = document.createElement('div');
        sep.style.textAlign = 'center';
        sep.style.color = 'var(--text-muted)';
        sep.style.fontFamily = "'VT323', monospace";
        sep.style.fontSize = '0.72rem';
        sep.style.fontWeight = '600';
        sep.style.letterSpacing = '1px';
        sep.style.marginBottom = '1rem';
        sep.textContent = '— OR —';
        wrap.appendChild(sep);

        // PayPal container
        const ppContainer = document.createElement('div');
        ppContainer.id = 'paypal-button-container-inner';
        wrap.appendChild(ppContainer);

        // PayPal SDK not configured yet — show contact fallback
        if (typeof paypal === 'undefined') {
            ppContainer.innerHTML = '<a href="mailto:contact@n2l8studio.com" class="cta-btn modal-buy-btn" style="display:block;text-align:center;font-size:1rem;">Contact to Purchase</a>';
            sep.style.display = 'none';
            return;
        }

        if (_ppButtons) { try { _ppButtons.close(); } catch(e) {} }
        _ppButtons = paypal.Buttons({
            style: { layout:'vertical', color:'gold', shape:'rect', label:'buynow', height:45 },
            createOrder: function() {
                return fetch('/payment/create-order.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'product_id=' + productId,
                }).then(r => r.json()).then(data => {
                    if (data.error) throw new Error(data.error);
                    return data.id;
                });
            },
            onApprove: function(data) {
                wrap.innerHTML = '<div style="color:var(--text-muted);font-family:\'Montserrat\',sans-serif;text-align:center;padding:1rem;font-weight:500;">Processing payment...</div>';
                return fetch('/payment/capture-order.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'order_id=' + data.orderID + '&product_id=' + productId,
                }).then(r => r.json()).then(result => {
                    if (result.success && result.download_url) {
                        wrap.innerHTML =
                            '<div style="text-align:center;">'+
                            '<div style="color:var(--text-main);font-family:\'Syncopate\',sans-serif;font-weight:700;font-size:1.1rem;margin-bottom:0.8rem;letter-spacing:1px;">✓ PAYMENT CONFIRMED</div>'+
                            '<a href="' + result.download_url + '" class="cta-btn" style="display:block;text-align:center;" download>DOWNLOAD NOW</a>'+
                            '<div style="color:var(--text-muted);font-size:0.85rem;margin-top:0.5rem;">Receipt sent to ' + result.email + '</div>'+
                            '</div>';
                    } else {
                        wrap.innerHTML = '<div style="color:#ff5c5c;text-align:center;font-family:\'Montserrat\',sans-serif;font-weight:600;">Payment error — please try again.</div>';
                    }
                });
            },
            onError: function(err) {
                console.error('PayPal error:', err);
                wrap.innerHTML = '<div style="color:#ff5c5c;text-align:center;font-family:\'Montserrat\',sans-serif;font-weight:600;">Payment failed. Please try again.</div>';
            },
            onCancel: function() { renderPayPalButtons(productId, price); }
        });
        _ppButtons.render('#paypal-button-container-inner');
    }

    function fmt(secs) {
        if (!isFinite(secs)) return '0:00';
        return `${Math.floor(secs/60)}:${Math.floor(secs%60).toString().padStart(2,'0')}`;
    }
    function updateNavBtns() {
        const onlyOne = tracks.length <= 1;
        document.getElementById('btnPrev').disabled = onlyOne || currentIdx <= 0;
        document.getElementById('btnNext').disabled = onlyOne || currentIdx >= tracks.length - 1;
    }
    function getSegment(track = tracks[currentIdx]) {
        if (!track) return { start: 0, end: null, duration: audio.duration || 0 };
        const start = Math.max(0, parseFloat(track.preview_start || 0));
        const rawEnd = track.preview_end === null || track.preview_end === undefined || track.preview_end === '' ? null : parseFloat(track.preview_end);
        const fileEnd = audio.duration || 0;
        const end = rawEnd && rawEnd > start ? Math.min(rawEnd, fileEnd || rawEnd) : null;
        const duration = end ? Math.max(0, end - start) : Math.max(0, fileEnd - start);
        return { start, end, duration };
    }
    function finishCurrentTrack() {
        if (currentIdx < tracks.length - 1) loadTrack(currentIdx + 1);
        else {
            audio.pause();
            document.getElementById('btnPlayPause').innerHTML = '&#9654;';
            currentIdx = -1;
            renderTrackList();
        }
    }
    function renderTrackList() {
        const list = document.getElementById('modalTrackList');
        list.innerHTML = '';
        if (!tracks.length) {
            list.innerHTML = '<li class="no-tracks-msg">No preview tracks available for this product.</li>';
            document.getElementById('playerControls').style.display = 'none';
            return;
        }
        document.getElementById('playerControls').style.display = 'flex';
        tracks.forEach((t, i) => {
            const li = document.createElement('li');
            li.className = 'track-item' + (i === currentIdx ? ' playing' : '');
            li.innerHTML = `<span class="track-num">${i+1}</span><span class="track-item-name">${t.title}</span><span class="play-icon">${i===currentIdx?'▶':'▷'}</span>`;
            li.addEventListener('click', () => loadTrack(i));
            list.appendChild(li);
        });
        updateNavBtns();
    }
    function loadTrack(idx) {
        currentIdx = idx;
        const start = Math.max(0, parseFloat(tracks[idx].preview_start || 0));
        audio.src = tracks[idx].url;
        audio.addEventListener('loadedmetadata', () => {
            if (start > 0 && start < audio.duration) audio.currentTime = start;
            audio.play();
        }, { once: true });
        audio.load();
        document.getElementById('nowPlayingName').textContent = tracks[idx].title;
        logAction('play_track', tracks[idx].title);
        document.getElementById('btnPlayPause').innerHTML = '&#9646;&#9646;';
        renderTrackList();
        updateNavBtns();
    }
    function openModal(productId) {
        audio.pause(); audio.src = ''; tracks = []; currentIdx = -1;
        document.getElementById('progressBar').style.width = '0%';
        document.getElementById('playerTime').textContent = '0:00 / 0:00';
        document.getElementById('btnPlayPause').innerHTML = '&#9654;';
        document.getElementById('nowPlayingName').textContent = '—';

        fetch(`/api/product.php?id=${productId}`)
            .then(r => r.json())
            .then(p => {
                document.getElementById('modalCoverWrap').innerHTML = p.cover_image
                    ? `<img src="${p.cover_image}" class="modal-cover-img" alt="${p.title}">`
                    : `<div class="modal-cover-placeholder"></div>`;
                document.getElementById('modalTitle').textContent = p.title;
                document.getElementById('modalAuthor').textContent = p.author || '';
                const tags = document.getElementById('modalTags');
                tags.innerHTML = '';
                const addTag = (txt, accent) => {
                    const s = document.createElement('span');
                    s.className = 'modal-tag' + (accent ? ' accent' : '');
                    s.textContent = txt;
                    tags.appendChild(s);
                };
                if (p.type)  addTag(p.type.toUpperCase());
                if (p.genre) addTag(p.genre.toUpperCase());
                if (p.bpm)   addTag(`${p.bpm} BPM`, true);
                if (p.key)   addTag(p.key, true);
                document.getElementById('modalDesc').textContent = p.description || '';
                document.getElementById('modalPrice').textContent = p.price > 0 ? `$${parseFloat(p.price).toFixed(2)}` : 'FREE';
                document.getElementById('modalPriceOrig').textContent = p.original_price ? `$${parseFloat(p.original_price).toFixed(2)}` : '';
                
                // Reset preorder notice
                const noticeWrap = document.getElementById('preorder-notice-wrap');
                if (noticeWrap) {
                    noticeWrap.style.display = 'none';
                    noticeWrap.innerHTML = '';
                }

                // Payment / download area
                const wrap = document.getElementById('paypal-btn-wrap');
                wrap.innerHTML = '';
                _currentProductId = productId;

                const isPreorder = p.is_preorder === 1;
                const releaseDate = p.release_date;
                const isPreorderPending = isPreorder && releaseDate && (new Date(releaseDate + 'T00:00:00') > new Date());

                if (isPreorderPending && noticeWrap) {
                    noticeWrap.style.display = 'block';
                    noticeWrap.innerHTML = `
                        <div style="background:rgba(168, 85, 247, 0.12); border:1px solid rgba(168,85,247,0.35); border-radius:4px; padding:1.2rem; font-family:'Montserrat',sans-serif; text-align:left; color:#fff; box-shadow:0 0 15px rgba(168,85,247,0.15); margin-bottom:0.5rem; margin-top:0.5rem;">
                            <strong style="color:#b57cff; font-family:'Syncopate',sans-serif; font-size:0.7rem; display:block; margin-bottom:0.4rem; letter-spacing:1.5px;">📅 PRE-ORDER ACTIVE</strong>
                            <span style="font-size:0.8rem; line-height:1.6; color:rgba(255,255,255,0.92); font-weight:500;">
                                This pack is a scheduled upcoming release out on <strong>${releaseDate}</strong>. Pre-ordering now secures your copy, and it will appear automatically for download in your Client Portal the second it is officially released!
                            </span>
                        </div>
                    `;
                }

                if (parseFloat(p.price) > 0) {
                    renderPayPalButtons(productId, p.price, isPreorderPending);
                } else {
                    if (p.allow_download && p.zip_file && !isPreorderPending) {
                        wrap.innerHTML = `<a href="${p.zip_file}" class="cta-btn modal-buy-btn" style="display:block;text-align:center;font-size:1rem;padding:0.8rem 1.5rem;font-family:'Syncopate',sans-serif;font-weight:700;letter-spacing:1px;text-decoration:none;margin-top:1rem;" download>⬇ DOWNLOAD FREE KIT</a>`;
                    } else {
                        // Download disabled — greyed out placeholder
                        wrap.innerHTML = `<button disabled style="display:block;width:100%;text-align:center;font-size:1rem;padding:0.8rem 1.5rem;font-family:'Syncopate',sans-serif;font-weight:700;letter-spacing:1px;border:1px solid rgba(123,225,168,0.2);background:rgba(123,225,168,0.04);color:rgba(123,225,168,0.3);cursor:not-allowed;margin-top:1rem;">⬇ DOWNLOAD — COMING SOON</button>`;
                    }
                }

                // Reset terms checkbox state
                const termsCheckbox = document.getElementById('termsCheckbox');
                if (termsCheckbox) {
                    termsCheckbox.checked = false;
                }

                // Show PDF link if available
                const pdfLinkWrap = document.getElementById('modalPdfLinkWrap');
                if (pdfLinkWrap) {
                    if (p.terms_pdf) {
                        pdfLinkWrap.innerHTML = `
                            <a href="${p.terms_pdf}" target="_blank" style="display:inline-flex; align-items:center; gap:0.4rem; color:var(--accent); text-decoration:none; font-weight:700;">
                                📄 READ TERMS &amp; RIGHTS (PDF)
                            </a>
                        `;
                    } else {
                        pdfLinkWrap.innerHTML = '';
                    }
                }

                // Initially disable the checkout/download button block
                wrap.style.pointerEvents = 'none';
                wrap.style.opacity = '0.4';

                tracks = p.tracks || [];
                renderTrackList();
                modal.classList.add('open');
                logAction('view_product', p.title, productId);
                // iOS-safe body scroll lock
                document.body.dataset.scrollY = window.scrollY;
                document.body.style.position = 'fixed';
                document.body.style.top = `-${window.scrollY}px`;
                document.body.style.width = '100%';
            });
    }
    function closeModal() {
        audio.pause(); audio.src = '';
        modal.classList.remove('open');
        // Restore body scroll position
        const scrollY = parseInt(document.body.dataset.scrollY || '0');
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.width = '';
        window.scrollTo(0, scrollY);
    }
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
    document.getElementById('termsCheckbox')?.addEventListener('change', function() {
        const wrap = document.getElementById('paypal-btn-wrap');
        if (this.checked) {
            wrap.style.pointerEvents = 'auto';
            wrap.style.opacity = '1';
        } else {
            wrap.style.pointerEvents = 'none';
            wrap.style.opacity = '0.4';
        }
    });
    document.getElementById('btnPlayPause').addEventListener('click', () => {
        if (!tracks.length) return;
        if (currentIdx < 0) { loadTrack(0); return; }
        if (audio.paused) { audio.play(); document.getElementById('btnPlayPause').innerHTML = '&#9646;&#9646;'; }
        else { audio.pause(); document.getElementById('btnPlayPause').innerHTML = '&#9654;'; }
    });
    document.getElementById('btnPrev').addEventListener('click', () => { if (currentIdx > 0) loadTrack(currentIdx-1); });
    document.getElementById('btnNext').addEventListener('click', () => { if (currentIdx < tracks.length-1) loadTrack(currentIdx+1); });
    audio.addEventListener('timeupdate', () => {
        if (!audio.duration) return;
        const segment = getSegment();
        if (segment.end && audio.currentTime >= segment.end) {
            finishCurrentTrack();
            return;
        }
        const elapsed = Math.max(0, audio.currentTime - segment.start);
        const duration = segment.duration || audio.duration;
        document.getElementById('progressBar').style.width = (Math.min(1, elapsed / duration) * 100) + '%';
        document.getElementById('playerTime').textContent = `${fmt(elapsed)} / ${fmt(duration)}`;
    });
    // Progress bar: click + touch seek
    function seekFromEvent(e) {
        if (!audio.duration) return;
        const rect = document.getElementById('progressWrap').getBoundingClientRect();
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const pct = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
        const segment = getSegment();
        audio.currentTime = segment.start + pct * (segment.duration || audio.duration);
    }
    const pw = document.getElementById('progressWrap');
    pw.addEventListener('click', seekFromEvent);
    pw.addEventListener('touchstart', e => { e.preventDefault(); seekFromEvent(e); }, { passive: false });
    pw.addEventListener('touchmove',  e => { e.preventDefault(); seekFromEvent(e); }, { passive: false });
    audio.addEventListener('ended', () => {
        finishCurrentTrack();
    });
    window.onload = () => {
        const previewId = new URLSearchParams(window.location.search).get('preview');
        if (previewId) openModal(previewId);
    };
    // Hamburger nav toggle
    const ham = document.getElementById('navHamburger');
    const navLinks = document.getElementById('navLinks');
    if (ham) {
        ham.addEventListener('click', () => { ham.classList.toggle('open'); navLinks.classList.toggle('open'); });
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
    if (navLinks) {
        navLinks.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
            ham.classList.remove('open');
            navLinks.classList.remove('open');
        }));
    }

    // ── ADMIN DRAG AND DROP ORDERING ──
    <?php if (is_owner()): ?>
    document.addEventListener('DOMContentLoaded', () => {
        const grid = document.querySelector('.kits-grid');
        if (!grid) return;

        let draggedItem = null;

        // Fetch cards and make them draggable
        const cards = grid.querySelectorAll('.kit-card');
        cards.forEach(card => {
            card.setAttribute('draggable', 'true');
            card.style.cursor = 'grab';
            
            card.addEventListener('dragstart', (e) => {
                draggedItem = card;
                card.classList.add('admin-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', card.dataset.id);
            });

            card.addEventListener('dragend', () => {
                card.classList.remove('admin-dragging');
                draggedItem = null;
                // Reveal the save button and update status notice
                const saveBtn = document.getElementById('adminSaveOrderBtn');
                if (saveBtn) saveBtn.style.display = 'inline-block';
                const notice = document.getElementById('adminOrderNotice');
                if (notice) {
                    notice.textContent = '⚠️ UNSAVED CHANGES: CLICK SAVE ORDER TO PUBLISH!';
                    notice.style.color = '#B89B5E'; // soft gold
                }
            });

            card.addEventListener('dragover', (e) => {
                e.preventDefault();
            });

            card.addEventListener('dragenter', (e) => {
                e.preventDefault();
                if (card !== draggedItem) {
                    card.classList.add('admin-drag-over');
                }
            });

            card.addEventListener('dragleave', () => {
                card.classList.remove('admin-drag-over');
            });

            card.addEventListener('drop', (e) => {
                e.preventDefault();
                card.classList.remove('admin-drag-over');
                
                if (draggedItem && card !== draggedItem) {
                    const allCards = Array.from(grid.querySelectorAll('.kit-card'));
                    const draggedIdx = allCards.indexOf(draggedItem);
                    const targetIdx = allCards.indexOf(card);
                    
                    if (draggedIdx < targetIdx) {
                        card.after(draggedItem);
                    } else {
                        card.before(draggedItem);
                    }
                }
            });
        });
    });

    function saveAdminOrder() {
        const btn = document.getElementById('adminSaveOrderBtn');
        btn.disabled = true;
        btn.textContent = '💾 SAVING...';
        
        const productIds = Array.from(document.querySelectorAll('.kit-card')).map(card => parseInt(card.dataset.id));
        
        fetch('/api/update_product_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ product_ids: productIds })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                btn.textContent = '✅ SAVED!';
                btn.style.background = '#28a745';
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                alert(data.error || 'Failed to save order.');
                btn.disabled = false;
                btn.textContent = '💾 SAVE ORDER';
            }
        })
        .catch(err => {
            console.error(err);
            alert('An error occurred while saving.');
            btn.disabled = false;
            btn.textContent = '💾 SAVE ORDER';
        });
    }
    <?php endif; ?>
    </script>
</body>
</html>

