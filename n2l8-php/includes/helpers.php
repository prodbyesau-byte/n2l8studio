<?php
// ─── UTILITY HELPERS ────────────────────────────────────────────────────────

function h($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function flash(string $msg): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'][] = $msg;
}

function get_flash(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $msgs = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $msgs;
}

function format_price(float $price): string {
    return $price > 0 ? '$' . number_format($price, 2) : 'FREE';
}

function get_user_avatar_nav(PDO $pdo): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_id'])) return '';
    try {
        $user_id = $_SESSION['user_id'];
        $stmt = $pdo->prepare('SELECT username, profile_picture FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();
        if ($u) {
            $username = $u['username'];
            $profile_pic = $u['profile_picture'];
            
            // Count unread messages
            $msg_stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0');
            $msg_stmt->execute([$user_id]);
            $unread_messages = (int)$msg_stmt->fetchColumn();
            
            // Count pending friend requests received by this user
            $friends_stmt = $pdo->prepare('
                SELECT COUNT(*) 
                FROM friendships 
                WHERE status = "pending" 
                  AND (user_id1 = ? OR user_id2 = ?) 
                  AND action_user_id != ?
            ');
            $friends_stmt->execute([$user_id, $user_id, $user_id]);
            $pending_friends = (int)$friends_stmt->fetchColumn();
            
            $total_notifs = $unread_messages + $pending_friends;
            
            $badge = '';
            if ($total_notifs > 0) {
                $badge = '<span class="portal-nav-badge" style="background:#ff3860; color:#fff; font-size:0.6rem; padding:1px 5px; border-radius:10px; margin-left:5px; font-family:\'Montserrat\',sans-serif; font-weight:700; box-shadow:0 0 5px rgba(255, 56, 96, 0.6); display:inline-block; vertical-align:middle;">' . $total_notifs . '</span>';
            }
            
            if ($profile_pic) {
                return '<img src="/static/uploads/' . htmlspecialchars($profile_pic, ENT_QUOTES, 'UTF-8') . '" style="width:20px; height:20px; border-radius:50%; object-fit:cover; border:1px solid var(--accent); vertical-align:middle; margin-right:4px; box-shadow:0 0 4px var(--accent);">' . $badge;
            } else {
                return '<span style="display:inline-block; width:20px; height:20px; border-radius:50%; background:rgba(255,255,255,0.08); border:1px solid var(--border-color); text-align:center; line-height:18px; font-family:\'Syncopate\',sans-serif; font-size:0.65rem; font-weight:700; color:#fff; vertical-align:middle; margin-right:4px;">' . strtoupper(substr($username, 0, 1)) . '</span>' . $badge;
            }
        }
    } catch (Throwable $e) {}
    return '';
}

// ─── DATABASE HELPERS ───────────────────────────────────────────────────────

function get_site_content(PDO $pdo): array {
    $rows = $pdo->query('SELECT section_key, text FROM content')->fetchAll();
    $site = [];
    foreach ($rows as $row) {
        $site[$row['section_key']] = $row['text'];
    }
    return $site;
}

function log_action(PDO $pdo, string $action): void {
    $pdo->prepare('INSERT INTO audit_log (action) VALUES (?)')
        ->execute([$action]);
}

// ─── FILE UPLOAD HELPERS ────────────────────────────────────────────────────

function save_upload(string $file_key, array $allowed_exts): ?string {
    if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $original = $_FILES[$file_key]['name'];
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) return null;

    $filename = pathinfo($original, PATHINFO_FILENAME);
    $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
    // Generate an unguessable 16-character random hex string
    $hash = bin2hex(random_bytes(8));
    $safe = $safe_name . '_' . $hash . '.' . $ext;
    $dest = rtrim(UPLOAD_DIR, '/') . '/' . $safe;

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    if (move_uploaded_file($_FILES[$file_key]['tmp_name'], $dest)) {
        return $safe;
    }
    return null;
}

function save_upload_multiple(string $file_key, array $allowed_exts): array {
    $saved = [];
    if (!isset($_FILES[$file_key])) return $saved;

    $files = $_FILES[$file_key];
    $count = is_array($files['name']) ? count($files['name']) : 1;

    for ($i = 0; $i < $count; $i++) {
        $error    = is_array($files['error'])    ? $files['error'][$i]    : $files['error'];
        $name     = is_array($files['name'])     ? $files['name'][$i]     : $files['name'];
        $tmp_name = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];

        if ($error !== UPLOAD_ERR_OK || empty($name)) continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_exts, true)) continue;

        $filename = pathinfo($name, PATHINFO_FILENAME);
        $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
        // Generate an unguessable 16-character random hex string
        $hash = bin2hex(random_bytes(8));
        $safe = $safe_name . '_' . $hash . '.' . $ext;
        $dest = rtrim(UPLOAD_DIR, '/') . '/' . $safe;

        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
        if (move_uploaded_file($tmp_name, $dest)) {
            $saved[] = ['filename' => $safe, 'original' => $name];
        }
    }
    return $saved;
}

// ─── ALLOWED TYPE SETS ──────────────────────────────────────────────────────

const ALLOWED_IMAGES = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
const ALLOWED_FILES  = ['zip', 'rar', '7z', 'wav', 'mp3'];
const ALLOWED_AUDIO  = ['mp3', 'wav', 'ogg', 'flac'];

// ─── VISITOR LOGGING ─────────────────────────────────────────────────────────

function get_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function get_ip_geo(string $ip): array {
    static $cache = [];
    if (isset($cache[$ip])) return $cache[$ip];
    $default = ['country' => '', 'country_code' => '', 'city' => ''];
    // Skip private/reserved IPs
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $cache[$ip] = $default;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $res = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,city", false, $ctx);
    if ($res) {
        $data = json_decode($res, true);
        if (($data['status'] ?? '') === 'success') {
            return $cache[$ip] = [
                'country'      => $data['country']     ?? '',
                'country_code' => strtolower($data['countryCode'] ?? ''),
                'city'         => $data['city']        ?? '',
            ];
        }
    }
    return $cache[$ip] = $default;
}

function log_visitor(PDO $pdo, string $action, string $page = ''): void {
    $ip  = get_client_ip();
    $geo = get_ip_geo($ip);
    $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $ref = substr($_SERVER['HTTP_REFERER']    ?? '', 0, 500);
    $pg  = $page ?: (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    try {
        $pdo->prepare(
            'INSERT INTO visitor_log (ip,country,country_code,city,page,action,user_agent,referrer)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$ip, $geo['country'], $geo['country_code'], $geo['city'], $pg, $action, $ua, $ref]);
    } catch (\Throwable $e) {
        // Fail silently — never break the page
    }
}

function flag_emoji(string $code): string {
    if (strlen($code) !== 2) return '🌍';
    $c = strtoupper($code);
    return mb_chr(0x1F1E6 + ord($c[0]) - 65) . mb_chr(0x1F1E6 + ord($c[1]) - 65);
}


/**
 * Get a PayPal OAuth2 bearer token.
 * Returns the token string, or false on failure.
 */
function paypal_get_token(): string|false {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => PAYPAL_BASE_URL . '/v1/oauth2/token',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en_US'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return false;
    $data = json_decode($res, true);
    return $data['access_token'] ?? false;
}

/**
 * Make an authenticated PayPal REST API call.
 */
function paypal_request(string $method, string $endpoint, array $body = [], string $idempotency = ''): array {
    $token = paypal_get_token();
    if (!$token) return ['_error' => 'Token fetch failed'];

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];
    if ($idempotency) {
        $headers[] = 'PayPal-Request-Id: ' . $idempotency;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => PAYPAL_BASE_URL . $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if (!empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true) ?? ['_error' => 'JSON decode failed'];
}

/**
 * Sends a platform email, using SMTP if enabled, falling back to mail().
 */
function send_platform_email(string $to, string $subject, string $html_body, string $alt_body = ''): bool {
    // Check if SMTP is enabled
    $smtp_enabled = defined('SMTP_ENABLED') && SMTP_ENABLED;
    
    if ($smtp_enabled) {
        try {
            require_once __DIR__ . '/PHPMailer/Exception.php';
            require_once __DIR__ . '/PHPMailer/PHPMailer.php';
            require_once __DIR__ . '/PHPMailer/SMTP.php';
            
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : '';
            $mail->SMTPAuth   = true;
            $mail->Username   = defined('SMTP_USER') ? SMTP_USER : '';
            $mail->Password   = defined('SMTP_PASS') ? SMTP_PASS : '';
            $mail->AuthType   = 'LOGIN'; // Force LOGIN authentication to bypass CRAM-MD5 issues
            $mail->Port       = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
            
            $secure = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls';
            if ($secure === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }
            
            // Recipients
            $from_email = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'admin@n2l8studios.com';
            $from_name  = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'N2L8 STUDIO';
            $mail->setFrom($from_email, $from_name);
            $mail->addAddress($to);
            $mail->addReplyTo($from_email, $from_name);
            
            // Content
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body    = $html_body;
            if ($alt_body) {
                $mail->AltBody = $alt_body;
            } else {
                $mail->AltBody = strip_tags($html_body);
            }
            
            return $mail->send();
        } catch (\Throwable $e) {
            // Log SMTP error and return false (do NOT fall back to raw mail() when SMTP is explicitly enabled)
            error_log("SMTP send error: " . $e->getMessage());
            return false;
        }
    }
    
    // Fallback: standard php mail() (only executed if SMTP_ENABLED is false)
    $from_email = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'admin@n2l8studios.com';
    $from_name  = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'N2L8 STUDIO';
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . $from_name . " <" . $from_email . ">\r\n";
    $headers .= "Reply-To: " . $from_email . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    return @mail($to, $subject, $html_body, $headers, "-f" . $from_email);
}

/**
 * Make an authenticated Stripe REST API call using cURL.
 */
function stripe_request(string $method, string $endpoint, array $data = []): array {
    $secret_key = defined('STRIPE_MODE') && STRIPE_MODE === 'live' ? STRIPE_LIVE_SECRET_KEY : STRIPE_TEST_SECRET_KEY;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1' . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $secret_key . ':');
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        // Stripe expects standard URL-encoded fields in POST requests
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    } else if (strtoupper($method) === 'GET' && !empty($data)) {
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1' . $endpoint . '?' . http_build_query($data));
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    }
    
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err) {
        error_log("Stripe cURL Error: " . $err);
        return ['error' => ['message' => $err]];
    }
    
    return json_decode($response, true) ?: [];
}



