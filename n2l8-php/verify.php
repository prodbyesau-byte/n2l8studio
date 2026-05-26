<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$pdo = get_pdo();
$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    redirect('/index.php');
}

$error = '';

try {
    // Find the user with the matching verification token and currently unverified (is_approved = 0)
    $stmt = $pdo->prepare('SELECT * FROM users WHERE verification_token = ? AND is_approved = 0');
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        // Mark user as approved/verified and clear token
        $update = $pdo->prepare('UPDATE users SET is_approved = 1, verification_token = NULL WHERE id = ?');
        if ($update->execute([$user['id']])) {
            log_action($pdo, "User verified via email link: {$user['username']} (ID {$user['id']})");
            flash('Success! Your email address has been verified. You can now log in.');
            redirect('/login.php');
        } else {
            $error = 'A database error occurred during verification. Please try again later.';
        }
    } else {
        // Token is invalid or the user is already verified
        $error = 'Invalid or expired verification link. The link may have already been used, or the account is already verified.';
    }
} catch (Throwable $e) {
    $error = 'An unexpected error occurred: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification Status - N2L8 STUDIO</title>
    <link rel="stylesheet" href="/static/style.css?v=21">
    <link rel="icon" type="image/png" href="/static/logo.png">
    <link rel="apple-touch-icon" href="/static/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
    <style>
        .status-box { 
            max-width: 480px; 
            margin: 120px auto; 
            background: rgba(5, 5, 8, 0.95); 
            padding: 3.5rem 2.5rem; 
            border: 1px solid var(--border-color); 
            border-radius: 8px;
            text-align: center; 
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.6), var(--accent-glow);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            transition: all 0.3s ease;
        }
        .status-box:hover {
            border-color: rgba(164, 74, 94, 0.4);
            box-shadow: 0 20px 45px rgba(164, 74, 94, 0.15), var(--accent-glow);
        }
        .status-icon {
            font-size: 3.5rem;
            margin-bottom: 1.5rem;
            display: inline-block;
            animation: pulse 2s infinite alternate;
        }
        .status-box h2 {
            font-family: 'Syncopate', sans-serif;
            color: #ffffff;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }
        .status-box p {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.9rem;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 2.2rem;
            letter-spacing: 0.05em;
        }
        .status-box .cta-btn {
            width: 100%;
            font-family: 'Syncopate', sans-serif;
            font-weight: 700;
            letter-spacing: 0.1em;
            padding: 0.95rem;
            border-radius: 4px;
            display: inline-block;
            text-decoration: none;
            box-sizing: border-box;
        }
        .box-footer-link {
            display: inline-block;
            margin-top: 1.5rem;
            color: var(--text-muted);
            text-decoration: none;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            transition: all 0.25s ease;
        }
        .box-footer-link:hover {
            color: #ffffff;
            text-shadow: 0 0 5px rgba(255, 255, 255, 0.4);
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            100% { transform: scale(1.08); }
        }
        @media (max-width: 480px) {
            .status-box {
                margin: 60px auto;
                padding: 2.2rem 1.5rem;
                width: 92%;
            }
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
        <div class="status-box" style="border-color: #A44A5E;">
            <div class="status-icon" style="color: #A44A5E;">⚠️</div>
            <h2>Verification Error</h2>
            <p><?= h($error) ?></p>
            
            <a href="/login.php" class="cta-btn">GO TO LOGIN</a>
            
            <div style="margin-top: 1.5rem; border-top: 1px dashed var(--border-color); padding-top: 1rem;">
                <a href="/index.php" class="box-footer-link">&lt; Back to Frontpage</a>
            </div>
        </div>
    </div>
</body>
</html>
