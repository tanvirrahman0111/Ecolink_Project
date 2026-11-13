<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/hub.php';

if (isLoggedIn()) redirect('/index.php');

$selectedRole = $_GET['role'] ?? $_POST['role'] ?? 'donor';
if (!in_array($selectedRole, ['donor','receiver','hub'], true)) $selectedRole = 'donor';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $location = trim($_POST['location'] ?? '');
    $org      = trim($_POST['organization_name'] ?? '');
    $agreed   = isset($_POST['agree']);

    if ($fullName === '' || $email === '' || strlen($password) < 8) {
        flash('error', 'Please fill all required fields. Password must be at least 8 characters.');
        redirect('/register.php?role=' . $selectedRole);
    }
    if ($password !== $confirm) {
        flash('error', 'The two passwords do not match.');
        redirect('/register.php?role=' . $selectedRole);
    }
    if (!$agreed) {
        flash('error', "Please accept EcoLink's terms and responsible-use guidelines.");
        redirect('/register.php?role=' . $selectedRole);
    }

    $db = getDB();
    $hubId = 0;
    if ($selectedRole === 'hub') {
        ensureHubTables($db);
        $hubId = (int)($_POST['hub_id'] ?? 0);
        $hs = $db->prepare("SELECT 1 FROM delivery_hubs WHERE hub_id = ? AND is_active = 1");
        $hs->execute([$hubId]);
        if (!$hs->fetchColumn()) {
            flash('error', 'Please choose the hub you work at.');
            redirect('/register.php?role=hub');
        }
    }
    $check = $db->prepare("SELECT user_id FROM users WHERE email = ?");
    $check->execute([$email]);
    if ($check->fetch()) {
        flash('error', 'An account with this email already exists.');
        redirect('/register.php?role=' . $selectedRole);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        "INSERT INTO users (full_name, email, phone, password_hash, role, organization_name, address, pickup_area, is_verified, email_verified)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0)"
    );
    $stmt->execute([$fullName, $email, $phone ?: '', $hash, $selectedRole, $org ?: null, $location ?: null, $location ?: null]);
    $userId = (int)$db->lastInsertId();

    if ($selectedRole === 'hub') {
        // Hub staff must be approved by an admin before they can operate a hub.
        $db->prepare("INSERT INTO hub_staff (user_id, hub_id, status) VALUES (?, ?, 'pending')")->execute([$userId, $hubId]);
        foreach ($db->query("SELECT user_id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'system', ?, ?)")
               ->execute([$adminId, 'New hub staff request', $fullName . ' asked to manage a delivery hub. Approve it in Delivery Hubs.']);
        }
    }

    // Log the new user in so they can complete email verification.
    $_SESSION['user_id']   = $userId;
    $_SESSION['role']      = $selectedRole;
    $_SESSION['full_name'] = $fullName;

    redirect('/verify_email.php');
}
$err = flash('error');
$hubList = [];
try { $__db = getDB(); ensureHubTables($__db); $hubList = $__db->query("SELECT hub_id, name, area FROM delivery_hubs WHERE is_active = 1 ORDER BY area, name")->fetchAll(); } catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account – <?= APP_NAME ?></title>
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
            <h2>Create your EcoLink account</h2>
            <p class="sub">Join the platform and take part in smart surplus redistribution.</p>
            <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

            <form method="post">
                <span class="field-label">Account Role *</span>
                <div class="role-cards" style="grid-template-columns:1fr 1fr 1fr;">
                    <input type="radio" name="role" id="r_donor" value="donor" <?= $selectedRole === 'donor' ? 'checked' : '' ?>>
                    <label class="role-card" for="r_donor">
                        <span class="badge-letter">D</span>
                        <strong>Donor</strong>
                        <small>Share surplus resources.</small>
                    </label>
                    <input type="radio" name="role" id="r_receiver" value="receiver" <?= $selectedRole === 'receiver' ? 'checked' : '' ?>>
                    <label class="role-card" for="r_receiver">
                        <span class="badge-letter">R</span>
                        <strong>Receiver</strong>
                        <small>Request what you need.</small>
                    </label>
                    <input type="radio" name="role" id="r_hub" value="hub" <?= $selectedRole === 'hub' ? 'checked' : '' ?>>
                    <label class="role-card" for="r_hub">
                        <span class="badge-letter">H</span>
                        <strong>Hub Staff</strong>
                        <small>Run a delivery hub.</small>
                    </label>
                </div>

                <div class="field" id="hubField" style="display:none;">
                    <span class="field-label">Which hub do you work at? *</span>
                    <select name="hub_id" id="hubSelect">
                        <option value="">Select a hub…</option>
                        <?php foreach ($hubList as $h): ?>
                            <option value="<?= (int)$h['hub_id'] ?>"><?= e($h['name']) ?> — <?= e($h['area']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:var(--ink-soft);"><?= $hubList ? 'An admin will approve your account before you can use the Hub Desk.' : 'No hub exists yet. Ask the EcoLink admin to create your hub first.' ?></small>
                </div>

                <div class="field">
                    <span class="field-label">Full Name *</span>
                    <input type="text" name="full_name" placeholder="Enter your full name" required>
                </div>
                <div class="field" id="orgField" style="display:none;">
                    <span class="field-label">Organization Name</span>
                    <input type="text" name="organization_name" placeholder="e.g. Community Learning Center">
                </div>
                <div class="field">
                    <span class="field-label">Email Address *</span>
                    <input type="email" name="email" placeholder="name@example.com" required>
                </div>
                <div class="field">
                    <span class="field-label">Phone Number</span>
                    <input type="text" name="phone" placeholder="+880 1XXXXXXXXX">
                </div>
                <div class="field">
                    <span class="field-label">Password *</span>
                    <input type="password" name="password" id="pw" placeholder="Create a password" minlength="8" required>
                </div>
                <div class="field">
                    <span class="field-label">Confirm Password *</span>
                    <input type="password" name="confirm_password" placeholder="Re-enter your password" minlength="8" required>
                </div>
                <div class="field">
                    <span class="field-label">Location / Area</span>
                    <input type="text" name="location" placeholder="e.g. Uttara, Dhaka">
                </div>

                <label class="checkbox-label" style="display:flex; gap:10px; align-items:flex-start; margin-bottom:20px; font-size:0.88rem; color:var(--ink-soft);">
                    <input type="checkbox" name="agree" required style="margin-top:3px;">
                    I agree to EcoLink's terms and responsible-use guidelines.
                </label>

                <button class="btn-accent" type="submit">Create Account →</button>
            </form>

            <p style="text-align:center; color:var(--ink-soft); margin:18px 0 0;">Already have an account?<br>
                <a href="<?= BASE_URL ?>/login.php"><strong>Log in →</strong></a>
            </p>
        </div>

        <aside class="auth-side">
            <h3>Why join EcoLink?</h3>
            <ol>
                <li><div><strong>Smart matching</strong><small>Find relevant donor–receiver matches.</small></div></li>
                <li><div><strong>Track the lifecycle</strong><small>Follow resources from match to completion.</small></div></li>
                <li><div><strong>Measure impact</strong><small>See reused resources and beneficiaries.</small></div></li>
            </ol>
            <p style="text-transform:uppercase; font-size:0.7rem; letter-spacing:0.06em; color:#8a988f; font-weight:700; margin-bottom:4px;">Supported Categories</p>
            <p style="margin:0; font-size:0.88rem;">Food • Clothes • Books<br>Electronics • Furniture</p>
        </aside>
    </div>

    <p class="auth-footnote">EcoLink — Smart Surplus Resource Optimization &amp; Redistribution Platform</p>
</div>
<script>
function syncOrgField() {
    var isReceiver = document.getElementById('r_receiver').checked;
    var isHub = document.getElementById('r_hub').checked;
    document.getElementById('orgField').style.display = isReceiver ? 'block' : 'none';
    document.getElementById('hubField').style.display = isHub ? 'block' : 'none';
    document.getElementById('hubSelect').required = isHub;
}
['r_donor','r_receiver','r_hub'].forEach(function (id) { document.getElementById(id).addEventListener('change', syncOrgField); });
syncOrgField();
</script>
</body>
</html>
