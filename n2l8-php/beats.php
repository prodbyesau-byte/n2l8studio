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
    <title>Beats - N2L8 STUDIO</title>
    <meta name="description" content="Premium beats from n2l8studio.">
    <link rel="stylesheet" href="/static/style.css?v=12">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="/static/logo.png">
    <link rel="apple-touch-icon" href="/static/logo.png">
</head>
<body class="page-beats">
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
                <?php if (is_logged_in()): ?>
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
            <p style="text-align:center; color:var(--text-muted); padding:3rem;">NO BEATS DETECTED IN THE PORTAL.</p>
            <?php endif; ?>
        </div>
    </main>

    <!-- Global Player Bar (Hidden by default, shows when playing) -->
    <div id="playerBar" style="position:fixed; bottom:0; left:0; width:100%; background:rgba(5,5,8,0.96); border-top:1.5px solid var(--accent); padding:1.2rem; display:none; z-index:1000; box-shadow: 0 -5px 25px rgba(0,0,0,0.5);">
        <div class="container" style="display:flex; align-items:center; gap:2rem; justify-content:space-between;">
            <div id="playerInfo" style="flex:1;">
                <div id="playerTitle" style="font-family:'Montserrat',sans-serif; font-weight:700; color:var(--text-main); font-size: 0.95rem;"></div>
                <div id="playerArtist" style="font-size:0.75rem; color:var(--text-muted); font-family:'Montserrat',sans-serif; margin-top: 0.2rem;"></div>
            </div>
            <div class="player-controls" style="display:flex; align-items:center; gap:1.2rem; flex:2;">
                <button id="globalPlayBtn" class="beat-play-btn">▶</button>
                <div style="font-family:'Montserrat',sans-serif; font-size: 0.8rem; color:var(--text-muted); min-width:40px;" id="currentTime">0:00</div>
                <input type="range" id="seekSlider" style="flex:1; accent-color:var(--accent); cursor:pointer; height:4px; border-radius:2px;" value="0" step="0.1">
                <div style="font-family:'Montserrat',sans-serif; font-size: 0.8rem; color:var(--text-muted); min-width:40px;" id="durationTime">0:00</div>
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

    <footer>
        <p><?= h($site['footer_text'] ?? '© 2026 n2l8studio. All rights reserved.') ?></p>
    </footer>

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
        content.innerHTML = '<div style="padding:4rem;text-align:center;font-family:\'Montserrat\',sans-serif;color:var(--accent);font-weight:600;letter-spacing:2px;">DECRYPTING DATA...</div>';
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
                        <h2 style="font-family:'Syncopate',sans-serif; color:var(--text-main); margin:0; font-size:1.3rem; letter-spacing:2px;">SELECT LICENSE</h2>
                        <p style="color:var(--text-muted); font-size:0.9rem;">${data.title} - ${data.author || 'n2l8studio'}</p>
                    </div>
                    
                    <div class="license-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem;">
                        <!-- MP3/WAV -->
                        <div class="license-card" style="border:1px solid var(--text-muted); padding:1.5rem; text-align:center; transition:0.3s; border-radius:4px;">
                            <h3 style="color:var(--accent); margin-top:0;">MP3 & WAV</h3>
                            <div style="font-size:1.5rem; font-family:'Syncopate',sans-serif; margin:1rem 0; font-weight:700;">$${basePrice.toFixed(2)}</div>
                            <ul style="list-style:none; padding:0; font-size:0.8rem; text-align:left; color:var(--text-muted); margin-bottom:1.5rem;">
                                ${renderList(benefits.basic)}
                            </ul>
                            <button class="cta-btn buy-tier" data-price="${basePrice}" data-name="MP3/WAV" style="width:100%;">SELECT</button>
                        </div>

                        <!-- STEMS -->
                        <div class="license-card" style="border:2px solid var(--accent); padding:1.5rem; text-align:center; position:relative; border-radius:4px; transform: scale(1.05);">
                            <div style="position:absolute; top:-12px; left:50%; transform:translateX(-50%); background:var(--accent); color:#000; font-size:0.7rem; padding:2px 8px; font-weight:bold;">POPULAR</div>
                            <h3 style="color:var(--accent); margin-top:0;">WAV & STEMS</h3>
                            <div style="font-size:1.5rem; font-family:'Syncopate',sans-serif; margin:1rem 0; font-weight:700;">$${premiumPrice.toFixed(2)}</div>
                            <ul style="list-style:none; padding:0; font-size:0.8rem; text-align:left; color:var(--text-muted); margin-bottom:1.5rem;">
                                ${renderList(benefits.premium)}
                            </ul>
                            <button class="cta-btn buy-tier" data-price="${premiumPrice}" data-name="WAV/STEMS" style="width:100%;">SELECT</button>
                        </div>

                        <!-- EXCLUSIVE -->
                        <div class="license-card" style="border:1px solid var(--text-muted); padding:1.5rem; text-align:center; border-radius:4px;">
                            <h3 style="color:var(--accent); margin-top:0;">EXCLUSIVE</h3>
                            <div style="font-size:1.5rem; font-family:'Syncopate',sans-serif; margin:1rem 0; font-weight:700;">$${exclusivePrice.toFixed(2)}</div>
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
                        renderPayPalButtons(data.id, price, tier, data);
                        
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

    function renderPayPalButtons(productId, price, tierName, data) {
        const wrap = document.getElementById('paypal-btn-wrap');
        wrap.innerHTML = '';
        if (parseFloat(price) <= 0) {
            if (data && data.allow_download && data.zip_file) {
                wrap.innerHTML = `<a href="${data.zip_file}" class="cta-btn" style="display:block;text-align:center;text-decoration:none;" download>FREE DOWNLOAD</a>`;
            } else {
                wrap.innerHTML = `<button disabled class="cta-btn" style="display:block;width:100%;text-align:center;opacity:0.4;cursor:not-allowed;border:1px solid rgba(123,225,168,0.2);background:rgba(123,225,168,0.04);color:rgba(123,225,168,0.3);">FREE DOWNLOAD — COMING SOON</button>`;
            }
            return;
        }

        // ── Stripe Credit Card Button ──
        const stripeBtn = document.createElement('button');
        stripeBtn.className = 'cta-btn modal-buy-btn';
        stripeBtn.style.background = '#C0152A';
        stripeBtn.style.color = '#ffffff';
        stripeBtn.style.border = 'none';
        stripeBtn.style.padding = '0.85rem';
        stripeBtn.style.fontSize = '0.9rem';
        stripeBtn.style.fontFamily = "'Syncopate', sans-serif";
        stripeBtn.style.fontWeight = '700';
        stripeBtn.style.letterSpacing = '1px';
        stripeBtn.style.borderRadius = '4px';
        stripeBtn.style.cursor = 'pointer';
        stripeBtn.style.marginBottom = '1rem';
        stripeBtn.style.width = '100%';
        stripeBtn.style.display = 'block';
        stripeBtn.style.transition = 'all 0.3s';
        stripeBtn.innerHTML = '💳 PAY WITH CARD';
        
        stripeBtn.addEventListener('click', () => {
            stripeBtn.textContent = 'SECURE REDIRECT...';
            stripeBtn.disabled = true;
            stripeBtn.style.opacity = '0.7';
            
            const form = new FormData();
            form.append('product_id', productId);
            form.append('license_tier', tierName);
            
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
        sep.style.fontFamily = "'Montserrat', sans-serif";
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

        if (typeof paypal !== 'undefined') {
            if (_ppButtons) { try { _ppButtons.close(); } catch(e) {} }
            _ppButtons = paypal.Buttons({
                style: { layout: 'horizontal', color: 'gold', shape: 'rect', height: 45 },
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
            });
            _ppButtons.render('#paypal-button-container-inner');
        } else {
            ppContainer.innerHTML = '<div style="color:var(--accent);text-align:center;">PAYPAL NOT INITIALIZED</div>';
        }
    }

    const ham = document.getElementById('navHamburger');
    const nl  = document.getElementById('navLinks');
    if (ham) {
        ham.addEventListener('click', () => { ham.classList.toggle('open'); nl.classList.toggle('open'); });
    }

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

    window.addEventListener('click', (event) => {
        if (!event.target.matches('.dropbtn')) {
            document.querySelectorAll('.dropdown-content').forEach(d => d.classList.remove('show'));
        }
    });
    </script>
</body>
</html>
