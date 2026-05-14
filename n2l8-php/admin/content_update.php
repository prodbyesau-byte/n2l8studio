<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/admin/index.php'); }

$pdo         = get_pdo();
$section_key = trim($_POST['section_key'] ?? '');
$text        = trim($_POST['text'] ?? '');

$stmt = $pdo->prepare('SELECT id FROM content WHERE section_key = ?');
$stmt->execute([$section_key]);
if ($stmt->fetch()) {
    $pdo->prepare('UPDATE content SET text=? WHERE section_key=?')->execute([$text, $section_key]);
    log_action($pdo, "Updated content: '{$section_key}'");
    flash("Content block '{$section_key}' saved.");
}
redirect('/admin/index.php?tab=content');
