<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/media.php';
requireRole('admin');

$db = getDB();
$sum = $db->query(
    "SELECT COUNT(*) total, COALESCE(ROUND(AVG(rating),2),0) avg_rating,
            COALESCE(ROUND(100*AVG(on_time),0),0) on_time_pct,
            COALESCE(ROUND(100*AVG(item_as_described),0),0) as_described_pct,
            COALESCE(ROUND(AVG(communication),2),0) avg_comm
     FROM donation_surveys"
)->fetch();
$pending = (int)$db->query(
    "SELECT COUNT(*) FROM donations d WHERE d.status = 'completed'
       AND (SELECT COUNT(*) FROM donation_surveys s WHERE s.donation_id = d.donation_id) = 0"
)->fetchColumn();

$rows = $db->query(
    "SELECT s.*, u.full_name, d.title FROM donation_surveys s
     JOIN users u ON u.user_id = s.user_id JOIN donations d ON d.donation_id = s.donation_id
     ORDER BY s.created_at DESC LIMIT 100"
)->fetchAll();

$pageTitle = 'Surveys';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Surveys</h1>
<p>Feedback from donors and receivers after a donation is completed.</p>
<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Responses</span><strong><?= (int)$sum['total'] ?></strong><span><?= $pending ?> completed donation(s) with no response</span></div>
    <div class="mini-card"><span class="eyebrow">Average rating</span><strong><?= e((string)$sum['avg_rating']) ?>/5</strong><span>overall</span></div>
    <div class="mini-card"><span class="eyebrow">On time</span><strong><?= (int)$sum['on_time_pct'] ?>%</strong><span>handovers on time</span></div>
    <div class="mini-card"><span class="eyebrow">As described</span><strong><?= (int)$sum['as_described_pct'] ?>%</strong><span>communication avg <?= e((string)$sum['avg_comm']) ?>/5</span></div>
</section>
<table class="data-table">
    <thead><tr><th>Donation</th><th>From</th><th>Rating</th><th>Details</th><th>Comment</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
        <tr>
            <td><a href="<?= donationUrl((int)$s['donation_id']) ?>"><?= e($s['title']) ?></a></td>
            <td><?= e($s['full_name']) ?><br><small><?= e($s['role']) ?></small></td>
            <td><span style="color:#f5a623;"><?= str_repeat('★', (int)$s['rating']) . str_repeat('☆', 5 - (int)$s['rating']) ?></span></td>
            <td><small><?= $s['item_as_described'] === null ? '' : ((int)$s['item_as_described'] ? 'As described ✓ · ' : 'Not as described ✗ · ') ?>
                <?= $s['on_time'] === null ? '' : ((int)$s['on_time'] ? 'On time ✓' : 'Late ✗') ?>
                <?= $s['communication'] ? ' · Comm ' . (int)$s['communication'] . '/5' : '' ?></small></td>
            <td><?= e($s['comment'] ?: '—') ?></td>
            <td><?= e(date('M d, H:i', strtotime($s['created_at']))) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6">No survey responses yet.</td></tr><?php endif; ?>
    </tbody>
</table>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
