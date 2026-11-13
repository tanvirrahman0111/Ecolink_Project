<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

$db = getDB();
$uid = currentUserId();

$user = $db->prepare("SELECT * FROM users WHERE user_id = ?");
$user->execute([$uid]);
$user = $user->fetch();

if ($user['email_verified']) {
    redirect('/' . $user['role'] . '/dashboard.php');
}

/** Issue a fresh 6-digit code valid for 10 minutes. */
function issueCode(PDO $db, int $uid): string {
    $db->prepare("UPDATE email_verifications SET used = 1 WHERE user_id = ? AND used = 0")->execute([$uid]);
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $db->prepare("INSERT INTO email_verifications (user_id, code, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))")
       ->execute([$uid, $code]);
    return $code;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['resend'])) {
        $code = issueCode($db, $uid);
        // NOTE: EcoLink has no mail server configured in the academic setup, so the
        // code is shown on screen instead of emailed. Swap this for mail()/SMTP
        // (e.g. PHPMailer) in a real deployment.
        flash('success', 'A new code was generated. Demo code: ' . $code);
        redirect('/verify_email.php');
    }

    $entered = '';
    for ($i = 1; $i <= 6; $i++) $entered .= trim($_POST['d' . $i] ?? '');

    $stmt = $db->prepare(
        "SELECT * FROM email_verifications
         WHERE user_id = ? AND used = 0 AND expires_at > NOW()
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$uid]);
    $record = $stmt->fetch();

    if ($record && hash_equals($record['code'], $entered)) {
        $db->prepare("UPDATE email_verifications SET used = 1 WHERE verification_id = ?")->execute([$record['verification_id']]);
        $db->prepare("UPDATE users SET email_verified = 1 WHERE user_id = ?")->execute([$uid]);
        flash('success', 'Email verified! Welcome to EcoLink.');
        redirect('/' . $user['role'] . '/dashboard.php');
    }
    flash('error', 'That code is incorrect or has expired. Request a new one below.');
    redirect('/verify_email.php');
}

// Ensure an active code exists when the page first loads.
$active = $db->prepare("SELECT * FROM email_verifications WHERE user_id = ? AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
$active->execute([$uid]);
$active = $active->fetch();
$demoCode = null;
if (!$active) {
    $demoCode = issueCode($db, $uid);
}

$maskedEmail = preg_replace('/^(.).*(@.*)$/', '$1****$2', $user['email']);
$err = flash('error');
$ok  = flash('success');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Email – <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= BASE_URL ?>/index.php"><span class="brand-dot"></span> EcoLink
            <small style="display:block; font-weight:400; font-size:0.72rem; color:var(--ink-soft);">Smart surplus redistribution</small>
        </a>
    </div>
</header>

<div class="status-card">
    <div class="status-icon plain"><span class="inner">✓</span></div>
    <h1>Verify your email</h1>
    <p>We sent a 6-digit verification code to<br><strong><?= e($maskedEmail) ?></strong></p>

    <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="alert alert-success"><?= e($ok) ?></div><?php endif; ?>
    <?php if ($demoCode): ?>
        <div class="alert alert-success">No mail server is configured in this setup, so here is your code: <strong><?= e($demoCode) ?></strong></div>
    <?php endif; ?>

    <form method="post">
        <div class="otp-row">
            <?php for ($i = 1; $i <= 6; $i++): ?>
                <input type="text" name="d<?= $i ?>" maxlength="1" inputmode="numeric" pattern="[0-9]" required
                       <?= $i === 1 ? 'autofocus' : '' ?> data-otp>
            <?php endfor; ?>
        </div>
        <p class="otp-hint">Code expires 10 minutes after it is issued.</p>
        <button class="btn-accent" type="submit" style="width:100%;">Verify Email</button>
    </form>

    <p style="color:var(--ink-soft); margin:22px 0 4px;">Didn't receive the code?</p>
    <form method="post"><button class="btn-secondary btn-small" name="resend" value="1" type="submit">Resend code</button></form>

    <p style="color:var(--ink-soft); margin:22px 0 4px;">Wrong email?</p>
    <a href="<?= BASE_URL ?>/logout.php"><strong>Go back</strong></a>
</div>

<p class="auth-footnote">&copy; <?= date('Y') ?> EcoLink · Secure account verification</p>
<script>
// Auto-advance between the OTP boxes
document.querySelectorAll('[data-otp]').forEach(function (box, idx, all) {
    box.addEventListener('input', function () {
        if (box.value && idx < all.length - 1) all[idx + 1].focus();
    });
    box.addEventListener('keydown', function (ev) {
        if (ev.key === 'Backspace' && !box.value && idx > 0) all[idx - 1].focus();
    });
});
</script>
</body>
</html>
