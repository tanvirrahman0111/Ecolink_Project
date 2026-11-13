<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';

$db = getDB();
$token = $_GET['token'] ?? $_POST['token'] ?? '';

$stmt = $db->prepare(
    "SELECT pr.*, u.email FROM password_resets pr JOIN users u ON u.user_id = pr.user_id
     WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()"
);
$stmt->execute([$token]);
$reset = $stmt->fetch();

$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        flash('error', 'Password must be at least 8 characters and include one uppercase letter and one number.');
    } elseif ($password !== $confirm) {
        flash('error', 'The two passwords do not match.');
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")->execute([$hash, $reset['user_id']]);
        $db->prepare("UPDATE password_resets SET used = 1 WHERE reset_id = ?")->execute([$reset['reset_id']]);
        $done = true;
    }
}
$err = flash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password – <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= BASE_URL ?>/index.php"><span class="brand-dot"></span> EcoLink</a>
        <span style="color:var(--ink-soft); font-size:0.9rem;">Secure account recovery</span>
    </div>
</header>

<div class="auth-page">
    <div class="auth-panel" style="max-width:560px; width:100%;">
        <h2>Reset your password</h2>
        <p class="sub">Create a new password for your EcoLink account.</p>
        <div style="text-align:center; margin-bottom:20px;">
            <span style="display:inline-flex; width:58px; height:58px; border-radius:50%; background:var(--green-light); color:var(--forest-2); align-items:center; justify-content:center; font-size:1.6rem;">🔒</span>
        </div>

        <?php if ($done): ?>
            <div class="alert alert-success">Your password has been updated. You can sign in now.</div>
            <a class="btn-accent" href="<?= BASE_URL ?>/login.php" style="width:100%;">Go to Login →</a>
        <?php elseif (!$reset): ?>
            <div class="alert alert-error">This reset link is invalid or has expired. Please request a new one.</div>
            <a class="btn-secondary" href="<?= BASE_URL ?>/forgot_password.php" style="width:100%;">Request a new link</a>
        <?php else: ?>
            <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="field">
                    <span class="field-label">New Password</span>
                    <input type="password" name="password" id="pw" placeholder="Enter new password" required>
                </div>
                <div class="field">
                    <span class="field-label">Confirm Password</span>
                    <input type="password" name="confirm_password" placeholder="Re-enter new password" required>
                </div>
                <p style="font-weight:700; font-size:0.85rem; margin:0 0 4px;">Password requirements</p>
                <ul class="pw-reqs">
                    <li id="req-len">At least 8 characters</li>
                    <li id="req-mix">One uppercase letter and one number</li>
                </ul>
                <button class="btn-accent" type="submit">Update Password</button>
            </form>
        <?php endif; ?>
    </div>
    <p class="auth-footnote">Your account security matters. You can sign in after updating your password.</p>
</div>
<script>
var pw = document.getElementById('pw');
if (pw) {
    pw.addEventListener('input', function () {
        document.getElementById('req-len').classList.toggle('ok', pw.value.length >= 8);
        document.getElementById('req-mix').classList.toggle('ok', /[A-Z]/.test(pw.value) && /[0-9]/.test(pw.value));
    });
}
</script>
</body>
</html>
