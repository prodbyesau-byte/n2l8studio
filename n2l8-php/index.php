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
    <link rel="stylesheet" href="/static/style.css?v=3">
    <link rel="icon" type="image/png" href="/static/logo.png">
    <link rel="apple-touch-icon" href="/static/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
</head>
<body class="page-home">
    <header class="hero">
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
                    <li class="dropdown">
                        <a href="javascript:void(0)" class="dropbtn" style="color: var(--accent);">Vault</a>
                        <div class="dropdown-content">
                            <a href="/profile.php">My Profile</a>
                            <a href="/portal/index.php">Client Vault</a>
                            <?php if (is_owner()): ?>
                                <a href="/admin/index.php">Mainframe</a>
                            <?php endif; ?>
                            <a href="/logout.php" style="color: var(--accent) !important;">Disconnect</a>
                        </div>
                    </li>
                <?php else: ?>
                    <li><a href="/login.php">Login</a></li>
                    <li><a href="/register.php">Register</a></li>
                <?php endif; ?>
            </ul>
        </nav>
        <div class="hero-content">
            <div class="logo-highlight">
                <img src="/static/logo.png" alt="N2L8 STUDIO Logo" class="hero-logo">
            </div>
            <div class="hero-brand-wordmark">N<span>2</span>L8studios</div>
            <p>PREMIUM SOUND &amp; ART COLLECTIVE SINCE 2025</p>
        </div>
    </header>

    <footer>
        <p><?= h($site['footer_text'] ?? '© 2026 n2l8studio. All rights reserved.') ?></p>
        <a href="/admin/login.php" class="nav-admin-btn">Owner Login</a>
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
