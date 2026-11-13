<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/hub.php';
requireRole('admin');

$db = getDB();
ensureHubTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $hubId  = (int)($_POST['hub_id'] ?? 0);
    $name   = trim($_POST['name'] ?? '');
    $area   = trim($_POST['area'] ?? '');
    $addr   = trim($_POST['address'] ?? '');
    $phone  = trim($_POST['phone'] ?? '');
    $hours  = trim($_POST['open_hours'] ?? '');

    if ($action === 'create' || $action === 'update') {
        if ($name === '' || $area === '' || $addr === '') {
            flash('error', 'Hub name, area and address are required.');
        } elseif ($action === 'create') {
            $db->prepare("INSERT INTO delivery_hubs (name, area, address, phone, open_hours) VALUES (?, ?, ?, ?, ?)")
               ->execute([$name, $area, $addr, $phone ?: null, $hours ?: null]);
            flash('success', 'Delivery hub "' . $name . '" created. Donors in ' . $area . ' can now drop items there.');
        } else {
            $db->prepare("UPDATE delivery_hubs SET name = ?, area = ?, address = ?, phone = ?, open_hours = ? WHERE hub_id = ?")
               ->execute([$name, $area, $addr, $phone ?: null, $hours ?: null, $hubId]);
            flash('success', 'Hub updated.');
        }
    } elseif ($action === 'make_staff') {
        $sName = trim($_POST['staff_name'] ?? '');
        $sMail = strtolower(trim($_POST['staff_email'] ?? ''));
        $sPhone = trim($_POST['staff_phone'] ?? '');
        $sPass = $_POST['staff_password'] ?? '';
        $dup = $db->prepare("SELECT 1 FROM users WHERE email = ?");
        $dup->execute([$sMail]);
        if ($sName === '' || !filter_var($sMail, FILTER_VALIDATE_EMAIL) || strlen($sPass) < 6) {
            flash('error', 'Staff name, a valid email and a password of at least 6 characters are required.');
        } elseif ($dup->fetchColumn()) {
            flash('error', 'That email is already registered.');
        } else {
            $db->beginTransaction();
            try {
                $db->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, is_verified, verification_status, email_verified)
                              VALUES (?, ?, ?, ?, 'hub', 1, 'verified', 1)")
                   ->execute([$sName, $sMail, $sPhone, password_hash($sPass, PASSWORD_DEFAULT)]);
                $newId = (int)$db->lastInsertId();
                $db->prepare("UPDATE delivery_hubs SET manager_user_id = COALESCE(manager_user_id, ?) WHERE hub_id = ?")->execute([$newId, $hubId]);
                $db->prepare("INSERT INTO hub_staff (user_id, hub_id, status) VALUES (?, ?, 'approved')")->execute([$newId, $hubId]);
                $db->commit();
                flash('success', 'Hub staff account created. They can log in with "Hub Staff" at the login page using ' . $sMail . '.');
            } catch (Throwable $e) { $db->rollBack(); flash('error', 'Could not create the account.'); }
        }
    } elseif ($action === 'reset_staff_pw') {
        $sPass = $_POST['staff_password'] ?? '';
        $sUid = (int)($_POST['staff_user_id'] ?? 0);
        if (strlen($sPass) < 6) {
            flash('error', 'Password must be at least 6 characters.');
        } else {
            $db->prepare("UPDATE users u JOIN hub_staff s ON s.user_id = u.user_id SET u.password_hash = ? WHERE u.user_id = ? AND s.hub_id = ? AND u.role = 'hub'")
               ->execute([password_hash($sPass, PASSWORD_DEFAULT), $sUid, $hubId]);
            flash('success', 'Hub staff password updated.');
        }
    } elseif ($action === 'approve_staff' || $action === 'reject_staff' || $action === 'remove_staff') {
        $sUid = (int)($_POST['staff_user_id'] ?? 0);
        if ($action === 'approve_staff') {
            $db->prepare("UPDATE hub_staff SET status = 'approved' WHERE user_id = ?")->execute([$sUid]);
            $db->prepare("UPDATE users SET is_verified = 1, verification_status = 'verified' WHERE user_id = ? AND role = 'hub'")->execute([$sUid]);
            hubNotify($db, $sUid, 'Hub staff approved', 'Your hub staff account is approved. You can now use the Hub Desk.');
            flash('success', 'Hub staff approved.');
        } elseif ($action === 'reject_staff') {
            $db->prepare("UPDATE hub_staff SET status = 'rejected' WHERE user_id = ?")->execute([$sUid]);
            hubNotify($db, $sUid, 'Hub staff request declined', 'Your hub staff request was not approved. Please contact the EcoLink admin.');
            flash('success', 'Request rejected.');
        } else {
            $db->prepare("UPDATE hub_staff SET status = 'rejected' WHERE user_id = ?")->execute([$sUid]);
            flash('success', 'Staff access removed.');
        }
    } elseif ($action === 'toggle') {
        $db->prepare("UPDATE delivery_hubs SET is_active = 1 - is_active WHERE hub_id = ?")->execute([$hubId]);
        flash('success', 'Hub status changed.');
    } elseif ($action === 'delete') {
        $used = $db->prepare("SELECT COUNT(*) FROM hub_items WHERE hub_id = ?");
        $used->execute([$hubId]);
        if ((int)$used->fetchColumn() > 0) {
            flash('error', 'This hub has item history, so it cannot be deleted. Deactivate it instead.');
        } else {
            $db->prepare("DELETE FROM delivery_hubs WHERE hub_id = ?")->execute([$hubId]);
            flash('success', 'Hub deleted.');
        }
    }
    redirect('/admin/hubs.php');
}

$hubs = $db->query(
    "SELECT h.*,
            SUM(i.status = 'awaiting_dropoff') AS awaiting,
            SUM(i.status = 'stored')           AS stored,
            SUM(i.status = 'collected')        AS collected,
            mu.full_name AS staff_name, mu.email AS staff_email
     FROM delivery_hubs h LEFT JOIN hub_items i ON i.hub_id = h.hub_id
          LEFT JOIN users mu ON mu.user_id = h.manager_user_id
     GROUP BY h.hub_id, mu.user_id ORDER BY h.is_active DESC, h.area, h.name"
)->fetchAll();

$pending = $db->query("SELECT s.user_id, s.hub_id, u.full_name, u.email, u.phone, h.name AS hub_name, h.area
                       FROM hub_staff s JOIN users u ON u.user_id = s.user_id JOIN delivery_hubs h ON h.hub_id = s.hub_id
                       WHERE s.status = 'pending' ORDER BY s.created_at")->fetchAll();
$staffByHub = [];
foreach ($db->query("SELECT s.hub_id, s.user_id, u.full_name, u.email FROM hub_staff s JOIN users u ON u.user_id = s.user_id WHERE s.status = 'approved' ORDER BY u.full_name")->fetchAll() as $r) {
    $staffByHub[$r['hub_id']][] = $r;
}

$pageTitle = 'Delivery Hubs';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Delivery Hubs</h1>
<p>Create an area-based hub where several donors can leave their items and receivers collect them with a Pickup ID. This replaces one-to-one delivery coordination.</p>

<?php if ($pending): ?>
<div class="mini-card" style="margin-bottom:24px; border-color:var(--green);">
    <h2 style="margin-top:0;">🧑‍💼 Hub staff waiting for approval (<?= count($pending) ?>)</h2>
    <?php foreach ($pending as $p): ?>
        <div style="display:flex; gap:12px; flex-wrap:wrap; justify-content:space-between; align-items:center; padding:8px 0; border-top:1px solid var(--line);">
            <div><strong><?= e($p['full_name']) ?></strong> · <?= e($p['email']) ?><?= $p['phone'] ? ' · ' . e($p['phone']) : '' ?><br>
                <small>wants to work at <strong><?= e($p['hub_name']) ?></strong> (<?= e($p['area']) ?>)</small></div>
            <div style="display:flex; gap:8px;">
                <form method="post"><input type="hidden" name="action" value="approve_staff"><input type="hidden" name="staff_user_id" value="<?= (int)$p['user_id'] ?>"><button class="btn-small btn-accent" type="submit">Approve</button></form>
                <form method="post"><input type="hidden" name="action" value="reject_staff"><input type="hidden" name="staff_user_id" value="<?= (int)$p['user_id'] ?>"><button class="btn-small btn-secondary" type="submit">Reject</button></form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="mini-card" style="margin-bottom:24px;">
    <h2 style="margin-top:0;">➕ Create a delivery hub</h2>
    <form method="post" class="form-grid">
        <input type="hidden" name="action" value="create">
        <div class="two-col">
            <label>Hub name <input type="text" name="name" placeholder="e.g. Uttara Community Hub" required maxlength="120"></label>
            <label>Area it serves <input type="text" name="area" placeholder="e.g. Uttara, Dhaka" required maxlength="120"></label>
        </div>
        <label>Hub address <input type="text" name="address" placeholder="e.g. House 12, Sector 7, Uttara" required maxlength="255"></label>
        <div class="two-col">
            <label>Contact phone <input type="text" name="phone" placeholder="+880 1XXXXXXXXX" maxlength="40"></label>
            <label>Open hours <input type="text" name="open_hours" placeholder="e.g. 10 AM – 8 PM, Sat–Thu" maxlength="120"></label>
        </div>
        <button class="btn-primary" type="submit" style="align-self:flex-start;">Create Hub</button>
    </form>
</div>

<h2>All hubs (<?= count($hubs) ?>)</h2>
<?php foreach ($hubs as $h): ?>
    <div class="mini-card" style="margin-bottom:14px;">
        <div style="display:flex; gap:16px; flex-wrap:wrap; justify-content:space-between; align-items:flex-start;">
            <div style="flex:1; min-width:240px;">
                <span class="eyebrow">📍 <?= e($h['area']) ?></span>
                <h3 style="margin:4px 0;"><?= e($h['name']) ?>
                    <span class="badge <?= $h['is_active'] ? 'badge-completed' : 'badge-cancelled' ?>"><?= $h['is_active'] ? 'Active' : 'Inactive' ?></span></h3>
                <p style="margin:4px 0; color:var(--ink-soft);"><?= e($h['address']) ?><?= $h['phone'] ? ' · 📞 ' . e($h['phone']) : '' ?><?= $h['open_hours'] ? ' · 🕒 ' . e($h['open_hours']) : '' ?></p>
                <p style="margin:6px 0 0;">
                    <span class="badge badge-accepted"><?= (int)$h['awaiting'] ?> awaiting drop-off</span>
                    <span class="badge badge-pickup"><?= (int)$h['stored'] ?> stored</span>
                    <span class="badge badge-completed"><?= (int)$h['collected'] ?> collected</span>
                </p>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a class="btn-small btn-accent" href="<?= BASE_URL ?>/admin/hub_desk.php?hub=<?= (int)$h['hub_id'] ?>">Open Hub Desk</a>
                <form method="post"><input type="hidden" name="action" value="toggle"><input type="hidden" name="hub_id" value="<?= (int)$h['hub_id'] ?>">
                    <button class="btn-small btn-secondary" type="submit"><?= $h['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
                <form method="post" onsubmit="return confirm('Delete this hub?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="hub_id" value="<?= (int)$h['hub_id'] ?>">
                    <button class="btn-small btn-secondary" type="submit">Delete</button></form>
            </div>
        </div>
        <div style="margin-top:12px; padding:12px; border:1px dashed var(--line); border-radius:12px;">
            <strong>👤 Hub staff</strong>
            <?php foreach ($staffByHub[$h['hub_id']] ?? [] as $sf): ?>
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:6px 0;">
                    <span><?= e($sf['full_name']) ?> · <code><?= e($sf['email']) ?></code></span>
                    <form method="post" style="display:flex; gap:6px;">
                        <input type="hidden" name="action" value="reset_staff_pw"><input type="hidden" name="hub_id" value="<?= (int)$h['hub_id'] ?>"><input type="hidden" name="staff_user_id" value="<?= (int)$sf['user_id'] ?>">
                        <input type="text" name="staff_password" placeholder="New password" minlength="6" required style="width:140px;">
                        <button class="btn-small btn-secondary" type="submit">Reset password</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Remove this staff member?')"><input type="hidden" name="action" value="remove_staff"><input type="hidden" name="staff_user_id" value="<?= (int)$sf['user_id'] ?>"><button class="btn-small btn-secondary" type="submit">Remove</button></form>
                </div>
            <?php endforeach; ?>
            <?php if (empty($staffByHub[$h['hub_id']])): ?><p style="margin:4px 0; color:var(--ink-soft);">No staff yet. Staff can sign up on the Register page (Hub Staff) and you approve them here, or create an account below.</p><?php endif; ?>
            <details style="margin-top:6px;">
                <summary style="cursor:pointer; color:var(--forest-2); font-weight:600;">Create a staff account manually</summary>
                <form method="post" class="form-grid" style="margin-top:8px;">
                    <input type="hidden" name="action" value="make_staff"><input type="hidden" name="hub_id" value="<?= (int)$h['hub_id'] ?>">
                    <div class="two-col">
                        <label>Staff name <input type="text" name="staff_name" required></label>
                        <label>Email (login) <input type="email" name="staff_email" required></label>
                    </div>
                    <div class="two-col">
                        <label>Phone <input type="text" name="staff_phone"></label>
                        <label>Password <input type="text" name="staff_password" minlength="6" required></label>
                    </div>
                    <button class="btn-accent" type="submit" style="align-self:flex-start;">Create hub staff account</button>
                </form>
            </details>
        </div>
        <details style="margin-top:10px;">
            <summary style="cursor:pointer; color:var(--forest-2); font-weight:600;">Edit details</summary>
            <form method="post" class="form-grid" style="margin-top:10px;">
                <input type="hidden" name="action" value="update"><input type="hidden" name="hub_id" value="<?= (int)$h['hub_id'] ?>">
                <div class="two-col">
                    <label>Name <input type="text" name="name" value="<?= e($h['name']) ?>" required></label>
                    <label>Area <input type="text" name="area" value="<?= e($h['area']) ?>" required></label>
                </div>
                <label>Address <input type="text" name="address" value="<?= e($h['address']) ?>" required></label>
                <div class="two-col">
                    <label>Phone <input type="text" name="phone" value="<?= e((string)$h['phone']) ?>"></label>
                    <label>Open hours <input type="text" name="open_hours" value="<?= e((string)$h['open_hours']) ?>"></label>
                </div>
                <button class="btn-accent" type="submit" style="align-self:flex-start;">Save changes</button>
            </form>
        </details>
    </div>
<?php endforeach; ?>
<?php if (!$hubs): ?><p style="color:var(--ink-soft);">No hubs yet. Create the first one above.</p><?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
