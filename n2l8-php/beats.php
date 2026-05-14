<?php
require_once __DIR__ . '/includes/db.php';
// Updated: 2026-05-14 19:27
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/config.php';

$pdo  = get_pdo();
$site = get_site_content($pdo);

// Fetch only beats
$stmt = $pdo->query("SELECT * FROM products WHERE is_active = 1 AND type = 'beat' ORDER BY id DESC");
$beats = $stmt->fetchAll();

// Fetch shop settings
$settings = get_site_content($pdo);
$pp_mode = $settings['paypal_mode'] ?? 'sandbox';
$pp_id   = ($pp_mode === 'live') ? ($settings['paypal_client_id_live'] ?? '') : ($settings['paypal_client_id_sandbox'] ?? 'test');

log_visitor($pdo, 'page_view', '/beats.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Beats - n2l8studio</title>
    <meta name="description" content="Premium beats from n2l8studio.">
    <link rel="stylesheet" href="/static/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Righteous&family=VT323&display=swap" rel="stylesheet">
    <link rel="icon" href="data:;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==">
</head>
<body class="page-beats">
    <header class="hero" style="min-height:auto;padding-bottom:1rem;">
        <nav>
            <a href="/index.php" class="logo-text" style="text-decoration:none;">n2l8studio</a>
            <button class="nav-hamburger" id="navHamburger" aria-label="Menu">
                <span></span><span></span><span></span>
            </button>
            <ul class="nav-links" id="navLinks">
                <li class="dropdown">
                    <a href="javascript:void(0)" class="dropbtn">Shop</a>
                    <div class="dropdown-content">
                        <a href="/shop.php">Loopkits & Drumkits</a>
                        <a href="/beats.php">Beats</a>
                    </div>
                </li>
                <li><a href="/pricing.php"><?= h($site['nav_pricing'] ?? 'Mixing & Mastering') ?></a></li>
                <li><a href="/admin/login.php" class="nav-admin-btn">Login</a></li>
            </ul>
        </nav>
    </header>

    <main class="beats-container">
        <h2 class="section-title">Beats</h2>
        
        <div class="beats-list">
            <?php foreach ($beats as $beat): ?>
            <div class="beat-row" data-id="<?= $beat['id'] ?>">
                <button class="beat-play-btn btn-play" data-id="<?= $beat['id'] ?>">▶</button>
                <div class="beat-info">
                    <h3><?= h($beat['title'] ?? '') ?></h3>
                    <p><?= h($beat['author'] ?? 'n2l8studio') ?></p>
                </div>
                <div class="beat-tags">
                    <span class="beat-tag"><?= h($beat['genre'] ?? '') ?></span>
                </div>
                <div class="beat-bpm-key">
                    <?= h($beat['bpm'] ?? '') ?> BPM | <?= h($beat['key'] ?? '') ?>
                </div>
                <div class="beat-price-btn">
                    <button class="cta-btn beat-buy-btn btn-buy" data-id="<?= $beat['id'] ?>">
                        <?= (float)$beat['price'] > 0 ? '$' . number_format($beat['price'], 2) : 'FREE' ?>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($beats)): ?>
            <p style="text-align:center; color:var(--text-muted); padding:3rem;">NO BEATS DETECTED IN THE VAULT.</p>
            <?php endif; ?>
        </div>
    </main>

    <!-- Global Player Bar (Hidden by default, shows when playing) -->
    <div id="playerBar" style="position:fixed; bottom:0; left:0; width:100%; background:rgba(5,10,5,0.95); border-top:2px solid var(--accent); padding:1rem; display:none; z-index:1000;">
        <div class="container" style="display:flex; align-items:center; gap:1.5rem;">
            <div id="playerInfo" style="flex:1;">
                <div id="playerTitle" style="font-family:'Righteous'; color:var(--text-main);"></div>
                <div id="playerArtist" style="font-size:0.85rem; color:var(--text-muted);"></div>
            </div>
            <div class="player-controls" style="display:flex; align-items:center; gap:1rem;">
                <button id="globalPlayBtn" class="beat-play-btn">▶</button>
                <div style="font-family:'VT323'; color:var(--accent); min-width:40px;" id="currentTime">0:00</div>
                <input type="range" id="seekSlider" style="flex:1; accent-color:var(--accent);" value="0" step="0.1">
                <div style="font-family:'VT323'; color:var(--accent); min-width:40px;" id="durationTime">0:00</div>
            </div>
        </div>
    </div>

    <!-- REUSING THE MODAL FROM SHOP.PHP FOR CHECKOUT -->
    <div class="modal-overlay" id="modalOverlay">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal()">×</button>
            <div class="modal-content" id="modalContent">
                <!-- Loaded via JS -->
            </div>
        </div>
    </div>

    <footer><p><?= h($site['footer_text'] ?? '© 2026 n2l8studio. All rights reserved.') ?></p></footer>

    <?php $pp_id = defined('PAYPAL_CLIENT_ID') ? PAYPAL_CLIENT_ID : 'test'; ?>
    <script src="https://www.paypal.com/sdk/js?client-id=<?= h($pp_id) ?>&currency=USD&intent=capture" data-sdk-integration-source="button-factory"></script>
    
    <script>
    let currentAudio = new Audio();
    let playingId = null;
    let _ppButtons = null;

    function logAction(action, metadata = '') {
        console.log('Logging action:', action, metadata);
        fetch('/api/log_action.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=' + encodeURIComponent(action) + '&metadata=' + encodeURIComponent(metadata)
        });
    }

    function togglePlay(id) {
        const btn = document.querySelector(`.btn-play[data-id="${id}"]`);
        if (!btn) return;
        
        if (playingId === id) {
            if (currentAudio.paused) {
                currentAudio.play();
                btn.textContent = '⏸';
                document.getElementById('globalPlayBtn').textContent = '⏸';
            } else {
                currentAudio.pause();
                btn.textContent = '▶';
                document.getElementById('globalPlayBtn').textContent = '▶';
            }
            return;
        }

        if (playingId) {
            const prevBtn = document.querySelector(`.btn-play[data-id="${playingId}"]`);
            if (prevBtn) prevBtn.textContent = '▶';
        }

        playingId = id;
        btn.textContent = '...';
        
        fetch(`/api/product.php?id=${id}`)
            .then(r => r.json())
            .then(data => {
                console.log('Beat data received:', data);
                if (data.tracks && data.tracks.length > 0) {
                    const trackUrl = data.tracks[0].url;
                    console.log('Attempting to play:', trackUrl);
                    currentAudio.src = trackUrl;
                    
                    const playPromise = currentAudio.play();
                    if (playPromise !== undefined) {
                        playPromise.then(_ => {
                            btn.textContent = '⏸';
                            document.getElementById('playerBar').style.display = 'block';
                            document.getElementById('playerTitle').textContent = data.title;
                            document.getElementById('playerArtist').textContent = data.author || 'n2l8studio';
                            document.getElementById('globalPlayBtn').textContent = '⏸';
                            logAction('play_beat', data.title);
                        }).catch(error => {
                            console.error('Playback failed:', error);
                            btn.textContent = '▶';
                            alert('Playback failed. Please ensure you have interacted with the page first.');
                        });
                    }
                } else {
                    console.warn('No tracks found for this beat.');
                    btn.textContent = '▶';
                    alert('No preview track available for this beat.');
                }
            })
            .catch(err => {
                console.error('Fetch error:', err);
                btn.textContent = '▶';
            });
    }

    // Bind play buttons
    document.querySelectorAll('.btn-play').forEach(b => {
        b.addEventListener('click', () => togglePlay(parseInt(b.dataset.id)));
    });

    // Bind buy buttons
    document.querySelectorAll('.btn-buy').forEach(b => {
        b.addEventListener('click', () => openModal(parseInt(b.dataset.id)));
    });

    document.getElementById('globalPlayBtn').addEventListener('click', () => {
        if (playingId) togglePlay(playingId);
    });

    currentAudio.ontimeupdate = () => {
        const seek = document.getElementById('seekSlider');
        const current = document.getElementById('currentTime');
        const total = document.getElementById('durationTime');
        if (currentAudio.duration) {
            seek.value = (currentAudio.currentTime / currentAudio.duration) * 100;
            current.textContent = formatTime(currentAudio.currentTime);
            total.textContent = formatTime(currentAudio.duration);
        }
    };

    function formatTime(s) {
        const m = Math.floor(s / 60);
        const sec = Math.floor(s % 60);
        return m + ':' + (sec < 10 ? '0' + sec : sec);
    }

    document.getElementById('seekSlider').oninput = (e) => {
        if (currentAudio.duration) {
            currentAudio.currentTime = (e.target.value / 100) * currentAudio.duration;
        }
    };

    function openModal(id) {
        const overlay = document.getElementById('modalOverlay');
        const content = document.getElementById('modalContent');
        content.innerHTML = '<div style="padding:4rem;text-align:center;font-family:\'VT323\';color:var(--accent);">DECRYPTING DATA...</div>';
        overlay.classList.add('open');

        // Benefits from PHP
        const benefits = {
            basic: <?= json_encode(explode("\n", $settings['license_basic_features'] ?? "")) ?>,
            premium: <?= json_encode(explode("\n", $settings['license_premium_features'] ?? "")) ?>,
            exclusive: <?= json_encode(explode("\n", $settings['license_exclusive_features'] ?? "")) ?>
        };

        fetch(`/api/product.php?id=${id}`)
            .then(r => r.json())
            .then(data => {
                const basePrice = parseFloat(data.price || 0);
                const premiumPrice = data.price_premium ? parseFloat(data.price_premium) : basePrice * 2;
                const exclusivePrice = data.price_exclusive ? parseFloat(data.price_exclusive) : basePrice * 10;

                const renderList = (list) => list.map(item => `<li>${item.trim()}</li>`).join('');

                content.innerHTML = `
                    <div class="modal-license-header" style="text-align:center; margin-bottom:2rem; padding-bottom:1rem; border-bottom:1px solid var(--accent);">
                        <h2 style="font-family:'Righteous'; color:var(--text-main); margin:0;">SELECT LICENSE</h2>
                        <p style="color:var(--text-muted); font-size:0.9rem;">${data.title} - ${data.author || 'n2l8studio'}</p>
                    </div>
                    
                    <div class="license-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem;">
                        <!-- MP3/WAV -->
                        <div class="license-card" style="border:1px solid var(--text-muted); padding:1.5rem; text-align:center; transition:0.3s; border-radius:4px;">
                            <h3 style="color:var(--accent); margin-top:0;">MP3 & WAV</h3>
                            <div style="font-size:1.5rem; font-family:'Righteous'; margin:1rem 0;">$${basePrice.toFixed(2)}</div>
                            <ul style="list-style:none; padding:0; font-size:0.8rem; text-align:left; color:var(--text-muted); margin-bottom:1.5rem;">
                                ${renderList(benefits.basic)}
                            </ul>
                            <button class="cta-btn buy-tier" data-price="${basePrice}" data-name="MP3/WAV" style="width:100%;">SELECT</button>
                        </div>

                        <!-- STEMS -->
                        <div class="license-card" style="border:2px solid var(--accent); padding:1.5rem; text-align:center; position:relative; border-radius:4px; transform: scale(1.05);">
                            <div style="position:absolute; top:-12px; left:50%; transform:translateX(-50%); background:var(--accent); color:#000; font-size:0.7rem; padding:2px 8px; font-weight:bold;">POPULAR</div>
                            <h3 style="color:var(--accent); margin-top:0;">WAV & STEMS</h3>
                            <div style="font-size:1.5rem; font-family:'Righteous'; margin:1rem 0;">$${premiumPrice.toFixed(2)}</div>
                            <ul style="list-style:none; padding:0; font-size:0.8rem; text-align:left; color:var(--text-muted); margin-bottom:1.5rem;">
                                ${renderList(benefits.premium)}
                            </ul>
                            <button class="cta-btn buy-tier" data-price="${premiumPrice}" data-name="WAV/STEMS" style="width:100%;">SELECT</button>
                        </div>

                        <!-- EXCLUSIVE -->
                        <div class="license-card" style="border:1px solid var(--text-muted); padding:1.5rem; text-align:center; border-radius:4px;">
                            <h3 style="color:var(--accent); margin-top:0;">EXCLUSIVE</h3>
                            <div style="font-size:1.5rem; font-family:'Righteous'; margin:1rem 0;">$${exclusivePrice.toFixed(2)}</div>
                            <ul style="list-style:none; padding:0; font-size:0.8rem; text-align:left; color:var(--text-muted); margin-bottom:1.5rem;">
                                ${renderList(benefits.exclusive)}
                            </ul>
                            <button class="cta-btn buy-tier" data-price="${exclusivePrice}" data-name="EXCLUSIVE" style="width:100%;">SELECT</button>
                        </div>
                    </div>

                    <div id="paypal-btn-wrap" style="margin-top:2rem; min-height:50px;"></div>
                `;
                
                logAction('modal_open_beat', data.title);

                // Bind license selection
                content.querySelectorAll('.buy-tier').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const price = btn.dataset.price;
                        const tier = btn.dataset.name;
                        logAction('select_license', `${data.title} - ${tier}`);
                        renderPayPalButtons(data.id, price, tier);
                        
                        // Highlight selected
                        content.querySelectorAll('.license-card').forEach(c => c.style.borderColor = 'var(--text-muted)');
                        btn.closest('.license-card').style.borderColor = 'var(--accent)';
                    });
                });
            });
    }

    function closeModal() {
        document.getElementById('modalOverlay').classList.remove('open');
    }
    document.querySelector('.modal-close')?.addEventListener('click', closeModal);

    function renderPayPalButtons(productId, price, tierName) {
        const wrap = document.getElementById('paypal-btn-wrap');
        wrap.innerHTML = '';
        if (parseFloat(price) <= 0) {
            wrap.innerHTML = '<a href="#" class="cta-btn" style="display:block;text-align:center;">FREE DOWNLOAD</a>';
            return;
        }
        if (typeof paypal !== 'undefined') {
            paypal.Buttons({
                style: { layout: 'horizontal', color: 'gold', shape: 'rect' },
                createOrder: (data, actions) => actions.order.create({
                    purchase_units: [{ 
                        description: `Beat License: ${tierName}`,
                        amount: { value: price } 
                    }]
                }),
                onApprove: (data, actions) => actions.order.capture().then(details => {
                    alert('Transaction completed by ' + details.payer.name.given_name);
                    logAction('purchase_success', `${productId} - ${tierName}`);
                })
            }).render('#paypal-btn-wrap');
        } else {
            wrap.innerHTML = '<div style="color:var(--accent);text-align:center;">PAYPAL NOT INITIALIZED</div>';
        }
    }

    const ham = document.getElementById('navHamburger');
    const nl  = document.getElementById('navLinks');
    if (ham) {
        ham.addEventListener('click', () => { ham.classList.toggle('open'); nl.classList.toggle('open'); });
    }

    const dropbtn = document.querySelector('.dropbtn');
    if (dropbtn) {
        dropbtn.addEventListener('click', (e) => {
            e.preventDefault();
            document.querySelector('.dropdown-content').classList.toggle('show');
        });
    }
    window.addEventListener('click', (event) => {
        if (!event.target.matches('.dropbtn')) {
            document.querySelectorAll('.dropdown-content').forEach(d => d.classList.remove('show'));
        }
    });
    </script>
</body>
</html>
