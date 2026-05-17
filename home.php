<?php
// ============================================================
// home.php
// Volunteer dashboard - shows their profile in read-only format.
//
// What this page does:
//   - Session guard
//   - Fetches the volunteer's full profile using JOINs
//   - If profile is incomplete (forename is NULL), redirects to profile_form.php
//   - Masks the postcode: "BN1 1AA" becomes "BN1****"
//   - Logs the page visit to audit_log
//   - Shows a placeholder link to the amendment form
// ============================================================
session_start();
require_once 'dbconnection.php';
require_once 'audit.php';

// -----------------------------------------------------------
// SESSION GUARD
// -----------------------------------------------------------
if (empty($_SESSION['volunteer_id'])) {
    header('Location: login.php');
    exit;
}

$volunteer_id = $_SESSION['volunteer_id'];

// -----------------------------------------------------------
// FETCH CORE PROFILE
// Single query for the volunteer row.
// We check forename here — if NULL, the profile hasn't been completed.
// -----------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT volunteer_id, volunteer_forename, volunteer_surname, date_of_birth, postcode
     FROM volunteer
     WHERE volunteer_id = ?'
);
$stmt->execute([$volunteer_id]);
$volunteer = $stmt->fetch();

// If the account doesn't exist in DB at all, force logout.
if (!$volunteer) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// If forename is NULL the volunteer has never completed their profile.
if ($volunteer['volunteer_forename'] === null) {
    header('Location: profile_form.php');
    exit;
}

// -----------------------------------------------------------
// POSTCODE MASKING
// Show the first 3 characters, replace the rest with asterisks.
// substr(string, start, length) — start 0, length 3 = first 3 chars.
// str_repeat('*', n) fills the rest.
// Example: "BN1 1AA" (7 chars) → "BN1" + "****" = "BN1****"
// -----------------------------------------------------------
$postcode_raw    = $volunteer['postcode'];
$postcode_masked = substr($postcode_raw, 0, 3)
                 . str_repeat('*', max(0, strlen($postcode_raw) - 3));

// -----------------------------------------------------------
// FETCH SKILLS via JOIN
// -----------------------------------------------------------
$skills_stmt = $pdo->prepare(
    'SELECT s.skill_name
     FROM volunteer_skill vs
     JOIN skill s ON s.skill_id = vs.skill_id
     WHERE vs.volunteer_id = ?
     ORDER BY s.skill_name'
);
$skills_stmt->execute([$volunteer_id]);
$skills = $skills_stmt->fetchAll();

// -----------------------------------------------------------
// FETCH QUALIFICATIONS via JOIN
// -----------------------------------------------------------
$quals_stmt = $pdo->prepare(
    'SELECT q.qualification_name
     FROM volunteer_qualification vq
     JOIN qualification q ON q.qualification_id = vq.qualification_id
     WHERE vq.volunteer_id = ?
     ORDER BY q.qualification_name'
);
$quals_stmt->execute([$volunteer_id]);
$qualifications = $quals_stmt->fetchAll();

// -----------------------------------------------------------
// FETCH TRANSPORT MODES via JOIN
// -----------------------------------------------------------
$transport_stmt = $pdo->prepare(
    'SELECT t.transport_name
     FROM volunteer_transport vt
     JOIN transport t ON t.transport_id = vt.transport_id
     WHERE vt.volunteer_id = ?
     ORDER BY t.transport_id'
);
$transport_stmt->execute([$volunteer_id]);
$transports = $transport_stmt->fetchAll();

// -----------------------------------------------------------
// FETCH AVAILABILITY
// day 0 = Monday ... 6 = Sunday
// -----------------------------------------------------------
$avail_stmt = $pdo->prepare(
    'SELECT day, start_time, end_time
     FROM availability
     WHERE volunteer_id = ?
     ORDER BY day, start_time'
);
$avail_stmt->execute([$volunteer_id]);
$availability = $avail_stmt->fetchAll();

$day_names = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// -----------------------------------------------------------
// AUDIT LOG — record that the volunteer visited their dashboard.
// -----------------------------------------------------------
write_audit_log($pdo, $volunteer_id, 'home_page_visit', 'Volunteer viewed their dashboard');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard – Home-Start</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="page-wrapper" style="padding:0;">

    <!-- Top navigation bar -->
    <div class="top-nav">
        <span class="nav-brand">Home-Start Volunteer Portal</span>
        <div style="display:flex;gap:0.5rem;">
            <a href="process_update.php">Edit Profile</a>
            <a href="logout.php">Log Out</a>
        </div>
    </div>

    <div style="padding:1.5rem;">

    <img src="Home-Start-Logo.png" alt="Home-Start" class="logo" style="width:12%;margin-bottom:1rem;">

    <h1 style="margin-bottom:0.2rem;">
        Welcome, <?= htmlspecialchars($volunteer['volunteer_forename']) ?>!
    </h1>
    <p style="text-align:center;color:#777;font-size:0.85rem;margin-bottom:1.5rem;">
        Your volunteer profile (read-only)
    </p>

    <!-- PERSONAL INFORMATION -->
    <div class="profile-section">
        <h3>Personal Information</h3>
        <table class="data-table">
            <tr>
                <th>Volunteer ID</th>
                <td><?= htmlspecialchars($volunteer['volunteer_id']) ?></td>
            </tr>
            <tr>
                <th>Full Name</th>
                <td>
                    <?= htmlspecialchars($volunteer['volunteer_forename']) ?>
                    <?= htmlspecialchars($volunteer['volunteer_surname']) ?>
                </td>
            </tr>
            <tr>
                <th>Date of Birth</th>
                <td>
                    <?php
                    $dob = $volunteer['date_of_birth']
                         ? DateTime::createFromFormat('Y-m-d', $volunteer['date_of_birth'])->format('d/m/Y')
                         : 'Not set';
                    echo htmlspecialchars($dob);
                    ?>
                </td>
            </tr>
            <tr>
                <th>Postcode</th>
                <!-- First 3 chars shown, rest masked for privacy -->
                <td><?= htmlspecialchars($postcode_masked) ?></td>
            </tr>
        </table>
    </div>

    <!-- SKILLS -->
    <div class="profile-section">
        <h3>Skills</h3>
        <?php if (empty($skills)): ?>
            <p style="color:#888;font-size:0.88rem;">No skills recorded.</p>
        <?php else: ?>
            <div class="tag-list">
                <?php foreach ($skills as $s): ?>
                    <span class="tag"><?= htmlspecialchars($s['skill_name']) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- QUALIFICATIONS -->
    <div class="profile-section">
        <h3>Qualifications</h3>
        <?php if (empty($qualifications)): ?>
            <p style="color:#888;font-size:0.88rem;">No qualifications recorded.</p>
        <?php else: ?>
            <div class="tag-list">
                <?php foreach ($qualifications as $q): ?>
                    <span class="tag tag-orange"><?= htmlspecialchars($q['qualification_name']) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- TRANSPORT MODES -->
    <div class="profile-section">
        <h3>Transport Modes</h3>
        <?php if (empty($transports)): ?>
            <p style="color:#888;font-size:0.88rem;">No transport modes recorded.</p>
        <?php else: ?>
            <div class="tag-list">
                <?php foreach ($transports as $t): ?>
                    <span class="tag" style="background:#444;"><?= htmlspecialchars($t['transport_name']) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- AVAILABILITY -->
    <div class="profile-section">
        <h3>Availability</h3>
        <?php if (empty($availability)): ?>
            <p style="color:#888;font-size:0.88rem;">No availability set.</p>
        <?php else: ?>
            <table class="avail-table">
                <thead>
                    <tr><th>Day</th><th>From</th><th>To</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($availability as $slot): ?>
                    <tr>
                        <td><?= htmlspecialchars($day_names[(int)$slot['day']]) ?></td>
                        <td><?= htmlspecialchars(substr($slot['start_time'], 0, 5)) ?></td>
                        <td><?= htmlspecialchars(substr($slot['end_time'],   0, 5)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    </div><!-- /padding wrapper -->
</div><!-- /page-wrapper -->
</body>
</html>
