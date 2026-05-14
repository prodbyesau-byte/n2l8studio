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
    <script>
    const ham = document.getElementById('navHamburger');
    const nl  = document.getElementById('navLinks');
    if (ham) {
        ham.addEventListener('click', () => { ham.classList.toggle('open'); nl.classList.toggle('open'); });
    }

    // Dropdown toggle for mobile
    const dropbtn = document.querySelector('.dropbtn');
    if (dropbtn) {
        dropbtn.addEventListener('click', (e) => {
            e.preventDefault();
            document.querySelector('.dropdown-content').classList.toggle('show');
        });
    }
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
    </script>
</body>
</html>
