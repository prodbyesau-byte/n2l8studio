<?php
// N2L8Studio — Configuration Template
// Copy this to config.php and fill in your values.
// On production: config.php is uploaded manually or via host control panel.

define('DB_HOST',   'your-mysql-host');
define('DB_PORT',   '3306');
define('DB_USER',   'your_db_user');
define('DB_PASS',   'your_db_password');
define('DB_NAME',   'your_db_name');

define('SECRET_KEY',   'change-this-to-a-long-random-string');
define('UPLOAD_DIR',   __DIR__ . '/../static/uploads/');
define('UPLOAD_URL',   '/static/uploads/');

// ── SMTP / Mail Server settings (Simply.com) ─────────────────────────────────
define('SMTP_ENABLED',     false);                 // Set to true to enable SMTP sending
define('SMTP_HOST',        'websmtp.simply.com');  // Simply.com outbound SMTP server
define('SMTP_PORT',        587);                   // Port 587 (TLS/STARTTLS) or 465 (SSL)
define('SMTP_USER',        'admin@n2l8studio.dk');
define('SMTP_PASS',        'your-email-account-password');
define('SMTP_SECURE',      'tls');                 // 'tls' or 'ssl' or 'none'

define('MAIL_FROM_EMAIL',  'admin@n2l8studios.com');
define('MAIL_FROM_NAME',   'N2L8 STUDIO');

// ── Stripe ───────────────────────────────────────────────────────────────────
define('STRIPE_ENABLED', true);
define('STRIPE_MODE', 'test'); // Set to 'live' for real transactions

// Sandbox/Test credentials
define('STRIPE_TEST_SECRET_KEY', 'sk_test_replace_with_your_test_secret_key');
define('STRIPE_TEST_PUBLISHABLE_KEY', 'pk_test_replace_with_your_test_pub_key');

// Production/Live credentials
define('STRIPE_LIVE_SECRET_KEY', 'sk_live_replace_with_your_live_secret_key');
define('STRIPE_LIVE_PUBLISHABLE_KEY', 'pk_live_replace_with_your_live_pub_key');

// ── IMAP / Incoming Mail settings ────────────────────────────────────────────
define('IMAP_HOST',        'imap.simply.com');
define('IMAP_PORT',        993);
define('IMAP_USER',        'your-imap-username');
define('IMAP_PASS',        'your-imap-password');
define('IMAP_ENCRYPTION',  'ssl');


