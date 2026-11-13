<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/media.php';
require_once __DIR__ . '/../includes/hub.php';
requireRole('donor');

$db = getDB();
ensureHubTables($db);
syncHubAssignments($db);
$uid = currentUserId();

// Donor advances a donation's lifecycle once a receiver has accepted:
// accepted -> pickup -> delivered  (delivered->completed is confirmed by receiver or admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['donation_id'], $_POST['next_status'])) {
    $donationId = (int)$_POST['donation_id'];
    $next = $_POST['next_status'];
    $allowedTransitions = ['accepted' => 'pickup', 'pickup' => 'delivered'];

    $stmt = $db->prepare("SELECT * FROM donations WHERE donation_id = ? AND donor_id = ?");
    $stmt->execute([$donationId, $uid]);
    $donation = $stmt->fetch();

    $viaHub = $donation ? hubItemForDonation($db, $donationId) : null;
    if ($viaHub) {
        flash('error', 'This donation goes through a delivery hub - hub staff update its status.');
    } elseif ($donation && isset($allowedTransitions[$donation['status']]) && $allowedTransitions[$donation['status']] === $next) {
        $upd = $db->prepare("UPDATE donations SET status = ? WHERE donation_id = ?");
        $upd->execute([$next, $donationId]);
        $log = $db->prepare("INSERT INTO lifecycle_log (donation_id, status, changed_by) VALUES (?, ?, ?)");
        $log->execute([$donationId, $next, $uid]);
        // Keep any linked receiver request's displayed status in sync (request_tracking.php
        // reads the donation's status directly, but this keeps request.status accurate too).
        $db->prepare("UPDATE requests SET status = ? WHERE matched_donation_id = ?")->execute([$next, $donationId]);
        flash('success', "Donation marked as " . ucfirst($next) . ".");
    } else {
        flash('error', 'Invalid status transition.');
    }
    redirect('/donor/my_donations.php');
}

$donations = $db->prepare(
    "SELECT d.*, c.name AS category_name, u.full_name AS receiver_name
     FROM donations d
     JOIN resource_categories c ON c.category_id = d.category_id
     LEFT JOIN users u ON u.user_id = d.matched_receiver_id
     WHERE d.donor_id = ? ORDER BY d.created_at DESC"
);
$donations->execute([$uid]);
$rows = $donations->fetchAll();
$hubByDonation = [];
foreach ($rows as $r0) { $hi = hubItemForDonation($db, (int)$r0['donation_id']); if ($hi) $hubByDonation[(int)$r0['donation_id']] = $hi; }

$pageTitle = 'My Donations';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>My donations</h1>
<table class="data-table">
    <thead><tr><th>Title</th><th>Category</th><th>Status</th><th>Matched Receiver</th><th>Score</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><a href="<?= donationUrl((int)$r['donation_id']) ?>"><?= e($r['title']) ?></a><br><small><a href="<?= donationUrl((int)$r['donation_id']) ?>#tracking">Tracking</a></small></td>
            <td><?= e($r['category_name']) ?></td>
            <td><span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst(str_replace('_',' ',$r['status']))) ?></span><?= $r['status']==='rejected' && $r['rejection_reason'] ? '<br><small>'.e($r['rejection_reason']).'</small>' : '' ?></td>
            <td><?= $r['receiver_name'] ? e($r['receiver_name']) : '—' ?></td>
            <td><?= $r['matched_score'] !== null ? e($r['matched_score']) . '/100' : '—' ?></td>
            <td>
                <?php $hi = $hubByDonation[(int)$r['donation_id']] ?? null; if ($hi && in_array($r['status'], ['accepted','pickup'], true)): ?>
                    <small>📍 <?= e($hi['hub_name']) ?><br><?= e(hubStatusLabel($hi['status'])) ?></small>
                <?php elseif ($r['status'] === 'accepted'): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="donation_id" value="<?= (int)$r['donation_id'] ?>">
                        <input type="hidden" name="next_status" value="pickup">
                        <button class="btn-small btn-accent" type="submit">Mark Picked Up</button>
                    </form>
                <?php elseif ($r['status'] === 'pickup'): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="donation_id" value="<?= (int)$r['donation_id'] ?>">
                        <input type="hidden" name="next_status" value="delivered">
                        <button class="btn-small btn-accent" type="submit">Mark Delivered</button>
                    </form>
                <?php elseif ($r['status'] === 'completed'): ?>
                    <a class="btn-small btn-accent" href="<?= donationUrl((int)$r['donation_id']) ?>#survey">Survey</a>
                <?php else: ?>
                    —
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="6">No donations yet.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
