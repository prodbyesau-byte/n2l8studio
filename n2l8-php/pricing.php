<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo  = get_pdo();
$site = get_site_content($pdo);
log_visitor($pdo, 'page_view', '/pricing.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mixing &amp; Mastering - N2L8 STUDIO</title>
    <meta name="description" content="Professional audio engineering from n2l8studio.">
    <link rel="stylesheet" href="/static/style.css?v=12">
    <link rel="icon" type="image/png" href="/static/logo.png">
    <link rel="apple-touch-icon" href="/static/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
</head>
<body class="page-pricing <?= ($site['site_theme'] ?? 'dark') === 'beige' ? 'theme-beige' : '' ?>">
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
                <?php if (is_logged_in()): ?>
                    <li><a href="/forum.php">Forum</a></li>
                    <li class="dropdown">
                        <a href="javascript:void(0)" class="dropbtn" style="color: var(--accent); display: inline-flex; align-items: center; gap: 4px; padding-top: 4px; padding-bottom: 4px;">
                            <?= get_user_avatar_nav($pdo) ?>
                            <span>Profile</span>
                        </a>
                        <div class="dropdown-content">
                            <a href="/portal/index.php">Client Profile</a>
                            <?php if (is_owner()): ?>
                                <a href="/admin/index.php">Admin Portal</a>
                            <?php endif; ?>
                            <a href="/logout.php" style="color: var(--accent) !important;">Disconnect</a>
                        </div>
                    </li>
                <?php else: ?>
                    <li><a href="/login.php">Login</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <section id="services" class="services">
        <div class="container">
            <h2><?= h($site['pricing_h2'] ?? 'Mixing & Mastering') ?></h2>
            <p class="section-desc"><?= h($site['pricing_desc'] ?? '') ?></p>
            <div class="pricing-grid">
                <div class="pricing-card">
                    <h3><?= h($site['pricing_mix_title'] ?? 'Mixing') ?></h3>
                    <div class="price"><?= h($site['pricing_mix_price'] ?? '$150') ?><span><?= h($site['pricing_mix_unit'] ?? '/track') ?></span></div>
                    <ul>
                        <li><?= h($site['pricing_mix_f1'] ?? '') ?></li>
                        <li><?= h($site['pricing_mix_f2'] ?? '') ?></li>
                        <li><?= h($site['pricing_mix_f3'] ?? '') ?></li>
                    </ul>
                    <a href="/index.php#contact" class="cta-btn secondary">Get Started</a>
                </div>
                <div class="pricing-card">
                    <h3><?= h($site['pricing_master_title'] ?? 'Mastering') ?></h3>
                    <div class="price"><?= h($site['pricing_master_price'] ?? '$50') ?><span><?= h($site['pricing_master_unit'] ?? '/track') ?></span></div>
                    <ul>
                        <li><?= h($site['pricing_master_f1'] ?? '') ?></li>
                        <li><?= h($site['pricing_master_f2'] ?? '') ?></li>
                        <li><?= h($site['pricing_master_f3'] ?? '') ?></li>
                    </ul>
                    <a href="/index.php#contact" class="cta-btn secondary">Get Started</a>
                </div>
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
        if (!event.target.closest('.dropdown')) {
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
        nl.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
            if (!a.classList.contains('dropbtn')) {
                ham.classList.remove('open');
                nl.classList.remove('open');
            }
        }));
    }
    </script>
</body>
</html>
