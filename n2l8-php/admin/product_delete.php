<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/admin/index.php'); }

$pdo = get_pdo();
$id  = (int)($_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT title FROM products WHERE id = ?');
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) { flash('Product not found.'); redirect('/admin/index.php?tab=products'); }

$pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
log_action($pdo, "Deleted product: '{$product['title']}'");
flash("Product '{$product['title']}' deleted.");
redirect('/admin/index.php?tab=products');
