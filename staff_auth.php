<?php
// ============================================================
// staff_auth.php
// Staff login handler — completely separate from volunteer auth.
//
// Key difference from auth.php:
//   - Looks up the staff table, not the volunteer table
//   - Uses password_verify() for bcrypt password checking
//   - Sets $_SESSION['staff_id'] (a different key from volunteer sessions)
//     so staff and volunteer sessions NEVER conflict
// ============================================================
session_start();
require_once 'dbconnection.php';
require_once 'audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: staff_login.php');
    exit;
}

// -----------------------------------------------------------
// CSRF CHECK — uses the staff-specific token key
// -----------------------------------------------------------
$submitted_token = $_POST['csrf_token']      ?? '';
$session_token   = $_SESSION['csrf_token_staff'] ?? '';

if (!hash_equals($session_token, $submitted_token)) {
    header('Location: staff_login.php?error=csrf');
    exit;
}
$_SESSION['csrf_token_staff'] = bin2hex(random_bytes(32));

// -----------------------------------------------------------
// THROTTLING (same logic as volunteer auth)
// -----------------------------------------------------------
if (!isset($_SESSION['staff_login_attempts'])) {
    $_SESSION['staff_login_attempts']    = 0;
    $_SESSION['staff_first_attempt_time'] = time();
}

if ((time() - $_SESSION['staff_first_attempt_time']) > 60) {
    $_SESSION['staff_login_attempts']    = 0;
    $_SESSION['staff_first_attempt_time'] = time();
}

if ($_SESSION['staff_login_attempts'] >= 5) {
    write_audit_log($pdo, null, 'staff_login_blocked', 'Rate limit hit');
    header('Location: staff_login.php?error=locked');
    exit;
}

// -----------------------------------------------------------
// INPUT COLLECTION
// -----------------------------------------------------------
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';   // Do NOT trim passwords

if (empty($username) || empty($password)) {
    $_SESSION['staff_login_attempts']++;
    header('Location: staff_login.php?error=missing');
    exit;
}

// -----------------------------------------------------------
// DATABASE LOOKUP
// We fetch only by username (unique), then verify the password
// separately with password_verify(). This is the correct pattern
// for bcrypt — you cannot query by hash because bcrypt is salted.
// -----------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT staff_id, username, password_hash FROM staff WHERE username = ?'
);
$stmt->execute([$username]);
$staff = $stmt->fetch();

// password_verify(plain_text, hash) returns true if the plain text
// matches the bcrypt hash. It is timing-safe internally.
if (!$staff || !password_verify($password, $staff['password_hash'])) {
    $_SESSION['staff_login_attempts']++;
    // Same generic error for wrong username OR wrong password
    // to prevent username enumeration attacks
    write_audit_log($pdo, null, 'staff_login_failed', 'Failed attempt for username: ' . $username);
    header('Location: staff_login.php?error=invalid');
    exit;
}

// -----------------------------------------------------------
// SUCCESSFUL STAFF LOGIN
// -----------------------------------------------------------
session_regenerate_id(true);

$_SESSION['staff_login_attempts']    = 0;
$_SESSION['staff_first_attempt_time'] = time();

// Use 'staff_id' as the session key — distinct from 'volunteer_id'
// so staff and volunteer sessions can't be confused or abused.
$_SESSION['staff_id']       = $staff['staff_id'];
$_SESSION['staff_username'] = $staff['username'];

write_audit_log($pdo, null, 'staff_login_success', 'Staff user logged in: ' . $staff['username']);

header('Location: staff_dashboard.php');
exit;
