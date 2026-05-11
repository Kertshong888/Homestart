<?php
session_start();
#checking if there is valid session if not backtologin
if (empty($_SESSION['volunteer_id'])) {
    header('Location: login.php');
    exit;
}
#info out of the session to home
$volunteer_id = $_SESSION['volunteer_id'];
$postcode = $_SESSION['postcode'];

echo 'Welcome volunteer ' . $volunteer_id;
?>
