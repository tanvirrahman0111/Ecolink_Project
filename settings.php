<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

$db = getDB();
$uid = currentUserId();
$tab = $_GET['tab'] ?? 'personal';
if (!in_array($tab, ['personal','preferences','notifications','security'], true)) $tab = 'personal';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'personal') {
        $name  = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $addr  = trim($_POST['address'] ?? '');
        $org   = trim($_POST['organization_name'] ?? '');
        if ($name === '') {
            flash('error', 'Name cannot be empty.');
        } else {
            $db->prepare("UPDATE users SET full_name = ?, phone = ?, address = ?, organization_name = ? WHERE user_id = ?")
               ->execute([$name, $phone, $addr ?: null, $org ?: null, $uid]);
            $_SESSION['full_name'] = $name;
            flash('success', 'Personal information updated.');
        }
        redirect('/settings.php?tab=personal');

    } elseif ($action === 'preferences') {
        $cats = isset($_POST['categories']) && is_array($_POST['categories'])
            ? implode(',', array_map('strval', $_POST['categories'])) : null;
        $area = trim($_POST['pickup_area'] ?? '');
        $cond = $_POST['preferred_condition'] ?? 'any';
        if (!in_array($cond, ['any','new','like_new','good','fair'], true)) $cond = 'any';
        $db->prepare("UPDATE users SET preferred_categories = ?, pickup_area = ?, preferred_condition = ? WHERE user_id = ?")
           ->execute([$cats, $area ?: null, $cond, $uid]);
        flash('success', 'Matching preferences updated.');
        redirect('/settings.php?tab=preferences');

    } elseif ($action === 'notifications') {
        $db->prepare("UPDATE users SET notify_matching = ?, notify_lifecycle = ?, notify_reminders = ? WHERE user_id = ?")
           ->execute([
               isset($_POST['notify_matching']) ? 1 : 0,
               isset($_POST['notify_lifecycle']) ? 1 : 0,
               isset($_POST['notify_reminders']) ? 1 : 0,
               $uid,
           ]);
        flash('success', 'Notification preferences updated.');
        redirect('/settings.php?tab=notifications');

    } elseif ($action === 'security') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $row = $db->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $row->execute([$uid]);
        $row = $row->fetch();

        if (!password_verify($current, $row['password_hash'])) {
            flash('error', 'Your current password is incorrect.');
        } elseif (strlen($new) < 8 || !preg_match('/[A-Z]/', $new) || !preg_match('/[0-9]/', $new)) {
            flash('error', 'New password must be at least 8 characters with one uppercase letter and one number.');
        } elseif ($new !== $confirm) {
            flash('error', 'The two new passwords do not match.');
        } else {
            $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
               ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            flash('success', 'Password changed.');
        }
        redirect('/settings.php?tab=security');
    }
}

$user = $db->prepare("SELECT * FROM users WHERE user_id = ?");
$user->execute([$uid]);
$user = $user->fetch();

$categories = $db->query("SELECT name FROM resource_categories ORDER BY name")->fetchAll();
$selectedCats = $user['preferred_categories'] ? explode(',', $user['preferred_categories']) : [];

$pageTitle = 'Profile & Settings';
require_once __DIR__ . '/includes/header.php';
?>
<h1>Profile &amp; Settings</h1>
<p>Manage your personal information, preferences and account security.</p>

<div class="profile-head">
    <div class="profile-avatar"><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?></div>
    <div class="who">
        <h2><?= e($user['full_name']) ?></h2>
        <small><?= e(ucfirst($user['role'])) ?> Account · <?= $user['is_verified'] ? 'Verified' : 'Pending verification' ?></small><br>
        <span class="badge badge-<?= $user['status'] === 'active' ? 'active' : 'restricted' ?>" style="margin-top:6px;"><?= e(ucfirst($user['status'])) ?></span>
    </div>
</div>

<nav class="tabs">
    <a class="<?= $tab === 'personal' ? 'active' : '' ?>" href="?tab=personal">Personal Information</a>
    <a class="<?= $tab === 'preferences' ? 'active' : '' ?>" href="?tab=preferences">Preferences</a>
    <a class="<?= $tab === 'notifications' ? 'active' : '' ?>" href="?tab=notifications">Notifications</a>
    <a class="<?= $tab === 'security' ? 'active' : '' ?>" href="?tab=security">Security</a>
</nav>

<?php if ($tab === 'personal'): ?>
    <div class="two-col" style="align-items:start;">
        <form method="post" class="mini-card">
            <input type="hidden" name="action" value="personal">
            <h3 style="margin-top:0;">Personal Information</h3>
            <p style="color:var(--ink-soft);">Keep your account details up to date for better matching and communication.</p>
            <div class="two-col">
                <label class="field"><span class="field-label">Full Name</span>
                    <input type="text" name="full_name" value="<?= e($user['full_name']) ?>" required>
                </label>
                <label class="field"><span class="field-label">Email Address</span>
                    <input type="email" value="<?= e($user['email']) ?>" disabled style="background:var(--bg);">
                </label>
            </div>
            <div class="two-col">
                <label class="field"><span class="field-label">Phone Number</span>
                    <input type="text" name="phone" value="<?= e($user['phone']) ?>">
                </label>
                <label class="field"><span class="field-label">Location</span>
                    <input type="text" name="address" value="<?= e($user['address'] ?? '') ?>">
                </label>
            </div>
            <?php if ($user['role'] === 'receiver'): ?>
                <label class="field"><span class="field-label">Organization Name</span>
                    <input type="text" name="organization_name" value="<?= e($user['organization_name'] ?? '') ?>">
                </label>
            <?php endif; ?>
            <div class="two-col">
                <label class="field"><span class="field-label">Account Role</span>
                    <input type="text" value="<?= e(ucfirst($user['role'])) ?>" disabled style="background:var(--bg);">
                </label>
                <label class="field"><span class="field-label">Member Since</span>
                    <input type="text" value="<?= e(date('Y', strtotime($user['created_at']))) ?>" disabled style="background:var(--bg);">
                </label>
            </div>
            <div style="display:flex; gap:10px;">
                <button class="btn-accent" type="submit">Save Changes</button>
                <a class="btn-secondary" href="<?= BASE_URL ?>/settings.php?tab=personal">Cancel</a>
            </div>
        </form>

        <div class="mini-card">
            <h3 style="margin-top:0;">Quick Preferences</h3>
            <p style="color:var(--ink-soft);">Control what EcoLink uses for your experience.</p>
            <p style="font-weight:700; margin:16px 0 8px;">Matching Preferences</p>
            <div style="background:var(--bg); border:1px solid var(--line); border-radius:10px; padding:12px; margin-bottom:10px;">
                <small style="color:var(--ink-soft);">Preferred Categories</small><br>
                <strong><?= $selectedCats ? e(implode(' • ', $selectedCats)) : 'Not set' ?></strong>
            </div>
            <div style="background:var(--bg); border:1px solid var(--line); border-radius:10px; padding:12px;">
                <small style="color:var(--ink-soft);">Pickup Area</small><br>
                <strong><?= e($user['pickup_area'] ?: 'Not set') ?></strong>
            </div>
            <p style="font-weight:700; margin:20px 0 8px;">Account Security</p>
            <div style="background:var(--bg); border:1px solid var(--line); border-radius:10px; padding:12px; display:flex; justify-content:space-between; align-items:center;">
                <div><small style="color:var(--ink-soft);">Password</small><br><strong>••••••••••</strong></div>
                <a href="?tab=security"><strong>Change</strong></a>
            </div>
            <p style="color:var(--ink-soft); font-size:0.82rem; margin-top:16px;">Profile visibility is used only to support matching, verification, and communication.</p>
        </div>
    </div>

<?php elseif ($tab === 'preferences'): ?>
    <form method="post" class="mini-card" style="max-width:640px;">
        <input type="hidden" name="action" value="preferences">
        <h3 style="margin-top:0;">Matching Preferences</h3>
        <p style="color:var(--ink-soft);">These preferences help EcoLink recommend resources that better fit your needs.</p>
        <span class="field-label">Preferred Categories</span>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px;">
            <?php foreach ($categories as $c): ?>
                <label class="checkbox-label" style="background:var(--bg); border:1px solid var(--line); padding:8px 14px; border-radius:999px; font-size:0.88rem;">
                    <input type="checkbox" name="categories[]" value="<?= e($c['name']) ?>" <?= in_array($c['name'], $selectedCats, true) ? 'checked' : '' ?>>
                    <?= e($c['name']) ?>
                </label>
            <?php endforeach; ?>
        </div>
        <label class="field"><span class="field-label">Preferred Location / Pickup Area</span>
            <input type="text" name="pickup_area" value="<?= e($user['pickup_area'] ?? '') ?>" placeholder="e.g. Uttara, Dhaka">
        </label>
        <label class="field"><span class="field-label">Preferred Condition</span>
            <select name="preferred_condition">
                <?php foreach (['any'=>'Any usable condition','good'=>'Good or better','like_new'=>'Like New or better','new'=>'New only'] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= $user['preferred_condition'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn-accent" type="submit">Save Preferences</button>
    </form>

<?php elseif ($tab === 'notifications'): ?>
    <form method="post" class="mini-card" style="max-width:640px;">
        <input type="hidden" name="action" value="notifications">
        <h3 style="margin-top:0;">Notification Preferences</h3>
        <p style="color:var(--ink-soft);">Choose which EcoLink updates you want to receive.</p>
        <label class="checkbox-label" style="padding:14px 0; border-bottom:1px solid var(--line);">
            <input type="checkbox" name="notify_matching" <?= $user['notify_matching'] ? 'checked' : '' ?>>
            <span><strong>Matching updates</strong><br><small style="color:var(--ink-soft);">New matches found for your resources or requests.</small></span>
        </label>
        <label class="checkbox-label" style="padding:14px 0; border-bottom:1px solid var(--line);">
            <input type="checkbox" name="notify_lifecycle" <?= $user['notify_lifecycle'] ? 'checked' : '' ?>>
            <span><strong>Lifecycle updates</strong><br><small style="color:var(--ink-soft);">Status changes from matched through completed.</small></span>
        </label>
        <label class="checkbox-label" style="padding:14px 0; border-bottom:1px solid var(--line); margin-bottom:18px;">
            <input type="checkbox" name="notify_reminders" <?= $user['notify_reminders'] ? 'checked' : '' ?>>
            <span><strong>Pickup reminders</strong><br><small style="color:var(--ink-soft);">Reminders to coordinate or complete a pickup.</small></span>
        </label>
        <button class="btn-accent" type="submit">Save Notification Settings</button>
    </form>

<?php else: ?>
    <form method="post" class="mini-card" style="max-width:520px;">
        <input type="hidden" name="action" value="security">
        <h3 style="margin-top:0;">Change Password</h3>
        <p style="color:var(--ink-soft);">Use a strong password you don't reuse elsewhere.</p>
        <label class="field"><span class="field-label">Current Password</span>
            <input type="password" name="current_password" required>
        </label>
        <label class="field"><span class="field-label">New Password</span>
            <input type="password" name="new_password" id="npw" required>
        </label>
        <label class="field"><span class="field-label">Confirm New Password</span>
            <input type="password" name="confirm_password" required>
        </label>
        <ul class="pw-reqs">
            <li id="req-len">At least 8 characters</li>
            <li id="req-mix">One uppercase letter and one number</li>
        </ul>
        <button class="btn-accent" type="submit">Update Password</button>
    </form>
    <script>
    var npw = document.getElementById('npw');
    npw.addEventListener('input', function () {
        document.getElementById('req-len').classList.toggle('ok', npw.value.length >= 8);
        document.getElementById('req-mix').classList.toggle('ok', /[A-Z]/.test(npw.value) && /[0-9]/.test(npw.value));
    });
    </script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
