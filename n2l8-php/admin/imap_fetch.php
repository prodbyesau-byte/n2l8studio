<?php
/**
 * IMAP Email Fetcher — Returns JSON list of emails from a specified folder.
 * GET ?folder=INBOX&page=1&limit=25
 * 
 * Requires admin authentication.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

require_owner();
header('Content-Type: application/json; charset=utf-8');

$folder   = strtoupper(trim($_GET['folder'] ?? 'INBOX'));
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = min(50, max(5, (int)($_GET['limit'] ?? 25)));
$search   = trim($_GET['search'] ?? '');

// Validate folder names
$allowed_folders = ['INBOX', 'SENT', 'SPAM', 'JUNK', 'TRASH', 'DRAFTS', 'IMPORTANT'];
// Map friendly names to IMAP folder names
$folder_map = [
    'INBOX'     => 'INBOX',
    'SENT'      => 'Sent',
    'SPAM'      => 'Spam',
    'JUNK'      => 'Junk',
    'TRASH'     => 'Trash',
    'DRAFTS'    => 'Drafts',
    'IMPORTANT' => 'INBOX', // We'll filter by flagged
];

if (!in_array($folder, $allowed_folders)) {
    echo json_encode(['error' => 'Invalid folder', 'emails' => []]);
    exit;
}

// IMAP config
$imap_host = defined('IMAP_HOST') ? IMAP_HOST : 'imap.simply.com';
$imap_port = defined('IMAP_PORT') ? IMAP_PORT : 993;
$imap_user = defined('IMAP_USER') ? IMAP_USER : (defined('SMTP_USER') ? SMTP_USER : '');
$imap_pass = defined('IMAP_PASS') ? IMAP_PASS : (defined('SMTP_PASS') ? SMTP_PASS : '');
$imap_enc  = defined('IMAP_ENCRYPTION') ? IMAP_ENCRYPTION : 'ssl';

if (empty($imap_user) || empty($imap_pass)) {
    echo json_encode(['error' => 'IMAP credentials not configured', 'emails' => []]);
    exit;
}

// Check if php-imap extension is available
if (!function_exists('imap_open')) {
    echo json_encode(['error' => 'PHP IMAP extension not available on server', 'emails' => []]);
    exit;
}

$imap_folder = $folder_map[$folder] ?? 'INBOX';
$mailbox_str = '{' . $imap_host . ':' . $imap_port . '/imap/' . $imap_enc . '}' . $imap_folder;

$inbox = @imap_open($mailbox_str, $imap_user, $imap_pass, 0, 1);

if (!$inbox) {
    // Try alternate folder names (different IMAP servers use different conventions)
    $alt_names = [
        'Sent'  => ['INBOX.Sent', 'Sent Messages', 'Sent Items'],
        'Spam'  => ['INBOX.Spam', 'INBOX.Junk', 'Junk E-mail', 'Bulk Mail'],
        'Trash' => ['INBOX.Trash', 'Deleted Messages', 'Deleted Items'],
        'Drafts'=> ['INBOX.Drafts', 'Draft'],
    ];
    
    $tried = false;
    if (isset($alt_names[$imap_folder])) {
        foreach ($alt_names[$imap_folder] as $alt) {
            $alt_str = '{' . $imap_host . ':' . $imap_port . '/imap/' . $imap_enc . '}' . $alt;
            $inbox = @imap_open($alt_str, $imap_user, $imap_pass, 0, 1);
            if ($inbox) { $tried = true; break; }
        }
    }
    
    if (!$inbox) {
        $err = imap_last_error();
        echo json_encode(['error' => 'IMAP connection failed: ' . ($err ?: 'Unknown error'), 'emails' => []]);
        exit;
    }
}

// Get total message count
$info = imap_mailboxmsginfo($inbox);
$total = $info->Nmsgs;
$unread = $info->Unread;

// Search if query provided
if ($search !== '') {
    $msg_nums = imap_search($inbox, 'OR SUBJECT "' . addcslashes($search, '"\\') . '" FROM "' . addcslashes($search, '"\\') . '"', SE_UID);
    if (!$msg_nums) $msg_nums = [];
    // Sort newest first
    rsort($msg_nums);
    $total_results = count($msg_nums);
} elseif ($folder === 'IMPORTANT') {
    // For "Important" — get flagged emails from INBOX
    $msg_nums = imap_search($inbox, 'FLAGGED', SE_UID);
    if (!$msg_nums) $msg_nums = [];
    rsort($msg_nums);
    $total_results = count($msg_nums);
} else {
    // Get all messages sorted newest first
    $msg_nums = [];
    for ($i = $total; $i >= 1; $i--) {
        $uid = imap_uid($inbox, $i);
        $msg_nums[] = $uid;
    }
    $total_results = $total;
}

// Paginate
$offset = ($page - 1) * $limit;
$page_uids = array_slice($msg_nums, $offset, $limit);

$emails = [];
foreach ($page_uids as $uid) {
    $header = @imap_fetchheader($inbox, $uid, FT_UID);
    $overview_arr = @imap_fetch_overview($inbox, (string)$uid, FT_UID);
    
    if (empty($overview_arr)) continue;
    $ov = $overview_arr[0];
    
    // Decode subject
    $subject = isset($ov->subject) ? imap_utf8($ov->subject) : '(No Subject)';
    
    // Decode from
    $from_raw = isset($ov->from) ? imap_utf8($ov->from) : 'Unknown';
    $from_parsed = imap_rfc822_parse_adrlist($from_raw, '');
    $from_name = '';
    $from_email = '';
    if (!empty($from_parsed)) {
        $from_name = isset($from_parsed[0]->personal) ? imap_utf8($from_parsed[0]->personal) : '';
        $from_email = ($from_parsed[0]->mailbox ?? '') . '@' . ($from_parsed[0]->host ?? '');
    }
    if (empty($from_name)) $from_name = $from_email;
    
    // Get snippet (first ~120 chars of plain text body)
    $body_text = @imap_fetchbody($inbox, $uid, '1', FT_UID | FT_PEEK);
    $body_text = imap_utf8($body_text);
    $body_text = strip_tags($body_text);
    $body_text = preg_replace('/\s+/', ' ', $body_text);
    $snippet = mb_substr(trim($body_text), 0, 120);
    
    // Date
    $date_raw = isset($ov->date) ? $ov->date : '';
    $timestamp = strtotime($date_raw);
    $date_formatted = $timestamp ? date('M j, H:i', $timestamp) : $date_raw;
    $date_full = $timestamp ? date('Y-m-d H:i:s', $timestamp) : $date_raw;
    
    // Flags
    $is_read = isset($ov->seen) && $ov->seen;
    $is_flagged = isset($ov->flagged) && $ov->flagged;
    
    $emails[] = [
        'uid'          => $uid,
        'from_name'    => $from_name,
        'from_email'   => $from_email,
        'subject'      => $subject,
        'snippet'      => $snippet,
        'date'         => $date_formatted,
        'date_full'    => $date_full,
        'timestamp'    => $timestamp ?: 0,
        'is_read'      => $is_read,
        'is_flagged'   => $is_flagged,
    ];
}

imap_close($inbox);

echo json_encode([
    'folder'   => $folder,
    'page'     => $page,
    'limit'    => $limit,
    'total'    => $total_results,
    'unread'   => $unread,
    'pages'    => ceil($total_results / $limit),
    'emails'   => $emails,
]);
