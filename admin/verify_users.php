<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/hub.php';
requireRole('admin');

$db = getDB();
ensureHubTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'verify') {
        $db->prepare("UPDATE users SET is_verified = 1, verification_status = 'verified' WHERE user_id = ?")->execute([$userId]);
        $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'system', ?, ?)")
           ->execute([$userId, 'Account verified', 'Your EcoLink account has been verified by an admin.']);
        // Hub staff are only allowed on the Hub Desk once an admin verified them.
        $db->prepare("UPDATE hub_staff SET status = 'approved' WHERE user_id = ?")->execute([$userId]);
        flash('success', 'Account verified.');
    } elseif ($action === 'reject') {
        $db->prepare("UPDATE users SET is_verified = 0, verification_status = 'rejected' WHERE user_id = ?")->execute([$userId]);
        $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'system', ?, ?)")
           ->execute([$userId, 'Verification not approved', 'Your verification could not be approved. Please review your account details.']);
        $db->prepare("UPDATE hub_staff SET status = 'rejected' WHERE user_id = ?")->execute([$userId]);
        flash('success', 'Verification rejected.');
    }
    redirect('/admin/verify_users.php');
}

$search = trim($_GET['search'] ?? '');
$role   = $_GET['role'] ?? '';
$status = $_GET['status'] ?? 'pending';

$where = ["u.role IN ('donor','receiver','hub')"];
$params = [];
if ($search !== '') { $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.organization_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($role !== '')   { $where[] = "u.role = ?"; $params[] = $role; }
if ($status !== '') { $where[] = "u.verification_status = ?"; $params[] = $status; }
$whereSql = implode(' AND ', $where);

$stmt = $db->prepare("SELECT u.*, dh.name AS hub_name FROM users u
    LEFT JOIN hub_staff hs ON hs.user_id = u.user_id LEFT JOIN delivery_hubs dh ON dh.hub_id = hs.hub_id
    WHERE $whereSql ORDER BY u.created_at DESC LIMIT 60");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stats = $db->query(
    "SELECT
        SUM(role IN ('donor','receiver','hub') AND verification_status = 'pending') AS pending,
        SUM(role = 'hub' AND verification_status = 'pending') AS hubs_pending,
        SUM(role = 'donor' AND verification_status = 'pending') AS donors_pending,
        SUM(role = 'receiver' AND verification_status = 'pending') AS receivers_pending,
        SUM(is_verified = 1) AS verified_total
     FROM users"
)->fetch();

$pageTitle = 'User Verification';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>User Verification</h1>
<p>Verify donors, receivers and delivery hub staff before allowing full platform participation. Hub staff can only use the Hub Desk after you verify them.</p>

<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Pending Verification</span><strong><?= (int)$stats['pending'] ?></strong><span>accounts</span></div>
    <div class="mini-card"><span class="eyebrow">Donors</span><strong><?= (int)$stats['donors_pending'] ?></strong><span>pending</span></div>
    <div class="mini-card"><span class="eyebrow">Receivers</span><strong><?= (int)$stats['receivers_pending'] ?></strong><span>pending</span></div>
    <div class="mini-card"><span class="eyebrow">Hub Staff</span><strong><?= (int)$stats['hubs_pending'] ?></strong><span>pending</span></div>
    <div class="mini-card"><span class="eyebrow">Verified Total</span><strong><?= (int)$stats['verified_total'] ?></strong><span>active accounts</span></div>
</section>

<h2>Verification Queue</h2>
<form method="get" class="filter-bar">
    <label>Search<input type="text" name="search" placeholder="Search user or organization..." value="<?= e($search) ?>"></label>
    <label>Role
        <select name="role">
            <option value="">All roles</option>
            <option value="donor" <?= $role === 'donor' ? 'selected' : '' ?>>Donor</option>
            <option value="receiver" <?= $role === 'receiver' ? 'selected' : '' ?>>Receiver</option>
            <option value="hub" <?= $role === 'hub' ? 'selected' : '' ?>>Hub Staff</option>
        </select>
    </label>
    <label>Status
        <select name="status">
            <option value="">All</option>
            <?php foreach (['pending','verified','rejected'] as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn-accent" type="submit">Apply Filters</button>
</form>

<table class="data-table">
    <thead><tr><th>User / Organization</th><th>Role</th><th>Account Type</th><th>Submitted</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u): ?>
        <tr>
            <td><strong><?= e($u['organization_name'] ?: $u['full_name']) ?></strong><br><small><?= e($u['email']) ?></small></td>
            <td><?= $u['role'] === 'hub' ? 'Hub Staff' : e(ucfirst($u['role'])) ?></td>
            <td><?= $u['role'] === 'hub' ? e('Hub: ' . ($u['hub_name'] ?: '—')) : ($u['organization_name'] ? 'Organization' : 'Individual') ?></td>
            <td><?= e(date('M d, Y', strtotime($u['created_at']))) ?></td>
            <td><span class="badge badge-<?= e($u['verification_status']) ?>"><?= e(ucfirst($u['verification_status'])) ?></span></td>
            <td style="display:flex; gap:6px;">
                <?php if ($u['verification_status'] !== 'verified'): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                        <button class="btn-small btn-accent" name="action" value="verify" type="submit">Verify</button>
                    </form>
                <?php endif; ?>
                <?php if ($u['verification_status'] !== 'rejected'): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                        <button class="btn-small btn-secondary" name="action" value="reject" type="submit">Reject</button>
                    </form>
                <?php endif; ?>
                <a class="btn-small btn-secondary" href="<?= BASE_URL ?>/admin/users.php?search=<?= urlencode($u['email']) ?>">Details</a>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6">No accounts match these filters.</td></tr><?php endif; ?>
    </tbody>
</table>

<div class="alert" style="background:var(--mint); border:1px solid var(--mint-line); color:var(--forest-2);">
    <strong>Verification purpose</strong><br>
    Admin verification helps EcoLink maintain trust, transparency and accountability between donors and receivers.
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
