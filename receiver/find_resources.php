<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/media.php';
require_once __DIR__ . '/../matching/RequestMatcher.php';
requireRole('receiver');

$db = getDB();
$uid = currentUserId();

$requests = $db->prepare(
    "SELECT r.*, c.name AS category_name FROM requests r
     JOIN resource_categories c ON c.category_id = r.category_id
     WHERE r.receiver_id = ? AND r.status = 'searching' ORDER BY r.created_at DESC"
);
$requests->execute([$uid]);
$requests = $requests->fetchAll();

$requestId = (int)($_GET['request_id'] ?? ($requests[0]['request_id'] ?? 0));
$activeRequest = null;
foreach ($requests as $r) {
    if ((int)$r['request_id'] === $requestId) { $activeRequest = $r; break; }
}

$matches = [];
if ($activeRequest) {
    $matches = (new RequestMatcher($db))->findMatches($activeRequest);
}

// Every admin-approved donation, regardless of whether the receiver has posted a request.
$allAvailable = $db->prepare(
    "SELECT d.*, c.name AS category_name, u.full_name AS donor_name,
            (SELECT status FROM donation_requests dr WHERE dr.donation_id = d.donation_id AND dr.receiver_id = ?) AS my_request_status
     FROM donations d
     JOIN resource_categories c ON c.category_id = d.category_id
     JOIN users u ON u.user_id = d.donor_id
     WHERE d.status = 'available' ORDER BY d.created_at DESC"
);
$allAvailable->execute([$uid]);
$allAvailable = $allAvailable->fetchAll();

$categoryIcons = ['Food' => '🍲', 'Clothes' => '👕', 'Books' => '📚', 'Electronics' => '💻', 'Furniture' => '🛋️'];

$pageTitle = 'Find Resources';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Available Resources</h1>
<p>Resources matched to your organization's current needs.</p>

<?php if (!$activeRequest): ?>
    <div class="profile-banner">
        <div>
            <div class="eyebrow">No Active Request</div>
            <h2>Post a request to see matches.</h2>
            <p>Once you post a specific resource request, EcoLink will show suitable donor resources here.</p>
        </div>
        <a class="btn-primary" href="<?= BASE_URL ?>/receiver/post_request.php">Post a Request →</a>
    </div>
<?php else: ?>
    <?php if (count($requests) > 1): ?>
        <form method="get" class="filter-bar" style="grid-template-columns: 1fr auto;">
            <label>Active Request
                <select name="request_id" onchange="this.form.submit()">
                    <?php foreach ($requests as $r): ?>
                        <option value="<?= (int)$r['request_id'] ?>" <?= (int)$r['request_id'] === $requestId ? 'selected' : '' ?>>
                            <?= e($r['title']) ?> · <?= (int)$r['quantity_min'] ?>-<?= (int)$r['quantity_max'] ?> units
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn-primary" type="submit">Switch</button>
        </form>
    <?php endif; ?>

    <div class="profile-banner">
        <div>
            <div class="eyebrow">Active Request</div>
            <h2><?= e($activeRequest['title']) ?> · <?= (int)$activeRequest['quantity_min'] ?>–<?= (int)$activeRequest['quantity_max'] ?> units needed</h2>
            <p>EcoLink found <?= count($matches) ?> suitable donor resource(s) based on category, quantity, condition, and location.</p>
        </div>
        <a class="btn-secondary" href="<?= BASE_URL ?>/receiver/request_tracking.php?request_id=<?= (int)$activeRequest['request_id'] ?>">View My Request</a>
    </div>

    <h2>Recommended Donor Resources</h2>
    <?php foreach ($matches as $m): $d = $m['donation']; ?>
        <div class="resource-row">
            <div class="icon" style="background:none;"><?= donationThumb($d, $categoryIcons[$activeRequest['category_name']] ?? '📦', 64) ?></div>
            <div class="info">
                <h4><a href="<?= donationUrl((int)$d['donation_id']) ?>"><?= e($d['title']) ?></a></h4>
                <small><?= (int)$d['quantity'] ?> <?= e($d['unit']) ?> · <?= e(ucfirst(str_replace('_',' ',$d['condition_grade']))) ?> condition · DONOR <?= e($d['donor_name']) ?></small>
            </div>
            <div style="text-align:center;">
                <span class="pct-badge"><?= $m['total_score'] ?>%</span>
                <div><small style="color:var(--ink-soft);"><?= RequestMatcher::label($m['total_score']) ?></small></div>
            </div>
            <a class="btn-accent btn-small" href="<?= BASE_URL ?>/receiver/resource_details.php?donation_id=<?= (int)$d['donation_id'] ?>&request_id=<?= (int)$activeRequest['request_id'] ?>">View Resource</a>
        </div>
    <?php endforeach; ?>
    <?php if (!$matches): ?>
        <p>No suitable donor resources available yet for this request. EcoLink will keep looking as new donations come in.</p>
    <?php endif; ?>

    <div class="alert" style="background:var(--mint); border:1px solid var(--mint-line); color:var(--forest-2);">
        <strong>Smart Matching helps you find relevant surplus faster.</strong><br>
        Open a resource to review donor details, suitability, and the next step before confirming.
    </div>
<?php endif; ?>

<h2>All Approved Donations (<?= count($allAvailable) ?>)</h2>
<p style="color:var(--ink-soft);">Every donation approved by the admin. Send a request and the donor will accept or reject it.</p>
<?php foreach ($allAvailable as $d): ?>
    <div class="resource-row">
        <div class="icon" style="background:none;"><?= donationThumb($d, $categoryIcons[$d['category_name']] ?? '📦', 64) ?></div>
        <div class="info">
            <h4><a href="<?= donationUrl((int)$d['donation_id']) ?>"><?= e($d['title']) ?></a></h4>
            <small><?= e($d['category_name']) ?> · <?= (int)$d['quantity'] ?> <?= e($d['unit']) ?> · <?= e(ucfirst(str_replace('_',' ',$d['condition_grade']))) ?> condition · DONOR <?= e($d['donor_name']) ?></small>
        </div>
        <?php if ($d['my_request_status'] === 'pending'): ?>
            <span class="badge badge-pending">Request sent</span>
        <?php elseif ($d['my_request_status'] === 'accepted'): ?>
            <span class="badge badge-accepted">Accepted</span>
        <?php else: ?>
            <a class="btn-accent btn-small" href="<?= BASE_URL ?>/receiver/browse_donations.php">Request</a>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php if (!$allAvailable): ?><p style="color:var(--ink-soft);">No approved donations yet.</p><?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
