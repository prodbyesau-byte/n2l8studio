<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo  = get_pdo();
$site = get_site_content($pdo);
log_visitor($pdo, 'page_view', '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>n2l8studio - Our Music Community</title>
    <meta name="description" content="n2l8studio is a creative music community and studio for passionate artists, producers, and creative souls.">
    <link rel="stylesheet" href="/static/style.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Righteous&family=VT323&display=swap" rel="stylesheet">
</head>
<body class="page-home">
    <header class="hero">
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
        <div class="hero-content">
            <div class="logo-highlight">
                <img src="/static/logo.png" alt="n2l8studio Logo" class="hero-logo">
            </div>
            <h1><?= h($site['home_hero_h1'] ?? 'n2l8studio') ?></h1>
            <p><?= h($site['home_hero_sub'] ?? '') ?></p>
            <div class="hero-buttons">
                <a href="/shop.php" class="cta-btn">View Shop</a>
                <a href="/pricing.php" class="cta-btn secondary">Our Services</a>
            </div>
        </div>
    </header>

    <section id="contact" class="contact">
        <div class="container">
            <h2><?= h($site['home_contact_h2'] ?? 'Join n2l8studio') ?></h2>
            <p><?= h($site['home_contact_p'] ?? '') ?></p>
            <a href="mailto:contact@n2l8studio.com" class="cta-btn secondary">Contact Us</a>
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
    nl.querySelectorAll('a').forEach(a => a.addEventListener('click', () => { ham.classList.remove('open'); nl.classList.remove('open'); }));
    </script>
</body>
</html>
