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

    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($original));
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

        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name));
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

