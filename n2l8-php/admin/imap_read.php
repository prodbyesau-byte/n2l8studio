<?php
/**
 * IMAP Email Reader — Returns full email body and metadata for a specific email UID.
 * GET ?uid=123&folder=INBOX
 * 
 * Requires admin authentication.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

require_owner();
header('Content-Type: application/json; charset=utf-8');

$uid    = (int)($_GET['uid'] ?? 0);
$folder = strtoupper(trim($_GET['folder'] ?? 'INBOX'));

if (!$uid) {
    echo json_encode(['error' => 'Missing email UID']);
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
    'JUNK'   => 'Spam',
    'TRASH'  => 'Trash',
    'DRAFTS' => 'Drafts',
    'ARCHIVE'=> 'Archive',
    'IMPORTANT' => 'INBOX',
];

$imap_folder = $folder_map[$folder] ?? 'INBOX';
$mailbox_str = '{' . $imap_host . ':' . $imap_port . '/imap/' . $imap_enc . '}' . $imap_folder;

$inbox = @imap_open($mailbox_str, $imap_user, $imap_pass, 0, 1);

if (!$inbox) {
    echo json_encode(['error' => 'IMAP connection failed: ' . imap_last_error()]);
    exit;
}

// Fetch overview for metadata
$overview_arr = @imap_fetch_overview($inbox, (string)$uid, FT_UID);
if (empty($overview_arr)) {
    imap_close($inbox);
    echo json_encode(['error' => 'Email not found']);
    exit;
}

$ov = $overview_arr[0];

// Parse headers
$header_obj = @imap_headerinfo($inbox, imap_msgno($inbox, $uid));

// From
$from_name = '';
$from_email = '';
if (!empty($header_obj->from)) {
    $f = $header_obj->from[0];
    $from_name = isset($f->personal) ? imap_utf8($f->personal) : '';
    $from_email = ($f->mailbox ?? '') . '@' . ($f->host ?? '');
}
if (empty($from_name)) $from_name = $from_email;

// To
$to_list = [];
if (!empty($header_obj->to)) {
    foreach ($header_obj->to as $t) {
        $to_name = isset($t->personal) ? imap_utf8($t->personal) : '';
        $to_addr = ($t->mailbox ?? '') . '@' . ($t->host ?? '');
        $to_list[] = $to_name ? "$to_name <$to_addr>" : $to_addr;
    }
}

// CC
$cc_list = [];
if (!empty($header_obj->cc)) {
    foreach ($header_obj->cc as $c) {
        $cc_name = isset($c->personal) ? imap_utf8($c->personal) : '';
        $cc_addr = ($c->mailbox ?? '') . '@' . ($c->host ?? '');
        $cc_list[] = $cc_name ? "$cc_name <$cc_addr>" : $cc_addr;
    }
}

// Subject
$subject = isset($ov->subject) ? imap_utf8($ov->subject) : '(No Subject)';

// Date
$date_raw = isset($ov->date) ? $ov->date : '';
$timestamp = strtotime($date_raw);
$date_formatted = $timestamp ? date('D, M j, Y \a\t H:i', $timestamp) : $date_raw;

// Flags
$is_read = isset($ov->seen) && $ov->seen;
$is_flagged = isset($ov->flagged) && $ov->flagged;

// Get email body — handle multipart messages
$structure = imap_fetchstructure($inbox, $uid, FT_UID);
$body_html = '';
$body_text = '';
$attachments = [];

function get_part($inbox, $uid, $part_number, $encoding) {
    $data = imap_fetchbody($inbox, $uid, $part_number, FT_UID);
    switch ($encoding) {
        case 0: // 7BIT
        case 1: // 8BIT
            return $data;
        case 2: // BINARY
            return $data;
        case 3: // BASE64
            return base64_decode($data);
        case 4: // QUOTED-PRINTABLE
            return quoted_printable_decode($data);
        default:
            return $data;
    }
}

function get_charset($part) {
    if (!empty($part->parameters)) {
        foreach ($part->parameters as $p) {
            if (strtolower($p->attribute) === 'charset') {
                return strtoupper($p->value);
            }
        }
    }
    return 'UTF-8';
}

function convert_to_utf8($text, $charset) {
    if ($charset === 'UTF-8' || empty($charset)) return $text;
    $converted = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $text);
    return $converted !== false ? $converted : $text;
}

function parse_parts($inbox, $uid, $structure, $part_number = '', &$body_html, &$body_text, &$attachments) {
    if (isset($structure->parts) && count($structure->parts) > 0) {
        foreach ($structure->parts as $i => $part) {
            $sub_part = $part_number ? ($part_number . '.' . ($i + 1)) : (string)($i + 1);
            parse_parts($inbox, $uid, $part, $sub_part, $body_html, $body_text, $attachments);
        }
    } else {
        // Single part
        $pn = $part_number ?: '1';
        $encoding = $structure->encoding ?? 0;
        $type = $structure->type ?? 0;
        $subtype = strtolower($structure->subtype ?? '');
        $charset = get_charset($structure);
        
        // Check if attachment
        $is_attachment = false;
        $filename = '';
        if (!empty($structure->dparameters)) {
            foreach ($structure->dparameters as $dp) {
                if (strtolower($dp->attribute) === 'filename') {
                    $is_attachment = true;
                    $filename = imap_utf8($dp->value);
                }
            }
        }
        if (!$is_attachment && !empty($structure->parameters)) {
            foreach ($structure->parameters as $p) {
                if (strtolower($p->attribute) === 'name') {
                    $is_attachment = true;
                    $filename = imap_utf8($p->value);
                }
            }
        }
        
        if ($is_attachment) {
            $attachments[] = [
                'filename' => $filename,
                'size'     => $structure->bytes ?? 0,
                'type'     => $type . '/' . $subtype,
            ];
        } elseif ($type === 0) { // TEXT
            $data = get_part($inbox, $uid, $pn, $encoding);
            $data = convert_to_utf8($data, $charset);
            
            if ($subtype === 'html') {
                $body_html = $data;
            } elseif ($subtype === 'plain') {
                $body_text = $data;
            }
        }
    }
}

parse_parts($inbox, $uid, $structure, '', $body_html, $body_text, $attachments);

// Sanitize HTML body — strip dangerous tags
if ($body_html) {
    // Remove script, style, iframe, object, embed tags
    $body_html = preg_replace('#<script[^>]*>.*?</script>#si', '', $body_html);
    $body_html = preg_replace('#<style[^>]*>.*?</style>#si', '', $body_html);
    $body_html = preg_replace('#<iframe[^>]*>.*?</iframe>#si', '', $body_html);
    $body_html = preg_replace('#<object[^>]*>.*?</object>#si', '', $body_html);
    $body_html = preg_replace('#<embed[^>]*/?>#si', '', $body_html);
    $body_html = preg_replace('#on\w+\s*=\s*"[^"]*"#si', '', $body_html);
    $body_html = preg_replace('#on\w+\s*=\s*\'[^\']*\'#si', '', $body_html);
}

// Mark as read (set \Seen flag)
if (!$is_read) {
    imap_setflag_full($inbox, (string)$uid, '\\Seen', ST_UID);
}

imap_close($inbox);

echo json_encode([
    'uid'         => $uid,
    'folder'      => $folder,
    'from_name'   => $from_name,
    'from_email'  => $from_email,
    'to'          => $to_list,
    'cc'          => $cc_list,
    'subject'     => $subject,
    'date'        => $date_formatted,
    'timestamp'   => $timestamp ?: 0,
    'is_read'     => true, // we just marked it
    'is_flagged'  => $is_flagged,
    'body_html'   => $body_html,
    'body_text'   => $body_text,
    'attachments' => $attachments,
]);
