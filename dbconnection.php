<?php
// ============================================================
// dbconnection.php
// Creates a PDO database connection object ($pdo) used by every
// other PHP file via require_once 'dbconnection.php'.
//
// PDO (PHP Data Objects) is the standard database abstraction
// layer that enables prepared statements, preventing SQL injection.
// ============================================================

$host = 'localhost';
$db   = 'homestart';
$user = 'root';
$pass = '';  // XAMPP default: blank root password

// DSN = Data Source Name - tells PDO which driver and database to use.
// charset=utf8mb4 supports full Unicode including emoji and special chars.
$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass);

    // ERRMODE_EXCEPTION: throw a PDOException on any SQL error
    // instead of silently returning false. Makes bugs visible.
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // FETCH_ASSOC: rows come back as ['column' => 'value'] arrays
    // rather than numeric arrays. Much clearer to work with.
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // In production you would log this, never show the raw message.
    // For development/XAMPP it is acceptable to die with the error.
    die('Database connection failed: ' . $e->getMessage());
}
