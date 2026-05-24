<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_owner();

if (session_status() === PHP_SESSION_NONE) session_start();

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipient_type = trim($_POST['recipient_type'] ?? 'custom');
    $custom_email   = trim($_POST['custom_email'] ?? '');
    $user_id        = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $subject        = trim($_POST['subject'] ?? '');
    $message_body   = trim($_POST['message'] ?? '');
    $template_type  = trim($_POST['template_type'] ?? 'premium_dark');

    if (empty($subject) || empty($message_body)) {
        flash('Subject and message body are required.');
        redirect('/admin/index.php?tab=email');
    }

    // Determine target emails
    $emails = [];
    if ($recipient_type === 'custom') {
        if (!filter_var($custom_email, FILTER_VALIDATE_EMAIL)) {
            flash('Error: Invalid custom email address.');
            redirect('/admin/index.php?tab=email');
        }
        $emails[] = ['email' => $custom_email, 'username' => 'Subscriber'];
    } elseif ($recipient_type === 'single_user') {
        $stmt = $pdo->prepare('SELECT email, username FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if (!$user || empty($user['email'])) {
            flash('Error: Target user not found or has no email.');
            redirect('/admin/index.php?tab=email');
        }
        $emails[] = $user;
    } elseif ($recipient_type === 'all_approved') {
        $stmt = $pdo->query('SELECT email, username FROM users WHERE is_approved = 1 AND role != "admin"');
        $emails = $stmt->fetchAll();
        if (empty($emails)) {
            flash('No approved users found.');
            redirect('/admin/index.php?tab=email');
        }
    } else {
        flash('Invalid recipient type.');
        redirect('/admin/index.php?tab=email');
    }

    $sent_count = 0;
    $errors = [];

    foreach ($emails as $recipient) {
        $to_email = $recipient['email'];
        $to_name  = htmlspecialchars($recipient['username'], ENT_QUOTES, 'UTF-8');

        if ($template_type === 'premium_dark') {
            $formatted_body = "
            <html>
            <body style=\"background-color:#050508; color:#ffffff; font-family:'Montserrat',sans-serif; padding:40px 20px; margin:0;\">
                <div style=\"max-width:600px; margin:0 auto; background:#0d0d12; border:1px solid #c0152a; padding:40px; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.5); text-align:left;\">
                    <div style=\"font-size:28px; font-weight:700; font-family:'Syncopate',sans-serif; letter-spacing:3px; color:#ffffff; text-align:center; margin-bottom:30px;\">
                        N<span style=\"color:#c0152a;\">2</span>L8studios
                    </div>
                    <div style=\"border-left:4px solid #c0152a; padding-left:15px; margin-bottom:25px;\">
                        <h2 style=\"font-family:'Syncopate',sans-serif; color:#ffffff; font-size:18px; text-transform:uppercase; letter-spacing:1px; margin:0;\">
                            " . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . "
                        </h2>
                    </div>
                    <p style=\"color:#ffffff; font-size:15px; font-weight:600; margin-bottom:20px;\">
                        Hello " . $to_name . ",
                    </p>
                    <div style=\"color:#b3b3b3; font-size:15px; line-height:1.7; margin-bottom:35px; white-space:pre-wrap;\">" . 
                        htmlspecialchars($message_body, ENT_QUOTES, 'UTF-8') . "
                    </div>
                    <div style=\"text-align:center; margin-top:40px;\">
                        <a href=\"https://www.n2l8studios.com/login.php\" style=\"display:inline-block; background:#c0152a; color:#ffffff; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:2px; text-decoration:none; padding:15px 40px; border-radius:4px; transition:all 0.2s;\">
                            Enter Portal
                        </a>
                    </div>
                    <div style=\"margin-top:40px; border-top:1px solid rgba(255,255,255,0.05); padding-top:20px; text-align:center; color:#666666; font-size:12px;\">
                        &copy; " . date('Y') . " N2L8studios. All rights reserved.<br>
                        You are receiving this premium transmission because you are registered at <a href=\"https://www.n2l8studios.com\" style=\"color:#c0152a; text-decoration:none;\">n2l8studios.com</a>.
                    </div>
                </div>
            </body>
            </html>
            ";
        } else {
            // Plain text inside basic HTML structure for safety
            $formatted_body = "<html><body><pre style=\"font-family:monospace; font-size:14px;\">" . htmlspecialchars($message_body, ENT_QUOTES, 'UTF-8') . "</pre></body></html>";
        }

        $success = send_platform_email($to_email, $subject, $formatted_body);
        
        if ($success) {
            $sent_count++;
        } else {
            $errors[] = $to_email;
        }
    }

    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($sent_count > 0) {
        log_action($pdo, "Admin broadcasted email: '{$subject}' to {$sent_count} recipient(s).");
        $flash_msg = "Successfully sent {$sent_count} email(s) from admin@n2l8studios.com!";
        if (!empty($errors)) {
            $flash_msg .= " Failed to send to: " . implode(', ', $errors);
        }
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => $flash_msg, 'sent' => $sent_count]);
            exit;
        }
        flash($flash_msg);
    } else {
        $err_msg = "Failed to send email. " . (!empty($errors) ? "Errors: " . implode(', ', $errors) : "");
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $err_msg]);
            exit;
        }
        flash($err_msg);
    }
}

redirect('/admin/index.php?tab=email');
exit;
