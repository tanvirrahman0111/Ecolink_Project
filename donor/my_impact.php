<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('donor');

$db = getDB();
$uid = currentUserId();

$summary = $db->prepare(
    "SELECT
        COALESCE(SUM(il.quantity),0) AS resources_reused,
        COUNT(DISTINCT il.donation_id) AS donations_completed,
        COUNT(DISTINCT il.beneficiary_id) AS receivers_helped
     FROM impact_log il JOIN donations d ON d.donation_id = il.donation_id
     WHERE d.donor_id = ?"
);
$summary->execute([$uid]);
$summary = $summary->fetch();

$byCategory = $db->prepare(
    "SELECT c.name, COALESCE(SUM(il.quantity),0) AS total
     FROM resource_categories c
     LEFT JOIN impact_log il ON il.category_id = c.category_id
     LEFT JOIN donations d ON d.donation_id = il.donation_id AND d.donor_id = ?
     GROUP BY c.category_id, c.name ORDER BY c.name"
);
$byCategory->execute([$uid]);
$byCategory = $byCategory->fetchAll();

$monthly = $db->prepare(
    "SELECT DATE_FORMAT(il.completed_at, '%b') AS m, DATE_FORMAT(il.completed_at, '%Y-%m') AS ym, COUNT(*) AS c
     FROM impact_log il JOIN donations d ON d.donation_id = il.donation_id
     WHERE d.donor_id = ? AND il.completed_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY ym, m ORDER BY ym ASC"
);
$monthly->execute([$uid]);
$monthly = $monthly->fetchAll();
$maxMonthly = max(1, ...array_map(fn($m) => (int)$m['c'], $monthly ?: [['c'=>0]]));

$colors = ['#14361f','#2f7a45','#5fae74','#9ed3ac','#cdeed6'];
$totalCat = array_sum(array_column($byCategory, 'total')) ?: 1;

$pageTitle = 'My Impact';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>My Impact</h1>
<p>See how your surplus resources have created social and environmental value.</p>

<div class="profile-banner">
    <div>
        <div class="eyebrow">Your EcoLink Impact</div>
        <h2>Small actions. Meaningful impact.</h2>
        <p>Your donated resources are being redirected to people and organizations that need them.</p>
    </div>
    <a class="btn-primary" href="<?= BASE_URL ?>/donor/my_donations.php">View Donations →</a>
</div>

<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Resources Reused</span><strong><?= (int)$summary['resources_reused'] ?></strong><span>items / units</span></div>
    <div class="mini-card"><span class="eyebrow">Donations Completed</span><strong><?= (int)$summary['donations_completed'] ?></strong><span>successfully delivered</span></div>
    <div class="mini-card"><span class="eyebrow">Receivers Helped</span><strong><?= (int)$summary['receivers_helped'] ?></strong><span>people / organizations</span></div>
    <div class="mini-card"><span class="eyebrow">Resource Categories</span><strong>5</strong><span>Food · Clothes · Books · Electronics · Furniture</span></div>
</section>

<div class="impact-grid">
    <div class="chart-card">
        <h3>Your Donation Activity</h3>
        <p class="sub">Completed donations over the last 6 months</p>
        <div class="bar-chart">
            <?php foreach ($monthly as $m): ?>
                <div class="bar-col">
                    <div class="bar-value"><?= (int)$m['c'] ?></div>
                    <div class="bar-fill" style="height: <?= max(6, ((int)$m['c'] / $maxMonthly) * 170) ?>px;"></div>
                    <div class="bar-label"><?= e($m['m']) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$monthly): ?><p style="color:var(--ink-soft);">No completed donations in the last 6 months yet.</p><?php endif; ?>
        </div>
    </div>
    <div class="chart-card donut-wrap">
        <h3 style="align-self:flex-start;">Impact by Category</h3>
        <p class="sub" style="align-self:flex-start;">Resources you have redirected</p>
        <svg viewBox="0 0 120 120" width="180" height="180">
            <?php
            $offset = 0;
            foreach ($byCategory as $i => $cat) {
                $frac = $cat['total'] / $totalCat;
                $circumference = 2 * M_PI * 50;
                $dash = $frac * $circumference;
                echo '<circle cx="60" cy="60" r="50" fill="none" stroke="' . $colors[$i % count($colors)] . '" stroke-width="16" '
                   . 'stroke-dasharray="' . round($dash,2) . ' ' . round($circumference,2) . '" '
                   . 'stroke-dashoffset="-' . round($offset,2) . '" transform="rotate(-90 60 60)"/>';
                $offset += $dash;
            }
            ?>
            <text x="60" y="56" text-anchor="middle" font-size="20" font-weight="800" fill="var(--ink)"><?= (int)array_sum(array_column($byCategory,'total')) ?></text>
            <text x="60" y="72" text-anchor="middle" font-size="7" fill="#8a988f">TOTAL ITEMS</text>
        </svg>
        <div class="donut-legend">
            <?php foreach ($byCategory as $i => $cat): ?>
                <div class="item"><span><span class="dot" style="background: <?= $colors[$i % count($colors)] ?>;"></span><?= e($cat['name']) ?></span><span><?= (int)$cat['total'] ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="cards-row" style="grid-template-columns: 1fr 1fr;">
    <div class="mini-card">
        <span class="eyebrow">Your Social Contribution</span>
        <p style="margin:6px 0;">Your donations have helped verified receivers access resources they need.</p>
        <strong style="font-size:1.1rem;"><?= (int)$summary['receivers_helped'] ?> receivers helped</strong>
        <span>· <?= (int)$summary['donations_completed'] ?> completed donations · <?= (int)$summary['resources_reused'] ?> resources reused</span>
    </div>
    <div class="mini-card">
        <span class="eyebrow">Keep the Impact Growing</span>
        <p style="margin:6px 0;">Have more surplus? Add another resource to EcoLink.</p>
        <a class="btn-primary btn-small" href="<?= BASE_URL ?>/donor/upload_resource.php">Add a Resource →</a>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
