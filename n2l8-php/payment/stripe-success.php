<?php
/**
 * Stripe — Success / Order Fulfillment Page
 * Redirected here by Stripe after successful checkout.
 * Verifies session, fulfills order in DB, and shows confirmation + download links.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

$session_id = isset($_GET['session_id']) ? trim($_GET['session_id']) : '';

if (!$session_id) {
    die("Error: Missing checkout session identifier.");
}

$pdo = get_pdo();
$site = get_site_content($pdo);

// 1. Retrieve the session from Stripe to verify status
$session = stripe_request('GET', '/checkout/sessions/' . $session_id);

if (isset($session['error']) || empty($session['payment_status'])) {
    $error_msg = $session['error']['message'] ?? 'Unable to retrieve Stripe payment session.';
} else {
    // Check if the session was successfully paid
    $payment_status = $session['payment_status'];
    $status = $session['status'] ?? '';
    
    if (!in_array($payment_status, ['paid', 'no_payment_required']) && $status !== 'complete') {
        $error_msg = "Payment status is not fully captured yet (Status: " . htmlspecialchars($payment_status) . ").";
    } else {
        // Retrieve metadata and customer details
        $metadata = $session['metadata'] ?? [];
        $customer_email = $session['customer_details']['email'] ?? 'customer@stripe.com';
        
        $is_subscription = isset($metadata['subscription']) && $metadata['subscription'] === 'pro';
        $user_id = isset($metadata['user_id']) ? (int)$metadata['user_id'] : 0;
        $product_id = isset($metadata['product_id']) ? (int)$metadata['product_id'] : 0;
        $license_tier = $metadata['license_tier'] ?? '';
        
        // Ensure we don't double-fulfill if success is refreshed
        // We can check if an order with this transaction/session key already exists in audit_log or orders (by using session_id as details/status)
        // For simplicity and resilience, we check if we already processed this Stripe session
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action LIKE ?");
        $check_stmt->execute(["%Stripe session processed: " . $session_id . "%"]);
        $already_fulfilled = ($check_stmt->fetchColumn() > 0);

        if (!$already_fulfilled) {
            if ($is_subscription && $user_id) {
                // Fulfill subscription upgrade
                $pdo->beginTransaction();
                try {
                    // Update user's role to 'pro'
                    $update_stmt = $pdo->prepare("UPDATE users SET role = 'pro' WHERE id = ?");
                    $update_stmt->execute([$user_id]);
                    
                    // Insert into orders as a subscription record
                    $order_stmt = $pdo->prepare("INSERT INTO orders (customer_email, product_id, status) VALUES (?, NULL, 'completed')");
                    $order_stmt->execute([$customer_email]);
                    
                    // Log to audit log
                    $log_msg = "Stripe subscription purchase: Pro Plan by {$customer_email} (User ID: {$user_id}) (Stripe session processed: {$session_id})";
                    log_action($pdo, $log_msg);
                    
                    $pdo->commit();
                    
                    // Instantly update current session role if the logged-in user is the buyer
                    if (is_logged_in() && (int)$_SESSION['user_id'] === $user_id) {
                        $_SESSION['role'] = 'pro';
                        $_SESSION['user_role'] = 'pro';
                    }
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    error_log("Stripe Subscription fulfillment failed: " . $e->getMessage());
                    $error_msg = "Failed to upgrade subscription role in database. Please contact support.";
                }
            } elseif ($product_id) {
                // Fulfill normal product order
                $pdo->beginTransaction();
                try {
                    // Fetch product
                    $p_stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
                    $p_stmt->execute([$product_id]);
                    $product = $p_stmt->fetch();
                    
                    if ($product) {
                        // Insert order record
                        $order_stmt = $pdo->prepare("INSERT INTO orders (customer_email, product_id, status) VALUES (?, ?, 'completed')");
                        $order_stmt->execute([$customer_email, $product_id]);
                        
                        // Log to audit log
                        $amount_cents = $session['amount_total'] ?? 0;
                        $amount_formatted = number_format($amount_cents / 100, 2);
                        $log_msg = "Stripe purchase: {$product['title']} ({$license_tier}) by {$customer_email} (\${$amount_formatted}) (Stripe session processed: {$session_id})";
                        log_action($pdo, $log_msg);
                        
                        // If user is logged in, optionally save product to their portal kits list automatically!
                        if ($user_id) {
                            $save_stmt = $pdo->prepare("INSERT IGNORE INTO user_saved_products (user_id, product_id) VALUES (?, ?)");
                            $save_stmt->execute([$user_id, $product_id]);
                        }
                    }
                    $pdo->commit();
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    error_log("Stripe Product fulfillment failed: " . $e->getMessage());
                    $error_msg = "Failed to register product order in database. Please contact support.";
                }
            }
        }
        
        // Finalize details for screen display
        if ($is_subscription) {
            $fulfillment_type = 'subscription';
        } else {
            $fulfillment_type = 'product';
            // Fetch product for download details
            $p_stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $p_stmt->execute([$product_id]);
            $product = $p_stmt->fetch();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed — N2L8 STUDIO</title>
    <link rel="stylesheet" href="/static/style.css?v=20">
    <link rel="icon" type="image/png" href="/static/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
    <style>
        .success-box {
            max-width: 580px;
            margin: 80px auto;
            background: rgba(5, 5, 8, 0.96);
            padding: 4rem 3rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.8), var(--accent-glow);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            transition: all 0.3s ease;
        }
        .success-box:hover {
            border-color: rgba(192, 21, 42, 0.4);
            box-shadow: 0 25px 55px rgba(192, 21, 42, 0.2), var(--accent-glow);
        }
        .success-icon {
            font-size: 3.5rem;
            color: #C0152A;
            margin-bottom: 1.5rem;
            text-shadow: 0 0 15px rgba(192, 21, 42, 0.6);
            animation: pulse-glow 2s infinite;
        }
        @keyframes pulse-glow {
            0% { transform: scale(1); text-shadow: 0 0 15px rgba(192, 21, 42, 0.6); }
            50% { transform: scale(1.05); text-shadow: 0 0 25px rgba(192, 21, 42, 0.9); }
            100% { transform: scale(1); text-shadow: 0 0 15px rgba(192, 21, 42, 0.6); }
        }
        .success-box h2 {
            font-family: 'Syncopate', sans-serif;
            color: #ffffff;
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            letter-spacing: 0.15em;
        }
        .success-box p {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 2.2rem;
            line-height: 1.7;
            letter-spacing: 0.05em;
        }
        .success-details {
            background: rgba(0,0,0,0.4);
            border: 1px solid var(--border-color);
            padding: 1.2rem;
            border-radius: 4px;
            margin-bottom: 2rem;
            text-align: left;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.82rem;
        }
        .success-row {
            display: flex;
            justify-content: space-between;
            padding: 0.4rem 0;
            border-bottom: 1px dashed rgba(255,255,255,0.05);
        }
        .success-row:last-child {
            border-bottom: none;
        }
        .success-label {
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.05em;
        }
        .success-val {
            color: #ffffff;
            font-weight: 500;
        }
        .success-val.premium-text {
            color: #C0152A;
            font-weight: bold;
        }
        .cta-btn {
            display: inline-block;
            font-family: 'Syncopate', sans-serif;
            font-weight: 700;
            letter-spacing: 0.12em;
            padding: 1rem 2rem;
            border-radius: 4px;
            text-decoration: none;
            width: 100%;
            text-align: center;
            box-sizing: border-box;
            transition: all 0.3s ease;
        }
        .error-msg {
            color: #ff5c5c;
            background: rgba(255, 92, 92, 0.08);
            border: 1px solid rgba(255, 92, 92, 0.2);
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 2rem;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.9rem;
            text-align: left;
        }
    </style>
</head>
<body class="page-home <?= get_active_theme($pdo) === 'beige' ? 'theme-beige' : '' ?>">
    <header class="hero" style="min-height: auto; padding-bottom: 0;">
        <nav>
            <a href="/index.php" class="logo-text" style="text-decoration:none;">N<span>2</span>L8studios</a>
        </nav>
    </header>

    <div class="container">
        <div class="success-box">
            <?php if (isset($error_msg)): ?>
                <div class="success-icon" style="color: #ff5c5c; text-shadow: 0 0 15px rgba(255, 92, 92, 0.5);">⚠</div>
                <h2>TRANSACTION ERROR</h2>
                <p>We encountered an issue finalizing your payment processing. Please do not worry—no double billing will occur.</p>
                
                <div class="error-msg">
                    <strong>Error details:</strong><br>
                    <?= h($error_msg) ?>
                </div>
                
                <a href="/index.php#contact" class="cta-btn" style="border-color:#ff5c5c; color:#ff5c5c;">SUPPORT INTERFACE</a>
            <?php else: ?>
                <div class="success-icon">✓</div>
                <h2>TRANSACTION COMPLETE</h2>
                <p>Authentication approved. Your premium assets have been compiled and secured.</p>
                
                <div class="success-details">
                    <div class="success-row">
                        <span class="success-label">Email Address</span>
                        <span class="success-val"><?= h($customer_email) ?></span>
                    </div>
                    <?php if ($fulfillment_type === 'subscription'): ?>
                        <div class="success-row">
                            <span class="success-label">Service Plan</span>
                            <span class="success-val premium-text">PRO TIER UPGRADE</span>
                        </div>
                        <div class="success-row">
                            <span class="success-label">Status</span>
                            <span class="success-val" style="color:#22c55e;">ACTIVE & ONLINE</span>
                        </div>
                    <?php else: ?>
                        <div class="success-row">
                            <span class="success-label">Product Purchased</span>
                            <span class="success-val"><?= h($product['title']) ?></span>
                        </div>
                        <?php if (!empty($license_tier)): ?>
                            <div class="success-row">
                                <span class="success-label">License Type</span>
                                <span class="success-val premium-text"><?= h(strtoupper($license_tier)) ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ($fulfillment_type === 'subscription'): ?>
                    <a href="/portal/index.php" class="cta-btn">LAUNCH CLIENT PORTAL &gt;</a>
                <?php else: ?>
                    <?php if (!empty($product['zip_file'])): ?>
                        <a href="<?= UPLOAD_URL . h($product['zip_file']) ?>" class="cta-btn" style="background:#C0152A; color:#ffffff;" download>DOWNLOAD PREMIUM VAULT</a>
                    <?php else: ?>
                        <button disabled class="cta-btn" style="border:1px solid rgba(123,225,168,0.2); background:rgba(123,225,168,0.04); color:rgba(123,225,168,0.3); cursor:not-allowed;">FILES PENDING — UPLOADING</button>
                    <?php endif; ?>
                    <div style="margin-top: 1.5rem; border-top: 1px dashed var(--border-color); padding-top: 1rem;">
                        <a href="/portal/index.php" class="box-footer-link" style="color:var(--text-muted); font-size:0.75rem; text-decoration:none;">Or view saved kits in your account portal &gt;</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
