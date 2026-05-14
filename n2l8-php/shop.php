<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo  = get_pdo();
$site = get_site_content($pdo);

$stmt = $pdo->query('SELECT * FROM products WHERE is_active = 1 ORDER BY id DESC');
$products = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop - n2l8studio</title>
    <meta name="description" content="Shop loopkits, drumkits and beats from n2l8studio.">
    <link rel="stylesheet" href="/static/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Righteous&family=VT323&display=swap" rel="stylesheet">
    <style>
        /* ── MODAL OVERLAY ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.85);
            z-index: 1000;
            /* scroll the overlay itself on mobile so modal content is reachable */
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 1rem 0;
        }
        .modal-overlay.open { display: block; }
        .modal-box {
            background: var(--bg-card);
            border: 1px solid var(--text-muted);
            box-shadow: 0 0 40px rgba(51,255,153,0.15);
            width: 92%;
            max-width: 780px;
            margin: 0 auto;
            position: relative;
            display: grid;
            grid-template-columns: 260px 1fr;
            /* allow content inside box to scroll independently */
            overflow: visible;
            overscroll-behavior: contain;
        }
        .modal-cover-col { background:var(--bg-dark); display:flex; flex-direction:column; align-items:center; padding:2rem 1.5rem; border-right:1px solid var(--text-muted); }
        .modal-cover-img { width:100%; max-width:200px; aspect-ratio:1; object-fit:cover; border:1px solid var(--text-muted); margin-bottom:1.2rem; filter:sepia(0.2) contrast(1.1); }
        .modal-cover-placeholder { width:200px; height:200px; background:#1a1a1a; border:1px solid var(--text-muted); margin-bottom:1.2rem; }
        .modal-price { font-family:'Righteous',cursive; color:var(--accent); font-size:2rem; text-align:center; line-height:1; }
        .modal-price-orig { color:var(--text-muted); text-decoration:line-through; font-size:1rem; text-align:center; margin-bottom:1rem; }
        .modal-buy-btn { width:100%; margin-top:auto; }
        .modal-info-col { padding:2rem; display:flex; flex-direction:column; gap:0.5rem; }
        .modal-close {
            position: absolute;
            top: 0.8rem; right: 0.8rem;
            background: rgba(0,0,0,0.6);
            border: 1px solid var(--text-muted);
            color: var(--text-muted);
            font-size: 2rem;
            cursor: pointer;
            line-height: 1;
            transition: color 0.2s;
            font-family: 'VT323', monospace;
            width: 44px; height: 44px; /* big tap target */
            display: flex; align-items: center; justify-content: center;
            z-index: 10;
        }
        .modal-close:hover { color:#ff5c5c; }
        .modal-title { font-family:'Righteous',cursive; font-size:1.8rem; color:var(--text-main); text-transform:uppercase; letter-spacing:2px; margin-bottom:0.2rem; }
        .modal-author { color:var(--text-muted); font-size:1.1rem; margin-bottom:0.5rem; }
        .modal-tags { display:flex; flex-wrap:wrap; gap:0.4rem; margin-bottom:0.8rem; }
        .modal-tag { background:rgba(57,255,20,0.08); border:1px solid var(--text-muted); color:var(--text-muted); padding:0.1rem 0.6rem; font-family:'VT323',monospace; font-size:0.95rem; text-transform:uppercase; letter-spacing:1px; }
        .modal-tag.accent { color:var(--accent); border-color:var(--accent); background:rgba(255,194,92,0.08); }
        .modal-desc { color:var(--text-muted); font-size:1.05rem; line-height:1.6; margin-bottom:1rem; border-top:1px dashed rgba(123,225,168,0.2); padding-top:0.8rem; }
        .player-section { border-top:1px dashed rgba(123,225,168,0.2); padding-top:1rem; }
        .player-section-label { font-family:'Righteous',cursive; color:var(--accent); font-size:1rem; text-transform:uppercase; letter-spacing:2px; margin-bottom:0.8rem; }
        .track-list { list-style:none; padding:0; margin-bottom:1rem; }
        .track-item {
            display:flex; align-items:center; gap:0.8rem;
            padding:0.7rem 0.6rem; /* taller for touch */
            cursor:pointer; border-left:2px solid transparent;
            transition:all 0.15s ease;
            min-height: 44px; /* iOS minimum tap target */
        }
        .track-item:hover { background:rgba(57,255,20,0.05); border-left-color:var(--text-muted); }
        .track-item.playing { border-left-color:var(--text-main); background:rgba(57,255,20,0.07); }
        .track-item.playing .track-item-name { color:var(--text-main); }
        .track-num { font-family:'Righteous',cursive; color:var(--text-muted); min-width:20px; font-size:0.9rem; }
        .track-item-name { flex:1; font-size:1.05rem; color:var(--text-muted); }
        .play-icon { color:var(--accent); font-size:1.2rem; min-width:18px; text-align:center; }
        .player-controls { display:flex; flex-direction:column; gap:0.5rem; background:rgba(0,0,0,0.3); border:1px solid rgba(123,225,168,0.2); padding:0.8rem 1rem; }
        .player-now-playing { font-family:'VT323',monospace; color:var(--text-muted); font-size:0.95rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .player-now-playing span { color:var(--text-main); }
        .player-row { display:flex; align-items:center; gap:0.8rem; }
        .player-btn {
            background:transparent; border:none; color:var(--text-main);
            font-size:1.6rem; cursor:pointer;
            padding:0.4rem 0.6rem; /* bigger touch target */
            min-width: 44px; min-height: 44px;
            transition:color 0.2s,text-shadow 0.2s;
            font-family:'VT323',monospace; line-height:1;
            display:flex; align-items:center; justify-content:center;
        }
        .player-btn:hover { color:var(--accent); text-shadow:0 0 8px rgba(255,194,92,0.6); }
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
            background:rgba(123,225,168,0.2);
            pointer-events: none;
        }
        .progress-bar {
            height: 4px;
            background:var(--text-main);
            width:0%;
            transition:width 0.1s linear;
            box-shadow:0 0 6px rgba(51,255,153,0.6);
            pointer-events: none;
        }
        .player-time { font-family:'VT323',monospace; color:var(--text-muted); font-size:0.95rem; min-width:70px; text-align:right; }
        .no-tracks-msg { color:var(--text-muted); font-size:1rem; font-style:italic; padding:0.5rem 0; }
        @media(max-width:640px){
            .modal-box { grid-template-columns:1fr; width:96%; }
            .modal-cover-col { border-right:none; border-bottom:1px solid var(--text-muted); padding:1.5rem 1rem; }
            .modal-info-col { padding:1.2rem; }
            .modal-title { font-size:1.4rem; }
        }
    </style>
</head>
<body class="page-shop">
    <header class="hero" style="min-height:auto;padding-bottom:2rem;">
        <nav>
            <div class="logo-text">n2l8studio</div>
            <button class="nav-hamburger" id="navHamburger" aria-label="Menu">
                <span></span><span></span><span></span>
            </button>
            <ul class="nav-links" id="navLinks">
                <li><a href="/shop.php"><?= h($site['nav_shop'] ?? 'Shop') ?></a></li>
                <li><a href="/pricing.php"><?= h($site['nav_pricing'] ?? 'Mixing & Mastering') ?></a></li>
                <li><a href="/admin/login.php" class="nav-admin-btn">Admin Vault</a></li>
            </ul>
        </nav>
    </header>

    <section id="shop" class="kits">
        <div class="container">
            <h2><?= h($site['shop_h2'] ?? 'Sample Packs & Drumkits') ?></h2>
            <p class="section-desc"><?= h($site['shop_desc'] ?? '') ?></p>

            <div class="kits-filter">
                <div class="filter-group">
                    <span class="filter-label">Type</span>
                    <button class="filter-tab active" data-filter-type="type" data-value="all">All</button>
                    <button class="filter-tab" data-filter-type="type" data-value="loopkit">Loop Kits</button>
                    <button class="filter-tab" data-filter-type="type" data-value="drumkit">Drumkits</button>
                    <button class="filter-tab" data-filter-type="type" data-value="beat">Beats</button>
                </div>
                <div class="filter-group">
                    <span class="filter-label">Genre</span>
                    <button class="filter-tab active" data-filter-type="genre" data-value="all">All</button>
                    <button class="filter-tab" data-filter-type="genre" data-value="trap">Trap</button>
                    <button class="filter-tab" data-filter-type="genre" data-value="melodic">Melodic</button>
                    <button class="filter-tab" data-filter-type="genre" data-value="drill">Drill</button>
                    <button class="filter-tab" data-filter-type="genre" data-value="rnb">R&B</button>
                </div>
            </div>

            <div class="kits-grid">
                <?php if (empty($products)): ?>
                <p style="color:var(--text-muted);grid-column:1/-1;text-align:center;padding:3rem 0;">No products available. Check back soon.</p>
                <?php else: foreach ($products as $p): ?>
                <div class="kit-card" data-type="<?= h($p['type']) ?>" data-genre="<?= h($p['genre']) ?>" data-id="<?= (int)$p['id'] ?>">
                    <div class="kit-cover <?= $p['cover_image'] ? '' : 'placeholder-1' ?>">
                        <?php if ($p['cover_image']): ?>
                        <img src="/static/uploads/<?= h($p['cover_image']) ?>" alt="<?= h($p['title']) ?> Cover" class="kit-image">
                        <?php endif; ?>
                        <?php if ($p['type'] === 'beat'): ?>
                        <span class="kit-badge">BEAT</span>
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
                        <button class="cta-btn kit-btn" onclick="openModal(<?= (int)$p['id'] ?>)">Preview &amp; Buy</button>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </section>

    <footer><p><?= h($site['footer_text'] ?? '© 2026 n2l8studio.') ?></p></footer>

    <!-- MODAL -->
    <div class="modal-overlay" id="productModal">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal()" title="Close">&times;</button>
            <div class="modal-cover-col">
                <div id="modalCoverWrap"></div>
                <div class="modal-price" id="modalPrice"></div>
                <div class="modal-price-orig" id="modalPriceOrig"></div>
                <a href="#" id="modalBuyBtn" class="cta-btn modal-buy-btn" style="text-align:center;margin-top:1rem;font-size:1rem;" download>Download / Buy</a>
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
    /* ── FILTER ── */
    const typeTabs = document.querySelectorAll('.filter-tab[data-filter-type="type"]');
    const genreTabs = document.querySelectorAll('.filter-tab[data-filter-type="genre"]');
    const cards = document.querySelectorAll('.kit-card');
    let currentType = 'all', currentGenre = 'all';
    function updateTabs(tabs, val) { tabs.forEach(t => t.classList.toggle('active', t.dataset.value === val)); }
    function filterKits() {
        cards.forEach(card => {
            const match = (currentType === 'all' || card.dataset.type === currentType)
                       && (currentGenre === 'all' || card.dataset.genre === currentGenre);
            card.style.opacity = match ? '1' : '0';
            card.style.display = match ? 'flex' : 'none';
        });
    }
    typeTabs.forEach(t => t.addEventListener('click', () => { currentType = t.dataset.value; updateTabs(typeTabs, currentType); filterKits(); }));
    genreTabs.forEach(t => t.addEventListener('click', () => { currentGenre = t.dataset.value; updateTabs(genreTabs, currentGenre); filterKits(); }));

    /* ── MODAL + PLAYER ── */
    const audio = document.getElementById('audioEl');
    const modal = document.getElementById('productModal');
    let tracks = [], currentIdx = -1;

    function fmt(secs) {
        if (!isFinite(secs)) return '0:00';
        return `${Math.floor(secs/60)}:${Math.floor(secs%60).toString().padStart(2,'0')}`;
    }
    function updateNavBtns() {
        const onlyOne = tracks.length <= 1;
        document.getElementById('btnPrev').disabled = onlyOne || currentIdx <= 0;
        document.getElementById('btnNext').disabled = onlyOne || currentIdx >= tracks.length - 1;
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
        audio.src = tracks[idx].url;
        audio.play();
        document.getElementById('nowPlayingName').textContent = tracks[idx].title;
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
                const buyBtn = document.getElementById('modalBuyBtn');
                if (p.zip_file) {
                    buyBtn.href = p.zip_file;
                    buyBtn.setAttribute('download', '');
                    buyBtn.textContent = p.price > 0 ? 'Add to Cart' : 'Free Download';
                } else {
                    buyBtn.href = '#'; buyBtn.removeAttribute('download'); buyBtn.textContent = 'Coming Soon';
                }
                tracks = p.tracks || [];
                renderTrackList();
                modal.classList.add('open');
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
        document.getElementById('progressBar').style.width = (audio.currentTime/audio.duration*100)+'%';
        document.getElementById('playerTime').textContent = `${fmt(audio.currentTime)} / ${fmt(audio.duration)}`;
    });
    // Progress bar: click + touch seek
    function seekFromEvent(e) {
        if (!audio.duration) return;
        const rect = document.getElementById('progressWrap').getBoundingClientRect();
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const pct = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
        audio.currentTime = pct * audio.duration;
    }
    const pw = document.getElementById('progressWrap');
    pw.addEventListener('click', seekFromEvent);
    pw.addEventListener('touchstart', e => { e.preventDefault(); seekFromEvent(e); }, { passive: false });
    pw.addEventListener('touchmove',  e => { e.preventDefault(); seekFromEvent(e); }, { passive: false });
    audio.addEventListener('ended', () => {
        if (currentIdx < tracks.length-1) loadTrack(currentIdx+1);
        else { document.getElementById('btnPlayPause').innerHTML = '&#9646;&#9646;'; currentIdx=-1; renderTrackList(); }
    });
    window.onload = () => {
        const previewId = new URLSearchParams(window.location.search).get('preview');
        if (previewId) openModal(previewId);
    };
    // Hamburger nav toggle
    const ham = document.getElementById('navHamburger');
    const navLinks = document.getElementById('navLinks');
    if (ham) {
        ham.addEventListener('click', () => {
            ham.classList.toggle('open');
            navLinks.classList.toggle('open');
        });
        navLinks.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
            ham.classList.remove('open');
            navLinks.classList.remove('open');
        }));
    }
    </script>
</body>
</html>

