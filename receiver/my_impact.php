<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('receiver');

$db = getDB();
$uid = currentUserId();

$summary = $db->prepare(
    "SELECT
        COALESCE(SUM(quantity),0) AS resources_received,
        COUNT(*) AS requests_fulfilled
     FROM impact_log WHERE beneficiary_id = ?"
);
$summary->execute([$uid]);
$summary = $summary->fetch();

$byCategory = $db->prepare(
    "SELECT c.name, COALESCE(SUM(il.quantity),0) AS total
     FROM resource_categories c
     LEFT JOIN impact_log il ON il.category_id = c.category_id AND il.beneficiary_id = ?
     GROUP BY c.category_id, c.name ORDER BY c.name"
);
$byCategory->execute([$uid]);
$byCategory = $byCategory->fetchAll();

$monthly = $db->prepare(
    "SELECT DATE_FORMAT(completed_at, '%b') AS m, DATE_FORMAT(completed_at, '%Y-%m') AS ym, COALESCE(SUM(quantity),0) AS c
     FROM impact_log
     WHERE beneficiary_id = ? AND completed_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
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
<p>See how received surplus resources have supported your organization and community.</p>

<div class="profile-banner">
    <div>
        <div class="eyebrow">Your EcoLink Impact</div>
        <h2>Resources received. People supported.</h2>
        <p>EcoLink helps your organization turn surplus resources into practical community value.</p>
    </div>
    <a class="btn-primary" href="<?= BASE_URL ?>/receiver/browse_matches.php">View My Requests →</a>
</div>

<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Resources Received</span><strong><?= (int)$summary['resources_received'] ?></strong><span>items / units</span></div>
    <div class="mini-card"><span class="eyebrow">Requests Fulfilled</span><strong><?= (int)$summary['requests_fulfilled'] ?></strong><span>successful requests</span></div>
    <div class="mini-card"><span class="eyebrow">People Benefited</span><strong><?= (int)$summary['resources_received'] ?></strong><span>estimated beneficiaries</span></div>
    <div class="mini-card"><span class="eyebrow">Resource Categories</span><strong>5</strong><span>Food · Clothes · Books · Electronics · Furniture</span></div>
</section>

<div class="impact-grid">
    <div class="chart-card">
        <h3>Resources Received Over Time</h3>
        <p class="sub">Successful resources received in the last 6 months</p>
        <div class="bar-chart">
            <?php foreach ($monthly as $m): ?>
                <div class="bar-col">
                    <div class="bar-value"><?= (int)$m['c'] ?></div>
                    <div class="bar-fill" style="height: <?= max(6, ((int)$m['c'] / $maxMonthly) * 170) ?>px; background:#4f9a6b;"></div>
                    <div class="bar-label"><?= e($m['m']) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$monthly): ?><p style="color:var(--ink-soft);">No resources received in the last 6 months yet.</p><?php endif; ?>
        </div>
    </div>
    <div class="chart-card donut-wrap">
        <h3 style="align-self:flex-start;">Resources by Category</h3>
        <p class="sub" style="align-self:flex-start;">Received through completed requests</p>
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
            <text x="60" y="72" text-anchor="middle" font-size="7" fill="#8a988f">TOTAL RECEIVED</text>
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
        <span class="eyebrow">Community Impact</span>
        <p style="margin:6px 0;">Received resources have supported learning, community services, and everyday needs.</p>
        <strong style="font-size:1.1rem;"><?= (int)$summary['resources_received'] ?> resources received</strong>
        <span>· <?= (int)$summary['requests_fulfilled'] ?> requests fulfilled</span>
    </div>
    <div class="mini-card">
        <span class="eyebrow">Make the Impact Bigger</span>
        <p style="margin:6px 0;">Need another resource? Post a request and let EcoLink match it.</p>
        <a class="btn-primary btn-small" href="<?= BASE_URL ?>/receiver/post_request.php">Post a Request →</a>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
