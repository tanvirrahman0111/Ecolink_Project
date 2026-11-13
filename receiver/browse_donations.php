<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/media.php';
requireRole('receiver');

$db = getDB();
$uid = currentUserId();

$me = $db->prepare("SELECT is_verified FROM users WHERE user_id = ?");
$me->execute([$uid]);
$isVerified = (bool)$me->fetchColumn();

// ---- Send a request to the donor ------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request') {
    $donationId = (int)($_POST['donation_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');

    $stmt = $db->prepare("SELECT * FROM donations WHERE donation_id = ? AND status = 'available'");
    $stmt->execute([$donationId]);
    $donation = $stmt->fetch();

    if (!$isVerified) {
        flash('error', 'Your account must be verified by an admin before you can request donations.');
    } elseif (!$donation) {
        flash('error', 'This donation is no longer available.');
    } else {
        $exists = $db->prepare("SELECT status FROM donation_requests WHERE donation_id = ? AND receiver_id = ?");
        $exists->execute([$donationId, $uid]);
        $prev = $exists->fetchColumn();
        if ($prev === 'pending' || $prev === 'accepted') {
            flash('error', 'You already requested this donation.');
        } else {
            if ($prev !== false) {
                // previously rejected/cancelled: allow asking again
                $db->prepare("UPDATE donation_requests SET status = 'pending', message = ?, created_at = NOW(), responded_at = NULL WHERE donation_id = ? AND receiver_id = ?")
                   ->execute([$message ?: null, $donationId, $uid]);
            } else {
                $db->prepare("INSERT INTO donation_requests (donation_id, receiver_id, message) VALUES (?, ?, ?)")
                   ->execute([$donationId, $uid, $message ?: null]);
            }
            $org = $db->prepare("SELECT COALESCE(NULLIF(organization_name,''), full_name) FROM users WHERE user_id = ?");
            $org->execute([$uid]);
            $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'request', ?, ?)")
               ->execute([$donation['donor_id'], 'New request for your donation',
                          $org->fetchColumn() . ' requested "' . mb_substr($donation['title'], 0, 100) . '". Open Receiver Requests to accept or reject.']);
            flash('success', 'Request sent! The donor will review it and you will be notified.');
        }
    }
    redirect('/receiver/browse_donations.php');
}

// ---- Filters --------------------------------------------------------
$category = (int)($_GET['category'] ?? 0);
$search   = trim($_GET['search'] ?? '');
$where = ["d.status = 'available'"];
$params = [];
if ($category > 0) { $where[] = 'd.category_id = ?'; $params[] = $category; }
if ($search !== '') { $where[] = '(d.title LIKE ? OR d.description LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }

$stmt = $db->prepare(
    "SELECT d.*, c.name AS category_name, u.full_name AS donor_name,
            (SELECT status FROM donation_requests dr WHERE dr.donation_id = d.donation_id AND dr.receiver_id = ?) AS my_request_status,
            (SELECT COUNT(*) FROM donation_requests dr WHERE dr.donation_id = d.donation_id AND dr.status = 'pending') AS pending_requests
     FROM donations d
     JOIN resource_categories c ON c.category_id = d.category_id
     JOIN users u ON u.user_id = d.donor_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY d.created_at DESC"
);
$stmt->execute(array_merge([$uid], $params));
$donations = $stmt->fetchAll();
$categories = $db->query("SELECT category_id, name FROM resource_categories ORDER BY name")->fetchAll();
$icons = ['Food' => '🍲', 'Clothes' => '👕', 'Books' => '📚', 'Electronics' => '💻', 'Furniture' => '🛋️'];

$pageTitle = 'Browse Donations';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Browse Donations</h1>
<p>All admin-approved donations. Pick what you need and send a request to the donor.</p>

<?php if (!$isVerified): ?>
    <div class="alert alert-error">Your account is pending admin verification. You can browse, but you can only request donations once verified.</div>
<?php endif; ?>

<form method="get" class="filter-bar">
    <label>Search<input type="text" name="search" value="<?= e($search) ?>" placeholder="Title or description"></label>
    <label>Category
        <select name="category">
            <option value="0">All categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['category_id'] ?>" <?= $category === (int)$c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn-accent" type="submit">Filter</button>
</form>

<?php foreach ($donations as $d): ?>
    <div class="mini-card" id="d<?= (int)$d['donation_id'] ?>" style="margin-bottom:14px;">
        <div style="display:flex; gap:16px; flex-wrap:wrap; justify-content:space-between; align-items:flex-start;">
            <?= donationThumb($d, $icons[$d['category_name']] ?? '📦', 110) ?>
            <div style="flex:1; min-width:260px;">
                <span class="eyebrow"><?= $icons[$d['category_name']] ?? '📦' ?> <?= e($d['category_name']) ?> · <?= e(ucfirst(str_replace('_', ' ', $d['condition_grade']))) ?> condition</span>
                <h3 style="margin:6px 0;"><a href="<?= donationUrl((int)$d['donation_id']) ?>"><?= e($d['title']) ?></a></h3>
                <p style="color:var(--ink-soft); margin:4px 0;"><?= e($d['description'] ?: 'No description provided.') ?></p>
                <p style="margin:4px 0;"><strong><?= (int)$d['quantity'] ?> <?= e($d['unit']) ?></strong> · Pickup: <?= e($d['pickup_address']) ?></p>
                <?php if ($d['expiry_datetime']): ?><p style="margin:4px 0;">Expires: <?= e(date('M d, Y H:i', strtotime($d['expiry_datetime']))) ?></p><?php endif; ?>
                <a href="<?= donationUrl((int)$d['donation_id']) ?>" style="display:block; margin:6px 0;">📷 Tap to see photos, videos &amp; full details</a>
                <small style="color:var(--ink-soft);">Donor: <?= e($d['donor_name']) ?> · Posted <?= e(date('M d, Y', strtotime($d['created_at']))) ?> · <?= (int)$d['pending_requests'] ?> pending request(s)</small>
            </div>
            <div style="min-width:240px;">
                <?php if ($d['my_request_status'] === 'pending'): ?>
                    <span class="badge badge-pending">Request sent - waiting for donor</span>
                <?php elseif ($d['my_request_status'] === 'accepted'): ?>
                    <span class="badge badge-accepted">Accepted</span>
                <?php else: ?>
                    <?php if ($d['my_request_status'] === 'rejected'): ?><p><span class="badge badge-rejected">Previous request rejected</span></p><?php endif; ?>
                    <form method="post" style="display:flex; flex-direction:column; gap:8px;">
                        <input type="hidden" name="action" value="request">
                        <input type="hidden" name="donation_id" value="<?= (int)$d['donation_id'] ?>">
                        <input type="text" name="message" maxlength="400" placeholder="Message to donor (optional)">
                        <button class="btn-primary" type="submit" <?= $isVerified ? '' : 'disabled' ?>>Request this Donation</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$donations): ?><p style="color:var(--ink-soft);">No approved donations available right now. Check back soon.</p><?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
