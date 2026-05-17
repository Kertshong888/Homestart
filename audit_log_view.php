<?php
// ============================================================
// audit_log_view.php — Plain HTML audit log viewer
// Staff-protected. Shows login events with timestamps.
// Linked from staff_dashboard.php as a hyperlink (spec change 6).
// ============================================================
session_start();
require_once 'dbconnection.php';

if (empty($_SESSION['staff_id'])) {
    header('Location: staff_login.php');
    exit;
}

// Fetch login events — most recent first
$stmt = $pdo->query(
    "SELECT actor_volunteer_id, event_type, event_detail, ip_address, created_at
     FROM audit_log
     WHERE event_type IN ('login_success','login_failed','login_blocked','staff_login_success','staff_login_failed')
     ORDER BY created_at DESC
     LIMIT 200"
);
$events = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log – Home-Start</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { margin: 1rem 2rem; background: #f9f9f9; }
        a    { color: #552682; font-size: 0.85rem; text-decoration: none; }
        a:hover { text-decoration: underline; }

        table { width: 100%; border-collapse: collapse; background: #fff;
                font-size: 0.85rem; border-radius: 6px; overflow: hidden;
                box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
        th { background: #552682; color: #fff; padding: 0.55rem 0.9rem; text-align: left; }
        td { padding: 0.45rem 0.9rem; border-bottom: 1px solid #f0eaf9; }
        tr:hover td { background: #f8f5ff; }

        .badge { display:inline-block; border-radius:12px; padding:0.15rem 0.6rem;
                 font-size:0.75rem; font-weight:bold; }
        .badge-success { background:#d4edda; color:#217a3c; }
        .badge-fail    { background:#f8d7da; color:#8b0000; }
        .badge-staff   { background:#cce5ff; color:#004085; }
        .badge-block   { background:#fff3cd; color:#856404; }
        .page-header { display:flex; align-items:center; gap:1rem;
                       margin-bottom:1rem; padding-bottom:0.6rem;
                       border-bottom: 2px solid #ea580d; }
    </style>
</head>
<body>

    <div class="page-header">
        <img src="Home-Start-Logo.png" alt="Home-Start" style="height:40px;">
        <div>
            <h1 style="margin:0;">Audit Log — Login Events</h1>
            <p style="margin:0;font-size:0.8rem;color:#888;">
                Showing <?= count($events) ?> most recent events &nbsp;|&nbsp;
                Staff: <?= htmlspecialchars($_SESSION['staff_username'] ?? '') ?>
            </p>
        </div>
        <a href="staff_dashboard.php" class="btn btn-outline" style="margin-left:auto;">&larr; Dashboard</a>
    </div>

    <?php if (empty($events)): ?>
        <p>No login events recorded yet.</p>
    <?php else: ?>

    <table>
        <thead>
            <tr>
                <th>Date &amp; Time</th>
                <th>Volunteer ID</th>
                <th>Event</th>
                <th>Detail</th>
                <th>IP Address</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($events as $e): ?>
            <?php
                // Pick badge style based on event type
                $badge_class = match($e['event_type']) {
                    'login_success'       => 'badge-success',
                    'staff_login_success' => 'badge-staff',
                    'login_blocked'       => 'badge-block',
                    default               => 'badge-fail',
                };
                // Format timestamp nicely: DD/MM/YYYY HH:MM:SS
                $ts = DateTime::createFromFormat('Y-m-d H:i:s', $e['created_at']);
                $ts_display = $ts ? $ts->format('d/m/Y H:i:s') : htmlspecialchars($e['created_at']);
            ?>
            <tr>
                <td><?= htmlspecialchars($ts_display) ?></td>
                <td><?= $e['actor_volunteer_id'] ? htmlspecialchars($e['actor_volunteer_id']) : '<em style="color:#aaa">staff/system</em>' ?></td>
                <td><span class="badge <?= $badge_class ?>"><?= htmlspecialchars($e['event_type']) ?></span></td>
                <td><?= htmlspecialchars($e['event_detail'] ?? '') ?></td>
                <td><?= htmlspecialchars($e['ip_address'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php endif; ?>

</body>
</html>
