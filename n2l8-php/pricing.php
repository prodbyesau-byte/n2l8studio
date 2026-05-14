<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo  = get_pdo();
$site = get_site_content($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mixing &amp; Mastering - n2l8studio</title>
    <meta name="description" content="Professional audio engineering from n2l8studio.">
    <link rel="stylesheet" href="/static/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Righteous&family=VT323&display=swap" rel="stylesheet">
</head>
<body class="page-pricing">
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

    <footer><p><?= h($site['footer_text'] ?? '© 2026 n2l8studio. All rights reserved.') ?></p></footer>
    <script>
    const ham = document.getElementById('navHamburger');
    const nl  = document.getElementById('navLinks');
    if (ham) {
        ham.addEventListener('click', () => { ham.classList.toggle('open'); nl.classList.toggle('open'); });
        nl.querySelectorAll('a').forEach(a => a.addEventListener('click', () => { ham.classList.remove('open'); nl.classList.remove('open'); }));
    }
    </script>
</body>
</html>
