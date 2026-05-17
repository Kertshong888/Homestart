<?php

// profile_submit.php
// Processes the POST from profile_form.php.
//
// Key design decisions 
//   - array_map('intval', ...) casts every checkbox value to int
//     so a manipulated form value like "1; DROP TABLE" becomes 0
//   - DateTime::createFromFormat validates date format strictly
//   - Everything runs inside ONE transaction so a mid-way error
//     leaves the DB unchanged (atomicity from ACID)
//   - write_audit_log is called on both success AND failure so
//     staff can review incomplete submissions too
session_start();
require_once 'dbconnection.php';
require_once 'audit.php';

// Only accept POST - direct visits bounce to the form.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profile_form.php');
    exit;
}

// SESSION GUARD

if (empty($_SESSION['volunteer_id'])) {
    header('Location: login.php');
    exit;
}

$volunteer_id = $_SESSION['volunteer_id'];

// CSRF CHECK — timing-safe comparison with hash_equals()
// 
$submitted_token = $_POST['csrf_token'] ?? '';
$session_token   = $_SESSION['csrf_token'] ?? '';

if (!hash_equals($session_token, $submitted_token)) {
    header('Location: profile_form.php?error=csrf');
    exit;
}

// Rotate the token after use so the same token can't be replayed.
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// 
// COLLECT AND VALIDATE PERSONAL INFO
// 
$forename = trim($_POST['forename'] ?? '');
$surname  = trim($_POST['surname']  ?? '');
$dob_raw  = trim($_POST['dob']      ?? '');

if (empty($forename) || empty($surname)) {
    header('Location: profile_form.php?error=missing_name');
    exit;
}

// DateTime::createFromFormat() validates format AND value.
// e.g. "31/02/1990" returns false because Feb has no 31st.
// The !d/m/Y format means the ENTIRE string must match (! anchors the start,
// and by default it anchors the end too - no trailing characters accepted).
$dob_obj = DateTime::createFromFormat('d/m/Y', $dob_raw);
$errors  = DateTime::getLastErrors();

if (!$dob_obj || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
    header('Location: profile_form.php?error=invalid_dob');
    exit;
}

// Date of birth cannot be in the future.
if ($dob_obj > new DateTime()) {
    header('Location: profile_form.php?error=future_dob');
    exit;
}

// Convert to MySQL DATE format (YYYY-MM-DD) for storage.
$dob_mysql = $dob_obj->format('Y-m-d');

// COLLECT AND VALIDATE CHECKBOXES
// array_map('intval', ...) converts every value in the array to
// an integer. This means even if someone edits the form HTML to
// submit "1; DROP TABLE skill", intval() makes it 0 (harmless).
// We then filter out 0s so only valid positive IDs remain.
// 
$raw_skills      = $_POST['skill_ids']         ?? [];
$raw_quals       = $_POST['qualification_ids'] ?? [];
$raw_transports  = $_POST['transport_ids']     ?? [];

// Cast to int, remove any zeros/negatives
$skill_ids      = array_filter(array_map('intval', $raw_skills),      fn($v) => $v > 0);
$qual_ids       = array_filter(array_map('intval', $raw_quals),       fn($v) => $v > 0);
$transport_ids  = array_filter(array_map('intval', $raw_transports),  fn($v) => $v > 0);

// Per spec: at least one transport mode is required.
if (empty($transport_ids)) {
    header('Location: profile_form.php?error=no_transport');
    exit;
}

// COLLECT AND VALIDATE AVAILABILITY
// $_POST['avail'] is an array indexed by day number (0-6).
// Only days where the checkbox was ticked have an 'enabled' key.
$avail_input = $_POST['avail'] ?? [];
$availability_rows = [];  // Will be inserted into DB

foreach ($avail_input as $day_index => $slot) {
    // Skip days without the checkbox ticked.
    if (empty($slot['enabled'])) {
        continue;
    }

    // Validate day index is 0-6.
    $day = (int) $day_index;
    if ($day < 0 || $day > 6) {
        continue;
    }

    $start = $slot['start'] ?? '09:00';
    $end   = $slot['end']   ?? '17:00';

    // Validate HH:MM format using regex before DB insertion.
    $time_pattern = '/^\d{2}:\d{2}$/';
    if (!preg_match($time_pattern, $start) || !preg_match($time_pattern, $end)) {
        continue;
    }

    // End time must be after start time.
    if ($end <= $start) {
        header('Location: profile_form.php?error=invalid_time');
        exit;
    }

    $availability_rows[] = ['day' => $day, 'start' => $start, 'end' => $end];
}

// -----------------------------------------------------------
// DATABASE TRANSACTION
// beginTransaction() starts an "all-or-nothing" block.
// If any INSERT fails, rollBack() undoes everything.
// commit() only runs if every single statement succeeds.
// This ensures the DB is never left in a half-saved state.
// -----------------------------------------------------------
try {
    $pdo->beginTransaction();

    // --- UPDATE volunteer table (personal info) ---
    // Volunteer already exists (created by admin with just ID + postcode).
    // We UPDATE rather than INSERT to fill in the profile fields.
    $stmt = $pdo->prepare(
        'UPDATE volunteer
         SET volunteer_forename = ?,
             volunteer_surname  = ?,
             date_of_birth      = ?
         WHERE volunteer_id = ?'
    );
    $stmt->execute([$forename, $surname, $dob_mysql, $volunteer_id]);

    // --- Clear any existing junction table rows for this volunteer ---
    // DELETE + re-INSERT is simpler than diffing old vs new selections.
    $pdo->prepare('DELETE FROM volunteer_skill         WHERE volunteer_id = ?')->execute([$volunteer_id]);
    $pdo->prepare('DELETE FROM volunteer_qualification WHERE volunteer_id = ?')->execute([$volunteer_id]);
    $pdo->prepare('DELETE FROM volunteer_transport     WHERE volunteer_id = ?')->execute([$volunteer_id]);
    $pdo->prepare('DELETE FROM availability            WHERE volunteer_id = ?')->execute([$volunteer_id]);

    // --- INSERT skills ---
    $skill_stmt = $pdo->prepare(
        'INSERT INTO volunteer_skill (volunteer_id, skill_id) VALUES (?, ?)'
    );
    foreach ($skill_ids as $sid) {
        $skill_stmt->execute([$volunteer_id, $sid]);
    }

    // --- INSERT qualifications ---
    $qual_stmt = $pdo->prepare(
        'INSERT INTO volunteer_qualification (volunteer_id, qualification_id) VALUES (?, ?)'
    );
    foreach ($qual_ids as $qid) {
        $qual_stmt->execute([$volunteer_id, $qid]);
    }

    // --- INSERT transport modes ---
    $transport_stmt = $pdo->prepare(
        'INSERT INTO volunteer_transport (volunteer_id, transport_id) VALUES (?, ?)'
    );
    foreach ($transport_ids as $tid) {
        $transport_stmt->execute([$volunteer_id, $tid]);
    }

    // --- INSERT availability slots ---
    $avail_stmt = $pdo->prepare(
        'INSERT INTO availability (volunteer_id, day, start_time, end_time) VALUES (?, ?, ?, ?)'
    );
    foreach ($availability_rows as $row) {
        $avail_stmt->execute([$volunteer_id, $row['day'], $row['start'], $row['end']]);
    }

    // All inserts succeeded — commit the transaction.
    $pdo->commit();

    // Audit the successful submission.
    write_audit_log($pdo, $volunteer_id, 'profile_submit', 'Profile saved successfully');

    // Send the volunteer to their dashboard.
    header('Location: home.php');
    exit;

} catch (PDOException $e) {
    // Something went wrong — undo every change made in this transaction.
    $pdo->rollBack();

    // Audit the failure (still has a valid $pdo connection).
    write_audit_log($pdo, $volunteer_id, 'profile_submit_failed', $e->getMessage());

    header('Location: profile_form.php?error=db_error');
    exit;
}
