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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_input = trim($_POST['login_input'] ?? '');
    $password    = $_POST['password'] ?? '';

    if (empty($login_input) || empty($password)) {
        $error = 'All fields are required.';
    } else {
        // Find user by username OR email
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$login_input, $login_input]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            $_SESSION['email']    = $user['email'];

            log_action($pdo, "User logged in: {$user['username']} ({$user['role']})");

            if ($user['role'] === 'admin') {
                redirect('/admin/index.php');
            } else {
                redirect('/portal/index.php');
            }
        } else {
            $error = 'Invalid credentials — access denied.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - N2L8 STUDIO</title>
    <link rel="stylesheet" href="/static/style.css?v=8">
    <link rel="icon" type="image/png" href="/static/logo.png">
    <link rel="apple-touch-icon" href="/static/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&family=VT323&display=swap" rel="stylesheet">
    <style>
        .login-box { 
            max-width: 420px; 
            margin: 100px auto; 
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
            border-color: rgba(192, 21, 42, 0.4);
            box-shadow: 0 20px 45px rgba(192, 21, 42, 0.15), var(--accent-glow);
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
            margin-bottom: 1.8rem;
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
            background: #000000; 
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
            background: rgba(192, 21, 42, 0.08);
            border: 1px solid var(--border-color);
            padding: 0.8rem;
            border-radius: 4px;
            margin-bottom: 1.5rem; 
            font-family: 'VT323', monospace;
            font-size: 1.15rem;
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
    </style>
</head>
<body class="page-home">
    <header class="hero" style="min-height: auto; padding-bottom: 0;">
        <nav>
            <a href="/index.php" class="logo-text" style="text-decoration:none;">N<span>2</span>L8studios</a>
        </nav>
    </header>

    <div class="container">
        <div class="login-box">
            <h2>ACCESS</h2>
            <p>Connect to the N2L8 Studio mainframe.</p>
            
            <?php 
            $flashes = get_flash();
            foreach ($flashes as $flash_msg): 
            ?>
            <div class="flash-msg" style="color: #7be1a8; background: rgba(123, 225, 168, 0.08); border-color: rgba(123, 225, 168, 0.2);">&gt; <?= h($flash_msg) ?></div>
            <?php endforeach; ?>

            <?php if ($error): ?>
            <div class="flash-msg">&gt; <?= h($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Username or Email Address</label>
                    <input type="text" name="login_input" required autocomplete="username" placeholder="e.g. wanderer">
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
                </div>

                <button type="submit" class="cta-btn">EXECUTE</button>
            </form>
            
            <div style="margin-top: 1.5rem; border-top: 1px dashed var(--border-color); padding-top: 1rem;">
                <a href="/register.php" class="box-footer-link" style="color: var(--accent);">Create an Account &gt;</a>
            </div>
            
            <a href="/index.php" class="box-footer-link">&lt; Return to Surface</a>
        </div>
    </div>
</body>
</html>
