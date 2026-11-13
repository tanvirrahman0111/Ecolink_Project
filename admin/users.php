<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'suspend') {
        $db->prepare("UPDATE users SET status = 'suspended' WHERE user_id = ?")->execute([$userId]);
        flash('success', 'Account restricted.');
    } elseif ($action === 'reactivate') {
        $db->prepare("UPDATE users SET status = 'active' WHERE user_id = ?")->execute([$userId]);
        flash('success', 'Account reactivated.');
    } elseif ($action === 'set_capacity') {
        $capacity = max(1, min(10, (int)($_POST['capacity_score'] ?? 5)));
        $db->prepare("UPDATE users SET capacity_score = ? WHERE user_id = ?")->execute([$capacity, $userId]);
        flash('success', 'Capacity score updated.');
    }
    redirect('/admin/users.php');
}

$search = trim($_GET['search'] ?? '');
$role   = $_GET['role'] ?? '';
$verification = $_GET['verification'] ?? '';
$status = $_GET['status'] ?? '';

$where = ["role IN ('donor','receiver','hub')"];
$params = [];
if ($search !== '') { $where[] = "(full_name LIKE ? OR email LIKE ? OR organization_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($role !== '')   { $where[] = "role = ?"; $params[] = $role; }
if ($verification !== '') { $where[] = "verification_status = ?"; $params[] = $verification; }
if ($status !== '') { $where[] = "status = ?"; $params[] = $status; }
$whereSql = implode(' AND ', $where);

$stmt = $db->prepare("SELECT * FROM users WHERE $whereSql ORDER BY created_at DESC LIMIT 40");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stats = $db->query(
    "SELECT
        SUM(role IN ('donor','receiver')) AS total_users,
        SUM(role = 'donor') AS donors,
        SUM(role = 'receiver') AS receivers,
        SUM(role IN ('donor','receiver','hub') AND verification_status = 'pending') AS pending
     FROM users"
)->fetch();

$pageTitle = 'Users & Organizations';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>User &amp; Organization Management</h1>
<p>Review registered donors and receivers, verification status, and account activity.</p>

<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Total Users</span><strong><?= (int)$stats['total_users'] ?></strong><span>Donors + Receivers</span></div>
    <div class="mini-card"><span class="eyebrow">Donors</span><strong><?= (int)$stats['donors'] ?></strong><span>registered donor accounts</span></div>
    <div class="mini-card"><span class="eyebrow">Receivers</span><strong><?= (int)$stats['receivers'] ?></strong><span>registered receiver accounts</span></div>
    <div class="mini-card"><span class="eyebrow">Pending Verification</span><strong><?= (int)$stats['pending'] ?></strong><span>need admin review</span></div>
</section>

<form method="get" class="filter-bar">
    <label>Search<input type="text" name="search" placeholder="Search by name, email or organization" value="<?= e($search) ?>"></label>
    <label>Role
        <select name="role">
            <option value="">All</option>
            <option value="donor" <?= $role === 'donor' ? 'selected' : '' ?>>Donor</option>
            <option value="receiver" <?= $role === 'receiver' ? 'selected' : '' ?>>Receiver</option>
            <option value="hub" <?= $role === 'hub' ? 'selected' : '' ?>>Hub Staff</option>
        </select>
    </label>
    <label>Verification
        <select name="verification">
            <option value="">All</option>
            <?php foreach (['pending','verified','rejected'] as $v): ?>
                <option value="<?= $v ?>" <?= $verification === $v ? 'selected' : '' ?>><?= ucfirst($v) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Status
        <select name="status">
            <option value="">All</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Restricted</option>
        </select>
    </label>
    <button class="btn-accent" type="submit">Apply</button>
</form>

<h2>Registered Users</h2>
<table class="data-table">
    <thead><tr><th>User</th><th>Role</th><th>Location</th><th>Verification</th><th>Account Status</th><th>Capacity</th><th>Joined</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u): ?>
        <tr>
            <td><strong><?= e($u['organization_name'] ?: $u['full_name']) ?></strong><br><small><?= e($u['email']) ?></small></td>
            <td><?= $u['role'] === 'hub' ? 'Hub Staff' : e(ucfirst($u['role'])) ?></td>
            <td><?= e($u['address'] ?: '—') ?></td>
            <td><span class="badge badge-<?= e($u['verification_status']) ?>"><?= e(ucfirst($u['verification_status'])) ?></span></td>
            <td><span class="badge badge-<?= $u['status'] === 'active' ? 'active' : 'restricted' ?>"><?= $u['status'] === 'active' ? 'Active' : 'Restricted' ?></span></td>
            <td>
                <?php if ($u['role'] === 'receiver'): ?>
                    <form method="post" style="display:flex; gap:4px;">
                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                        <input type="hidden" name="action" value="set_capacity">
                        <input type="number" name="capacity_score" min="1" max="10" value="<?= (int)$u['capacity_score'] ?>" style="width:52px; padding:4px; border:1px solid var(--line); border-radius:6px;">
                        <button class="btn-small btn-secondary" type="submit">Set</button>
                    </form>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= e(date('M d, Y', strtotime($u['created_at']))) ?></td>
            <td>
                <?php if ($u['status'] === 'active'): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                        <button class="btn-small btn-secondary" name="action" value="suspend" type="submit">Restrict</button>
                    </form>
                <?php else: ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                        <button class="btn-small btn-accent" name="action" value="reactivate" type="submit">Reactivate</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8">No users match these filters.</td></tr><?php endif; ?>
    </tbody>
</table>

<div class="mini-card">
    <h3 style="margin-top:0;">Verification Workflow</h3>
    <p style="color:var(--ink-soft);">Admin review → verified account → active participation</p>
    <a class="btn-accent" href="<?= BASE_URL ?>/admin/verify_users.php">Open Verification Queue</a>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
