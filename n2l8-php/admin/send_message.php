<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_owner();

if (session_status() === PHP_SESSION_NONE) session_start();

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipient_id = isset($_POST['recipient_id']) ? (int)$_POST['recipient_id'] : 0;
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($recipient_id <= 0 || empty($subject) || empty($message)) {
        flash('All message fields are required.');
        redirect('/admin/index.php?tab=messages');
    }

    try {
        // Verify recipient exists
        $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
        $stmt->execute([$recipient_id]);
        $recipient_name = $stmt->fetchColumn();

        if (!$recipient_name) {
            flash('Error: Recipient user does not exist.');
            redirect('/admin/index.php?tab=messages');
        }

        // Insert message
        $stmt = $pdo->prepare('
            INSERT INTO messages (sender_id, recipient_id, subject, message, is_read) 
            VALUES (?, ?, ?, ?, 0)
        ');
        $stmt->execute([$_SESSION['user_id'], $recipient_id, $subject, $message]);

        log_action($pdo, "Admin sent private message to {$recipient_name}: '{$subject}'");
        flash("Message sent successfully to {$recipient_name}!");
    } catch (Throwable $e) {
        flash("Error sending message: " . $e->getMessage());
    }
}

redirect('/admin/index.php?tab=messages');
exit;
