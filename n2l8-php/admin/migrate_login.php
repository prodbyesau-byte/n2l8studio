<?php
/**
 * N2L8Studio — Login Content Migration
 * Run this once to add login page content keys to the database.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

$pdo = get_pdo();
$log = [];

$content_rows = [
    ['login_title',         'Login',        'Title',                     'Admin Login'],
    ['login_subtitle',      'Login',        'Subtitle',                  'Please authenticate.'],
    ['login_button',        'Login',        'Button Text',               'ACCESS'],
    ['login_return',        'Login',        'Return Link',               '< Return to Home'],
    ['login_error',         'Login',        'Error Message',             'Invalid credentials — access denied.'],
];

$stmt = $pdo->prepare(
    'INSERT IGNORE INTO content (section_key, page, label, text) VALUES (?, ?, ?, ?)'
);

$seeded = 0;
foreach ($content_rows as [$key, $page, $label, $text]) {
    $stmt->execute([$key, $page, $label, $text]);
    if ($stmt->rowCount()) {
        $seeded++;
        $log[] = "Added key: {$key}";
    }
}

echo "Migration complete. {$seeded} keys added.<br>";
foreach ($log as $l) echo "- {$l}<br>";
echo "<br><a href='/admin/index.php?tab=content'>Go to Content Editor</a>";
