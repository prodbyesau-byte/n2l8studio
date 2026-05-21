<?php
/**
 * N2L8Studio — P2P Send Message Endpoint
 * Handles secure direct messaging between portal members.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_client_login();

if (session_status() === PHP_SESSION_NONE) session_start();

$pdo = get_pdo();

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($is_ajax) {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }
    redirect('/portal/index.php?tab=inbox');
}

$recipient_id = isset($_POST['recipient_id']) ? (int)$_POST['recipient_id'] : 0;
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($recipient_id <= 0 || empty($subject) || empty($message)) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'All fields (recipient, subject, message) are required.']);
        exit;
    }
    flash('All fields are required.');
    redirect('/portal/index.php?tab=inbox');
}

if ($recipient_id === (int)$_SESSION['user_id']) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'You cannot send a message to yourself.']);
        exit;
    }
    flash('You cannot send a message to yourself.');
    redirect('/portal/index.php?tab=inbox');
}

try {
    // Verify recipient exists and is active/approved
    $stmt = $pdo->prepare('SELECT username, role, is_approved FROM users WHERE id = ?');
    $stmt->execute([$recipient_id]);
    $recipient = $stmt->fetch();

    if (!$recipient || (!$recipient['is_approved'] && $recipient['role'] !== 'admin')) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Recipient user does not exist or is not approved.']);
            exit;
        }
        flash('Error: Recipient user does not exist or is pending approval.');
        redirect('/portal/index.php?tab=inbox');
    }

    // Save message to database
    $stmt = $pdo->prepare('
        INSERT INTO messages (sender_id, recipient_id, subject, message, is_read) 
        VALUES (?, ?, ?, ?, 0)
    ');
    $stmt->execute([$_SESSION['user_id'], $recipient_id, $subject, $message]);

    log_action($pdo, "User {$_SESSION['username']} sent message to {$recipient['username']}: '{$subject}'");

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => "Message sent successfully to {$recipient['username']}!"]);
        exit;
    }

    flash("Message sent successfully to {$recipient['username']}!");
    redirect('/portal/index.php?tab=inbox&subtab=sent');
} catch (Throwable $e) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
    flash('Error sending message: ' . $e->getMessage());
    redirect('/portal/index.php?tab=inbox');
}
