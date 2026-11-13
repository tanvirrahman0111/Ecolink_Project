<?php
require_once __DIR__ . '/config/app.php';
if (isLoggedIn()) redirect('/index.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome – <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="auth-page">
    <div class="auth-logo">
        <span class="brand-dot"></span>
        <h1>EcoLink</h1>
    </div>
    <h2 style="text-align:center; font-size:1.8rem; margin:0 0 6px;">Welcome to EcoLink</h2>
    <p style="text-align:center; color:var(--ink-soft); margin:0 0 28px;">Choose how you want to use the platform.</p>

    <div class="auth-panel" style="max-width:740px; width:100%;">
        <h2 style="font-size:1.25rem;">Select your role</h2>
        <p class="sub">You can continue to registration after selecting your role.</p>
        <div class="role-cards" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));">
            <div class="role-card">
                <span class="badge-letter">D</span>
                <strong>Donor</strong>
                <small>Share surplus resources with suitable receivers.</small>
                <a class="btn-accent btn-small" href="<?= BASE_URL ?>/register.php?role=donor">Continue</a>
            </div>
            <div class="role-card">
                <span class="badge-letter">R</span>
                <strong>Receiver</strong>
                <small>Request resources your organization or community needs.</small>
                <a class="btn-secondary btn-small" href="<?= BASE_URL ?>/register.php?role=receiver">Continue</a>
            </div>
            <div class="role-card">
                <span class="badge-letter">H</span>
                <strong>Hub Staff</strong>
                <small>Run a delivery hub: receive donations and hand them to receivers.</small>
                <a class="btn-secondary btn-small" href="<?= BASE_URL ?>/register.php?role=hub">Continue</a>
            </div>
            <div class="role-card">
                <span class="badge-letter">A</span>
                <strong>Admin</strong>
                <small>Manage verification, moderation and analytics.</small>
                <a class="btn-secondary btn-small" href="<?= BASE_URL ?>/login.php?role=admin">Admin Login</a>
            </div>
        </div>
    </div>

    <p class="auth-footnote">
        Already have an account?<br>
        <a href="<?= BASE_URL ?>/login.php"><strong>Log in instead →</strong></a>
    </p>
    <p class="auth-footnote">
        EcoLink — Smart Surplus Resource Optimization &amp; Redistribution Platform<br>
        Food • Clothes • Books • Electronics • Furniture
    </p>
</div>
</body>
</html>
