<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo  = get_pdo();
$site = get_site_content($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription - N2L8 STUDIO</title>
    <link rel="stylesheet" href="/static/style.css?v=12">
    <link rel="icon" type="image/png" href="/static/logo.png">
    <link rel="apple-touch-icon" href="/static/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
</head>
<body class="page-sub">
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

    <section class="services">
        <div class="container" style="max-width:600px;text-align:center;">
            <h2><?= h($site['sub_h2'] ?? 'Monthly Rations') ?></h2>
            <p class="section-desc"><?= h($site['sub_desc'] ?? '') ?></p>
            <div class="pricing-card" style="margin-top:2rem;">
                <h3>Pro Plan</h3>
                <div class="price"><?= h($site['sub_pro_price'] ?? '$19') ?><span><?= h($site['sub_pro_unit'] ?? '.99/mo') ?></span></div>
                <ul style="text-align:left;">
                    <li><?= h($site['sub_pro_f1'] ?? '') ?></li>
                    <li><?= h($site['sub_pro_f2'] ?? '') ?></li>
                    <li><?= h($site['sub_pro_f3'] ?? '') ?></li>
                    <li><?= h($site['sub_pro_f4'] ?? '') ?></li>
                </ul>
                <?php if (is_logged_in()): ?>
                    <button class="cta-btn" id="subscribeBtn" style="border:none; cursor:pointer; width:100%;">Subscribe via Stripe</button>
                <?php else: ?>
                    <a href="/login.php?redirect=subscription.php" class="cta-btn">Login to Subscribe</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <footer>
        <p><?= h($site['footer_text'] ?? '© 2026 n2l8studio. All rights reserved.') ?></p>
    </footer>
    <script>
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
    if (nl) {
        nl.querySelectorAll('a').forEach(a => a.addEventListener('click', () => { ham.classList.remove('open'); nl.classList.remove('open'); }));
    }

    const subBtn = document.getElementById('subscribeBtn');
    if (subBtn) {
        subBtn.addEventListener('click', () => {
            subBtn.textContent = 'SECURE STRIPE CHECKOUT...';
            subBtn.disabled = true;
            subBtn.style.opacity = '0.7';
            
            const form = new FormData();
            form.append('subscription', 'pro');
            
            fetch('/payment/create-stripe-session.php', {
                method: 'POST',
                body: form
            })
            .then(r => {
                if (r.status === 401) {
                    window.location.href = '/login.php?redirect=subscription.php';
                    throw new Error('Auth required');
                }
                return r.json();
            })
            .then(data => {
                if (data.url) {
                    window.location.href = data.url;
                } else {
                    alert(data.error || 'Failed to create checkout session.');
                    subBtn.textContent = 'Subscribe via Stripe';
                    subBtn.disabled = false;
                    subBtn.style.opacity = '1';
                }
            })
            .catch(err => {
                console.error(err);
                if (err.message !== 'Auth required') {
                    subBtn.textContent = 'Subscribe via Stripe';
                    subBtn.disabled = false;
                    subBtn.style.opacity = '1';
                }
            });
        });
    }
    </script>
</body>
</html>
