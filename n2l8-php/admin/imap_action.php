<?php
/**
 * IMAP Email Actions — delete, mark read/unread, move to folder, flag/unflag
 * POST: action=delete|mark_read|mark_unread|flag|unflag|move  uid=123  folder=INBOX  target_folder=Spam
 * 
 * Requires admin authentication.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

require_owner();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$action = trim($_POST['action'] ?? '');
$uid    = (int)($_POST['uid'] ?? 0);
$folder = strtoupper(trim($_POST['folder'] ?? 'INBOX'));
$target = trim($_POST['target_folder'] ?? '');

if (!$action || !$uid) {
    echo json_encode(['error' => 'Missing action or uid']);
    exit;
}

$allowed_actions = ['delete', 'mark_read', 'mark_unread', 'flag', 'unflag', 'move'];
if (!in_array($action, $allowed_actions)) {
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

// IMAP config
$imap_host = defined('IMAP_HOST') ? IMAP_HOST : 'imap.simply.com';
$imap_port = defined('IMAP_PORT') ? IMAP_PORT : 993;
$imap_user = defined('IMAP_USER') ? IMAP_USER : (defined('SMTP_USER') ? SMTP_USER : '');
$imap_pass = defined('IMAP_PASS') ? IMAP_PASS : (defined('SMTP_PASS') ? SMTP_PASS : '');
$imap_enc  = defined('IMAP_ENCRYPTION') ? IMAP_ENCRYPTION : 'ssl';

$folder_map = [
    'INBOX'  => 'INBOX',
    'PRIMARY'=> 'INBOX',
    'SENT'   => 'Sent',
    'SPAM'   => 'Spam',
    'TRASH'  => 'Trash',
    'DRAFTS' => 'Drafts',
    'ARCHIVE'=> 'Archive',
    'IMPORTANT' => 'INBOX',
];

$imap_folder = $folder_map[$folder] ?? 'INBOX';
$base_str = '{' . $imap_host . ':' . $imap_port . '/imap/' . $imap_enc . '}';
$mailbox_str = $base_str . $imap_folder;

$inbox = @imap_open($mailbox_str, $imap_user, $imap_pass, 0, 1);

if (!$inbox) {
    echo json_encode(['error' => 'IMAP connection failed: ' . imap_last_error()]);
    exit;
}

$success = false;
$message = '';

switch ($action) {
    case 'delete':
        // Move to Trash, or expunge if already in Trash
        if ($imap_folder === 'Trash') {
            imap_setflag_full($inbox, (string)$uid, '\\Deleted', ST_UID);
            imap_expunge($inbox);
            $message = 'Email permanently deleted';
        } else {
            $success = @imap_mail_move($inbox, (string)$uid, 'Trash', CP_UID);
            if (!$success) {
                // Try INBOX.Trash
                $success = @imap_mail_move($inbox, (string)$uid, 'INBOX.Trash', CP_UID);
            }
            imap_expunge($inbox);
            $message = 'Email moved to Trash';
        }
        $success = true;
        break;
        
    case 'mark_read':
        imap_setflag_full($inbox, (string)$uid, '\\Seen', ST_UID);
        $success = true;
        $message = 'Email marked as read';
        break;
        
    case 'mark_unread':
        imap_clearflag_full($inbox, (string)$uid, '\\Seen', ST_UID);
        $success = true;
        $message = 'Email marked as unread';
        break;
        
    case 'flag':
        imap_setflag_full($inbox, (string)$uid, '\\Flagged', ST_UID);
        $success = true;
        $message = 'Email flagged as important';
        break;
        
    case 'unflag':
        imap_clearflag_full($inbox, (string)$uid, '\\Flagged', ST_UID);
        $success = true;
        $message = 'Email unflagged';
        break;
        
    case 'move':
        if (empty($target)) {
            $message = 'Missing target folder';
            break;
        }
        $target_imap = $folder_map[strtoupper($target)] ?? $target;
        $success = @imap_mail_move($inbox, (string)$uid, $target_imap, CP_UID);
        if ($success) {
            imap_expunge($inbox);
            $message = "Email moved to $target";
        } else {
            $message = 'Failed to move email: ' . imap_last_error();
        }
        break;
}

imap_close($inbox);

echo json_encode([
    'success' => $success,
    'message' => $message,
    'action'  => $action,
    'uid'     => $uid,
]);
