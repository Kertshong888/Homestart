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
    <style>
        body { font-family: Verdana, Geneva, Tahoma, sans-serif; margin: 1rem 2rem; background:#f9f9f9; color:#333; }
        h1   { color: #552682; font-size: 1.2rem; }
        h2   { color: #ea580d; font-size: 0.9rem; font-weight: normal; margin-bottom: 1rem; }
        a    { color: #552682; font-size: 0.85rem; }

        table { width: 100%; border-collapse: collapse; background: #fff; font-size: 0.85rem; }
        th {
            background: #552682; color: #fff;
            padding: 0.5rem 0.8rem; text-align: left;
        }
        td { padding: 0.4rem 0.8rem; border-bottom: 1px solid #eee; }
        tr:hover td { background: #f5f0ff; }

        .badge {
            display: inline-block; border-radius: 12px;
            padding: 0.1rem 0.5rem; font-size: 0.75rem; font-weight: bold;
        }
        .badge-success { background: #d4edda; color: #217a3c; }
        .badge-fail    { background: #f8d7da; color: #8b0000; }
        .badge-staff   { background: #cce5ff; color: #004085; }
        .badge-block   { background: #fff3cd; color: #856404; }
    </style>
</head>
<body>

    <p><a href="staff_dashboard.php">&larr; Back to Dashboard</a></p>

    <h1>Audit Log — Login Events</h1>
    <h2>Showing last <?= count($events) ?> login-related events &nbsp;|&nbsp;
        Logged in as: <?= htmlspecialchars($_SESSION['staff_username'] ?? '') ?>
    </h2>

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
