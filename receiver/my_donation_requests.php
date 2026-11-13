<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/media.php';
requireRole('receiver');

$db = getDB();
$uid = currentUserId();

// Receiver cancels a pending request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    $id = (int)($_POST['donation_request_id'] ?? 0);
    $db->prepare("UPDATE donation_requests SET status = 'cancelled', responded_at = NOW() WHERE donation_request_id = ? AND receiver_id = ? AND status = 'pending'")
       ->execute([$id, $uid]);
    flash('success', 'Request cancelled.');
    redirect('/receiver/my_donation_requests.php');
}

$stmt = $db->prepare(
    "SELECT dr.*, d.title, d.quantity, d.unit, d.pickup_address, d.status AS donation_status,
            d.matched_receiver_id, c.name AS category_name, u.full_name AS donor_name, u.phone AS donor_phone
     FROM donation_requests dr
     JOIN donations d ON d.donation_id = dr.donation_id
     JOIN resource_categories c ON c.category_id = d.category_id
     JOIN users u ON u.user_id = d.donor_id
     WHERE dr.receiver_id = ? ORDER BY dr.created_at DESC"
);
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();

$pageTitle = 'My Donation Requests';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>My Donation Requests</h1>
<p>Track every request you sent to donors, through pickup and delivery.</p>

<table class="data-table">
    <thead><tr><th>Donation</th><th>Donor</th><th>My Request</th><th>Delivery Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $mine = ($r['status'] === 'accepted' && (int)$r['matched_receiver_id'] === $uid);
    ?>
        <tr>
            <td><strong><a href="<?= donationUrl((int)$r['donation_id']) ?>"><?= e($r['title']) ?></a></strong><br><small><?= e($r['category_name']) ?> · <?= (int)$r['quantity'] ?> <?= e($r['unit']) ?></small></td>
            <td><?= e($r['donor_name']) ?>
                <?php if ($mine): ?><br><small>📞 <?= e($r['donor_phone']) ?><br>📍 <?= e($r['pickup_address']) ?></small><?php endif; ?></td>
            <td><span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td><?= $mine ? '<span class="badge badge-' . e($r['donation_status']) . '">' . e(ucfirst($r['donation_status'])) . '</span>' : '—' ?></td>
            <td>
                <?php if ($r['status'] === 'pending'): ?>
                    <form method="post"><input type="hidden" name="action" value="cancel"><input type="hidden" name="donation_request_id" value="<?= (int)$r['donation_request_id'] ?>">
                        <button class="btn-small btn-secondary" type="submit">Cancel</button></form>
                <?php elseif ($mine && $r['donation_status'] === 'delivered'): ?>
                    <form method="post" action="<?= BASE_URL ?>/receiver/accept_donation.php">
                        <input type="hidden" name="donation_id" value="<?= (int)$r['donation_id'] ?>">
                        <button class="btn-small btn-accent" name="action" value="complete" type="submit">Confirm Received</button></form>
                <?php elseif ($mine && in_array($r['donation_status'], ['accepted','pickup'], true)): ?>
                    <a class="btn-small btn-accent" href="<?= BASE_URL ?>/receiver/hub_pickup.php">🎫 Pickup ID &amp; Hub</a>
                <?php elseif ($mine && $r['donation_status'] === 'pickup'): ?>
                    <small>On the way</small>
                <?php elseif ($mine && $r['donation_status'] === 'completed'): ?>
                    <a class="btn-small btn-accent" href="<?= donationUrl((int)$r['donation_id']) ?>#survey">Survey</a>
                <?php else: ?>—<?php endif; ?>
                <?php if ($mine): ?><br><a href="<?= donationUrl((int)$r['donation_id']) ?>#tracking"><small>Track</small></a><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5">No requests yet. <a href="<?= BASE_URL ?>/receiver/browse_donations.php">Browse donations</a>.</td></tr><?php endif; ?>
    </tbody>
</table>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
