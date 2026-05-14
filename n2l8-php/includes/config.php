<?php
// N2L8Studio — Local Configuration
// LOCAL ONLY — never uploaded (listed in .ftpignore)

define('DB_HOST',   'mysql63.unoeuro.com');
define('DB_PORT',   '3306');
define('DB_USER',   'n2l8studio_dk');
define('DB_PASS',   'y2Bf39FwbgkxGa6DctRE');
define('DB_NAME',   'n2l8studio_dk_db');

define('SECRET_KEY',   'n2l8studio_secret_key_1950s');
define('UPLOAD_DIR',   __DIR__ . '/../static/uploads/');
define('UPLOAD_URL',   '/static/uploads/');

// ── PayPal ───────────────────────────────────────────────────────────────────
// Get credentials: https://developer.paypal.com → My Apps & Credentials
// Set PAYPAL_SANDBOX = false when you go live.

define('PAYPAL_SANDBOX', true);   // ← flip to false for live

// Sandbox credentials (for testing — get from developer.paypal.com → Sandbox)
define('PAYPAL_SANDBOX_CLIENT_ID', 'REPLACE_WITH_SANDBOX_CLIENT_ID');
define('PAYPAL_SANDBOX_SECRET',    'REPLACE_WITH_SANDBOX_SECRET');

// Live credentials (for real payments — get from developer.paypal.com → Live)
define('PAYPAL_LIVE_CLIENT_ID',    'REPLACE_WITH_LIVE_CLIENT_ID');
define('PAYPAL_LIVE_SECRET',       'REPLACE_WITH_LIVE_SECRET');

// Auto-select based on sandbox flag (do not edit below)
define('PAYPAL_CLIENT_ID', PAYPAL_SANDBOX ? PAYPAL_SANDBOX_CLIENT_ID : PAYPAL_LIVE_CLIENT_ID);
define('PAYPAL_SECRET',    PAYPAL_SANDBOX ? PAYPAL_SANDBOX_SECRET    : PAYPAL_LIVE_SECRET);
define('PAYPAL_BASE_URL',  PAYPAL_SANDBOX
    ? 'https://api-m.sandbox.paypal.com'
    : 'https://api-m.paypal.com');
