<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

$db = getDB();

$impact = $db->query(
    "SELECT
        COUNT(DISTINCT il.donation_id) AS total_completed,
        COUNT(DISTINCT il.beneficiary_id) AS beneficiaries_reached,
        COALESCE(SUM(il.quantity),0) AS total_units_redistributed,
        COALESCE(SUM(il.estimated_weight_kg),0) AS total_weight_kg,
        COALESCE(SUM(il.estimated_co2_saved_kg),0) AS total_co2_kg
     FROM impact_log il"
)->fetch();

$byCategory = $db->query(
    "SELECT c.name, COUNT(il.impact_id) AS donations,
            COALESCE(SUM(il.quantity),0) AS units,
            COALESCE(SUM(il.estimated_weight_kg),0) AS weight_kg,
            COALESCE(SUM(il.estimated_co2_saved_kg),0) AS co2_kg
     FROM resource_categories c
     LEFT JOIN impact_log il ON il.category_id = c.category_id
     GROUP BY c.category_id, c.name ORDER BY co2_kg DESC"
)->fetchAll();

$lifecycleFunnel = $db->query(
    "SELECT status, COUNT(*) AS c FROM donations GROUP BY status"
)->fetchAll();
$funnel = array_fill_keys(['available','matched','accepted','pickup','delivered','completed','cancelled'], 0);
foreach ($lifecycleFunnel as $row) $funnel[$row['status']] = (int)$row['c'];

$monthly = $db->query(
    "SELECT DATE_FORMAT(completed_at, '%Y-%m') AS ym, COUNT(*) AS c, SUM(estimated_co2_saved_kg) AS co2
     FROM impact_log GROUP BY ym ORDER BY ym DESC LIMIT 6"
)->fetchAll();

$pageTitle = 'Reports & Impact';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Impact dashboard & waste analytics</h1>

<section class="cards-row">
    <div class="mini-card"><strong><?= (int)$impact['total_completed'] ?></strong><span>Donations Completed</span></div>
    <div class="mini-card"><strong><?= (int)$impact['beneficiaries_reached'] ?></strong><span>Beneficiaries Reached</span></div>
    <div class="mini-card"><strong><?= (int)$impact['total_units_redistributed'] ?></strong><span>Units Redistributed</span></div>
    <div class="mini-card"><strong><?= number_format((float)$impact['total_weight_kg'],1) ?> kg</strong><span>Resources Reused</span></div>
    <div class="mini-card"><strong><?= number_format((float)$impact['total_co2_kg'],1) ?> kg</strong><span>CO₂ Saved</span></div>
</section>

<h2>Impact by category</h2>
<table class="data-table">
    <thead><tr><th>Category</th><th>Completed Donations</th><th>Units</th><th>Weight (kg)</th><th>CO₂ Saved (kg)</th></tr></thead>
    <tbody>
    <?php foreach ($byCategory as $c): ?>
        <tr>
            <td><?= e($c['name']) ?></td>
            <td><?= (int)$c['donations'] ?></td>
            <td><?= (int)$c['units'] ?></td>
            <td><?= number_format((float)$c['weight_kg'],1) ?></td>
            <td><?= number_format((float)$c['co2_kg'],1) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h2>Donation lifecycle funnel</h2>
<table class="data-table">
    <thead><tr><th>Available</th><th>Matched</th><th>Accepted</th><th>Pickup</th><th>Delivered</th><th>Completed</th><th>Cancelled</th></tr></thead>
    <tbody>
        <tr>
            <td><?= $funnel['available'] ?></td>
            <td><?= $funnel['matched'] ?></td>
            <td><?= $funnel['accepted'] ?></td>
            <td><?= $funnel['pickup'] ?></td>
            <td><?= $funnel['delivered'] ?></td>
            <td><?= $funnel['completed'] ?></td>
            <td><?= $funnel['cancelled'] ?></td>
        </tr>
    </tbody>
</table>

<h2>Monthly trend (last 6 months with completions)</h2>
<table class="data-table">
    <thead><tr><th>Month</th><th>Completed Donations</th><th>CO₂ Saved (kg)</th></tr></thead>
    <tbody>
    <?php foreach ($monthly as $m): ?>
        <tr><td><?= e($m['ym']) ?></td><td><?= (int)$m['c'] ?></td><td><?= number_format((float)$m['co2'],1) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$monthly): ?><tr><td colspan="3">No completed donations yet.</td></tr><?php endif; ?>
    </tbody>
</table>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
