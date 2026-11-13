<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

$db = getDB();
$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90], true)) $days = 30;

$summary = $db->prepare(
    "SELECT
        (SELECT COUNT(*) FROM donations WHERE matched_receiver_id IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)) AS total_matches,
        (SELECT COUNT(*) FROM donations WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)) AS completed,
        (SELECT COUNT(*) FROM requests WHERE status = 'searching') AS unmatched,
        (SELECT AVG(matched_score) FROM donations WHERE matched_score IS NOT NULL) AS avg_score"
);
$summary->execute([$days, $days]);
$summary = $summary->fetch();
$successRate = $summary['total_matches'] > 0 ? round($summary['completed'] / $summary['total_matches'] * 100, 1) : 0;

// Daily trend: matched donations vs new requests
$trend = $db->prepare(
    "SELECT DATE(created_at) d,
            SUM(matched_receiver_id IS NOT NULL) AS matches,
            COUNT(*) AS total
     FROM donations
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
     GROUP BY DATE(created_at) ORDER BY d ASC"
);
$trend->execute([$days]);
$trend = $trend->fetchAll();

$maxTrend = 1;
foreach ($trend as $t) $maxTrend = max($maxTrend, (int)$t['total'], (int)$t['matches']);

/** Build an SVG polyline from a series of values across a 0..100 x 0..100 viewBox. */
function seriesPoints(array $values, int $max): string {
    $n = count($values);
    if ($n === 0) return '';
    $pts = [];
    foreach (array_values($values) as $i => $v) {
        $x = $n === 1 ? 50 : ($i / ($n - 1)) * 100;
        $y = 100 - (($v / max(1, $max)) * 90) - 5;
        $pts[] = round($x, 2) . ',' . round($y, 2);
    }
    return implode(' ', $pts);
}

$byCategory = $db->query(
    "SELECT c.name,
            COUNT(d.donation_id) AS total,
            SUM(d.status = 'completed') AS completed
     FROM resource_categories c
     LEFT JOIN donations d ON d.category_id = c.category_id
     GROUP BY c.category_id, c.name ORDER BY c.name"
)->fetchAll();

$recent = $db->query(
    "SELECT d.*, c.name AS category_name, donor.full_name AS donor_name,
            recv.full_name AS receiver_name, recv.organization_name
     FROM donations d
     JOIN resource_categories c ON c.category_id = d.category_id
     JOIN users donor ON donor.user_id = d.donor_id
     JOIN users recv ON recv.user_id = d.matched_receiver_id
     WHERE d.matched_receiver_id IS NOT NULL
     ORDER BY d.updated_at DESC LIMIT 8"
)->fetchAll();

$pageTitle = 'Matching Analytics';
require_once __DIR__ . '/../includes/header.php';
?>
<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
    <div>
        <h1>Matching Analytics</h1>
        <p>Monitor how efficiently donor resources are matched with receiver requests.</p>
    </div>
    <form method="get">
        <select name="days" onchange="this.form.submit()" style="padding:10px 14px; border:1px solid var(--line); border-radius:10px; font-family:inherit;">
            <option value="7"  <?= $days === 7 ? 'selected' : '' ?>>Last 7 days</option>
            <option value="30" <?= $days === 30 ? 'selected' : '' ?>>Last 30 days</option>
            <option value="90" <?= $days === 90 ? 'selected' : '' ?>>Last 90 days</option>
        </select>
    </form>
</div>

<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Total Matches</span><strong><?= (int)$summary['total_matches'] ?></strong><span>in the selected period</span></div>
    <div class="mini-card"><span class="eyebrow">Success Rate</span><strong><?= $successRate ?>%</strong><span style="color:var(--forest-2);">matches reaching completion</span></div>
    <div class="mini-card"><span class="eyebrow">Avg. Match Score</span><strong><?= $summary['avg_score'] !== null ? round((float)$summary['avg_score']) : 0 ?></strong><span>out of 100</span></div>
    <div class="mini-card"><span class="eyebrow">Unmatched Requests</span><strong><?= (int)$summary['unmatched'] ?></strong><span style="color:var(--amber-text);">needs attention</span></div>
</section>

<div class="impact-grid">
    <div class="chart-card">
        <h3>Matching Performance Trend</h3>
        <p class="sub">Successful matches and total resources over the selected period</p>
        <div class="chart-legend">
            <span><span class="dot" style="background:var(--forest-2);"></span>Successful matches</span>
            <span><span class="dot" style="background:#c3cdc6;"></span>Total resources</span>
        </div>
        <?php if ($trend): ?>
            <svg class="line-chart" viewBox="0 0 100 100" preserveAspectRatio="none">
                <?php for ($i = 0; $i <= 4; $i++): $y = $i * 25; ?>
                    <line class="grid-line" x1="0" y1="<?= $y ?>" x2="100" y2="<?= $y ?>" vector-effect="non-scaling-stroke"/>
                <?php endfor; ?>
                <polyline fill="none" stroke="#c3cdc6" stroke-width="2" stroke-dasharray="4 3" vector-effect="non-scaling-stroke"
                          points="<?= seriesPoints(array_column($trend, 'total'), $maxTrend) ?>"/>
                <polyline fill="none" stroke="#1f5c33" stroke-width="2.5" vector-effect="non-scaling-stroke"
                          points="<?= seriesPoints(array_column($trend, 'matches'), $maxTrend) ?>"/>
            </svg>
            <div style="display:flex; justify-content:space-between; font-size:0.78rem; color:var(--ink-soft); margin-top:8px;">
                <span><?= e(date('M d', strtotime($trend[0]['d']))) ?></span>
                <span><?= e(date('M d', strtotime($trend[count($trend)-1]['d']))) ?></span>
            </div>
        <?php else: ?>
            <p style="color:var(--ink-soft);">No matching activity in this period yet.</p>
        <?php endif; ?>
    </div>

    <div class="chart-card">
        <h3>By Resource Category</h3>
        <p class="sub">Match success rate</p>
        <div class="progress-list">
            <?php foreach ($byCategory as $c):
                $rate = $c['total'] > 0 ? round($c['completed'] / $c['total'] * 100) : 0; ?>
                <div class="progress-item">
                    <span><?= e($c['name']) ?></span>
                    <span class="progress-track"><span style="width: <?= $rate ?>%;"></span></span>
                    <span class="val"><?= $rate ?>%</span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<h2>Recent Matching Activity</h2>
<p style="color:var(--ink-soft); margin-top:-12px;">Latest matches generated by the recommendation engine</p>
<table class="data-table">
    <thead><tr><th>Match ID</th><th>Category</th><th>Donor → Receiver</th><th>Match Score</th><th>Lifecycle</th><th>Updated</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
        <tr>
            <td><strong>#EL-<?= str_pad((string)$r['donation_id'], 4, '0', STR_PAD_LEFT) ?></strong></td>
            <td><?= e($r['category_name']) ?></td>
            <td><?= e($r['donor_name']) ?> → <?= e($r['organization_name'] ?: $r['receiver_name']) ?></td>
            <td><?= $r['matched_score'] !== null ? '<span class="pct-badge">' . (int)$r['matched_score'] . '%</span>' : '—' ?></td>
            <td><span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td><?= e(date('M d, H:i', strtotime($r['updated_at']))) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="6">No matches generated yet.</td></tr><?php endif; ?>
    </tbody>
</table>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
