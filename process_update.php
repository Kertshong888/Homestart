<?php
// ============================================================
// process_update.php
// Amendment form for returning volunteers who want to update their profile.
//
// This file handles BOTH the GET (display pre-populated form)
// and the POST (process the submitted changes).
// GET  → show the form pre-filled with current DB values
// POST → validate, update DB inside a transaction, redirect
//
// The update strategy:
//   - UPDATE the volunteer table row (personal info)
//   - DELETE all rows in junction tables for this volunteer, then re-INSERT
//     This "delete-and-replace" approach is simpler and safer than diffing
//     old vs new selections. Any old data is cleanly replaced.
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

// Ensure there is a CSRF token in the session.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$day_names = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// ============================================================
// POST HANDLER — process the form submission
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- CSRF check ---
    $submitted_token = $_POST['csrf_token'] ?? '';
    $session_token   = $_SESSION['csrf_token'] ?? '';
    if (!hash_equals($session_token, $submitted_token)) {
        header('Location: process_update.php?error=csrf');
        exit;
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    // --- Validate personal info (same rules as profile_submit.php) ---
    $forename = trim($_POST['forename'] ?? '');
    $surname  = trim($_POST['surname']  ?? '');
    $dob_raw  = trim($_POST['dob']      ?? '');

    if (empty($forename) || empty($surname)) {
        header('Location: process_update.php?error=missing_name');
        exit;
    }

    $dob_obj = DateTime::createFromFormat('d/m/Y', $dob_raw);
    $errors  = DateTime::getLastErrors();
    if (!$dob_obj || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        header('Location: process_update.php?error=invalid_dob');
        exit;
    }
    if ($dob_obj > new DateTime()) {
        header('Location: process_update.php?error=future_dob');
        exit;
    }
    $dob_mysql = $dob_obj->format('Y-m-d');

    // --- Validate and cast checkbox arrays ---
    $skill_ids     = array_filter(array_map('intval', $_POST['skill_ids']         ?? []), fn($v) => $v > 0);
    $qual_ids      = array_filter(array_map('intval', $_POST['qualification_ids'] ?? []), fn($v) => $v > 0);
    $transport_ids = array_filter(array_map('intval', $_POST['transport_ids']     ?? []), fn($v) => $v > 0);

    if (empty($transport_ids)) {
        header('Location: process_update.php?error=no_transport');
        exit;
    }

    // --- Validate availability ---
    $avail_input   = $_POST['avail'] ?? [];
    $availability_rows = [];
    foreach ($avail_input as $day_index => $slot) {
        if (empty($slot['enabled'])) {
            continue;
        }
        $day   = (int) $day_index;
        if ($day < 0 || $day > 6) continue;
        $start = $slot['start'] ?? '09:00';
        $end   = $slot['end']   ?? '17:00';
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            continue;
        }
        if ($end <= $start) {
            header('Location: process_update.php?error=invalid_time');
            exit;
        }
        $availability_rows[] = ['day' => $day, 'start' => $start, 'end' => $end];
    }

    // --- Run the update in a transaction ---
    try {
        $pdo->beginTransaction();

        // UPDATE personal info (not INSERT — the row already exists)
        $pdo->prepare(
            'UPDATE volunteer
             SET volunteer_forename = ?,
                 volunteer_surname  = ?,
                 date_of_birth      = ?
             WHERE volunteer_id = ?'
        )->execute([$forename, $surname, $dob_mysql, $volunteer_id]);

        // Delete-and-replace junction tables
        $pdo->prepare('DELETE FROM volunteer_skill         WHERE volunteer_id = ?')->execute([$volunteer_id]);
        $pdo->prepare('DELETE FROM volunteer_qualification WHERE volunteer_id = ?')->execute([$volunteer_id]);
        $pdo->prepare('DELETE FROM volunteer_transport     WHERE volunteer_id = ?')->execute([$volunteer_id]);
        $pdo->prepare('DELETE FROM availability            WHERE volunteer_id = ?')->execute([$volunteer_id]);

        $skill_stmt = $pdo->prepare('INSERT INTO volunteer_skill (volunteer_id, skill_id) VALUES (?, ?)');
        foreach ($skill_ids as $sid) {
            $skill_stmt->execute([$volunteer_id, $sid]);
        }

        $qual_stmt = $pdo->prepare('INSERT INTO volunteer_qualification (volunteer_id, qualification_id) VALUES (?, ?)');
        foreach ($qual_ids as $qid) {
            $qual_stmt->execute([$volunteer_id, $qid]);
        }

        $transport_stmt = $pdo->prepare('INSERT INTO volunteer_transport (volunteer_id, transport_id) VALUES (?, ?)');
        foreach ($transport_ids as $tid) {
            $transport_stmt->execute([$volunteer_id, $tid]);
        }

        $avail_stmt = $pdo->prepare('INSERT INTO availability (volunteer_id, day, start_time, end_time) VALUES (?, ?, ?, ?)');
        foreach ($availability_rows as $row) {
            $avail_stmt->execute([$volunteer_id, $row['day'], $row['start'], $row['end']]);
        }

        $pdo->commit();
        write_audit_log($pdo, $volunteer_id, 'profile_updated', 'Profile amendment saved successfully');

        header('Location: home.php');
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        write_audit_log($pdo, $volunteer_id, 'profile_update_failed', $e->getMessage());
        header('Location: process_update.php?error=db_error');
        exit;
    }
}

// ============================================================
// GET HANDLER — fetch current data and display pre-populated form
// ============================================================

// Fetch current volunteer row
$stmt = $pdo->prepare(
    'SELECT volunteer_forename, volunteer_surname, date_of_birth FROM volunteer WHERE volunteer_id = ?'
);
$stmt->execute([$volunteer_id]);
$volunteer = $stmt->fetch();

// Format stored date back to DD/MM/YYYY for the form field
$dob_display = '';
if (!empty($volunteer['date_of_birth'])) {
    $dob_display = DateTime::createFromFormat('Y-m-d', $volunteer['date_of_birth'])->format('d/m/Y');
}

// Fetch current skills (to pre-tick checkboxes)
$stmt = $pdo->prepare('SELECT skill_id FROM volunteer_skill WHERE volunteer_id = ?');
$stmt->execute([$volunteer_id]);
$current_skill_ids = array_column($stmt->fetchAll(), 'skill_id');

// Fetch current qualifications
$stmt = $pdo->prepare('SELECT qualification_id FROM volunteer_qualification WHERE volunteer_id = ?');
$stmt->execute([$volunteer_id]);
$current_qual_ids = array_column($stmt->fetchAll(), 'qualification_id');

// Fetch current transport
$stmt = $pdo->prepare('SELECT transport_id FROM volunteer_transport WHERE volunteer_id = ?');
$stmt->execute([$volunteer_id]);
$current_transport_ids = array_column($stmt->fetchAll(), 'transport_id');

// Fetch current availability (keyed by day for easy lookup in the form)
$stmt = $pdo->prepare('SELECT day, start_time, end_time FROM availability WHERE volunteer_id = ? ORDER BY day');
$stmt->execute([$volunteer_id]);
$current_avail = [];
foreach ($stmt->fetchAll() as $row) {
    $current_avail[(int)$row['day']] = $row;
}

// Fetch all lookup options
$all_skills      = $pdo->query('SELECT skill_id, skill_name FROM skill ORDER BY skill_name')->fetchAll();
$all_quals       = $pdo->query('SELECT qualification_id, qualification_name FROM qualification ORDER BY qualification_name')->fetchAll();
$all_transports  = $pdo->query('SELECT transport_id, transport_name FROM transport ORDER BY transport_id')->fetchAll();

// Error display
$error = htmlspecialchars($_GET['error'] ?? '');
$error_messages = [
    'missing_name' => 'First name and last name are required.',
    'invalid_dob'  => 'Date of birth must be in DD/MM/YYYY format.',
    'future_dob'   => 'Date of birth cannot be in the future.',
    'no_transport' => 'You must select at least one transport mode.',
    'invalid_time' => 'Availability end time must be after start time.',
    'csrf'         => 'Security token error. Please try again.',
    'db_error'     => 'A database error occurred. Please try again.',
];
$message = $error_messages[$error] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Profile – Home-Start</title>
</head>
<body>
    <h1>Home-Start Volunteer Portal</h1>
    <h2>Edit Your Profile</h2>
    <p><a href="home.php">← Back to Dashboard</a></p>

    <?php if ($message): ?>
        <p style="color:red;"><?= $message ?></p>
    <?php endif; ?>

    <form method="POST" action="process_update.php">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <!-- Personal Info — pre-filled with current DB values -->
        <fieldset>
            <legend>Personal Information</legend>

            <label for="forename">First Name: *</label><br>
            <input type="text" id="forename" name="forename"
                   value="<?= htmlspecialchars($volunteer['volunteer_forename'] ?? '') ?>"
                   maxlength="50" required><br><br>

            <label for="surname">Last Name: *</label><br>
            <input type="text" id="surname" name="surname"
                   value="<?= htmlspecialchars($volunteer['volunteer_surname'] ?? '') ?>"
                   maxlength="50" required><br><br>

            <label for="dob">Date of Birth (DD/MM/YYYY): *</label><br>
            <input type="text" id="dob" name="dob"
                   value="<?= htmlspecialchars($dob_display) ?>"
                   maxlength="10" required><br>
        </fieldset>

        <br>

        <!-- Skills — pre-tick current selections -->
        <fieldset>
            <legend>Skills</legend>
            <?php foreach ($all_skills as $skill): ?>
                <label>
                    <input type="checkbox"
                           name="skill_ids[]"
                           value="<?= (int)$skill['skill_id'] ?>"
                           <?= in_array($skill['skill_id'], $current_skill_ids) ? 'checked' : '' ?>>
                    <?= htmlspecialchars($skill['skill_name']) ?>
                </label><br>
            <?php endforeach; ?>
        </fieldset>

        <br>

        <!-- Qualifications -->
        <fieldset>
            <legend>Qualifications</legend>
            <?php foreach ($all_quals as $qual): ?>
                <label>
                    <input type="checkbox"
                           name="qualification_ids[]"
                           value="<?= (int)$qual['qualification_id'] ?>"
                           <?= in_array($qual['qualification_id'], $current_qual_ids) ? 'checked' : '' ?>>
                    <?= htmlspecialchars($qual['qualification_name']) ?>
                </label><br>
            <?php endforeach; ?>
        </fieldset>

        <br>

        <!-- Transport -->
        <fieldset>
            <legend>Transport Modes * (select at least one)</legend>
            <?php foreach ($all_transports as $transport): ?>
                <label>
                    <input type="checkbox"
                           name="transport_ids[]"
                           value="<?= (int)$transport['transport_id'] ?>"
                           <?= in_array($transport['transport_id'], $current_transport_ids) ? 'checked' : '' ?>>
                    <?= htmlspecialchars($transport['transport_name']) ?>
                </label><br>
            <?php endforeach; ?>
        </fieldset>

        <br>

        <!-- Availability — pre-tick days and pre-fill times -->
        <fieldset>
            <legend>Availability</legend>
            <table>
                <thead>
                    <tr><th>Day</th><th>Available?</th><th>From</th><th>To</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($day_names as $i => $day_name): ?>
                    <?php
                        $has_slot  = isset($current_avail[$i]);
                        $start_val = $has_slot ? substr($current_avail[$i]['start_time'], 0, 5) : '09:00';
                        $end_val   = $has_slot ? substr($current_avail[$i]['end_time'],   0, 5) : '17:00';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($day_name) ?></td>
                        <td>
                            <input type="checkbox"
                                   name="avail[<?= $i ?>][enabled]"
                                   value="1"
                                   <?= $has_slot ? 'checked' : '' ?>>
                        </td>
                        <td>
                            <input type="time"
                                   name="avail[<?= $i ?>][start]"
                                   value="<?= htmlspecialchars($start_val) ?>">
                        </td>
                        <td>
                            <input type="time"
                                   name="avail[<?= $i ?>][end]"
                                   value="<?= htmlspecialchars($end_val) ?>">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </fieldset>

        <br>
        <button type="submit">Update Profile</button>
    </form>
</body>
</html>
