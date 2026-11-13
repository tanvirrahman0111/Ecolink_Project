<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/media.php';
require_once __DIR__ . '/../matching/PriorityMatcher.php';
requireRole('admin');

$db = getDB();
$adminId = currentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $donationId = (int)($_POST['donation_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $stmt = $db->prepare("SELECT * FROM donations WHERE donation_id = ? AND status = 'pending_approval'");
    $stmt->execute([$donationId]);
    $donation = $stmt->fetch();

    if (!$donation) {
        flash('error', 'This donation is no longer waiting for approval.');
    } elseif ($action === 'approve') {
        $db->prepare("UPDATE donations SET status = 'available', approved_by = ?, approved_at = NOW(), rejection_reason = NULL WHERE donation_id = ?")
           ->execute([$adminId, $donationId]);
        $db->prepare("INSERT INTO lifecycle_log (donation_id, status, note, changed_by) VALUES (?, 'available', 'Approved by admin', ?)")
           ->execute([$donationId, $adminId]);

        // Donor notification
        $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'lifecycle', ?, ?)")
           ->execute([$donation['donor_id'], 'Donation approved', 'Your donation "' . $donation['title'] . '" was approved and is now visible to receivers.']);

        // Tell every verified receiver there is a new donation to look at.
        $receivers = $db->query("SELECT user_id FROM users WHERE role = 'receiver' AND is_verified = 1 AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        $n = $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'matching', ?, ?)");
        foreach ($receivers as $rid) {
            $n->execute([$rid, 'New donation available', '"' . mb_substr($donation['title'], 0, 100) . '" is now available. Open Browse Donations to request it.']);
        }

        // Score receivers for analytics only (does NOT assign anyone).
        try { (new PriorityMatcher($db))->matchDonation($donationId, false); } catch (Throwable $e) {}

        flash('success', 'Donation approved. It is now visible to all verified receivers.');
    } elseif ($action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        $db->prepare("UPDATE donations SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE donation_id = ?")
           ->execute([$adminId, $reason ?: null, $donationId]);
        $db->prepare("INSERT INTO lifecycle_log (donation_id, status, note, changed_by) VALUES (?, 'rejected', ?, ?)")
           ->execute([$donationId, $reason ?: 'Rejected by admin', $adminId]);
        $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'lifecycle', ?, ?)")
           ->execute([$donation['donor_id'], 'Donation rejected', 'Your donation "' . $donation['title'] . '" was not approved.' . ($reason ? ' Reason: ' . mb_substr($reason, 0, 120) : '')]);
        flash('success', 'Donation rejected.');
    }
    redirect('/admin/approve_donations.php');
}

$pending = $db->query(
    "SELECT d.*, c.name AS category_name, u.full_name AS donor_name, u.email AS donor_email
     FROM donations d
     JOIN resource_categories c ON c.category_id = d.category_id
     JOIN users u ON u.user_id = d.donor_id
     WHERE d.status = 'pending_approval' ORDER BY d.created_at ASC"
)->fetchAll();

$recent = $db->query(
    "SELECT d.donation_id, d.title, d.status, d.approved_at, d.rejection_reason, u.full_name AS donor_name
     FROM donations d JOIN users u ON u.user_id = d.donor_id
     WHERE d.approved_by IS NOT NULL ORDER BY d.approved_at DESC LIMIT 10"
)->fetchAll();

$pageTitle = 'Donation Approvals';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Donation Approvals</h1>
<p>Review donations submitted by donors. Only approved donations are shown to receivers.</p>

<h2>Waiting for approval (<?= count($pending) ?>)</h2>
<?php foreach ($pending as $d): ?>
    <div class="mini-card" style="margin-bottom:14px;">
        <div style="display:flex; gap:16px; flex-wrap:wrap; justify-content:space-between;">
            <div style="flex:1; min-width:260px;">
                <span class="eyebrow"><?= e($d['category_name']) ?> · <?= e(ucfirst(str_replace('_', ' ', $d['condition_grade']))) ?> condition</span>
                <h3 style="margin:6px 0;"><a href="<?= donationUrl((int)$d['donation_id']) ?>"><?= e($d['title']) ?></a> <span class="badge badge-pending">Pending</span></h3>
                <p style="color:var(--ink-soft); margin:4px 0;"><?= e($d['description'] ?: 'No description provided.') ?></p>
                <p style="margin:4px 0;"><strong><?= (int)$d['quantity'] ?> <?= e($d['unit']) ?></strong> · Pickup: <?= e($d['pickup_address']) ?></p>
                <?php if ($d['expiry_datetime']): ?><p style="margin:4px 0;">Expires: <?= e(date('M d, Y H:i', strtotime($d['expiry_datetime']))) ?></p><?php endif; ?>
                <p style="margin:4px 0; color:var(--ink-soft);">Donor: <?= e($d['donor_name']) ?> (<?= e($d['donor_email']) ?>) · Submitted <?= e(date('M d, H:i', strtotime($d['created_at']))) ?></p>
                <div style="display:flex; gap:10px; align-items:center; margin-top:8px;">
                    <?= donationThumb($d, '📦', 96) ?>
                    <a class="btn-small btn-secondary" href="<?= donationUrl((int)$d['donation_id']) ?>">View all photos/videos &amp; details</a>
                </div>
            </div>
            <div style="display:flex; flex-direction:column; gap:8px; min-width:220px;">
                <form method="post">
                    <input type="hidden" name="donation_id" value="<?= (int)$d['donation_id'] ?>">
                    <button class="btn-primary" name="action" value="approve" type="submit" style="width:100%;">Approve</button>
                </form>
                <form method="post" style="display:flex; flex-direction:column; gap:6px;">
                    <input type="hidden" name="donation_id" value="<?= (int)$d['donation_id'] ?>">
                    <input type="text" name="reason" placeholder="Reason (optional)" maxlength="200">
                    <button class="btn-secondary" name="action" value="reject" type="submit" onclick="return confirm('Reject this donation?')">Reject</button>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$pending): ?><p style="color:var(--ink-soft);">Nothing waiting. All donations are reviewed. 🎉</p><?php endif; ?>

<h2>Recently reviewed</h2>
<table class="data-table">
    <thead><tr><th>Donation</th><th>Donor</th><th>Decision</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
        <tr>
            <td><?= e($r['title']) ?></td>
            <td><?= e($r['donor_name']) ?></td>
            <td><span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst(str_replace('_', ' ', $r['status']))) ?></span>
                <?php if ($r['rejection_reason']): ?><br><small><?= e($r['rejection_reason']) ?></small><?php endif; ?></td>
            <td><?= $r['approved_at'] ? e(date('M d, H:i', strtotime($r['approved_at']))) : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="4">No reviews yet.</td></tr><?php endif; ?>
    </tbody>
</table>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
