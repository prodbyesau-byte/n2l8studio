<?php
// ─── UTILITY HELPERS ────────────────────────────────────────────────────────

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

const ALLOWED_IMAGES = ['png', 'jpg', 'jpeg', 'webp'];
const ALLOWED_FILES  = ['zip', 'rar', '7z', 'wav', 'mp3'];
const ALLOWED_AUDIO  = ['mp3', 'wav', 'ogg', 'flac'];
