<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';

if (isLoggedIn()) redirect('/index.php');

$selectedRole = $_GET['role'] ?? $_POST['role'] ?? 'donor';
if (!in_array($selectedRole, ['donor','receiver','admin','hub'], true)) $selectedRole = 'donor';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        flash('error', 'Invalid email or password.');
        redirect('/login.php?role=' . $selectedRole);
    }
    if ($user['status'] === 'suspended') {
        flash('error', 'This account has been suspended. Contact an administrator.');
        redirect('/login.php?role=' . $selectedRole);
    }
    // The role toggle is a convenience for the user; the account's own role
    // is what actually decides access, so flag an obvious mismatch.
    if ($user['role'] !== $selectedRole) {
        flash('error', 'That account is registered as a ' . $user['role'] . '. Switch the role selector and try again.');
        redirect('/login.php?role=' . $user['role']);
    }

    $_SESSION['user_id']   = $user['user_id'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];

    if (!$user['email_verified']) redirect('/verify_email.php');
    if ($user['role'] === 'donor') redirect('/donor/dashboard.php');
    if ($user['role'] === 'receiver') redirect('/receiver/dashboard.php');
    if ($user['role'] === 'hub') redirect('/hub/desk.php');
    redirect('/admin/dashboard.php');
}
$err = flash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log In – <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="auth-page">
    <div class="auth-logo">
        <span class="brand-dot"></span>
        <h1>EcoLink</h1>
    </div>

    <div class="auth-layout">
        <div class="auth-panel">
            <h2>Welcome back</h2>
            <p class="sub">Log in to continue managing your EcoLink activity.</p>
            <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

            <form method="post">
                <span class="field-label">Account Role</span>
                <div class="role-toggle">
                    <?php foreach (['donor' => 'Donor', 'receiver' => 'Receiver', 'admin' => 'Admin', 'hub' => 'Hub Staff'] as $val => $label): ?>
                        <input type="radio" name="role" id="role_<?= $val ?>" value="<?= $val ?>" <?= $selectedRole === $val ? 'checked' : '' ?>>
                        <label for="role_<?= $val ?>"><?= $label ?></label>
                    <?php endforeach; ?>
                </div>

                <div class="field">
                    <span class="field-label">Email Address</span>
                    <input type="email" name="email" placeholder="name@example.com" required autofocus>
                </div>
                <div class="field">
                    <span class="field-label">Password</span>
                    <input type="password" name="password" placeholder="Enter your password" required>
                    <p style="margin:8px 0 0;"><a href="<?= BASE_URL ?>/forgot_password.php"><strong>Forgot password?</strong></a></p>
                </div>

                <button class="btn-accent" type="submit">Log In →</button>
            </form>

            <p style="text-align:center; color:var(--ink-soft); margin:20px 0 6px;">or</p>
            <p style="text-align:center; color:var(--ink-soft); margin:0;">Don't have an account?<br>
                <a href="<?= BASE_URL ?>/get_started.php"><strong>Create an account →</strong></a>
            </p>
        </div>

        <aside class="auth-side">
            <h3>Continue with EcoLink</h3>
            <p>Your account gives you access to:</p>
            <ol>
                <li><div><strong>Smart matching</strong><small>Relevant donor–receiver recommendations.</small></div></li>
                <li><div><strong>Lifecycle tracking</strong><small>Follow donation/request progress.</small></div></li>
                <li><div><strong>Impact visibility</strong><small>See resources reused and beneficiaries.</small></div></li>
            </ol>
        </aside>
    </div>

    <p class="auth-footnote">
        EcoLink — Smart Surplus Resource Optimization &amp; Redistribution Platform<br>
        Food • Clothes • Books • Electronics • Furniture
    </p>
</div>
</body>
</html>
