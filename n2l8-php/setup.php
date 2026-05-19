<?php
/**
 * N2L8Studio — One-time Setup Script
 * Creates admin user + seeds default content.
 *
 * Usage: https://n2l8studio.dk/setup.php?token=n2l8setup2026
 * DELETE THIS FILE FROM THE SERVER after running it.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

// ── Security token ──────────────────────────────────────────────────────────
define('SETUP_TOKEN', 'n2l8setup2026');
if (($_GET['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    die('403 Forbidden — provide ?token=... to run setup.');
}

$pdo = get_pdo();
$log = [];

// ── Create admin user ───────────────────────────────────────────────────────
$existing = $pdo->query("SELECT COUNT(*) FROM users WHERE username='N2L8studio'")->fetchColumn();
if (!$existing) {
    $hash = password_hash('Rbnyccxp7', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')")
        ->execute(['N2L8studio', $hash]);
    $log[] = '✅ Admin user created: N2L8studio';
} else {
    $log[] = '⚠️  Admin user already exists — skipped.';
}

// ── Seed content blocks ─────────────────────────────────────────────────────
$content_rows = [
    // HOME
    ['home_hero_h1',        'Home',         'Hero Heading',              'n2l8studio'],
    ['home_hero_sub',       'Home',         'Hero Sub-heading',          'A creative music community where passion, talent, and sound unite.'],
    ['home_contact_h2',     'Home',         'Contact Section Heading',   'Join n2l8studio'],
    ['home_contact_p',      'Home',         'Contact Section Paragraph', 'Are you ready to take your music to the next level? Contact us to learn more about how you can become a part of our growing community.'],
    // SHOP
    ['shop_h2',             'Shop',         'Shop Heading',              'Sample Packs & Drumkits'],
    ['shop_desc',           'Shop',         'Shop Description',          'Industry-quality sounds built for producers who want a harder, cleaner, and more modern sound.'],
    // PRICING
    ['pricing_h2',          'Pricing',      'Pricing Heading',           'Mixing & Mastering'],
    ['pricing_desc',        'Pricing',      'Pricing Description',       'Professional audio engineering to bring your tracks to industry standards.'],
    ['pricing_mix_title',   'Pricing',      'Mixing Card Title',         'Mixing'],
    ['pricing_mix_price',   'Pricing',      'Mixing Price',              '$150'],
    ['pricing_mix_unit',    'Pricing',      'Mixing Price Unit',         '/track'],
    ['pricing_mix_f1',      'Pricing',      'Mixing Feature 1',          'Full vocal & instrumental mix'],
    ['pricing_mix_f2',      'Pricing',      'Mixing Feature 2',          'Industry-standard plugins'],
    ['pricing_mix_f3',      'Pricing',      'Mixing Feature 3',          '3 free revisions'],
    ['pricing_master_title','Pricing',      'Mastering Card Title',      'Mastering'],
    ['pricing_master_price','Pricing',      'Mastering Price',           '$50'],
    ['pricing_master_unit', 'Pricing',      'Mastering Price Unit',      '/track'],
    ['pricing_master_f1',   'Pricing',      'Mastering Feature 1',       'Volume optimization'],
    ['pricing_master_f2',   'Pricing',      'Mastering Feature 2',       'EQ & compression'],
    ['pricing_master_f3',   'Pricing',      'Mastering Feature 3',       'Streaming platform ready'],
    // SUBSCRIPTION
    ['sub_h2',              'Subscription', 'Subscription Heading',      'Monthly Subscription'],
    ['sub_desc',            'Subscription', 'Subscription Description',  'Sign up for a monthly plan to claim free loopkits of your choice from our shop.'],
    ['sub_pro_price',       'Subscription', 'Pro Plan Price',            '$19'],
    ['sub_pro_unit',        'Subscription', 'Pro Plan Price Unit',       '.99/mo'],
    ['sub_pro_f1',          'Subscription', 'Pro Feature 1',             '3 Free Loopkits per month'],
    ['sub_pro_f2',          'Subscription', 'Pro Feature 2',             'Access to hidden exclusive loopkits'],
    ['sub_pro_f3',          'Subscription', 'Pro Feature 3',             'High quality WAV files & Stems'],
    ['sub_pro_f4',          'Subscription', 'Pro Feature 4',             'Cancel anytime'],
    // GLOBAL
    ['nav_shop',            'Global',       'Nav: Shop Link',            'Shop'],
    ['nav_pricing',         'Global',       'Nav: Pricing Link',         'Mixing & Mastering'],
    ['nav_sub',             'Global',       'Nav: Subscription Link',    'Subscription Plan'],
    ['footer_text',         'Global',       'Footer Copyright Text',     '© 2026 n2l8studio. All rights reserved.'],
];

$stmt = $pdo->prepare(
    'INSERT IGNORE INTO content (section_key, label, page, text) VALUES (?, ?, ?, ?)'
);
$seeded = 0;
foreach ($content_rows as [$key, $page, $label, $text]) {
    $stmt->execute([$key, $label, $page, $text]);
    if ($stmt->rowCount()) $seeded++;
}
$log[] = "✅ Content blocks seeded: {$seeded} new rows inserted.";

// ── Done ────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>N2L8Studio Setup</title>
<style>
body { background:#070a07; color:#33ff99; font-family:monospace; padding:2rem; }
h1 { color:#ffc25c; }
.ok { color:#33ff99; }
.warn { color:#ffc25c; }
.danger { color:#ff5c5c; font-size:1.2rem; margin-top:2rem; }
</style></head>
<body>
<h1>⚙ N2L8Studio Setup Complete</h1>
<?php foreach ($log as $line): ?>
<p><?= h($line) ?></p>
<?php endforeach; ?>
<p class="danger">⚠ DELETE setup.php from the server now!<br>
FTP → /public_html/setup.php → Delete</p>
<p><a href="/" style="color:#7be1a8">&larr; Back to site</a></p>
</body>
</html>
