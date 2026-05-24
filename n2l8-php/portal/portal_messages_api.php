<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$pdo = get_pdo();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'fetch_conversations') {
        // Fetch all unique conversation partners
        $sql = '
            SELECT 
                u.id AS partner_id,
                u.username AS partner_username,
                u.profile_picture AS partner_avatar,
                f.status AS friendship_status,
                m.id AS last_msg_id,
                m.message AS last_msg_text,
                m.created_at AS last_msg_time,
                m.sender_id AS last_msg_sender_id,
                m.is_read AS last_msg_is_read,
                (
                    SELECT COUNT(*) 
                    FROM messages 
                    WHERE sender_id = u.id 
                      AND recipient_id = :user_id 
                      AND is_read = 0 
                      AND deleted_by_recipient = 0
                ) AS unread_count
            FROM (
                SELECT DISTINCT 
                    CASE 
                        WHEN sender_id = :user_id THEN recipient_id 
                        ELSE sender_id 
                    END AS partner_id
                FROM messages
                WHERE (sender_id = :user_id AND deleted_by_sender = 0)
                   OR (recipient_id = :user_id AND deleted_by_recipient = 0)
            ) AS partners
            JOIN users u ON u.id = partners.partner_id
            LEFT JOIN friendships f ON (
                (f.user_id1 = LEAST(:user_id, u.id) AND f.user_id2 = GREATEST(:user_id, u.id))
            )
            JOIN messages m ON m.id = (
                SELECT id 
                FROM messages 
                WHERE ((sender_id = :user_id AND recipient_id = u.id AND deleted_by_sender = 0)
                   OR (recipient_id = :user_id AND sender_id = u.id AND deleted_by_recipient = 0))
                ORDER BY id DESC 
                LIMIT 1
            )
            ORDER BY m.id DESC
        ';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['user_id' => $user_id]);
        $conversations = $stmt->fetchAll();
        
        $primary = [];
        $general = [];
        
        foreach ($conversations as $c) {
            $c['last_msg_text'] = strip_tags($c['last_msg_text']);
            if ($c['friendship_status'] === 'accepted') {
                $primary[] = $c;
            } else {
                $general[] = $c;
            }
        }
        
        echo json_encode([
            'success' => true,
            'primary' => $primary,
            'general' => $general
        ]);
        exit;
    }
    
    elseif ($action === 'fetch_thread') {
        $partner_id = (int)($_GET['partner_id'] ?? 0);
        if ($partner_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid partner ID']);
            exit;
        }
        
        // Mark all incoming messages from this partner as read
        $upd = $pdo->prepare('
            UPDATE messages 
            SET is_read = 1 
            WHERE sender_id = ? AND recipient_id = ? AND is_read = 0 AND deleted_by_recipient = 0
        ');
        $upd->execute([$partner_id, $user_id]);
        
        // Fetch chronological messages between user and partner
        $sql = '
            SELECT m.*, 
                   sender.username AS sender_username, 
                   sender.profile_picture AS sender_avatar,
                   recipient.username AS recipient_username,
                   recipient.profile_picture AS recipient_avatar
            FROM messages m
            JOIN users sender ON sender.id = m.sender_id
            JOIN users recipient ON recipient.id = m.recipient_id
            WHERE (m.sender_id = :user_id AND m.recipient_id = :partner_id AND m.deleted_by_sender = 0)
               OR (m.sender_id = :partner_id AND m.recipient_id = :user_id AND m.deleted_by_recipient = 0)
            ORDER BY m.id ASC
        ';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['user_id' => $user_id, 'partner_id' => $partner_id]);
        $messages = $stmt->fetchAll();
        
        // Fetch partner details
        $p_stmt = $pdo->prepare('SELECT id, username, profile_picture FROM users WHERE id = ?');
        $p_stmt->execute([$partner_id]);
        $partner = $p_stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'partner' => $partner,
            'messages' => $messages
        ]);
        exit;
    }
    
    elseif ($action === 'send_message') {
        $recipient_id = (int)($_POST['recipient_id'] ?? 0);
        $message_text = trim($_POST['message'] ?? '');
        $subject = trim($_POST['subject'] ?? 'Direct Message');
        
        if (empty($message_text)) {
            echo json_encode(['success' => false, 'error' => 'Message content cannot be empty']);
            exit;
        }
        
        if ($recipient_id <= 0 && isset($_POST['recipient_username'])) {
            // Find by username
            $u_stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $u_stmt->execute([trim($_POST['recipient_username'])]);
            $recipient_id = (int)$u_stmt->fetchColumn();
        }
        
        if ($recipient_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Recipient not found']);
            exit;
        }
        
        if ($recipient_id === $user_id) {
            echo json_encode(['success' => false, 'error' => 'You cannot send a message to yourself']);
            exit;
        }
        
        $ins = $pdo->prepare('
            INSERT INTO messages (sender_id, recipient_id, subject, message, is_read, created_at)
            VALUES (?, ?, ?, ?, 0, NOW())
        ');
        $ins->execute([$user_id, $recipient_id, $subject, $message_text]);
        $new_id = $pdo->lastInsertId();
        
        // Log action
        log_action($pdo, "User {$_SESSION['username']} sent a direct message to user ID {$recipient_id}");
        
        echo json_encode([
            'success' => true,
            'message_id' => $new_id,
            'message' => [
                'id' => $new_id,
                'sender_id' => $user_id,
                'recipient_id' => $recipient_id,
                'subject' => $subject,
                'message' => $message_text,
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit;
    }
    
    elseif ($action === 'toggle_star') {
        $message_id = (int)($_POST['message_id'] ?? 0);
        if ($message_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid message ID']);
            exit;
        }
        
        // Fetch message to see if we are sender or recipient
        $stmt = $pdo->prepare('SELECT sender_id, recipient_id, is_flagged_by_sender, is_flagged_by_recipient FROM messages WHERE id = ?');
        $stmt->execute([$message_id]);
        $msg = $stmt->fetch();
        
        if (!$msg || ($msg['sender_id'] != $user_id && $msg['recipient_id'] != $user_id)) {
            echo json_encode(['success' => false, 'error' => 'Message not found or access denied']);
            exit;
        }
        
        $new_state = 0;
        if ($msg['sender_id'] == $user_id) {
            $new_state = $msg['is_flagged_by_sender'] ? 0 : 1;
            $upd = $pdo->prepare('UPDATE messages SET is_flagged_by_sender = ? WHERE id = ?');
            $upd->execute([$new_state, $message_id]);
        } else {
            $new_state = $msg['is_flagged_by_recipient'] ? 0 : 1;
            $upd = $pdo->prepare('UPDATE messages SET is_flagged_by_recipient = ? WHERE id = ?');
            $upd->execute([$new_state, $message_id]);
        }
        
        echo json_encode(['success' => true, 'is_starred' => $new_state]);
        exit;
    }
    
    elseif ($action === 'delete_message') {
        $message_id = (int)($_POST['message_id'] ?? 0);
        if ($message_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid message ID']);
            exit;
        }
        
        // Fetch message
        $stmt = $pdo->prepare('SELECT sender_id, recipient_id, deleted_by_sender, deleted_by_recipient FROM messages WHERE id = ?');
        $stmt->execute([$message_id]);
        $msg = $stmt->fetch();
        
        if (!$msg || ($msg['sender_id'] != $user_id && $msg['recipient_id'] != $user_id)) {
            echo json_encode(['success' => false, 'error' => 'Message not found or access denied']);
            exit;
        }
        
        if ($msg['sender_id'] == $user_id) {
            $upd = $pdo->prepare('UPDATE messages SET deleted_by_sender = 1 WHERE id = ?');
            $upd->execute([$message_id]);
        } else {
            $upd = $pdo->prepare('UPDATE messages SET deleted_by_recipient = 1 WHERE id = ?');
            $upd->execute([$message_id]);
        }
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    elseif ($action === 'fetch_important') {
        // Fetch all starred/flagged messages that are not soft-deleted
        $sql = '
            SELECT m.*, 
                   sender.username AS sender_username, 
                   sender.profile_picture AS sender_avatar,
                   recipient.username AS recipient_username,
                   recipient.profile_picture AS recipient_avatar
            FROM messages m
            JOIN users sender ON sender.id = m.sender_id
            JOIN users recipient ON recipient.id = m.recipient_id
            WHERE (m.sender_id = :user_id AND m.is_flagged_by_sender = 1 AND m.deleted_by_sender = 0)
               OR (m.recipient_id = :user_id AND m.is_flagged_by_recipient = 1 AND m.deleted_by_recipient = 0)
            ORDER BY m.id DESC
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['user_id' => $user_id]);
        $messages = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'messages' => $messages]);
        exit;
    }
    
    elseif ($action === 'fetch_deleted') {
        // Fetch all soft-deleted messages for the user
        $sql = '
            SELECT m.*, 
                   sender.username AS sender_username, 
                   sender.profile_picture AS sender_avatar,
                   recipient.username AS recipient_username,
                   recipient.profile_picture AS recipient_avatar
            FROM messages m
            JOIN users sender ON sender.id = m.sender_id
            JOIN users recipient ON recipient.id = m.recipient_id
            WHERE (m.sender_id = :user_id AND m.deleted_by_sender = 1)
               OR (m.recipient_id = :user_id AND m.deleted_by_recipient = 1)
            ORDER BY m.id DESC
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['user_id' => $user_id]);
        $messages = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'messages' => $messages]);
        exit;
    }
    
    elseif ($action === 'fetch_global_unread') {
        // Count unread DMs received by user that are not soft-deleted
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0 AND deleted_by_recipient = 0');
        $stmt->execute([$user_id]);
        $unread = (int)$stmt->fetchColumn();
        
        echo json_encode(['success' => true, 'unread_count' => $unread]);
        exit;
    }
    
    else {
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
