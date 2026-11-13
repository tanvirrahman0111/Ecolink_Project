<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

$db = getDB();

$impact = $db->query(
    "SELECT
        COALESCE(SUM(quantity),0) AS resources_reused,
        COUNT(DISTINCT beneficiary_id) AS beneficiaries,
        COALESCE(SUM(estimated_co2_saved_kg),0) AS co2_kg,
        COUNT(*) AS completed_donations
     FROM impact_log"
)->fetch();

$orgsHelped = $db->query("SELECT COUNT(DISTINCT beneficiary_id) c FROM impact_log")->fetch()['c'];

$growth = $db->query(
    "SELECT DATE_FORMAT(completed_at, '%b') AS m, DATE_FORMAT(completed_at, '%Y-%m') AS ym,
            COALESCE(SUM(quantity),0) AS reused,
            COUNT(DISTINCT beneficiary_id) AS beneficiaries
     FROM impact_log
     WHERE completed_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY ym, m ORDER BY ym ASC"
)->fetchAll();

$maxGrowth = 1;
foreach ($growth as $g) $maxGrowth = max($maxGrowth, (int)$g['reused'], (int)$g['beneficiaries']);

function svgSeries(array $values, int $max): string {
    $n = count($values);
    if ($n === 0) return '';
    $pts = [];
    foreach (array_values($values) as $i => $v) {
        $x = $n === 1 ? 50 : ($i / ($n - 1)) * 100;
        $y = 100 - (($v / max(1, $max)) * 88) - 6;
        $pts[] = round($x, 2) . ',' . round($y, 2);
    }
    return implode(' ', $pts);
}

$byCategory = $db->query(
    "SELECT c.name, COALESCE(SUM(il.quantity),0) AS total
     FROM resource_categories c
     LEFT JOIN impact_log il ON il.category_id = c.category_id
     GROUP BY c.category_id, c.name ORDER BY total DESC"
)->fetchAll();
$maxCat = max(1, ...array_map(fn($c) => (int)$c['total'], $byCategory ?: [['total'=>0]]));
$topCategory = $byCategory[0]['name'] ?? '—';

$pageTitle = 'Impact Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Impact Dashboard</h1>
<p>A platform-wide view of EcoLink's social and environmental impact.</p>

<div class="profile-banner">
    <div>
        <div class="eyebrow">EcoLink Platform Impact</div>
        <h2>Turning surplus into measurable impact.</h2>
        <p>Track resources reused, beneficiaries reached, and estimated CO₂ emissions saved through completed redistribution.</p>
    </div>
    <a class="btn-accent" href="<?= BASE_URL ?>/admin/reports.php">View Analytics →</a>
</div>

<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Resources Reused</span><strong><?= number_format((int)$impact['resources_reused']) ?></strong><span>items / units redistributed</span></div>
    <div class="mini-card"><span class="eyebrow">Beneficiaries Reached</span><strong><?= number_format((int)$impact['beneficiaries']) ?></strong><span>estimated people supported</span></div>
    <div class="mini-card"><span class="eyebrow">CO₂ Emissions Saved</span><strong><?= number_format((float)$impact['co2_kg'] / 1000, 1) ?> t</strong><span>estimated environmental saving</span></div>
    <div class="mini-card"><span class="eyebrow">Completed Donations</span><strong><?= number_format((int)$impact['completed_donations']) ?></strong><span>successful redistribution cycles</span></div>
</section>

<div class="impact-grid">
    <div class="chart-card">
        <h3>Impact Growth</h3>
        <p class="sub">Resources reused and beneficiaries reached over time</p>
        <div class="chart-legend">
            <span><span class="dot" style="background:var(--forest-2);"></span>Resources reused</span>
            <span><span class="dot" style="background:#8cc49c;"></span>Beneficiaries</span>
        </div>
        <?php if ($growth): ?>
            <svg class="line-chart" viewBox="0 0 100 100" preserveAspectRatio="none">
                <?php for ($i = 0; $i <= 4; $i++): $y = $i * 25; ?>
                    <line class="grid-line" x1="0" y1="<?= $y ?>" x2="100" y2="<?= $y ?>" vector-effect="non-scaling-stroke"/>
                <?php endfor; ?>
                <polyline fill="none" stroke="#8cc49c" stroke-width="2.5" vector-effect="non-scaling-stroke"
                          points="<?= svgSeries(array_column($growth, 'beneficiaries'), $maxGrowth) ?>"/>
                <polyline fill="none" stroke="#1f5c33" stroke-width="2.5" vector-effect="non-scaling-stroke"
                          points="<?= svgSeries(array_column($growth, 'reused'), $maxGrowth) ?>"/>
            </svg>
            <div style="display:flex; justify-content:space-between; font-size:0.78rem; color:var(--ink-soft); margin-top:8px;">
                <?php foreach ($growth as $g): ?><span><?= e($g['m']) ?></span><?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="color:var(--ink-soft);">No completed donations yet, so there is no impact trend to show.</p>
        <?php endif; ?>
    </div>

    <div class="chart-card">
        <h3>Impact by Category</h3>
        <p class="sub">Resources reused across EcoLink categories</p>
        <div class="progress-list">
            <?php foreach ($byCategory as $c): ?>
                <div class="progress-item">
                    <span><?= e($c['name']) ?></span>
                    <span class="progress-track"><span style="width: <?= round((int)$c['total'] / $maxCat * 100) ?>%;"></span></span>
                    <span class="val"><?= (int)$c['total'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="two-col" style="align-items:start;">
    <div class="mini-card">
        <h3 style="margin-top:0;">Social Impact Summary</h3>
        <p style="color:var(--ink-soft);">EcoLink's completed redistribution has created measurable community value.</p>
        <div class="cards-row" style="margin:0;">
            <div class="mini-card" style="background:var(--bg);"><span class="eyebrow">Organizations Helped</span><strong><?= (int)$orgsHelped ?></strong></div>
            <div class="mini-card" style="background:var(--bg);"><span class="eyebrow">Requests Fulfilled</span><strong><?= (int)$impact['completed_donations'] ?></strong></div>
            <div class="mini-card" style="background:var(--bg);"><span class="eyebrow">Estimated CO₂ Saved</span><strong><?= number_format((float)$impact['co2_kg'] / 1000, 1) ?> t</strong></div>
        </div>
    </div>
    <div class="mini-card">
        <h3 style="margin-top:0;">Key Insight</h3>
        <p><strong><?= e($topCategory) ?> shows the strongest reuse activity.</strong></p>
        <p style="color:var(--ink-soft);">High resource reuse suggests strong demand for this category. Admins can monitor demand and matching performance to improve efficiency.</p>
        <a class="btn-accent btn-small" href="<?= BASE_URL ?>/admin/reports.php">Open Waste Analytics →</a>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
