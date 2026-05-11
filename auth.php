<?php
session_start();
require_once'dbconnection.php';

#if someone tried to directly visit auth.php,
#this will kick them out into login page again
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {  
    header('Location: login.php');
    exit;#stops the script from running further after redirecting
}

#to fetch the data from the submission form
$volunteer_id=trim($_POST['volunteer_id']);
$postcode=strtoupper(trim($_POST['postcode']));

#to check  if both the fields are filled, if not then error
if (empty($volunteer_id)|| empty($postcode)){
    header('Location: login.php?error=missing');
    exit;
}

#quering into database
$stmt=$pdo->prepare('SELECT*FROM volunteer WHERE volunteer_id=? AND  postcode=?');
$stmt->execute([$volunteer_id,$postcode]);#runs the query passing values
$volunteer = $stmt-> fetch();#grabs the results and if no matches returns false

#if no match were found this will send back to loginpage
#using the same error bcs we dont want to tell them details
if (!$volunteer){
    header ('Location: login.php?error=invalid');
    exit;
}

#creating a fresh session on loging to prevent session fixation attacks
#$_SESSION is where you store the logged in volunteer's info so other pages know who they are
session_regenerate_id(true);
$_SESSION['volunteer_id'] = $volunteer['volunteer_id'];
$_SESSION['postcode'] = $volunteer['postcode'];

header('Location: home.php');
exit;
