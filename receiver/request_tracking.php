<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/hub.php';
requireRole('receiver');

$db = getDB();
ensureHubTables($db);
syncHubAssignments($db);
$uid = currentUserId();
$requestId = (int)($_GET['request_id'] ?? 0);

$stmt = $db->prepare(
    "SELECT r.*, c.name AS category_name, d.title AS donation_title, d.pickup_address,
            d.donor_id, u.full_name AS donor_name, d.status AS donation_status
     FROM requests r
     JOIN resource_categories c ON c.category_id = r.category_id
     LEFT JOIN donations d ON d.donation_id = r.matched_donation_id
     LEFT JOIN users u ON u.user_id = d.donor_id
     WHERE r.request_id = ? AND r.receiver_id = ?"
);
$stmt->execute([$requestId, $uid]);
$request = $stmt->fetch();

if (!$request) {
    flash('error', 'Request not found.');
    redirect('/receiver/browse_matches.php');
}

$steps = ['matched' => 1, 'accepted' => 2, 'pickup' => 3, 'delivered' => 4, 'completed' => 5];
$currentStatus = $request['donation_status'] ?? ($request['status'] === 'searching' ? 'matched' : $request['status']);
$currentStep = $steps[$currentStatus] ?? 1;

$hubItem = !empty($request['matched_donation_id']) ? hubItemForDonation($db, (int)$request['matched_donation_id']) : null;

$pageTitle = 'Request Tracking';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Request Tracking</h1>
<p>Track an accepted resource from donor confirmation to final delivery.</p>
<p style="color:var(--ink-soft);">My Requests / <?= e($request['title']) ?> / Tracking</p>

<div class="profile-banner">
    <div>
        <div class="eyebrow">Current Request</div>
        <h2><?= e($request['title']) ?> — <?= (int)$request['quantity_min'] ?>–<?= (int)$request['quantity_max'] ?> units</h2>
        <p>
            <?php if ($request['donor_name']): ?>
                Donor: <?= e($request['donor_name']) ?> · Match accepted
            <?php else: ?>
                Still searching for a suitable donor resource
            <?php endif; ?>
        </p>
    </div>
    <span class="badge badge-<?= e($currentStatus === 'searching' ? 'available' : $currentStatus) ?>" style="font-size:0.85rem;">STATUS: <?= e(strtoupper($currentStatus)) ?></span>
</div>

<h2>Request Journey</h2>
<div class="journey">
    <?php
    $labels = [1 => ['Matched','Donor found'], 2 => ['Accepted','You accepted'], 3 => ['Pickup','Current step'], 4 => ['Delivered','Pending'], 5 => ['Completed','Pending']];
    foreach ($labels as $num => $pair):
        [$label, $sub] = $pair;
        $cls = $num < $currentStep ? 'done' : ($num === $currentStep ? 'current' : 'pending');
    ?>
        <div class="journey-step <?= $cls ?>">
            <div class="journey-line"></div>
            <div class="dot"><?= $cls === 'done' ? '✓' : $num ?></div>
            <strong><?= $label ?></strong>
            <small><?= $cls === 'current' ? 'Current step' : ($cls === 'done' ? 'Done' : $sub) ?></small>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($request['matched_donation_id']): ?>
<?= renderHubTrackingCard($hubItem, 'receiver') ?>
<?php endif; ?>

<div class="two-col" style="align-items:start;">
    <div class="mini-card">
        <h3 style="margin-top:0;">Pickup Coordination</h3>
        <?php if ($currentStep < 3): ?>
            <div class="alert" style="background:var(--amber-bg); color:var(--amber-text); border:1px solid #f2dca3;">
                <strong>Pickup scheduling is pending</strong><br>Collect your item from the delivery hub above using your Pickup ID.
            </div>
        <?php else: ?>
            <div class="alert alert-success">Pickup has been coordinated.</div>
        <?php endif; ?>
        <p><small style="text-transform:uppercase; color:var(--ink-soft);">Pickup Place</small><br><?= $hubItem ? e($hubItem['hub_name'] . ' — ' . $hubItem['hub_address']) : e($request['pickup_address'] ?? $request['pickup_location']) ?></p>
        <p><small style="text-transform:uppercase; color:var(--ink-soft);">Donor</small><br><?= e($request['donor_name'] ?? '—') ?></p>
        <?php if ($request['matched_donation_id']): ?>
            <p><a class="btn-accent btn-small" href="<?= BASE_URL ?>/donation_view.php?id=<?= (int)$request['matched_donation_id'] ?>">📷 View photos, videos &amp; full tracking</a>
            <?php if ($currentStatus === 'completed'): ?> <a class="btn-small btn-secondary" href="<?= BASE_URL ?>/donation_view.php?id=<?= (int)$request['matched_donation_id'] ?>#survey">Survey</a><?php endif; ?></p>
        <?php endif; ?>
        <small style="color:var(--ink-soft);">Contact details become available after coordination is started.</small>
    </div>
    <div class="mini-card">
        <h3 style="margin-top:0;">Current Action</h3>
        <p style="color:var(--ink-soft);">Next step for this request:</p>
        <?php if ($currentStatus === 'delivered'): ?>
            <form method="post" action="<?= BASE_URL ?>/receiver/accept_donation.php">
                <input type="hidden" name="donation_id" value="<?= (int)$request['matched_donation_id'] ?>">
                <button class="btn-primary" name="action" value="complete" type="submit" style="width:100%; margin-bottom:8px;">Confirm Received</button>
            </form>
        <?php elseif ($request['donor_id']): ?>
            <a class="btn-primary" href="<?= BASE_URL ?>/receiver/hub_pickup.php" style="width:100%; margin-bottom:8px;">Open Hub Pickup (my ID)</a>
        <?php else: ?>
            <a class="btn-primary" href="<?= BASE_URL ?>/receiver/find_resources.php?request_id=<?= (int)$requestId ?>" style="width:100%; margin-bottom:8px;">Browse Matches</a>
        <?php endif; ?>
        <p style="margin-top:14px;"><strong>Need help?</strong><br><small style="color:var(--ink-soft);">Use the notification and support options if pickup details need to be changed.</small></p>
    </div>
</div>

<div class="alert" style="background:var(--mint); border:1px solid var(--mint-line); color:var(--forest-2); margin-top:20px;">
    <strong>Request ID: REQ-<?= str_pad($requestId, 5, '0', STR_PAD_LEFT) ?></strong> <?= $request['matched_donation_id'] ? ' · Matched donation: ECO-' . str_pad($request['matched_donation_id'], 5, '0', STR_PAD_LEFT) : '' ?><br>
    View the complete request in <a href="<?= BASE_URL ?>/receiver/request_history.php">Request History</a>.
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
