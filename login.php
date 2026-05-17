<?php
// ============================================================
// login.php — Volunteer login form
// Backend: CSRF token generation, error display
// Frontend: Home-Start brand (orange #ea580d / purple #552682)
// ============================================================
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = htmlspecialchars($_GET['error'] ?? '');
$error_messages = [
    'missing' => 'Please enter both your Volunteer ID and postcode.',
    'invalid' => 'Volunteer ID or postcode not recognised. Please try again.',
    'locked'  => 'Too many failed attempts. Please wait 60 seconds and try again.',
    'csrf'    => 'Security token mismatch. Please try again.',
];
$message = $error_messages[$error] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login – Home-Start</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="page-wrapper">

    <img src="Home-Start-Logo.png" alt="Home-Start Arun, Worthing &amp; Adur" class="logo">

    <h1>Home-Start Volunteer Portal</h1>
    <h2>Volunteer Login</h2>

    <?php if ($message): ?>
        <div class="alert alert-error"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST" action="auth.php">
        <!-- CSRF token — never remove this hidden field -->
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="form-group">
            <label for="volunteer_id">Volunteer ID:</label>
            <input type="text" id="volunteer_id" name="volunteer_id"
                   placeholder="e.g. VOL001" required>
        </div>

        <div class="form-group">
            <label for="postcode">
                Postcode:
                <span class="tooltip"
                      data-tooltip="Your volunteer ID and postcode were sent to you in your welcome email from Home-Start Arun, Worthing &amp; Adur.">?</span>
            </label>
            <input type="text" id="postcode" name="postcode"
                   placeholder="e.g. BN1 1AA" required>
        </div>

        <div class="btn-row">
            <button type="submit" class="btn btn-primary">Log In</button>
        </div>
    </form>

    <p style="text-align:center; margin-top:1.5rem; font-size:0.8rem; color:#888;">
        Staff? <a href="staff_login.php" style="color:#552682;">Staff login &rarr;</a>
    </p>

</div>
</body>
</html>
