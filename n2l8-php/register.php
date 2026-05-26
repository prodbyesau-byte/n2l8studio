<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (is_logged_in()) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        redirect('/admin/index.php');
    } else {
        redirect('/portal/index.php');
    }
}

$pdo = get_pdo();
$content = get_site_content($pdo);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($username) || empty($email) || empty($password)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        // Check if username or email already taken
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $error = 'Username or Email is already registered.';
        } else {
            // Hash password and insert as 'user' (is_approved = 0 by default) with verification token
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare('INSERT INTO users (username, email, password, role, is_approved, verification_token) VALUES (?, ?, ?, "user", 0, ?)');
            if ($stmt->execute([$username, $email, $hash, $token])) {
                // Build dynamic verification link
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
                $domain = $_SERVER['HTTP_HOST'];
                $verification_link = "{$protocol}://{$domain}/verify.php?token={$token}";

                // Send verification email
                $subject = "Verify Your N2L8 STUDIO Account";
                $body = "
                <html>
                <body style=\"background-color:#0F0F11; color:#F5F1EA; font-family:'Montserrat',sans-serif; padding:40px 20px; margin:0;\">
                    <div style=\"max-width:600px; margin:0 auto; background:#0F0F11; border:1px solid #A44A5E; padding:40px; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.5); text-align:center;\">
                        <div style=\"font-size:28px; font-weight:700; font-family:'Syncopate',sans-serif; letter-spacing:3px; color:#ffffff; margin-bottom:30px;\">
                            N<span style=\"color:#B89B5E;\">2</span>L8studios
                        </div>
                        <h2 style=\"font-family:'Syncopate',sans-serif; color:#ffffff; font-size:22px; text-transform:uppercase; letter-spacing:1px; margin-bottom:20px;\">
                            Verify Your Email
                        </h2>
                        <p style=\"color:#b3b3b3; font-size:15px; line-height:1.7; margin-bottom:35px;\">
                            Hello " . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ",<br><br>
                            Thank you for registering at N2L8 STUDIO! Please click the button below to verify your email address and activate your account.
                        </p>
                        <a href=\"" . htmlspecialchars($verification_link, ENT_QUOTES, 'UTF-8') . "\" style=\"display:inline-block; background:#A44A5E; color:#ffffff; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:2px; text-decoration:none; padding:15px 40px; border-radius:4px; transition:all 0.2s;\">
                            Verify Account
                        </a>
                        <p style=\"color:#666666; font-size:12px; margin-top:30px; line-height:1.5;\">
                            If the button above does not work, copy and paste the following link into your browser:<br>
                            <a href=\"" . htmlspecialchars($verification_link, ENT_QUOTES, 'UTF-8') . "\" style=\"color:#A44A5E; text-decoration:none;\">" . htmlspecialchars($verification_link, ENT_QUOTES, 'UTF-8') . "</a>
                        </p>
                        <div style=\"margin-top:40px; border-top:1px solid rgba(255,255,255,0.05); padding-top:20px; color:#666666; font-size:12px;\">
                            &copy; " . date('Y') . " N2L8studios. All rights reserved.
                        </div>
                    </div>
                </body>
                </html>
                ";

                send_platform_email($email, $subject, $body);

                log_action($pdo, "New user registered (pending email verification): {$username} ({$email})");
                flash('Account created! A verification email has been sent to ' . htmlspecialchars($email) . '. Please click the link in the email to verify and activate your account.');
                redirect('/login.php');
            } else {
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - N2L8 STUDIO</title>
    <link rel="stylesheet" href="/static/style.css?v=21">
    <link rel="icon" type="image/png" href="/static/logo.png">
    <link rel="apple-touch-icon" href="/static/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
    <style>
        .login-box { 
            max-width: 440px; 
            margin: 80px auto; 
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
        .login-box:hover {
            border-color: rgba(164, 74, 94, 0.4);
            box-shadow: 0 20px 45px rgba(164, 74, 94, 0.15), var(--accent-glow);
        }
        .login-box h2 {
            font-family: 'Syncopate', sans-serif;
            color: #ffffff;
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            letter-spacing: 0.1em;
        }
        .login-box p {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 2.2rem;
            letter-spacing: 0.05em;
        }
        .form-group {
            position: relative;
            margin-bottom: 1.5rem;
            text-align: left;
        }
        .form-group label {
            display: block;
            color: var(--text-muted);
            font-family: 'Montserrat', sans-serif;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
        }
        .login-box input { 
            width: 100%; 
            padding: 0.85rem 1rem; 
            background: #0F0F11; 
            border: 1px solid var(--border-color); 
            border-radius: 4px;
            color: var(--text-main); 
            font-family: 'Montserrat', sans-serif; 
            font-size: 0.9rem; 
            outline: none; 
            transition: all 0.25s ease;
        }
        .login-box input:focus { 
            border-color: var(--accent); 
            box-shadow: var(--accent-glow);
        }
        .flash-msg { 
            color: var(--accent); 
            background: rgba(164, 74, 94, 0.08);
            border: 1px solid var(--border-color);
            padding: 0.8rem;
            border-radius: 4px;
            margin-bottom: 1.5rem; 
            font-family: 'Montserrat', sans-serif;
            font-size: 0.95rem;
            text-align: left;
        }
        .login-box .cta-btn {
            width: 100%;
            font-family: 'Syncopate', sans-serif;
            font-weight: 700;
            letter-spacing: 0.1em;
            padding: 0.95rem;
            margin-top: 0.5rem;
            border-radius: 4px;
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
        @media (max-width: 480px) {
            .login-box {
                margin: 40px auto;
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
        <div class="login-box">
            <h2>REGISTER</h2>
            <p>Join the N2L8 Studio portal to access your products.</p>
            
            <?php if ($error): ?>
            <div class="flash-msg">&gt; <?= h($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required autocomplete="username" placeholder="e.g. alex">
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" required autocomplete="email" placeholder="e.g. alex@example.com">
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required autocomplete="new-password" placeholder="•••••••• (Min 6 chars)">
                </div>

                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" required autocomplete="new-password" placeholder="••••••••">
                </div>

                <button type="submit" class="cta-btn">REGISTER</button>
            </form>
            
            <div style="margin-top: 1.5rem; border-top: 1px dashed var(--border-color); padding-top: 1rem;">
                <a href="/login.php" class="box-footer-link" style="color: var(--accent);">Already have an account? Login here &gt;</a>
            </div>
            
            <a href="/index.php" class="box-footer-link">&lt; Return to Home</a>
        </div>
    </div>
</body>
</html>
