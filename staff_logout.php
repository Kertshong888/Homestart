<?php
// ============================================================
// staff_logout.php
// Destroys the staff session and redirects to staff login.
// ============================================================
session_start();
session_destroy();
header('Location: staff_login.php');
exit;
