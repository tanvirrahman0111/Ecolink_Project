<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/media.php';
require_once __DIR__ . '/../includes/hub.php';
requireRole('donor');

$db = getDB();
$uid = currentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $drId = (int)($_POST['donation_request_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $stmt = $db->prepare(
        "SELECT dr.*, d.title, d.donor_id, d.status AS donation_status
         FROM donation_requests dr JOIN donations d ON d.donation_id = dr.donation_id
         WHERE dr.donation_request_id = ? AND d.donor_id = ?"
    );
    $stmt->execute([$drId, $uid]);
    $dr = $stmt->fetch();

    if (!$dr || $dr['status'] !== 'pending') {
        flash('error', 'This request is no longer pending.');
    } elseif ($action === 'accept') {
        if ($dr['donation_status'] !== 'available') {
            flash('error', 'This donation is no longer available.');
        } else {
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE donations SET status = 'accepted', matched_receiver_id = ? WHERE donation_id = ? AND status = 'available'")
                   ->execute([$dr['receiver_id'], $dr['donation_id']]);
                $db->prepare("UPDATE donation_requests SET status = 'accepted', responded_at = NOW() WHERE donation_request_id = ?")
                   ->execute([$drId]);
                if ($dr['request_id']) {
                    $db->prepare("UPDATE requests SET status = 'accepted', matched_donation_id = ? WHERE request_id = ?")
                       ->execute([$dr['donation_id'], $dr['request_id']]);
                }
                $db->prepare("INSERT INTO lifecycle_log (donation_id, status, note, changed_by) VALUES (?, 'accepted', 'Donor accepted receiver request', ?)")
                   ->execute([$dr['donation_id'], $uid]);

                // Everyone else who asked for the same donation is rejected.
                $others = $db->prepare("SELECT donation_request_id, receiver_id FROM donation_requests WHERE donation_id = ? AND status = 'pending' AND donation_request_id <> ?");
                $others->execute([$dr['donation_id'], $drId]);
                $n = $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, ?, ?, ?)");
                foreach ($others->fetchAll() as $o) {
                    $db->prepare("UPDATE donation_requests SET status = 'rejected', responded_at = NOW() WHERE donation_request_id = ?")->execute([$o['donation_request_id']]);
                    $n->execute([$o['receiver_id'], 'request', 'Request not selected', 'The donor chose another receiver for "' . mb_substr($dr['title'], 0, 100) . '".']);
                }
                $n->execute([$dr['receiver_id'], 'request', 'Request accepted!', 'The donor accepted your request for "' . mb_substr($dr['title'], 0, 100) . '". Coordinate pickup now.']);
                $db->commit();
                ensureHubTables($db);
                syncHubAssignments($db);   // gives the receiver a Pickup ID + tells the donor which hub
                flash('success', 'Request accepted. Take the item to the delivery hub — the receiver gets a Pickup ID automatically.');
            } catch (Throwable $e) {
                $db->rollBack();
                flash('error', 'Could not accept the request. Please try again.');
            }
            redirect('/donor/my_donations.php');
        }
    } elseif ($action === 'reject') {
        $db->prepare("UPDATE donation_requests SET status = 'rejected', responded_at = NOW() WHERE donation_request_id = ?")->execute([$drId]);
        $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'request', ?, ?)")
           ->execute([$dr['receiver_id'], 'Request rejected', 'The donor declined your request for "' . mb_substr($dr['title'], 0, 100) . '".']);
        flash('success', 'Request rejected.');
    }
    redirect('/donor/donation_requests.php');
}

$stmt = $db->prepare(
    "SELECT dr.*, d.title, d.quantity, d.unit, d.status AS donation_status,
            u.full_name AS receiver_name, u.organization_name, u.phone AS receiver_phone,
            u.address AS receiver_address, u.is_verified
     FROM donation_requests dr
     JOIN donations d ON d.donation_id = dr.donation_id
     JOIN users u ON u.user_id = dr.receiver_id
     WHERE d.donor_id = ?
     ORDER BY (dr.status = 'pending') DESC, dr.created_at DESC"
);
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();

$pageTitle = 'Receiver Requests';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Receiver Requests</h1>
<p>Receivers who asked for your approved donations. Accept one to start delivery.</p>

<table class="data-table">
    <thead><tr><th>Donation</th><th>Receiver</th><th>Message</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong><a href="<?= donationUrl((int)$r['donation_id']) ?>"><?= e($r['title']) ?></a></strong><br><small><?= (int)$r['quantity'] ?> <?= e($r['unit']) ?></small></td>
            <td><?= e($r['organization_name'] ?: $r['receiver_name']) ?><br>
                <small><?= $r['is_verified'] ? 'Verified' : 'Unverified' ?><?= $r['status'] === 'accepted' ? ' · ' . e($r['receiver_phone']) . '<br>' . e((string)$r['receiver_address']) : '' ?></small></td>
            <td><?= e($r['message'] ?: '—') ?></td>
            <td><span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td>
                <?php if ($r['status'] === 'pending' && $r['donation_status'] === 'available'): ?>
                    <form method="post" style="display:inline"><input type="hidden" name="donation_request_id" value="<?= (int)$r['donation_request_id'] ?>">
                        <button class="btn-small btn-accent" name="action" value="accept" type="submit">Accept</button></form>
                    <form method="post" style="display:inline"><input type="hidden" name="donation_request_id" value="<?= (int)$r['donation_request_id'] ?>">
                        <button class="btn-small btn-secondary" name="action" value="reject" type="submit">Reject</button></form>
                <?php else: ?>—<?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5">No requests yet. Once your donation is approved, receivers can request it.</td></tr><?php endif; ?>
    </tbody>
</table>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
