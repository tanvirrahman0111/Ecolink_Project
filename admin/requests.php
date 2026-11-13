<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

$db = getDB();

$search   = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';
$status   = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 8;

$where = ['1=1']; $params = [];
if ($search !== '')   { $where[] = "(r.title LIKE ? OR u.full_name LIKE ? OR u.organization_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($category !== '') { $where[] = "c.name = ?"; $params[] = $category; }
if ($status !== '')   { $where[] = "r.status = ?"; $params[] = $status; }
if ($priority !== '') { $where[] = "r.priority = ?"; $params[] = $priority; }
$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare(
    "SELECT COUNT(*) c FROM requests r
     JOIN resource_categories c ON c.category_id = r.category_id
     JOIN users u ON u.user_id = r.receiver_id WHERE $whereSql"
);
$countStmt->execute($params);
$total = (int)$countStmt->fetch()['c'];
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    "SELECT r.*, c.name AS category_name, u.full_name AS receiver_name, u.organization_name
     FROM requests r
     JOIN resource_categories c ON c.category_id = r.category_id
     JOIN users u ON u.user_id = r.receiver_id
     WHERE $whereSql ORDER BY r.created_at DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$categories = $db->query("SELECT name FROM resource_categories ORDER BY name")->fetchAll();

$stats = $db->query(
    "SELECT COUNT(*) AS total_requests,
            SUM(status = 'searching') AS open_requests,
            SUM(status IN ('matched','accepted','pickup','delivered')) AS matched,
            SUM(status = 'completed') AS completed
     FROM requests"
)->fetch();
$totalReq = max(1, (int)$stats['total_requests']);

$pageTitle = 'Request Management';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Request Management</h1>
<p>Monitor receiver requests and their matching status across EcoLink.</p>

<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Total Requests</span><strong><?= (int)$stats['total_requests'] ?></strong><span>all time</span></div>
    <div class="mini-card"><span class="eyebrow">Open Requests</span><strong><?= (int)$stats['open_requests'] ?></strong><span style="color:var(--amber-text);">still searching</span></div>
    <div class="mini-card"><span class="eyebrow">Matched</span><strong><?= (int)$stats['matched'] ?></strong><span><?= round((int)$stats['matched'] / $totalReq * 100) ?>% of requests</span></div>
    <div class="mini-card"><span class="eyebrow">Completed / Fulfilled</span><strong><?= (int)$stats['completed'] ?></strong><span><?= round((int)$stats['completed'] / $totalReq * 100) ?>% overall</span></div>
</section>

<form method="get" class="filter-bar">
    <label>Search<input type="text" name="search" placeholder="Request, receiver, or resource..." value="<?= e($search) ?>"></label>
    <label>Category
        <select name="category">
            <option value="">All Categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= e($c['name']) ?>" <?= $category === $c['name'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Status
        <select name="status">
            <option value="">All Statuses</option>
            <?php foreach (['searching','matched','accepted','pickup','delivered','completed','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Priority
        <select name="priority">
            <option value="">Any</option>
            <?php foreach (['normal','high','urgent'] as $p): ?>
                <option value="<?= $p ?>" <?= $priority === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn-accent" type="submit">Apply Filters</button>
</form>

<h2>Receiver Requests</h2>
<table class="data-table">
    <thead><tr><th>Request</th><th>Receiver</th><th>Resource Needed</th><th>Qty</th><th>Priority</th><th>Status</th><th>Match</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong style="color:var(--forest-2);">REQ-<?= str_pad((string)$r['request_id'], 4, '0', STR_PAD_LEFT) ?></strong></td>
            <td><?= e($r['organization_name'] ?: $r['receiver_name']) ?></td>
            <td><?= e($r['title']) ?></td>
            <td><?= (int)$r['quantity_min'] ?>–<?= (int)$r['quantity_max'] ?></td>
            <td><span class="badge badge-<?= e($r['priority']) ?>"><?= e(ucfirst($r['priority'])) ?></span></td>
            <td><span class="badge badge-<?= e($r['status'] === 'searching' ? 'searching' : $r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td><?= $r['match_score'] !== null ? '<strong style="color:var(--forest-2);">' . (int)$r['match_score'] . '%</strong>' : '—' ?></td>
            <td><?= e(date('M d, Y', strtotime($r['created_at']))) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8">No requests match these filters.</td></tr><?php endif; ?>
    </tbody>
</table>

<div style="display:flex; justify-content:space-between; align-items:center;">
    <span style="color:var(--ink-soft);">Showing <?= count($rows) ? $offset + 1 : 0 ?>–<?= $offset + count($rows) ?> of <?= $total ?> requests</span>
    <div style="display:flex; gap:6px;">
        <?php for ($p = 1; $p <= max(1, ceil($total / $perPage)); $p++): ?>
            <a class="btn-small <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
