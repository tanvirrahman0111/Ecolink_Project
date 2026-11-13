<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../matching/RequestMatcher.php';
requireRole('receiver');

$db = getDB();
$uid = currentUserId();
$donationId = (int)($_GET['donation_id'] ?? $_POST['donation_id'] ?? 0);
$requestId  = (int)($_GET['request_id'] ?? $_POST['request_id'] ?? 0);

$donStmt = $db->prepare(
    "SELECT d.*, c.name AS category_name, u.full_name AS donor_name
     FROM donations d JOIN resource_categories c ON c.category_id = d.category_id
     JOIN users u ON u.user_id = d.donor_id WHERE d.donation_id = ?"
);
$donStmt->execute([$donationId]);
$donation = $donStmt->fetch();

$reqStmt = $db->prepare("SELECT * FROM requests WHERE request_id = ? AND receiver_id = ?");
$reqStmt->execute([$requestId, $uid]);
$request = $reqStmt->fetch();

if (!$donation || !$request) {
    flash('error', 'That resource or request could not be found.');
    redirect('/receiver/find_resources.php');
}

$score = (new RequestMatcher($db))->scoreOne($donation, $request);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'accept' && $donation['status'] === 'available' && $request['status'] === 'searching') {
        // The receiver only SENDS A REQUEST here. The donor must accept it.
        $verified = $db->prepare("SELECT is_verified FROM users WHERE user_id = ?");
        $verified->execute([$uid]);
        if (!$verified->fetchColumn()) {
            flash('error', 'Your account must be verified by an admin before you can request donations.');
            redirect('/receiver/find_resources.php?request_id=' . $requestId);
        }
        $db->prepare("INSERT INTO donation_requests (donation_id, receiver_id, request_id, message)
                      VALUES (?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE status = 'pending', request_id = VALUES(request_id), message = VALUES(message), created_at = NOW(), responded_at = NULL")
           ->execute([$donationId, $uid, $requestId, 'For request: ' . mb_substr($request['title'], 0, 120)]);
        $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'request', ?, ?)")->execute([
            $donation['donor_id'], 'New request for your donation',
            'A receiver requested "' . mb_substr($donation['title'], 0, 100) . '". Open Receiver Requests to accept or reject.',
        ]);
        flash('success', 'Request sent to the donor! You will be notified when they respond.');
        redirect('/receiver/my_donation_requests.php');
    } elseif ($action === 'decline') {
        flash('success', 'No problem — this resource was skipped for this request.');
        redirect('/receiver/find_resources.php?request_id=' . $requestId);
    } else {
        flash('error', 'This action is no longer available (the resource or request may have already moved on).');
        redirect('/receiver/find_resources.php?request_id=' . $requestId);
    }
}

$pageTitle = 'Resource Details';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Resource Details</h1>
<p>Review the donor resource and confirm whether it meets your request.</p>

<div class="two-col" style="align-items:start;">
    <div class="mini-card">
        <span class="eyebrow"><?= e($donation['category_name']) ?> · <?= e(ucfirst(str_replace('_',' ',$donation['condition_grade']))) ?> condition</span>
        <h2 style="margin:6px 0;"><?= e($donation['title']) ?></h2>
        <p style="color:var(--ink-soft);"><?= e($donation['description'] ?: 'No additional description provided.') ?></p>
        <div class="two-col" style="margin-top:14px;">
            <div><small style="color:var(--ink-soft);text-transform:uppercase;">Quantity Available</small><br><strong><?= (int)$donation['quantity'] ?> <?= e($donation['unit']) ?></strong></div>
            <div><small style="color:var(--ink-soft);text-transform:uppercase;">Pickup</small><br><strong><?= e($donation['pickup_address']) ?></strong></div>
        </div>
        <p style="margin-top:14px;"><small style="color:var(--ink-soft);text-transform:uppercase;">Donor</small><br><?= e($donation['donor_name']) ?> · Posted <?= e(date('M d, Y', strtotime($donation['created_at']))) ?></p>
        <p><strong>Resource status:</strong> <span class="badge badge-<?= e($donation['status']) ?>"><?= e(ucfirst($donation['status'])) ?></span></p>
        <span class="pct-badge"><?= $score['total_score'] ?>% MATCH</span>
    </div>
    <div class="mini-card">
        <span class="eyebrow">Your Request</span>
        <h3 style="margin:6px 0;"><?= e($request['title']) ?></h3>
        <p style="color:var(--ink-soft);">Need <?= (int)$request['quantity_min'] ?>–<?= (int)$request['quantity_max'] ?> units · <?= e($request['pickup_location']) ?></p>
        <div style="background:var(--mint); border:1px solid var(--mint-line); border-radius:10px; padding:14px; margin-top:10px;">
            <strong>Why this is a good match</strong>
            <ul style="margin:8px 0 0; padding-left:18px; color:var(--ink-soft);">
                <?php foreach ($score['reasons'] as $reason): ?><li><?= e($reason) ?></li><?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<div class="mini-card" style="margin-top:20px;">
    <h3 style="margin-top:0;">Request this donation?</h3>
    <p style="color:var(--ink-soft);">This sends your request to the donor. Delivery begins once the donor accepts.</p>
    <form method="post" style="display:flex; gap:10px; flex-wrap:wrap;">
        <input type="hidden" name="donation_id" value="<?= (int)$donationId ?>">
        <input type="hidden" name="request_id" value="<?= (int)$requestId ?>">
        <button class="btn-primary" name="action" value="accept" type="submit">Send Request to Donor →</button>
        <button class="btn-secondary" name="action" value="decline" type="submit">Decline</button>
    </form>
</div>
<p style="color:var(--ink-soft); margin-top:14px;"><strong>Next step:</strong> After acceptance, EcoLink will move this into pickup coordination. Track progress anytime from <a href="<?= BASE_URL ?>/receiver/browse_matches.php">My Requests</a>.</p>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
