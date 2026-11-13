<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('receiver');

$db = getDB();
$uid = currentUserId();

$stmt = $db->prepare(
    "SELECT r.*, c.name AS category_name, d.status AS donation_status, u.full_name AS donor_name
     FROM requests r
     JOIN resource_categories c ON c.category_id = r.category_id
     LEFT JOIN donations d ON d.donation_id = r.matched_donation_id
     LEFT JOIN users u ON u.user_id = d.donor_id
     WHERE r.receiver_id = ? AND r.status NOT IN ('completed','cancelled','declined')
     ORDER BY r.created_at DESC"
);
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();

$pageTitle = 'My Requests';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>My Requests</h1>
<p>Track resources matched to your requests, from match through delivery.</p>

<?php foreach ($rows as $r): ?>
    <?php $displayStatus = $r['donation_status'] ?? $r['status']; ?>
    <div class="match-card">
        <div class="match-card-main">
            <h3><?= e($r['title']) ?> <span class="badge badge-<?= e($displayStatus === 'searching' ? 'available' : $displayStatus) ?>"><?= e(ucfirst($displayStatus)) ?></span></h3>
            <p><?= e($r['category_name']) ?> · Need <?= (int)$r['quantity_min'] ?>–<?= (int)$r['quantity_max'] ?> units · <?= e($r['pickup_location']) ?></p>
            <?php if ($r['donor_name']): ?><p>Donor: <?= e($r['donor_name']) ?></p><?php endif; ?>
        </div>
        <div class="match-card-score">
            <?php if ($r['match_score'] !== null): ?>
                <div class="score-total"><?= e($r['match_score']) ?>%</div>
            <?php endif; ?>
            <?php if ($r['status'] === 'searching'): ?>
                <a class="btn-accent btn-small" href="<?= BASE_URL ?>/receiver/find_resources.php?request_id=<?= (int)$r['request_id'] ?>">View Matches</a>
            <?php else: ?>
                <a class="btn-accent btn-small" href="<?= BASE_URL ?>/receiver/request_tracking.php?request_id=<?= (int)$r['request_id'] ?>">Track</a>
                <?php if ($r['matched_donation_id']): ?>
                    <a class="btn-small btn-secondary" style="margin-top:6px;" href="<?= BASE_URL ?>/receiver/report_resource.php?donation_id=<?= (int)$r['matched_donation_id'] ?>">Report Issue</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$rows): ?>
    <p>No active requests yet. <a href="<?= BASE_URL ?>/receiver/post_request.php">Post your first request</a>.</p>
<?php endif; ?>
<p style="margin-top:10px;"><a href="<?= BASE_URL ?>/receiver/request_history.php">View full request history →</a></p>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
