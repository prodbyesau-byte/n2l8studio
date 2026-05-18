<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (is_owner()) {
    redirect('/admin/index.php');
} elseif (is_logged_in()) {
    redirect('/profile.php');
}

$pdo = get_pdo();
log_visitor($pdo, 'page_view', '/login.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT id, username, password, role FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password']) && !in_array($user['role'], ['admin', 'owner'], true)) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_role'] = $user['role'];
        log_action($pdo, "User logged in: {$username}");
        redirect('/profile.php');
    }

    $error = 'Invalid user credentials.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Login - n2l8studio</title>
    <link rel="stylesheet" href="/static/style.css?v=3">
    <link rel="icon" type="image/png" href="/static/logo.png">
    <link rel="apple-touch-icon" href="/static/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
    <style>
        .user-login-wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:2rem; }
        .user-login-box { width:100%; max-width:420px; text-align:center; border:1px solid var(--border-color); background:rgba(5,5,8,0.72); padding:2.5rem 2rem; border-radius:8px; box-shadow:var(--pop-shadow); }
        .user-login-box h1 { font-size:1.35rem; letter-spacing:0.12em; margin-bottom:1rem; }
        .user-login-box p { color:var(--text-muted); font-size:0.9rem; margin-bottom:2rem; }
        .user-login-box input { width:100%; padding:0.85rem 1rem; margin-bottom:1rem; background:#020203; border:1px solid var(--border-color); color:var(--text-main); font-family:'Montserrat',sans-serif; }
        .login-error { color:var(--accent); margin-bottom:1rem; font-size:0.86rem; }
        .back-link { display:inline-block; margin-top:1.5rem; color:var(--text-muted); text-decoration:none; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.12em; }
        .back-link:hover { color:#ffffff; }
    </style>
</head>
<body class="page-home">
    <main class="user-login-wrap">
        <section class="user-login-box">
            <h1>User Login</h1>
            <p>Access your personal N2L8studios profile.</p>
            <?php if ($error): ?><div class="login-error"><?= h($error) ?></div><?php endif; ?>
            <form method="POST">
                <input type="text" name="username" placeholder="Username" required autocomplete="username">
                <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
                <button class="cta-btn" style="width:100%;" type="submit">Login</button>
            </form>
            <a href="/index.php" class="back-link">Return to front page</a>
        </section>
    </main>
</body>
</html>
