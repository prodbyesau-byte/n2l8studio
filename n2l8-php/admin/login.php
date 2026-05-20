<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// If already logged in, redirect accordingly
if (is_logged_in()) {
    if (is_owner()) {
        redirect('/admin/index.php');
    } else {
        redirect('/portal/index.php');
    }
}

$pdo = get_pdo();
$content = get_site_content($pdo);

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
            // EXCLUSIVE CHECK: Must be an admin/owner
            $user_role = $user['role'];
            if ($user_role !== 'admin' && $user_role !== 'owner') {
                $error = 'Access denied. This portal is strictly for administrators.';
                log_action($pdo, "Unauthorized admin login attempt: {$user['username']} (role: {$user_role})");
            } else {
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['email']     = $user['email'];

                log_action($pdo, "Administrator logged in: {$user['username']}");
                redirect('/admin/index.php');
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
    <title>Admin Login - N2L8 STUDIO</title>
    <link rel="stylesheet" href="/static/style.css?v=8">
    <link rel="icon" type="image/png" href="/static/logo.png">
    <link rel="apple-touch-icon" href="/static/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
    <style>
        .login-box { 
            max-width: 420px; 
            margin: 100px auto; 
            background: rgba(10, 3, 3, 0.97); 
            padding: 3.5rem 2.5rem; 
            border: 1px solid rgba(192, 21, 42, 0.3); 
            border-radius: 8px;
            text-align: center; 
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.8), 0 0 25px rgba(192, 21, 42, 0.15);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            transition: all 0.3s ease;
        }
        .login-box:hover {
            border-color: rgba(192, 21, 42, 0.6);
            box-shadow: 0 20px 45px rgba(192, 21, 42, 0.25), 0 0 35px rgba(192, 21, 42, 0.2);
        }
        .login-box h2 {
            font-family: 'Syncopate', sans-serif;
            color: #ffffff;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .login-box h2 span {
            color: var(--accent);
        }
        .login-box p {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-bottom: 2.2rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
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
            border: 1px solid rgba(192, 21, 42, 0.2);
            padding: 0.8rem;
            border-radius: 4px;
            margin-bottom: 1.5rem; 
            font-family: 'Montserrat', sans-serif;
            font-size: 0.85rem;
            text-align: left;
            letter-spacing: 0.03em;
            line-height: 1.4;
        }
        .login-box .cta-btn {
            width: 100%;
            font-family: 'Syncopate', sans-serif;
            font-weight: 700;
            letter-spacing: 0.1em;
            padding: 0.95rem;
            margin-top: 0.5rem;
            border-radius: 4px;
            background: var(--accent);
            border: none;
            color: #ffffff;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .login-box .cta-btn:hover {
            background: #e61930;
            box-shadow: 0 0 15px rgba(192, 21, 42, 0.4);
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
            <h2>ADMIN <span>LOGIN</span></h2>
            <p>Administrative Secure Gateway</p>

            <?php if ($error): ?>
            <div class="flash-msg">&gt; <?= h($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Admin Username or Email</label>
                    <input type="text" name="login_input" required autocomplete="username" placeholder="e.g. admin_user">
                </div>
                
                <div class="form-group">
                    <label>Security Password</label>
                    <input type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
                </div>

                <button type="submit" class="cta-btn">ACCESS PORTAL</button>
            </form>
            
            <div style="margin-top: 1.5rem; display: flex; flex-direction: column; gap: 0.8rem; align-items: center;">
                <a href="/login.php" class="box-footer-link" style="color: var(--accent); margin-top: 0;">User Login Portal &gt;</a>
                <a href="/index.php" class="box-footer-link" style="margin-top: 0;">&lt; Back to Frontpage</a>
            </div>
        </div>
    </div>
</body>
</html>
