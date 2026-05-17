<?php
// ============================================================
// staff_login.php
// Minimal staff login form. No styling — frontend teammate owns that.
// Backend job: generate a CSRF token and embed it in the form.
// ============================================================
session_start();

if (empty($_SESSION['csrf_token_staff'])) {
    // Separate token key from volunteer CSRF token so they don't collide
    // if somehow both sessions co-exist.
    $_SESSION['csrf_token_staff'] = bin2hex(random_bytes(32));
}

$error = htmlspecialchars($_GET['error'] ?? '');
$messages = [
    'missing'  => 'Please enter your username and password.',
    'invalid'  => 'Username or password incorrect.',
    'csrf'     => 'Security token error. Please try again.',
    'locked'   => 'Too many failed attempts. Please wait 60 seconds.',
];
$message = $messages[$error] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login – Home-Start</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="page-wrapper">

    <img src="Home-Start-Logo.png" alt="Home-Start" class="logo">

    <h1>Home-Start Staff Portal</h1>
    <h2>Staff Login</h2>

    <?php if ($message): ?>
        <div class="alert alert-error"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST" action="staff_auth.php">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($_SESSION['csrf_token_staff']) ?>">

        <div class="form-group">
            <label for="username">Username:</label>
            <input type="text" id="username" name="username" required autocomplete="username">
        </div>

        <div class="form-group">
            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>

        <div class="btn-row">
            <button type="submit" class="btn btn-primary">Log In</button>
        </div>
    </form>

    <p style="text-align:center;margin-top:1.5rem;font-size:0.8rem;color:#888;">
        Volunteer? <a href="login.php" style="color:#552682;">Volunteer login &rarr;</a>
    </p>

</div>
</body>
</html>
