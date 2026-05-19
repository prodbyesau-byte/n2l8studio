<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_owner();

$pdo = get_pdo();

$action  = $_GET['action'] ?? '';
$user_id = (int)($_GET['id'] ?? 0);

if (!$user_id) {
    flash('Fejl: Ugyldigt bruger-ID.');
    redirect('/admin/index.php?tab=users');
}

// Ensure we don't modify administrators
$check = $pdo->prepare("SELECT username, email, role FROM users WHERE id = ?");
$check->execute([$user_id]);
$target_user = $check->fetch();

if (!$target_user || $target_user['role'] === 'admin') {
    flash('Fejl: Brugeren findes ikke eller er en administrator.');
    redirect('/admin/index.php?tab=users');
}

switch ($action) {
    case 'approve':
        $stmt = $pdo->prepare("UPDATE users SET is_approved = 1 WHERE id = ?");
        if ($stmt->execute([$user_id])) {
            log_action($pdo, "User approved by admin: {$target_user['username']} (ID {$user_id})");
            flash("Brugeren '{$target_user['username']}' er nu godkendt og kan logge ind!");

            // Send verification email to user
            if (!empty($target_user['email'])) {
                $to = $target_user['email'];
                $subject = "Your N2L8 STUDIO Account Has Been Verified!";
                
                $headers = "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $headers .= "From: N2L8 STUDIO <noreply@n2l8studios.com>\r\n";
                $headers .= "Reply-To: noreply@n2l8studios.com\r\n";
                $headers .= "X-Mailer: PHP/" . phpversion();

                $body = "
                <html>
                <body style=\"background-color:#050508; color:#ffffff; font-family:'Montserrat',sans-serif; padding:40px 20px; margin:0;\">
                    <div style=\"max-width:600px; margin:0 auto; background:#0d0d12; border:1px solid #c0152a; padding:40px; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.5); text-align:center;\">
                        <div style=\"font-size:28px; font-weight:700; font-family:'Syncopate',sans-serif; letter-spacing:3px; color:#ffffff; margin-bottom:30px;\">
                            N<span style=\"color:#c0152a;\">2</span>L8studios
                        </div>
                        <h2 style=\"font-family:'Syncopate',sans-serif; color:#ffffff; font-size:22px; text-transform:uppercase; letter-spacing:1px; margin-bottom:20px;\">
                            ACCESS GRANTED
                        </h2>
                        <p style=\"color:#b3b3b3; font-size:15px; line-height:1.7; margin-bottom:35px;\">
                            Hello " . htmlspecialchars($target_user['username'], ENT_QUOTES, 'UTF-8') . ",<br><br>
                            We are excited to inform you that your account registration has been verified and **approved** by our team!<br><br>
                            You now have full access to our premium sound & art portal, personal file vault, and inbox.
                        </p>
                        <a href=\"https://www.n2l8studios.com/login.php\" style=\"display:inline-block; background:#c0152a; color:#ffffff; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:2px; text-decoration:none; padding:15px 40px; border-radius:4px; transition:all 0.2s;\">
                            Enter Portal
                        </a>
                        <div style=\"margin-top:40px; border-top:1px solid rgba(255,255,255,0.05); padding-top:20px; color:#666666; font-size:12px;\">
                            &copy; " . date('Y') . " N2L8studios. All rights reserved.
                        </div>
                    </div>
                </body>
                </html>
                ";

                @mail($to, $subject, $body, $headers);
            }
        } else {
            flash('Fejl under godkendelse.');
        }
        break;

    case 'deactivate':
        $stmt = $pdo->prepare("UPDATE users SET is_approved = 0 WHERE id = ?");
        if ($stmt->execute([$user_id])) {
            log_action($pdo, "User deactivated by admin: {$target_user['username']} (ID {$user_id})");
            flash("Brugeren '{$target_user['username']}' er blevet deaktiveret.");
        } else {
            flash('Fejl under deaktivering.');
        }
        break;

    case 'reject':
        // Deleting the user is clean and removes pending spam accounts
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        if ($stmt->execute([$user_id])) {
            log_action($pdo, "User registration rejected/deleted by admin: {$target_user['username']} (ID {$user_id})");
            flash("Registrering for '{$target_user['username']}' blev afvist og profilen slettet.");
        } else {
            flash('Fejl under afvisning.');
        }
        break;

    default:
        flash('Fejl: Ugyldig handling.');
        break;
}

redirect('/admin/index.php?tab=users');
