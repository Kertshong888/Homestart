<?php
// ============================================================
// profile_form.php
// First-time profile setup form for volunteers.
// Protected: only accessible after a valid volunteer login.
//
// What this page does:
//   - Session guard at the top
//   - Pulls skill/qualification/transport options LIVE from the DB
//   - Renders a form with CSRF token
//   - Availability section: one row per day, checkbox + time range
// ============================================================
session_start();
require_once 'dbconnection.php';

// -----------------------------------------------------------
// SESSION GUARD — if not logged in, redirect to login page.
// This single check at the top of every protected page is the
// core of PHP session-based authentication.
// -----------------------------------------------------------
if (empty($_SESSION['volunteer_id'])) {
    header('Location: login.php');
    exit;
}

$volunteer_id = $_SESSION['volunteer_id'];

// -----------------------------------------------------------
// CSRF TOKEN GENERATION
// A new token is generated here if one doesn't exist.
// The same token is embedded in the form and checked in profile_submit.php.
// -----------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// -----------------------------------------------------------
// FETCH LOOKUP DATA FROM DATABASE
// By querying the DB rather than hardcoding, adding a new skill
// only requires one INSERT into the skill table - no code changes.
// -----------------------------------------------------------

// Fetch all available skills
$skills_stmt = $pdo->query('SELECT skill_id, skill_name FROM skill ORDER BY skill_name');
$skills = $skills_stmt->fetchAll();

// Fetch all available qualifications
$quals_stmt = $pdo->query('SELECT qualification_id, qualification_name FROM qualification ORDER BY qualification_name');
$qualifications = $quals_stmt->fetchAll();

// Fetch transport options (always exactly 4 per spec)
$transport_stmt = $pdo->query('SELECT transport_id, transport_name FROM transport ORDER BY transport_id');
$transports = $transport_stmt->fetchAll();

// -----------------------------------------------------------
// DAY NAMES for the availability section
// Index 0 = Monday ... 6 = Sunday (matches availability.day TINYINT)
// -----------------------------------------------------------
$day_names = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Show any error passed back from profile_submit.php
$error = htmlspecialchars($_GET['error'] ?? '');
$error_messages = [
    'missing_name'      => 'First name and last name are required.',
    'invalid_dob'       => 'Date of birth must be in DD/MM/YYYY format.',
    'future_dob'        => 'Date of birth cannot be in the future.',
    'no_transport'      => 'You must select at least one transport mode.',
    'invalid_time'      => 'Availability end time must be after start time.',
    'csrf'              => 'Security token error. Please try again.',
    'db_error'          => 'A database error occurred. Please try again.',
];
$message = $error_messages[$error] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Your Profile – Home-Start</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="page-wrapper">

    <img src="Home-Start-Logo.png" alt="Home-Start" class="logo">
    <h1>Home-Start Volunteer Portal</h1>
    <h2>Complete Your Profile</h2>
    <p style="text-align:center;color:#666;font-size:0.85rem;margin-bottom:1.2rem;">
        Please fill in all sections before continuing.
    </p>

    <?php if ($message): ?>
        <div class="alert alert-error"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST" action="profile_submit.php">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <!-- PERSONAL INFORMATION -->
        <fieldset>
            <legend>Personal Information</legend>

            <div class="form-group">
                <label for="forename">First Name *</label>
                <input type="text" id="forename" name="forename" maxlength="50" required>
            </div>
            <div class="form-group">
                <label for="surname">Last Name *</label>
                <input type="text" id="surname" name="surname" maxlength="50" required>
            </div>
            <div class="form-group">
                <label for="dob">Date of Birth *</label>
                <input type="text" id="dob" name="dob" placeholder="DD/MM/YYYY" maxlength="10" required>
                <small>Format: DD/MM/YYYY &nbsp;e.g. 15/06/1990</small>
            </div>
        </fieldset>

        <!-- SKILLS -->
        <fieldset>
            <legend>Skills &nbsp;<span style="font-weight:normal;font-size:0.8rem;color:#888;">(select all that apply)</span></legend>
            <div class="checkbox-list">
                <?php foreach ($skills as $skill): ?>
                    <label>
                        <input type="checkbox" name="skill_ids[]"
                               value="<?= (int)$skill['skill_id'] ?>">
                        <?= htmlspecialchars($skill['skill_name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <!-- QUALIFICATIONS -->
        <fieldset>
            <legend>Qualifications &nbsp;<span style="font-weight:normal;font-size:0.8rem;color:#888;">(select all you hold)</span></legend>
            <div class="checkbox-list">
                <?php foreach ($qualifications as $qual): ?>
                    <label>
                        <input type="checkbox" name="qualification_ids[]"
                               value="<?= (int)$qual['qualification_id'] ?>">
                        <?= htmlspecialchars($qual['qualification_name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <!-- TRANSPORT -->
        <fieldset>
            <legend>Transport Modes * &nbsp;<span style="font-weight:normal;font-size:0.8rem;color:#ea580d;">At least one required</span></legend>
            <div class="checkbox-list">
                <?php foreach ($transports as $transport): ?>
                    <label>
                        <input type="checkbox" name="transport_ids[]"
                               value="<?= (int)$transport['transport_id'] ?>">
                        <?= htmlspecialchars($transport['transport_name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <!-- AVAILABILITY -->
        <fieldset>
            <legend>Availability &nbsp;<span style="font-weight:normal;font-size:0.8rem;color:#888;">Tick the days you are free</span></legend>
            <table class="avail-table">
                <thead>
                    <tr><th>Day</th><th>Available?</th><th>From</th><th>To</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($day_names as $index => $day_name): ?>
                    <tr>
                        <td><?= htmlspecialchars($day_name) ?></td>
                        <td><input type="checkbox" name="avail[<?= $index ?>][enabled]" value="1"></td>
                        <td><input type="time" name="avail[<?= $index ?>][start]" value="09:00"></td>
                        <td><input type="time" name="avail[<?= $index ?>][end]"   value="17:00"></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </fieldset>

        <div class="btn-row">
            <button type="submit" class="btn btn-primary">Save Profile</button>
        </div>

    </form>
</div>
</body>
</html>
