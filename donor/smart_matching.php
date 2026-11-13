<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../matching/RequestMatcher.php';
requireRole('donor');

$db = getDB();
$uid = currentUserId();

$myDonations = $db->prepare(
    "SELECT * FROM donations WHERE donor_id = ? AND status = 'available' ORDER BY created_at DESC"
);
$myDonations->execute([$uid]);
$myDonations = $myDonations->fetchAll();

$donationId = (int)($_GET['donation_id'] ?? ($myDonations[0]['donation_id'] ?? 0));
$activeDonation = null;
foreach ($myDonations as $d) {
    if ((int)$d['donation_id'] === $donationId) { $activeDonation = $d; break; }
}

$matches = [];
if ($activeDonation) {
    $matches = (new RequestMatcher($db))->findRequestsForDonation($activeDonation);
}

$pageTitle = 'Smart Matching';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Smart Matching</h1>
<p>Find suitable receivers for your surplus resources using EcoLink matching.</p>

<div class="profile-banner">
    <div>
        <div class="eyebrow">Smart Matching Engine</div>
        <h2>Compare your resource against receiver requests.</h2>
        <p>EcoLink compares your resource category, quantity, condition, location, and receiver needs to suggest the most suitable matches.</p>
    </div>
</div>

<?php if (!$myDonations): ?>
    <p>You don't have any available resources yet. <a href="<?= BASE_URL ?>/donor/upload_resource.php">Add one</a> to see smart matches.</p>
<?php else: ?>
    <h2>Match a Resource</h2>
    <form method="get" class="filter-bar" style="grid-template-columns: 1fr auto;">
        <label>Your Resource
            <select name="donation_id" onchange="this.form.submit()">
                <?php foreach ($myDonations as $d): ?>
                    <option value="<?= (int)$d['donation_id'] ?>" <?= (int)$d['donation_id'] === $donationId ? 'selected' : '' ?>>
                        <?= e($d['title']) ?> — <?= (int)$d['quantity'] ?> <?= e($d['unit']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn-primary" type="submit">Change Resource</button>
    </form>

    <h2>Recommended Matches <span style="float:right; font-weight:400; color:var(--ink-soft); font-size:0.9rem;"><?= count($matches) ?> matches found</span></h2>
    <div class="two-col" style="grid-template-columns: repeat(auto-fit, minmax(260px,1fr));">
        <?php foreach ($matches as $m): $r = $m['request']; ?>
            <div class="mini-card">
                <span class="pct-badge" style="float:right;"><?= $m['total_score'] ?>%</span>
                <h3 style="margin:0 0 2px;"><?= e($r['organization_name'] ?: $r['receiver_name']) ?></h3>
                <small style="color:var(--ink-soft);"><?= $r['is_verified'] ? 'Verified organization' : 'Unverified' ?></small>
                <p style="margin:12px 0 6px;"><small style="text-transform:uppercase; color:var(--ink-soft);">Match Details</small></p>
                <p style="margin:2px 0;">Needs <?= (int)$r['quantity_min'] ?>–<?= (int)$r['quantity_max'] ?> <?= e($r['title']) ?></p>
                <p style="margin:2px 0; color:var(--ink-soft);">Receiver type: <?= $r['is_verified'] ? 'Verified organization' : 'Unverified' ?></p>
                <p style="margin:2px 0 12px; color:var(--ink-soft);">Location: <?= e($r['pickup_location']) ?></p>
                <div style="border-top:1px solid var(--line); padding-top:10px;">
                    <small style="text-transform:uppercase; color:var(--ink-soft);">Why this match?</small><br>
                    <small>Category · quantity · condition · location</small>
                </div>
                <div style="display:flex; gap:8px; margin-top:12px;">
                    <span class="badge badge-completed"><?= RequestMatcher::label($m['total_score']) ?></span>
                    <a class="btn-accent btn-small" href="<?= BASE_URL ?>/donor/match_details.php?donation_id=<?= (int)$activeDonation['donation_id'] ?>&request_id=<?= (int)$r['request_id'] ?>">View Match →</a>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$matches): ?><p style="color:var(--ink-soft);">No matching receiver requests found for this resource yet.</p><?php endif; ?>
    </div>

    <div class="mini-card" style="margin-top:20px;">
        <h3 style="margin-top:0;">How EcoLink chooses a match</h3>
        <p style="color:var(--ink-soft);">The system evaluates key resource and receiver details to recommend relevant options.</p>
        <p>1 Category fit &nbsp; 2 Quantity need &nbsp; 3 Condition &nbsp; 4 Location &nbsp; 5 Receiver suitability</p>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
