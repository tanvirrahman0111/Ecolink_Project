<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../matching/RequestMatcher.php';
requireRole('donor');

$db = getDB();
$uid = currentUserId();
$donationId = (int)($_GET['donation_id'] ?? $_POST['donation_id'] ?? 0);
$requestId  = (int)($_GET['request_id'] ?? $_POST['request_id'] ?? 0);

$donStmt = $db->prepare("SELECT * FROM donations WHERE donation_id = ? AND donor_id = ?");
$donStmt->execute([$donationId, $uid]);
$donation = $donStmt->fetch();

$reqStmt = $db->prepare(
    "SELECT r.*, u.full_name AS receiver_name, u.organization_name, u.is_verified
     FROM requests r JOIN users u ON u.user_id = r.receiver_id WHERE r.request_id = ?"
);
$reqStmt->execute([$requestId]);
$request = $reqStmt->fetch();

if (!$donation || !$request) {
    flash('error', 'That resource or request could not be found.');
    redirect('/donor/smart_matching.php');
}

$score = (new RequestMatcher($db))->scoreOne($donation, $request);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'accept' && $donation['status'] === 'available' && $request['status'] === 'searching') {
        // A donor can NOT assign a receiver directly. The receiver has to send a
        // request for the donation first; then the donor accepts it from
        // "Receiver Requests". Here we only invite the receiver.
        $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'matching', ?, ?)")->execute([
            $request['receiver_id'], 'A donation may fit your request',
            'A donor has "' . mb_substr($donation['title'], 0, 100) . '" that may fit your request "' . mb_substr($request['title'], 0, 80) . '". Open Browse Donations to request it.',
        ]);
        flash('success', 'The receiver was invited to request your donation. You can accept their request from Receiver Requests.');
        redirect('/donor/smart_matching.php?donation_id=' . $donationId);
    } elseif ($action === 'decline') {
        flash('success', 'This match was skipped.');
        redirect('/donor/smart_matching.php?donation_id=' . $donationId);
    }
}

$pageTitle = 'Match Details';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Match Details</h1>
<p>Review the receiver request before confirming your donation match.</p>

<div class="two-col" style="align-items:start;">
    <div class="mini-card">
        <span class="pct-badge"><?= $score['total_score'] ?>% MATCH</span>
        <h2 style="margin:10px 0 2px;"><?= e($request['organization_name'] ?: $request['receiver_name']) ?></h2>
        <small style="color:var(--ink-soft);"><?= $request['is_verified'] ? 'Verified receiver' : 'Unverified receiver' ?></small>
        <div style="background:var(--bg); border-radius:10px; padding:14px; margin:14px 0;">
            <strong>Receiver Request</strong>
            <p style="color:var(--ink-soft); margin:6px 0 0;">The organization currently needs <?= e(strtolower($request['title'])) ?> <?= $request['reason'] ? '— ' . e($request['reason']) : '' ?></p>
            <p style="margin:6px 0 0;">Requested quantity: <strong><?= (int)$request['quantity_min'] ?>–<?= (int)$request['quantity_max'] ?> units</strong></p>
            <p style="margin:2px 0 0;">Location: <strong><?= e($request['pickup_location']) ?></strong></p>
        </div>
        <h3>Request Details</h3>
        <table class="data-table">
            <tr><td>Resource needed</td><td><strong><?= e($request['title']) ?></strong></td></tr>
            <tr><td>Category</td><td><?= (int)$donation['category_id'] === (int)$request['category_id'] ? '✓ Matches your donation' : 'Different category' ?></td></tr>
            <tr><td>Quantity requested</td><td><?= (int)$request['quantity_min'] ?>–<?= (int)$request['quantity_max'] ?> units</td></tr>
            <tr><td>Condition accepted</td><td><?= e(ucfirst(str_replace('_',' ',$request['condition_preference']))) ?></td></tr>
            <tr><td>Preferred location</td><td><?= e($request['pickup_location']) ?></td></tr>
        </table>
        <h3>Why this match?</h3>
        <ul style="color:var(--ink-soft);">
            <?php foreach ($score['reasons'] as $reason): ?><li><?= e($reason) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <div>
        <div class="mini-card">
            <span class="eyebrow">Your Donation</span>
            <h3 style="margin:6px 0;"><?= e($donation['title']) ?></h3>
            <p style="color:var(--ink-soft);"><?= (int)$donation['quantity'] ?> <?= e($donation['unit']) ?> · <?= e(ucfirst(str_replace('_',' ',$donation['condition_grade']))) ?> condition · Available for pickup</p>
            <hr style="border:none; border-top:1px solid var(--line); margin:12px 0;">
            <strong>Match score</strong>
            <div style="display:flex; justify-content:space-between; align-items:center; margin:6px 0;">
                <div class="bar" style="width:80%; height:6px; background:var(--line); border-radius:4px;"><span style="display:block; height:100%; width:<?= $score['total_score'] ?>%; background:var(--forest-2); border-radius:4px;"></span></div>
                <strong><?= $score['total_score'] ?>%</strong>
            </div>
            <small style="color:var(--ink-soft);"><?= RequestMatcher::label($score['total_score']) ?> based on available information.</small>
            <hr style="border:none; border-top:1px solid var(--line); margin:12px 0;">
            <p><strong>Interested in this receiver?</strong><br><small style="color:var(--ink-soft);">The receiver will be notified and can then send you a request. You accept it from Receiver Requests.</small></p>
            <form method="post" style="display:flex; flex-direction:column; gap:8px;">
                <input type="hidden" name="donation_id" value="<?= (int)$donationId ?>">
                <input type="hidden" name="request_id" value="<?= (int)$requestId ?>">
                <button class="btn-primary" name="action" value="accept" type="submit">Invite Receiver to Request →</button>
                <button class="btn-secondary" name="action" value="decline" type="submit">Decline</button>
            </form>
        </div>
        <div class="mini-card" style="margin-top:16px;">
            <span class="eyebrow">Donation Journey</span>
            <p style="color:var(--ink-soft);">After you accept this match:</p>
            <p>Matched → Accepted → Pickup → Delivered → Completed</p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
