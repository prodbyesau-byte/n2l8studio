<?php
/**
 * Stripe — Create Checkout Session
 * Handles creating checkout sessions for both product purchases and monthly subscriptions.
 * Returns JSON: { "id": "cs_...", "url": "https://..." }
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$product_id = isset($_POST['product_id']) ? trim($_POST['product_id']) : '';
$license_tier = isset($_POST['license_tier']) ? trim($_POST['license_tier']) : '';
$is_subscription = (isset($_POST['subscription']) && $_POST['subscription'] === 'pro') || ($product_id === 'pro');

// Determine base URL dynamically for success/cancel redirects
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$base_url = $protocol . '://' . $host;

$pdo = get_pdo();

if ($is_subscription) {
    // Subscriptions require client to be logged in
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['error' => 'Login required', 'redirect' => '/login.php?redirect=subscription.php']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['username'];
    
    // Fetch subscription price details from content table or use defaults
    $site = get_site_content($pdo);
    $price_str = $site['sub_pro_price'] ?? '$19';
    // Clean price string (e.g. '$19' or '19.99' or '19')
    $price_clean = preg_replace('/[^0-9.]/', '', $price_str);
    $price_val = (float)$price_clean;
    if ($price_val <= 0) $price_val = 19.99; // Default fallback
    
    $amount_in_cents = (int)round($price_val * 100);
    $item_name = 'Pro Plan — N2L8 STUDIO';
    $item_desc = 'Monthly subscription for unlimited downloads, graphics, and premium portal privileges.';
    
    $success_url = $base_url . '/payment/stripe-success.php?session_id={CHECKOUT_SESSION_ID}&subscription=pro';
    $cancel_url = $base_url . '/subscription.php';
    
    $payload = [
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency' => 'usd',
                'recurring' => [
                    'interval' => 'month',
                ],
                'product_data' => [
                    'name' => $item_name,
                    'description' => $item_desc,
                ],
                'unit_amount' => $amount_in_cents,
            ],
            'quantity' => 1,
        ]],
        'mode' => 'subscription',
        'success_url' => $success_url,
        'cancel_url' => $cancel_url,
        'metadata' => [
            'subscription' => 'pro',
            'user_id' => $user_id,
            'username' => $username,
        ],
    ];
} else {
    // Normal product checkout
    $product_id = (int)$product_id;
    if (!$product_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing product_id']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1');
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found']);
        exit;
    }

    $base_price = (float)$product['price'];
    if ($base_price <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Product is free — no Stripe transaction needed']);
        exit;
    }

    $price = $base_price;
    $tier_display = '';

    // Handle licensing tiers for beats if specified
    if ($product['type'] === 'beat' && !empty($license_tier)) {
        $tier = strtoupper($license_tier);
        if ($tier === 'WAV/STEMS' || $tier === 'STEMS' || $tier === 'PREMIUM') {
            $price = $product['price_premium'] !== null ? (float)$product['price_premium'] : $base_price * 2;
            $tier_display = ' [WAV & STEMS License]';
        } elseif ($tier === 'EXCLUSIVE') {
            $price = $product['price_exclusive'] !== null ? (float)$product['price_exclusive'] : $base_price * 10;
            $tier_display = ' [EXCLUSIVE License]';
        } else {
            $tier_display = ' [MP3 & WAV License]';
        }
    }

    $amount_in_cents = (int)round($price * 100);
    $item_name = $product['title'] . $tier_display;
    $item_desc = $product['description'] ? substr(strip_tags($product['description']), 0, 200) : 'Premium kit from N2L8 STUDIO';

    $success_url = $base_url . '/payment/stripe-success.php?session_id={CHECKOUT_SESSION_ID}&product_id=' . $product['id'] . '&license_tier=' . urlencode($license_tier);
    $cancel_url = $_SERVER['HTTP_REFERER'] ?? ($base_url . '/shop.php');

    $payload = [
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                    'name' => $item_name,
                    'description' => $item_desc,
                ],
                'unit_amount' => $amount_in_cents,
            ],
            'quantity' => 1,
        ]],
        'mode' => 'payment',
        'success_url' => $success_url,
        'cancel_url' => $cancel_url,
        'metadata' => [
            'product_id' => $product['id'],
            'license_tier' => $license_tier,
            'user_id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? null,
        ],
    ];

    // Prefill customer email if logged in
    if (is_logged_in() && !empty($_SESSION['user_id'])) {
        $u_stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $u_stmt->execute([$_SESSION['user_id']]);
        $u = $u_stmt->fetch();
        if ($u && !empty($u['email'])) {
            $payload['customer_email'] = $u['email'];
        }
    }
}

// Call Stripe API
$session = stripe_request('POST', '/checkout/sessions', $payload);

if (isset($session['error']) || !isset($session['id'])) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Stripe Checkout Session creation failed',
        'detail' => $session['error'] ?? 'Unknown error'
    ]);
    exit;
}

echo json_encode([
    'id' => $session['id'],
    'url' => $session['url']
]);
