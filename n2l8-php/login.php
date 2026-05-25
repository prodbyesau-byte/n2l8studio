<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (is_logged_in()) {
    if (is_owner()) {
        redirect('/admin/index.php');
    } else {
        $redirect_url = trim($_GET['redirect'] ?? $_POST['redirect'] ?? '');
        if (empty($redirect_url) || strpos($redirect_url, '/') !== 0) {
            $redirect_url = '/portal/index.php';
        }
        redirect($redirect_url);
    }
}

$pdo = get_pdo();
$content = get_site_content($pdo);

$redirect_url = trim($_REQUEST['redirect'] ?? '');
if (empty($redirect_url) || strpos($redirect_url, '/') !== 0) {
    $redirect_url = '/portal/index.php';
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_input = trim($_POST['login_input'] ?? $_POST['username'] ?? '');
    $password    = $_POST['password'] ?? '';

    if (empty($login_input) || empty($password)) {
        $error = 'All fields are required.';
    } else {
        // Find user by username OR email
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$login_input, $login_input]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $user_role = $user['role'];
            if ($user_role === 'admin' || $user_role === 'owner') {
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['email']     = $user['email'];

                log_action($pdo, "Administrator logged in: {$user['username']}");
                redirect('/admin/index.php');
            } else if (empty($user['is_approved'])) {
                $error = 'Your account is pending administrative approval. You will be able to log in once your profile has been verified.';
            } else {
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['email']     = $user['email'];

                log_action($pdo, "User logged in: {$user['username']} ({$user['role']})");
                
                $redirect_url = trim($_POST['redirect'] ?? $_GET['redirect'] ?? '');
                if (empty($redirect_url) || strpos($redirect_url, '/') !== 0) {
                    $redirect_url = '/portal/index.php';
                }
                redirect($redirect_url);
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
    <link rel="stylesheet" href="/static/style.css?v=20">
    <link rel="icon" type="image/png" href="/static/logo.png">
    <link rel="apple-touch-icon" href="/static/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
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
            <h2>LOGIN</h2>
            <p>Login to access your account.</p>
            
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
                <input type="hidden" name="redirect" value="<?= h($redirect_url) ?>">
                <div class="form-group">
                    <label>Username or Email Address</label>
                    <input type="text" name="login_input" required autocomplete="username" placeholder="e.g. alex">
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
                </div>

                <button type="submit" class="cta-btn">LOGIN</button>
            </form>
            
            <div style="margin-top: 1.5rem; display: flex; flex-direction: column; gap: 0.8rem; align-items: center;">
                <a href="/register.php" class="box-footer-link" style="color: var(--accent); margin-top: 0;">Create an Account &gt;</a>
                <a href="/index.php" class="box-footer-link" style="margin-top: 0;">&lt; Back to Frontpage</a>
            </div>
        </div>
    </div>
</body>
</html>
