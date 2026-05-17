<?php
// ============================================================
// auth.php
// Volunteer login handler - processes POST from login.php.
// Security layers implemented here:
//   1. CSRF token verification
//   2. Session-based login throttling (5 attempts / 60 seconds)
//   3. Prepared statement for DB lookup
//   4. Generic error message (no enumeration - never reveal which field was wrong)
//   5. session_regenerate_id() after successful login (prevents session fixation)
// ============================================================
session_start();
require_once 'dbconnection.php';
require_once 'audit.php';

// Only accept POST. A direct browser visit gets kicked back to the login form.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

// -----------------------------------------------------------
// 1. CSRF TOKEN CHECK
// hash_equals() does a timing-safe string comparison.
// Using === instead would allow timing attacks that could reveal the token.
// -----------------------------------------------------------
$submitted_token = $_POST['csrf_token'] ?? '';
$session_token   = $_SESSION['csrf_token'] ?? '';

if (!hash_equals($session_token, $submitted_token)) {
    header('Location: login.php?error=csrf');
    exit;
}

// Regenerate CSRF token after each submission so it can't be reused.
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// -----------------------------------------------------------
// 2. SESSION-BASED THROTTLING
// We track attempt count and timestamp in the session.
// After 5 failures within 60 seconds the account is locked for that session.
// This stops automated brute-force scripts without needing a DB column.
// -----------------------------------------------------------
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts']    = 0;
    $_SESSION['first_attempt_time'] = time();
}

// If 60 seconds have passed since the first failure, reset the counter.
if ((time() - $_SESSION['first_attempt_time']) > 60) {
    $_SESSION['login_attempts']    = 0;
    $_SESSION['first_attempt_time'] = time();
}

// Block if threshold exceeded.
if ($_SESSION['login_attempts'] >= 5) {
    // Log the blocked attempt
    write_audit_log($pdo, null, 'login_blocked', 'Rate limit hit for attempted ID: ' . ($_POST['volunteer_id'] ?? ''));
    header('Location: login.php?error=locked');
    exit;
}

// -----------------------------------------------------------
// 3. INPUT COLLECTION AND BASIC SANITISATION
// trim() removes leading/trailing whitespace.
// strtoupper() normalises postcode so "bn1 1aa" matches "BN1 1AA".
// -----------------------------------------------------------
$volunteer_id = trim($_POST['volunteer_id'] ?? '');
$postcode     = strtoupper(trim($_POST['postcode'] ?? ''));

if (empty($volunteer_id) || empty($postcode)) {
    $_SESSION['login_attempts']++;
    header('Location: login.php?error=missing');
    exit;
}

// -----------------------------------------------------------
// 4. DATABASE LOOKUP — prepared statement only, never concatenation
// We query by both fields at once. If either is wrong we get no row.
// We deliberately give the same error for wrong ID or wrong postcode
// so an attacker cannot enumerate valid volunteer IDs.
// -----------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT volunteer_id, postcode FROM volunteer WHERE volunteer_id = ? AND postcode = ?'
);
$stmt->execute([$volunteer_id, $postcode]);
$volunteer = $stmt->fetch();

if (!$volunteer) {
    // Increment failure counter before redirecting.
    $_SESSION['login_attempts']++;
    write_audit_log($pdo, null, 'login_failed', 'Failed attempt for ID: ' . $volunteer_id);
    header('Location: login.php?error=invalid');
    exit;
}

// -----------------------------------------------------------
// 5. SUCCESSFUL LOGIN
// session_regenerate_id(true) creates a new session ID and deletes
// the old one. This prevents session fixation attacks where an
// attacker tricks a victim into using a known session ID.
// -----------------------------------------------------------
session_regenerate_id(true);

// Reset throttle counter on success.
$_SESSION['login_attempts']    = 0;
$_SESSION['first_attempt_time'] = time();

// Store the volunteer's identity in the session.
// Every protected page checks $_SESSION['volunteer_id'] at the top.
$_SESSION['volunteer_id'] = $volunteer['volunteer_id'];
$_SESSION['postcode']     = $volunteer['postcode'];

write_audit_log($pdo, $volunteer['volunteer_id'], 'login_success', 'Volunteer logged in');

header('Location: home.php');
exit;
