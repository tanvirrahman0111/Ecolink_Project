<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/media.php';
requireRole('receiver');

$db = getDB();
$uid = currentUserId();

$user = $db->prepare("SELECT * FROM users WHERE user_id = ?");
$user->execute([$uid]);
$user = $user->fetch();

$overview = $db->prepare(
    "SELECT
        SUM(status = 'searching') AS active_requests,
        SUM(status IN ('matched','accepted','pickup','delivered')) AS matched_requests,
        (SELECT COALESCE(SUM(quantity),0) FROM impact_log WHERE beneficiary_id = ?) AS resources_received,
        (SELECT COUNT(DISTINCT beneficiary_id) FROM impact_log WHERE beneficiary_id = ?) AS people_reached
     FROM requests WHERE receiver_id = ?"
);
$overview->execute([$uid, $uid, $uid]);
$overview = $overview->fetch();

$currentRequests = $db->prepare(
    "SELECT r.*, c.name AS category_name, d.status AS donation_status
     FROM requests r
     JOIN resource_categories c ON c.category_id = r.category_id
     LEFT JOIN donations d ON d.donation_id = r.matched_donation_id
     WHERE r.receiver_id = ? AND r.status NOT IN ('completed','cancelled','declined')
     ORDER BY r.created_at DESC LIMIT 6"
);
$currentRequests->execute([$uid]);
$currentRequests = $currentRequests->fetchAll();

$recommended = $db->query(
    "SELECT d.donation_id, d.title, d.quantity, d.unit, d.image_path FROM donations d WHERE d.status = 'available' ORDER BY d.created_at DESC LIMIT 4"
)->fetchAll();

$pageTitle = 'Receiver Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Welcome to EcoLink</h1>
<p>Find surplus resources that match your organization's needs.</p>

<?php if (!$user['is_verified']): ?>
    <div class="alert alert-error">Your account is pending admin verification. You'll start receiving matches once verified.</div>
<?php endif; ?>

<div class="profile-banner">
    <div>
        <div class="eyebrow">Your Receiver Profile</div>
        <h2><?= e($user['organization_name'] ?: $user['full_name']) ?></h2>
        <p><?= $user['is_verified'] ? 'Verified organization' : 'Verification pending' ?> · Capacity score <?= (int)$user['capacity_score'] ?>/10</p>
    </div>
    <a class="btn-primary" href="<?= BASE_URL ?>/receiver/post_request.php">Post a Request →</a>
</div>

<h2>Request Overview</h2>
<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Active Requests</span><strong><?= (int)$overview['active_requests'] ?></strong><span>open</span></div>
    <div class="mini-card"><span class="eyebrow">Matched</span><strong><?= (int)$overview['matched_requests'] ?></strong><span>requests matched</span></div>
    <div class="mini-card"><span class="eyebrow">Received</span><strong><?= (int)$overview['resources_received'] ?></strong><span>resources</span></div>
    <div class="mini-card"><span class="eyebrow">People / Users Reached</span><strong><?= (int)$overview['people_reached'] ?></strong><span>beneficiaries</span></div>
</section>

<h2>Current Requests</h2>
<table class="data-table">
    <thead><tr><th>Resource Needed</th><th>Category</th><th>Quantity</th><th>Match Status</th><th>Posted</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($currentRequests as $r): ?>
        <?php $displayStatus = $r['donation_status'] ?? $r['status']; ?>
        <tr>
            <td><strong><?= e($r['title']) ?></strong></td>
            <td><?= e($r['category_name']) ?></td>
            <td><?= (int)$r['quantity_min'] ?>–<?= (int)$r['quantity_max'] ?></td>
            <td><span class="badge badge-<?= e($displayStatus === 'searching' ? 'available' : $displayStatus) ?>"><?= $r['status'] === 'searching' ? 'Searching' : e(ucfirst($displayStatus)) ?></span></td>
            <td><?= e(date('M d, Y', strtotime($r['created_at']))) ?></td>
            <td><a class="btn-accent btn-small" href="<?= BASE_URL . ($r['status'] === 'searching' ? '/receiver/find_resources.php?request_id=' . (int)$r['request_id'] : '/receiver/request_tracking.php?request_id=' . (int)$r['request_id']) ?>">View</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$currentRequests): ?>
        <tr><td colspan="6">No requests yet. <a href="<?= BASE_URL ?>/receiver/post_request.php">Post your first request</a>.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h2>Recommended Resources</h2>
<div class="two-col">
    <?php foreach ($recommended as $rec): ?>
        <div class="resource-row">
            <div class="icon" style="background:none;"><?= donationThumb($rec, '📦', 56) ?></div>
            <div class="info"><h4><a href="<?= donationUrl((int)$rec['donation_id']) ?>"><?= e($rec['title']) ?></a></h4><small><?= (int)$rec['quantity'] ?> <?= e($rec['unit']) ?> available</small></div>
        </div>
    <?php endforeach; ?>
    <?php if (!$recommended): ?><p style="color:var(--ink-soft);">Nothing available right now.</p><?php endif; ?>
</div>
<p><a href="<?= BASE_URL ?>/receiver/browse_donations.php">Browse all approved donations &amp; send a request →</a></p>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
