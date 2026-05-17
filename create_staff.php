<?php
// ============================================================
// create_staff.php
// ONE-TIME SETUP SCRIPT — run this once to create the test staff account.
// Delete or move this file after running it (it's a security risk to leave
// a script that inserts admin accounts accessible in production).
//
// Visit: http://localhost/HOMESTART/create_staff.php
// Then DELETE this file.
// ============================================================
require_once 'dbconnection.php';

// The plain-text password for the test staff account.
// You can change this to whatever you want.
$username       = 'admin';
$plain_password = 'staffpass123';

// password_hash() generates a secure bcrypt hash.
// PASSWORD_DEFAULT uses bcrypt with cost factor 10.
// The hash includes the salt, so no separate salt column is needed.
$hash = password_hash($plain_password, PASSWORD_DEFAULT);

// Use INSERT ... ON DUPLICATE KEY UPDATE so re-running this script
// just updates the password rather than throwing a duplicate key error.
$stmt = $pdo->prepare(
    'INSERT INTO staff (username, password_hash)
     VALUES (?, ?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
);
$stmt->execute([$username, $hash]);

echo '<h2>Staff account created / updated.</h2>';
echo '<p><strong>Username:</strong> ' . htmlspecialchars($username) . '</p>';
echo '<p><strong>Password:</strong> ' . htmlspecialchars($plain_password) . '</p>';
echo '<p><strong>Hash:</strong> ' . htmlspecialchars($hash) . '</p>';
echo '<hr>';
echo '<p style="color:red;"><strong>IMPORTANT: Delete this file now!</strong></p>';
echo '<p>This file should never be accessible in a live environment.</p>';
