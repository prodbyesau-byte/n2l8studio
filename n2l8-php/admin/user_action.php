<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_owner();

$pdo = get_pdo();

$action  = $_GET['action'] ?? '';
$user_id = (int)($_GET['id'] ?? 0);

if (!$user_id) {
    flash('Fejl: Ugyldigt bruger-ID.');
    redirect('/admin/index.php?tab=users');
}

// Ensure we don't modify administrators
$check = $pdo->prepare("SELECT username, role FROM users WHERE id = ?");
$check->execute([$user_id]);
$target_user = $check->fetch();

if (!$target_user || $target_user['role'] === 'admin') {
    flash('Fejl: Brugeren findes ikke eller er en administrator.');
    redirect('/admin/index.php?tab=users');
}

switch ($action) {
    case 'approve':
        $stmt = $pdo->prepare("UPDATE users SET is_approved = 1 WHERE id = ?");
        if ($stmt->execute([$user_id])) {
            log_action($pdo, "User approved by admin: {$target_user['username']} (ID {$user_id})");
            flash("Brugeren '{$target_user['username']}' er nu godkendt og kan logge ind!");
        } else {
            flash('Fejl under godkendelse.');
        }
        break;

    case 'deactivate':
        $stmt = $pdo->prepare("UPDATE users SET is_approved = 0 WHERE id = ?");
        if ($stmt->execute([$user_id])) {
            log_action($pdo, "User deactivated by admin: {$target_user['username']} (ID {$user_id})");
            flash("Brugeren '{$target_user['username']}' er blevet deaktiveret.");
        } else {
            flash('Fejl under deaktivering.');
        }
        break;

    case 'reject':
        // Deleting the user is clean and removes pending spam accounts
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        if ($stmt->execute([$user_id])) {
            log_action($pdo, "User registration rejected/deleted by admin: {$target_user['username']} (ID {$user_id})");
            flash("Registrering for '{$target_user['username']}' blev afvist og profilen slettet.");
        } else {
            flash('Fejl under afvisning.');
        }
        break;

    default:
        flash('Fejl: Ugyldig handling.');
        break;
}

redirect('/admin/index.php?tab=users');
