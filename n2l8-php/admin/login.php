<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (is_logged_in()) {
    redirect('/admin/index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo      = get_pdo();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT id, password FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        log_action($pdo, "Admin logged in: {$username}");
        redirect('/admin/index.php');
    } else {
        $error = 'Invalid credentials — access denied.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - n2l8studio</title>
    <link rel="stylesheet" href="/static/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Righteous&family=VT323&display=swap" rel="stylesheet">
    <style>
        .login-box { max-width:400px; margin:100px auto; background:rgba(10,15,10,0.9); padding:3rem 2rem; border:2px solid var(--text-muted); text-align:center; box-shadow:inset 0 0 10px rgba(57,255,20,0.1),0 0 15px rgba(57,255,20,0.2); }
        .login-box input { width:100%; padding:0.8rem; margin-bottom:1.5rem; background:var(--bg-dark); border:1px solid var(--text-muted); color:var(--text-main); font-family:'VT323',monospace; font-size:1.2rem; outline:none; }
        .login-box input:focus { border-color:var(--text-main); box-shadow:0 0 10px rgba(57,255,20,0.2); }
        .flash-msg { color:var(--accent); margin-bottom:1rem; }
    </style>
</head>
<body class="page-home">
    <div class="container">
        <div class="login-box">
            <h2>SYSTEM OVERRIDE</h2>
            <p style="color:var(--text-muted);margin-bottom:2rem;">Please authenticate.</p>
            <?php if ($error): ?>
            <div class="flash-msg">&gt; <?= h($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="text" name="username" placeholder="Username" required autocomplete="username">
                <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
                <button type="submit" class="cta-btn" style="width:100%;">ACCESS</button>
            </form>
            <br>
            <a href="/index.php" style="color:var(--text-muted);text-decoration:none;font-size:1.2rem;">&lt; Return to Surface</a>
        </div>
    </div>
</body>
</html>
