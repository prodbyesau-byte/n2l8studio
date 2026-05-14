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
    <title>Subscription - n2l8studio</title>
    <link rel="stylesheet" href="/static/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Righteous&family=VT323&display=swap" rel="stylesheet">
</head>
<body class="page-sub">
    <header class="hero" style="min-height:auto;padding-bottom:2rem;">
        <nav>
            <div class="logo-text">n2l8studio</div>
            <ul class="nav-links">
                <li><a href="/shop.php"><?= h($site['nav_shop'] ?? 'Shop') ?></a></li>
                <li><a href="/pricing.php"><?= h($site['nav_pricing'] ?? 'Mixing & Mastering') ?></a></li>
                <li><a href="/admin/login.php" class="nav-admin-btn">Admin Vault</a></li>
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
                <a href="/index.php#contact" class="cta-btn">Get Started</a>
            </div>
        </div>
    </section>

    <footer><p><?= h($site['footer_text'] ?? '© 2026 n2l8studio. All rights reserved.') ?></p></footer>
</body>
</html>
