<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_owner();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/admin/index.php'); }

$pdo = get_pdo();
$id  = (int)($_POST['id'] ?? 0);

$p = $pdo->prepare('SELECT * FROM products WHERE id = ?');
$p->execute([$id]);
$product = $p->fetch();
if (!$product) { flash('Product not found.'); redirect('/admin/index.php?tab=products'); }

$product['is_active'] = $product['is_active'] ? 0 : 1;
$state = $product['is_active'] ? 'enabled' : 'disabled';

$pdo->prepare('UPDATE products SET is_active = ? WHERE id = ?')
    ->execute([$product['is_active'], $id]);

log_action($pdo, "Product '{$product['title']}' {$state}");
redirect('/admin/index.php?tab=products');
