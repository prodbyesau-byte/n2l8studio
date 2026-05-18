<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_owner();

$ip  = $_GET['ip'] ?? '';
if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
    header('Location: /admin/index.php?tab=stats'); exit;
}

$pdo = get_pdo();

// All actions for this IP
$logs = $pdo->prepare('SELECT * FROM visitor_log WHERE ip = ? ORDER BY created_at DESC');
$logs->execute([$ip]);
$logs = $logs->fetchAll();

// Summary info from first row with geo
$geo_row = null;
foreach ($logs as $r) {
    if ($r['country']) { $geo_row = $r; break; }
}

$total    = count($logs);
$first_at = $logs ? end($logs)['created_at']   : '—';
$last_at  = $logs ? $logs[0]['created_at']      : '—';

// Action breakdown
$action_counts = [];
foreach ($logs as $r) {
    $action_counts[$r['action']] = ($action_counts[$r['action']] ?? 0) + 1;
}
arsort($action_counts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor: <?= h($ip) ?> — Admin</title>
    <link rel="stylesheet" href="/static/style.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Righteous&family=VT323&display=swap" rel="stylesheet">
    <style>
        body { background-attachment:scroll; }
        .admin-topbar { background:rgba(18,18,21,0.97); border-bottom:2px solid var(--brand-dark-red); padding:0.8rem 2rem; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:100; }
        .admin-topbar .logo-text { font-size:1.3rem; letter-spacing:3px; }
        .section-title { font-family:'Righteous',cursive; color:var(--accent); font-size:1.4rem; margin-bottom:1rem; letter-spacing:2px; text-transform:uppercase; border-bottom:1px dashed var(--text-muted); padding-bottom:0.5rem; }
        .form-card { background:rgba(26,26,31,0.85); border:1px solid var(--text-muted); padding:1.5rem; margin-bottom:1.5rem; }
        .btn { padding:0.3rem 0.7rem; font-family:'VT323',monospace; font-size:1rem; cursor:pointer; border:1px solid; background:transparent; transition:all 0.2s; text-decoration:none; display:inline-block; text-transform:uppercase; }
        .btn-muted { color:var(--text-muted); border-color:var(--text-muted); }
        .btn-muted:hover { background:var(--text-muted); color:var(--bg-dark); }
        .visitor-hero { display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:1rem; margin-bottom:1.5rem; }
        .stat-card { background:rgba(26,26,31,0.85); border:1px solid var(--text-muted); padding:1.2rem; text-align:center; }
        .stat-num { font-family:'Righteous',cursive; font-size:2rem; color:var(--accent); line-height:1; }
        .stat-label { color:var(--text-muted); font-size:0.9rem; margin-top:0.3rem; }
        .admin-table { width:100%; border-collapse:collapse; font-size:1rem; }
        .admin-table th { font-family:'Righteous',cursive; color:var(--accent); text-align:left; padding:0.6rem 0.8rem; border-bottom:2px solid var(--text-muted); text-transform:uppercase; font-size:0.85rem; }
        .admin-table td { padding:0.55rem 0.8rem; border-bottom:1px dashed rgba(192,21,42,0.15); color:var(--text-main); vertical-align:middle; font-size:0.95rem; }
        .admin-table tr:hover td { background:rgba(192,21,42,0.04); }
        .action-badge { display:inline-block; padding:0.1rem 0.5rem; background:rgba(192,21,42,0.1); border:1px solid rgba(192,21,42,0.3); font-family:'VT323',monospace; font-size:0.95rem; color:var(--text-main); }
        .action-modal { background:rgba(192,21,42,0.15); border-color:rgba(192,21,42,0.4); color:var(--accent); }
        .action-checkout { background:rgba(46,204,113,0.15); border-color:rgba(46,204,113,0.5); color:#2ecc71; }
        .ua-cell { color:var(--text-muted); font-size:0.8rem; max-width:250px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .action-bar-row { display:flex; align-items:center; gap:0.6rem; margin-bottom:0.5rem; }
        .action-bar { height:6px; background:var(--accent); border-radius:2px; min-width:4px; }
        @media(max-width:768px){ .visitor-hero{grid-template-columns:1fr 1fr;} .ua-cell{display:none;} }
    </style>
</head>
<body class="page-home">

<div class="admin-topbar">
    <div class="logo-text">⚙ VISITOR PROFILE</div>
    <div style="display:flex;gap:0.8rem;">
        <a href="/admin/index.php?tab=stats" class="btn btn-muted">← Back to Stats</a>
        <a href="/admin/logout.php" class="btn btn-muted" style="color:#ff5c5c;border-color:#ff5c5c;">Disconnect</a>
    </div>
</div>

<div class="container" style="max-width:1200px;padding:2rem 1rem 4rem;">

    <!-- IP + Geo header -->
    <div style="margin-bottom:1.5rem;">
        <div style="font-family:'Righteous',cursive;font-size:1.8rem;color:var(--text-main);letter-spacing:2px;">
            <?= $geo_row ? flag_emoji($geo_row['country_code']) . ' ' : '🌍 ' ?>
            <?= h($ip) ?>
        </div>
        <?php if ($geo_row): ?>
        <div style="color:var(--text-muted);font-family:'VT323',monospace;font-size:1.2rem;margin-top:0.3rem;">
            <?= h($geo_row['city']) ?><?= $geo_row['city'] && $geo_row['country'] ? ', ' : '' ?><?= h($geo_row['country']) ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Stats cards -->
    <div class="visitor-hero">
        <div class="stat-card">
            <div class="stat-num"><?= $total ?></div>
            <div class="stat-label">Total Actions</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= count($action_counts) ?></div>
            <div class="stat-label">Unique Actions</div>
        </div>
        <div class="stat-card">
            <div class="stat-num" style="font-size:1rem;line-height:1.3;"><?= h(substr($first_at,0,16)) ?></div>
            <div class="stat-label">First Visit</div>
        </div>
        <div class="stat-card">
            <div class="stat-num" style="font-size:1rem;line-height:1.3;"><?= h(substr($last_at,0,16)) ?></div>
            <div class="stat-label">Last Seen</div>
        </div>
    </div>

    <!-- Action breakdown -->
    <?php if ($action_counts): ?>
    <div class="section-title">Action Breakdown</div>
    <div class="form-card">
        <?php $max = max($action_counts); foreach ($action_counts as $act => $cnt): ?>
        <div class="action-bar-row">
            <div style="width:200px;color:var(--text-muted);font-family:'VT323',monospace;font-size:1rem;flex-shrink:0;"><?= h($act) ?></div>
            <div class="action-bar" style="width:<?= round(($cnt/$max)*200) ?>px;"></div>
            <div style="color:var(--accent);font-family:'Righteous',cursive;font-size:0.95rem;"><?= $cnt ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Full timeline -->
    <div class="section-title">Full Activity Timeline</div>
    <div class="form-card" style="padding:0;overflow-x:auto;">
        <table class="admin-table">
            <thead><tr>
                <th>Time</th>
                <th>Action</th>
                <th>Page</th>
                <th>Referrer</th>
                <th class="ua-cell">User Agent</th>
            </tr></thead>
            <tbody>
            <?php foreach ($logs as $r): ?>
            <?php
            $ac = $r['action'];
            $badge_class = str_starts_with($ac,'modal_open') ? 'action-modal'
                         : (str_starts_with($ac,'checkout') ? 'action-checkout' : '');
            ?>
            <tr>
                <td style="white-space:nowrap;color:var(--text-muted);font-size:0.85rem;"><?= h(substr($r['created_at'],0,16)) ?></td>
                <td><span class="action-badge <?= $badge_class ?>"><?= h($ac) ?></span></td>
                <td style="color:var(--text-muted);font-size:0.9rem;"><?= h($r['page']) ?></td>
                <td style="color:var(--text-muted);font-size:0.85rem;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($r['referrer'] ?: '—') ?></td>
                <td class="ua-cell"><?= h($r['user_agent']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:2rem;">No activity recorded.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
</body>
</html>
