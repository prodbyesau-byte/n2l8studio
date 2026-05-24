<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$pdo = get_pdo();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'get_profile') {
        $target_id = (int)($_GET['user_id'] ?? 0);
        $target_username = trim($_GET['username'] ?? '');
        
        if ($target_id <= 0 && !empty($target_username)) {
            $u_stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $u_stmt->execute([$target_username]);
            $target_id = (int)$u_stmt->fetchColumn();
        }
        
        if ($target_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }
        
        // Fetch user basic info
        $u_stmt = $pdo->prepare('SELECT id, username, profile_picture, role FROM users WHERE id = ?');
        $u_stmt->execute([$target_id]);
        $user = $u_stmt->fetch();
        
        if (!$user) {
            echo json_encode(['success' => false, 'error' => 'User details not found']);
            exit;
        }
        
        // Count forum activity
        $t_stmt = $pdo->prepare('SELECT COUNT(*) FROM forum_threads WHERE user_id = ?');
        $t_stmt->execute([$target_id]);
        $threads_count = (int)$t_stmt->fetchColumn();
        
        $r_stmt = $pdo->prepare('SELECT COUNT(*) FROM forum_replies WHERE user_id = ?');
        $r_stmt->execute([$target_id]);
        $replies_count = (int)$r_stmt->fetchColumn();
        
        // Fetch friendship status
        $f_status = 'none';
        $action_user = 0;
        
        $u1 = min($user_id, $target_id);
        $u2 = max($user_id, $target_id);
        
        $f_stmt = $pdo->prepare('SELECT status, action_user_id FROM friendships WHERE user_id1 = ? AND user_id2 = ?');
        $f_stmt->execute([$u1, $u2]);
        $friendship = $f_stmt->fetch();
        
        if ($friendship) {
            if ($friendship['status'] === 'accepted') {
                $f_status = 'accepted';
            } elseif ($friendship['status'] === 'pending') {
                if ($friendship['action_user_id'] == $user_id) {
                    $f_status = 'sent_pending';
                } else {
                    $f_status = 'received_pending';
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'avatar' => $user['profile_picture'],
                'role' => $user['role'],
                'threads_count' => $threads_count,
                'replies_count' => $replies_count
            ],
            'friendship_status' => $f_status,
            'is_self' => ($user_id === $target_id)
        ]);
        exit;
    }
    
    elseif ($action === 'send_request') {
        $target_id = (int)($_POST['user_id'] ?? 0);
        if ($target_id <= 0 || $target_id === $user_id) {
            echo json_encode(['success' => false, 'error' => 'Invalid target user']);
            exit;
        }
        
        $u1 = min($user_id, $target_id);
        $u2 = max($user_id, $target_id);
        
        // Check if any record exists
        $stmt = $pdo->prepare('SELECT id, status FROM friendships WHERE user_id1 = ? AND user_id2 = ?');
        $stmt->execute([$u1, $u2]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            if ($existing['status'] === 'accepted') {
                echo json_encode(['success' => false, 'error' => 'You are already friends']);
                exit;
            } elseif ($existing['status'] === 'pending') {
                echo json_encode(['success' => false, 'error' => 'Friendship request is already pending']);
                exit;
            } else {
                // Update to pending
                $upd = $pdo->prepare('UPDATE friendships SET status = "pending", action_user_id = ? WHERE id = ?');
                $upd->execute([$user_id, $existing['id']]);
            }
        } else {
            // Insert new pending friendship
            $ins = $pdo->prepare('INSERT INTO friendships (user_id1, user_id2, status, action_user_id) VALUES (?, ?, "pending", ?)');
            $ins->execute([$u1, $u2, $user_id]);
        }
        
        // Log action
        log_action($pdo, "User {$_SESSION['username']} sent a friend request to user ID {$target_id}");
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    elseif ($action === 'accept_request') {
        $target_id = (int)($_POST['user_id'] ?? 0);
        if ($target_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }
        
        $u1 = min($user_id, $target_id);
        $u2 = max($user_id, $target_id);
        
        $stmt = $pdo->prepare('SELECT id, status, action_user_id FROM friendships WHERE user_id1 = ? AND user_id2 = ?');
        $stmt->execute([$u1, $u2]);
        $existing = $stmt->fetch();
        
        if (!$existing || $existing['status'] !== 'pending' || $existing['action_user_id'] == $user_id) {
            echo json_encode(['success' => false, 'error' => 'No pending request to accept from this user']);
            exit;
        }
        
        $upd = $pdo->prepare('UPDATE friendships SET status = "accepted", action_user_id = ? WHERE id = ?');
        $upd->execute([$user_id, $existing['id']]);
        
        // Log action
        log_action($pdo, "User {$_SESSION['username']} accepted friend request from user ID {$target_id}");
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    elseif ($action === 'decline_request' || $action === 'cancel_request' || $action === 'unfriend') {
        $target_id = (int)($_POST['user_id'] ?? 0);
        if ($target_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }
        
        $u1 = min($user_id, $target_id);
        $u2 = max($user_id, $target_id);
        
        $del = $pdo->prepare('DELETE FROM friendships WHERE user_id1 = ? AND user_id2 = ?');
        $del->execute([$u1, $u2]);
        
        // Log action
        log_action($pdo, "User {$_SESSION['username']} removed/declined friendship with user ID {$target_id}");
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    elseif ($action === 'list_friends') {
        // Fetch all accepted friends for the user
        $sql = '
            SELECT u.id, u.username, u.profile_picture
            FROM users u
            JOIN friendships f ON (
                (f.user_id1 = u.id AND f.user_id2 = :user_id1) OR
                (f.user_id2 = u.id AND f.user_id1 = :user_id2)
            )
            WHERE f.status = "accepted"
            ORDER BY u.username ASC
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'user_id1' => $user_id,
            'user_id2' => $user_id
        ]);
        $friends = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'friends' => $friends]);
        exit;
    }
    
    elseif ($action === 'list_pending_requests') {
        // Fetch pending requests received by the user
        $sql = '
            SELECT u.id, u.username, u.profile_picture
            FROM users u
            JOIN friendships f ON (
                (f.user_id1 = u.id AND f.user_id2 = :user_id1) OR
                (f.user_id2 = u.id AND f.user_id1 = :user_id2)
            )
            WHERE f.status = "pending" AND f.action_user_id != :user_id3
            ORDER BY f.created_at DESC
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'user_id1' => $user_id,
            'user_id2' => $user_id,
            'user_id3' => $user_id
        ]);
        $pending = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'pending' => $pending]);
        exit;
    }
    
    elseif ($action === 'search_users') {
        $query = trim($_GET['query'] ?? '');
        if (strlen($query) < 2) {
            echo json_encode(['success' => true, 'users' => []]);
            exit;
        }
        
        $sql = '
            SELECT id, username, profile_picture, role
            FROM users
            WHERE username LIKE :query AND id != :my_id AND is_private = 0
            ORDER BY username ASC
            LIMIT 10
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'query' => '%' . $query . '%',
            'my_id' => $user_id
        ]);
        $users = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'users' => $users]);
        exit;
    }
    
    elseif ($action === 'list_discover_members') {
        $query = trim($_GET['query'] ?? '');
        
        $sql = '
            SELECT 
                u.id, 
                u.username, 
                u.profile_picture, 
                u.role,
                f.status AS friendship_status,
                f.action_user_id
            FROM users u
            LEFT JOIN friendships f ON (
                (f.user_id1 = u.id AND f.user_id2 = :my_id1) OR
                (f.user_id1 = :my_id2 AND f.user_id2 = u.id)
            )
            WHERE u.is_approved = 1 
              AND u.role != "admin" 
              AND u.id != :my_id3 
              AND u.is_private = 0
        ';
        
        if (!empty($query)) {
            $sql .= ' AND u.username LIKE :query';
        }
        
        $sql .= ' ORDER BY u.username ASC';
        
        $stmt = $pdo->prepare($sql);
        $params = [
            'my_id1' => $user_id,
            'my_id2' => $user_id,
            'my_id3' => $user_id
        ];
        if (!empty($query)) {
            $params['query'] = '%' . $query . '%';
        }
        
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'users' => $users]);
        exit;
    }
    
    else {
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
