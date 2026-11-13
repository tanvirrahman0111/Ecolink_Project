<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/media.php';
requireRole('donor');

$db = getDB();
$uid = currentUserId();

$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 6;

$where = ["d.donor_id = ?"];
$params = [$uid];
if ($search !== '') { $where[] = "d.title LIKE ?"; $params[] = "%$search%"; }
if ($category !== '') { $where[] = "c.name = ?"; $params[] = $category; }
if ($status !== '') { $where[] = "d.status = ?"; $params[] = $status; }
$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) c FROM donations d JOIN resource_categories c ON c.category_id = d.category_id WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetch()['c'];

$offset = ($page - 1) * $perPage;
$stmt = $db->prepare(
    "SELECT d.*, c.name AS category_name, u.full_name AS receiver_name
     FROM donations d
     JOIN resource_categories c ON c.category_id = d.category_id
     LEFT JOIN users u ON u.user_id = d.matched_receiver_id
     WHERE $whereSql ORDER BY d.created_at DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$categories = $db->query("SELECT name FROM resource_categories ORDER BY name")->fetchAll();

$summary = $db->prepare(
    "SELECT COUNT(*) AS total, SUM(status='completed') AS completed, SUM(status NOT IN ('completed','cancelled')) AS in_progress,
            (SELECT COALESCE(SUM(il.quantity),0) FROM impact_log il JOIN donations dd ON dd.donation_id = il.donation_id WHERE dd.donor_id = ?) AS reused
     FROM donations WHERE donor_id = ?"
);
$summary->execute([$uid, $uid]);
$summary = $summary->fetch();

$pageTitle = 'Donation History';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Donation History</h1>
<p>View and track all resources you have donated through EcoLink.</p>

<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Total Donations</span><strong><?= (int)$summary['total'] ?></strong><span>resources</span></div>
    <div class="mini-card"><span class="eyebrow">Completed</span><strong><?= (int)$summary['completed'] ?></strong><span>delivered</span></div>
    <div class="mini-card"><span class="eyebrow">In Progress</span><strong><?= (int)$summary['in_progress'] ?></strong><span>active</span></div>
    <div class="mini-card"><span class="eyebrow">Resources Reused</span><strong><?= (int)$summary['reused'] ?></strong><span>items / units</span></div>
</section>

<h2>All Donations</h2>
<form method="get" class="filter-bar">
    <label>Search
        <input type="text" name="search" placeholder="Search donation..." value="<?= e($search) ?>">
    </label>
    <label>Category
        <select name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= e($c['name']) ?>" <?= $category === $c['name'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Status
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach (['available','matched','accepted','pickup','delivered','completed','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn-primary" type="submit">Apply Filters</button>
</form>

<table class="data-table">
    <thead><tr><th>Resource</th><th>Category</th><th>Quantity</th><th>Matched With</th><th>Status</th><th>Date</th><th>View</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong><a href="<?= donationUrl((int)$r['donation_id']) ?>"><?= e($r['title']) ?></a></strong></td>
            <td><?= e($r['category_name']) ?></td>
            <td><?= (int)$r['quantity'] ?> <?= e($r['unit']) ?></td>
            <td><?= $r['receiver_name'] ? e($r['receiver_name']) : '—' ?></td>
            <td><span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td><?= e(date('M d, Y', strtotime($r['created_at']))) ?></td>
            <td><a href="<?= BASE_URL ?>/donor/my_donations.php">→</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7">No donations found.</td></tr><?php endif; ?>
    </tbody>
</table>

<div style="display:flex; justify-content:space-between; align-items:center;">
    <span style="color:var(--ink-soft);">Showing <?= count($rows) ? $offset + 1 : 0 ?>–<?= $offset + count($rows) ?> of <?= $total ?> donations</span>
    <div style="display:flex; gap:6px;">
        <?php for ($p = 1; $p <= max(1, ceil($total / $perPage)); $p++): ?>
            <a class="btn-small <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
</div>

<p style="color:var(--ink-soft); margin-top:16px;">Donation statuses are updated automatically as each resource moves through the EcoLink lifecycle.</p>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
