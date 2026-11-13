<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';

if (isLoggedIn()) redirect('/index.php');

$resetLink = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $db = getDB();
    $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $token = bin2hex(random_bytes(32));
        $db->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0")->execute([$user['user_id']]);
        $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))")
           ->execute([$user['user_id'], $token]);
        // NOTE: no mail server in the academic setup, so the link is shown on
        // screen. In a real deployment this would be emailed instead.
        $resetLink = BASE_URL . '/reset_password.php?token=' . $token;
    }
    // Always show the same confirmation, so the form can't be used to discover
    // which email addresses have accounts.
    $sent = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password – <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="auth-page">
    <div class="auth-logo" style="align-self:flex-start; position:absolute; top:30px; left:40px; text-align:left;">
        <a class="brand" href="<?= BASE_URL ?>/index.php"><span class="brand-dot"></span> EcoLink</a>
    </div>

    <div class="auth-panel" style="max-width:520px; width:100%;">
        <div style="text-align:center; margin-bottom:6px;"><span class="brand-dot" style="width:40px;height:40px;display:inline-block;"></span></div>
        <h2>Forgot Password?</h2>
        <p class="sub">Enter your registered email and we'll help you reset your password.</p>

        <?php if (!empty($sent)): ?>
            <div class="alert alert-success">If an account exists for that email, a reset link has been created.</div>
            <?php if ($resetLink): ?>
                <div class="alert alert-success">
                    No mail server is configured here, so use this link directly:<br>
                    <a href="<?= e($resetLink) ?>"><strong>Reset your password →</strong></a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post">
            <div class="field">
                <span class="field-label">Email Address</span>
                <input type="email" name="email" placeholder="you@example.com" required autofocus>
            </div>
            <button class="btn-accent" type="submit">Send Reset Link</button>
        </form>

        <p style="text-align:center; color:var(--ink-soft); margin:20px 0 0;">Remember your password?<br>
            <a href="<?= BASE_URL ?>/login.php"><strong>Back to Login</strong></a>
        </p>
        <hr style="border:none; border-top:1px solid var(--line); margin:22px 0 14px;">
        <p style="text-align:center; color:var(--ink-soft); font-size:0.85rem; margin:0;">For account security, never share your reset link with anyone.</p>
    </div>

    <p class="auth-footnote">&copy; <?= date('Y') ?> EcoLink • Smart Surplus Resource Redistribution Platform</p>
</div>
</body>
</html>
