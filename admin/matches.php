<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

$db = getDB();

$search   = trim($_GET['search'] ?? '');
$status   = $_GET['status'] ?? '';
$category = $_GET['category'] ?? '';
$minScore = (int)($_GET['min_score'] ?? 0);

$where = ["d.matched_receiver_id IS NOT NULL"];
$params = [];
if ($search !== '')   { $where[] = "(d.title LIKE ? OR donor.full_name LIKE ? OR recv.full_name LIKE ? OR recv.organization_name LIKE ?)";
                        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($status !== '')   { $where[] = "d.status = ?"; $params[] = $status; }
if ($category !== '') { $where[] = "c.name = ?"; $params[] = $category; }
if ($minScore > 0)    { $where[] = "d.matched_score >= ?"; $params[] = $minScore; }
$whereSql = implode(' AND ', $where);

$stmt = $db->prepare(
    "SELECT d.*, c.name AS category_name,
            donor.full_name AS donor_name,
            recv.full_name AS receiver_name, recv.organization_name
     FROM donations d
     JOIN resource_categories c ON c.category_id = d.category_id
     JOIN users donor ON donor.user_id = d.donor_id
     JOIN users recv ON recv.user_id = d.matched_receiver_id
     WHERE $whereSql ORDER BY d.updated_at DESC LIMIT 30"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$categories = $db->query("SELECT name FROM resource_categories ORDER BY name")->fetchAll();

$stats = $db->query(
    "SELECT
        (SELECT COUNT(*) FROM donations WHERE matched_receiver_id IS NOT NULL AND status NOT IN ('completed','cancelled')) AS active_matches,
        (SELECT COUNT(*) FROM donations WHERE status = 'completed') AS completed,
        (SELECT COUNT(*) FROM donations WHERE matched_receiver_id IS NOT NULL) AS all_matches,
        (SELECT COUNT(*) FROM requests WHERE status = 'searching') AS unmatched_requests,
        (SELECT AVG(matched_score) FROM donations WHERE matched_score IS NOT NULL) AS avg_score"
)->fetch();
$successRate = $stats['all_matches'] > 0 ? round($stats['completed'] / $stats['all_matches'] * 100) : 0;

$pageTitle = 'Match Monitoring';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Match Monitoring</h1>
<p>Monitor EcoLink's intelligent donor–receiver matching and donation lifecycle states.</p>

<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Active Matches</span><strong><?= (int)$stats['active_matches'] ?></strong><span>current matches</span></div>
    <div class="mini-card"><span class="eyebrow">Match Success Rate</span><strong><?= $successRate ?>%</strong><span>completed matches</span></div>
    <div class="mini-card"><span class="eyebrow">Unmatched Requests</span><strong><?= (int)$stats['unmatched_requests'] ?></strong><span>needs suitable resource</span></div>
    <div class="mini-card"><span class="eyebrow">Avg. Match Score</span><strong><?= $stats['avg_score'] !== null ? round((float)$stats['avg_score']) : 0 ?></strong><span>out of 100</span></div>
</section>

<h2>Match Queue</h2>
<form method="get" class="filter-bar">
    <label>Search<input type="text" name="search" placeholder="Donor, receiver, resource..." value="<?= e($search) ?>"></label>
    <label>Status
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach (['matched','accepted','pickup','delivered','completed'] as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Category
        <select name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= e($c['name']) ?>" <?= $category === $c['name'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Min Match Score
        <select name="min_score">
            <option value="0">All scores</option>
            <?php foreach ([90, 80, 70, 60] as $s): ?>
                <option value="<?= $s ?>" <?= $minScore === $s ? 'selected' : '' ?>><?= $s ?>%+</option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn-accent" type="submit">Apply Filters</button>
</form>

<table class="data-table">
    <thead><tr><th>Donor → Receiver</th><th>Resource</th><th>Category</th><th>Match Score</th><th>Lifecycle</th><th>Updated</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong><?= e($r['donor_name']) ?> → <?= e($r['organization_name'] ?: $r['receiver_name']) ?></strong><br><small>Donor → Receiver</small></td>
            <td><?= e($r['title']) ?></td>
            <td><?= e($r['category_name']) ?></td>
            <td><?= $r['matched_score'] !== null ? '<span class="pct-badge">' . (int)$r['matched_score'] . '%</span>' : '—' ?></td>
            <td><span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td><?= e(date('M d, H:i', strtotime($r['updated_at']))) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6">No matches found for these filters.</td></tr><?php endif; ?>
    </tbody>
</table>

<div class="alert" style="background:var(--mint); border:1px solid var(--mint-line); color:var(--forest-2);">
    <strong>Matching visibility</strong><br>
    Admins can monitor match scores, pairing status, and lifecycle progress without manually performing the match.
    The score combines category fit, receiver need, distance and other category-specific factors.
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
