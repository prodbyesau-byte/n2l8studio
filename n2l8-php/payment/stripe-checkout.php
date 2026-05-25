<?php
/**
 * Stripe — Create Checkout Session and Redirect
 * Receives GET/POST product_id, initiates checkout, and redirects.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$product_id = (int)($_REQUEST['product_id'] ?? 0);
if (!$product_id) {
    die("Error: Missing product_id");
}

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1');
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    die("Error: Product not found");
}

$tier = trim($_REQUEST['tier'] ?? 'basic');
$price = (float)$product['price'];
$tier_name = 'Basic (MP3/WAV) License';

if ($tier === 'WAV/STEMS' || $tier === 'premium') {
    $price = !empty($product['price_premium']) ? (float)$product['price_premium'] : $price * 2;
    $tier_name = 'Premium (WAV & Stems) License';
} elseif ($tier === 'EXCLUSIVE' || $tier === 'exclusive') {
    $price = !empty($product['price_exclusive']) ? (float)$product['price_exclusive'] : $price * 10;
    $tier_name = 'Exclusive License';
}

if ($price <= 0) {
    // If free, send directly to success download page
    header("Location: /payment/stripe-success.php?product_id=" . $product_id . "&free=1");
    exit;
}

// Convert price to cents
$amount_in_cents = round($price * 100);

// Get Stripe Secret Key based on mode
$stripe_secret = '';
if (defined('STRIPE_MODE') && STRIPE_MODE === 'live') {
    $stripe_secret = defined('STRIPE_LIVE_SECRET_KEY') ? STRIPE_LIVE_SECRET_KEY : $stripe_secret;
} else {
    $stripe_secret = defined('STRIPE_TEST_SECRET_KEY') ? STRIPE_TEST_SECRET_KEY : $stripe_secret;
}

// Determine host URL dynamically
$is_https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
$host = ($is_https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'www.n2l8studios.com');

$success_url = $host . '/payment/stripe-success.php?session_id={CHECKOUT_SESSION_ID}&product_id=' . $product_id . '&tier=' . urlencode($tier);
$cancel_url  = $host . ($product['type'] === 'beat' ? '/beats.php' : '/shop.php');

// Prepare API post data
$post_data = [
    'payment_method_types' => ['card'],
    'line_items' => [
        [
            'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                    'name' => $product['title'] . ' [' . $tier_name . '] — N2L8 STUDIO',
                    'description' => ($product['author'] ? 'By ' . $product['author'] : 'Exclusive Loopkit/Beat')
                ],
                'unit_amount' => $amount_in_cents,
            ],
            'quantity' => 1,
        ]
    ],
    'mode' => 'payment',
    'success_url' => $success_url,
    'cancel_url' => $cancel_url,
];

// Perform direct cURL request to Stripe API
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://api.stripe.com/v1/checkout/sessions',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_USERPWD        => $stripe_secret . ':', // Stripe expects API key as username, empty password
    CURLOPT_POSTFIELDS     => http_build_query($post_data), // URL-encoded format is expected by Stripe
    CURLOPT_TIMEOUT        => 20,
]);

$response = curl_exec($ch);
$curl_err = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curl_err) {
    die("Stripe Connection Error: " . htmlspecialchars($curl_err));
}

$session = json_decode($response, true);

if ($http_code !== 200 || isset($session['error'])) {
    $err_msg = $session['error']['message'] ?? 'Unknown error';
    die("Stripe Checkout Error: " . htmlspecialchars($err_msg));
}

$checkout_url = $session['url'] ?? '';

if (!$checkout_url) {
    die("Error: Could not retrieve Stripe Checkout URL.");
}

// Redirect buyer directly to Stripe hosted checkout page
header("Location: " . $checkout_url);
exit;
