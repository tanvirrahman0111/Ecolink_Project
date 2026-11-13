<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('receiver');

$db = getDB();
$uid = currentUserId();
$donationId = (int)($_GET['donation_id'] ?? $_POST['donation_id'] ?? 0);

$stmt = $db->prepare(
    "SELECT d.*, c.name AS category_name FROM donations d
     JOIN resource_categories c ON c.category_id = d.category_id WHERE d.donation_id = ?"
);
$stmt->execute([$donationId]);
$donation = $stmt->fetch();

if (!$donation) {
    flash('error', 'That resource could not be found.');
    redirect('/receiver/browse_matches.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim($_POST['reason'] ?? '');
    $type   = $_POST['type'] ?? 'resource';
    if (!in_array($type, ['resource','user','verification'], true)) $type = 'resource';

    if ($reason === '') {
        flash('error', 'Please describe the issue so an admin can review it.');
        redirect('/receiver/report_resource.php?donation_id=' . $donationId);
    }

    $db->prepare(
        "INSERT INTO moderation_reports (title, detail, type, priority, reported_by, donation_id, reported_user_id, reason)
         VALUES (?, ?, ?, 'medium', ?, ?, ?, ?)"
    )->execute([
        'Reported: ' . $donation['title'],
        $donation['category_name'] . ' · resource #ECO-' . str_pad((string)$donationId, 5, '0', STR_PAD_LEFT),
        $type, $uid, $donationId, $donation['donor_id'], $reason,
    ]);

    flash('success', 'Thanks — your report has been sent to the moderation team for review.');
    redirect('/receiver/browse_matches.php');
}

$pageTitle = 'Report a Resource';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Report a Resource</h1>
<p>Let the EcoLink moderation team know if something about this resource looks inaccurate or unsafe.</p>

<form method="post" class="mini-card" style="max-width:600px;">
    <input type="hidden" name="donation_id" value="<?= (int)$donationId ?>">
    <span class="eyebrow">Reported Item</span>
    <h3 style="margin:6px 0;"><?= e($donation['title']) ?></h3>
    <p style="color:var(--ink-soft);"><?= (int)$donation['quantity'] ?> <?= e($donation['unit']) ?> · <?= e(ucfirst(str_replace('_',' ',$donation['condition_grade']))) ?> condition · <?= e($donation['category_name']) ?></p>

    <label class="field"><span class="field-label">Issue Type</span>
        <select name="type">
            <option value="resource">Resource listing problem (condition, quantity, duplicate)</option>
            <option value="user">Account or claim dispute</option>
            <option value="verification">Verification information mismatch</option>
        </select>
    </label>
    <label class="field"><span class="field-label">What's wrong?</span>
        <textarea name="reason" rows="4" placeholder="e.g. The listed condition does not match what was described." required></textarea>
    </label>
    <div style="display:flex; gap:10px;">
        <button class="btn-accent" type="submit">Submit Report</button>
        <a class="btn-secondary" href="<?= BASE_URL ?>/receiver/browse_matches.php">Cancel</a>
    </div>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
