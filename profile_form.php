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
    <title>Complete Your Profile – Home-Start</title>
</head>
<body>
    <h1>Home-Start Volunteer Portal</h1>
    <h2>Complete Your Profile</h2>
    <p>Welcome! Please fill in your details before continuing.</p>

    <?php if ($message): ?>
        <p style="color:red;"><?= $message ?></p>
    <?php endif; ?>

    <form method="POST" action="profile_submit.php">

        <!-- CSRF hidden field -->
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <!-- ------------------------------------------------
             PERSONAL INFORMATION
             ------------------------------------------------ -->
        <fieldset>
            <legend>Personal Information</legend>

            <label for="forename">First Name: *</label><br>
            <input type="text" id="forename" name="forename"
                   maxlength="50" required><br><br>

            <label for="surname">Last Name: *</label><br>
            <input type="text" id="surname" name="surname"
                   maxlength="50" required><br><br>

            <label for="dob">Date of Birth (DD/MM/YYYY): *</label><br>
            <input type="text" id="dob" name="dob"
                   placeholder="31/12/1990" maxlength="10" required><br>
            <small>Format: DD/MM/YYYY</small>
        </fieldset>

        <br>

        <!-- ------------------------------------------------
             SKILLS (multi-select checkboxes from DB)
             ------------------------------------------------ -->
        <fieldset>
            <legend>Skills</legend>
            <p>Select all skills that apply:</p>
            <?php foreach ($skills as $skill): ?>
                <label>
                    <input type="checkbox"
                           name="skill_ids[]"
                           value="<?= (int)$skill['skill_id'] ?>">
                    <?= htmlspecialchars($skill['skill_name']) ?>
                </label><br>
            <?php endforeach; ?>
        </fieldset>

        <br>

        <!-- ------------------------------------------------
             QUALIFICATIONS (multi-select checkboxes from DB)
             ------------------------------------------------ -->
        <fieldset>
            <legend>Qualifications</legend>
            <p>Select all qualifications you hold:</p>
            <?php foreach ($qualifications as $qual): ?>
                <label>
                    <input type="checkbox"
                           name="qualification_ids[]"
                           value="<?= (int)$qual['qualification_id'] ?>">
                    <?= htmlspecialchars($qual['qualification_name']) ?>
                </label><br>
            <?php endforeach; ?>
        </fieldset>

        <br>

        <!-- ------------------------------------------------
             TRANSPORT (at least 1 required per spec)
             4 fixed options: Walking, Cycling, Vehicle, Public Transport
             ------------------------------------------------ -->
        <fieldset>
            <legend>Transport Modes * (select at least one)</legend>
            <?php foreach ($transports as $transport): ?>
                <label>
                    <input type="checkbox"
                           name="transport_ids[]"
                           value="<?= (int)$transport['transport_id'] ?>">
                    <?= htmlspecialchars($transport['transport_name']) ?>
                </label><br>
            <?php endforeach; ?>
        </fieldset>

        <br>

        <!-- ------------------------------------------------
             AVAILABILITY
             One row per day. Volunteer checks the day if they
             are available, then sets their start and end times.
             avail[0] = Monday ... avail[6] = Sunday
             ------------------------------------------------ -->
        <fieldset>
            <legend>Availability</legend>
            <p>Tick the days you are available and enter your available hours:</p>
            <table>
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Available?</th>
                        <th>From</th>
                        <th>To</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($day_names as $index => $day_name): ?>
                    <tr>
                        <td><?= htmlspecialchars($day_name) ?></td>
                        <td>
                            <input type="checkbox"
                                   name="avail[<?= $index ?>][enabled]"
                                   value="1">
                        </td>
                        <td>
                            <input type="time"
                                   name="avail[<?= $index ?>][start]"
                                   value="09:00">
                        </td>
                        <td>
                            <input type="time"
                                   name="avail[<?= $index ?>][end]"
                                   value="17:00">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </fieldset>

        <br>
        <button type="submit">Save Profile</button>
    </form>
</body>
</html>
