<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('receiver');

$db = getDB();
$uid = currentUserId();

$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 6;

$where = ["r.receiver_id = ?"];
$params = [$uid];
if ($search !== '') { $where[] = "r.title LIKE ?"; $params[] = "%$search%"; }
if ($category !== '') { $where[] = "c.name = ?"; $params[] = $category; }
if ($status !== '') { $where[] = "r.status = ?"; $params[] = $status; }
$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare(
    "SELECT COUNT(*) c FROM requests r JOIN resource_categories c ON c.category_id = r.category_id WHERE $whereSql"
);
$countStmt->execute($params);
$total = (int)$countStmt->fetch()['c'];

$offset = ($page - 1) * $perPage;
$stmt = $db->prepare(
    "SELECT r.*, c.name AS category_name, d.status AS donation_status, u.full_name AS donor_name
     FROM requests r
     JOIN resource_categories c ON c.category_id = r.category_id
     LEFT JOIN donations d ON d.donation_id = r.matched_donation_id
     LEFT JOIN users u ON u.user_id = d.donor_id
     WHERE $whereSql ORDER BY r.created_at DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$categories = $db->query("SELECT name FROM resource_categories ORDER BY name")->fetchAll();

$summary = $db->prepare(
    "SELECT
        COUNT(*) AS total_requests,
        SUM(status IN ('completed')) AS fulfilled,
        SUM(status IN ('matched','accepted','pickup','delivered')) AS active,
        (SELECT COALESCE(SUM(quantity),0) FROM impact_log WHERE beneficiary_id = ?) AS resources_received
     FROM requests WHERE receiver_id = ?"
);
$summary->execute([$uid, $uid]);
$summary = $summary->fetch();

$pageTitle = 'Request History';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Request History</h1>
<p>Review your previous resource requests, matches, and completed deliveries.</p>

<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Total Requests</span><strong><?= (int)$summary['total_requests'] ?></strong><span>requests</span></div>
    <div class="mini-card"><span class="eyebrow">Fulfilled</span><strong><?= (int)$summary['fulfilled'] ?></strong><span>completed</span></div>
    <div class="mini-card"><span class="eyebrow">Active</span><strong><?= (int)$summary['active'] ?></strong><span>in progress</span></div>
    <div class="mini-card"><span class="eyebrow">Resources Received</span><strong><?= (int)$summary['resources_received'] ?></strong><span>items / units</span></div>
</section>

<h2>All Requests</h2>
<form method="get" class="filter-bar">
    <label>Search
        <input type="text" name="search" placeholder="Search request..." value="<?= e($search) ?>">
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
            <?php foreach (['searching','matched','accepted','pickup','delivered','completed','cancelled','declined'] as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn-primary" type="submit">Apply Filters</button>
</form>

<table class="data-table">
    <thead><tr><th>Request</th><th>Category</th><th>Quantity</th><th>Match / Donor</th><th>Status</th><th>Date</th><th>View</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <?php $displayStatus = $r['donation_status'] ?? $r['status']; ?>
        <tr>
            <td><strong><?= e($r['title']) ?></strong></td>
            <td><?= e($r['category_name']) ?></td>
            <td><?= (int)$r['quantity_min'] ?>–<?= (int)$r['quantity_max'] ?></td>
            <td><?= $r['donor_name'] ? e($r['donor_name']) : '—' ?></td>
            <td><span class="badge badge-<?= e($displayStatus === 'searching' ? 'available' : $displayStatus) ?>"><?= e(ucfirst($displayStatus)) ?></span></td>
            <td><?= e(date('M d, Y', strtotime($r['created_at']))) ?></td>
            <td><a href="<?= BASE_URL ?>/receiver/request_tracking.php?request_id=<?= (int)$r['request_id'] ?>">→</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7">No requests found.</td></tr><?php endif; ?>
    </tbody>
</table>

<div style="display:flex; justify-content:space-between; align-items:center;">
    <span style="color:var(--ink-soft);">Showing <?= count($rows) ? $offset + 1 : 0 ?>–<?= $offset + count($rows) ?> of <?= $total ?> requests</span>
    <div style="display:flex; gap:6px;">
        <?php for ($p = 1; $p <= max(1, ceil($total / $perPage)); $p++): ?>
            <a class="btn-small <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
</div>

<div class="alert" style="background:var(--mint); border:1px solid var(--mint-line); color:var(--forest-2); margin-top:20px;">
    <strong>Request history keeps your resource journey organized.</strong><br>
    Open any request to review its matched donation, tracking status, and final outcome.
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
