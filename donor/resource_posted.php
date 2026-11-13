<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('donor');

$db = getDB();
$donationId = (int)($_GET['donation_id'] ?? 0);

$stmt = $db->prepare(
    "SELECT d.*, c.name AS category_name FROM donations d
     JOIN resource_categories c ON c.category_id = d.category_id
     WHERE d.donation_id = ? AND d.donor_id = ?"
);
$stmt->execute([$donationId, currentUserId()]);
$donation = $stmt->fetch();

if (!$donation) {
    flash('error', 'That resource could not be found.');
    redirect('/donor/my_donations.php');
}

$statusLabels = [
    'pending_approval' => 'Pending admin approval',
    'rejected'  => 'Rejected by admin',
    'available' => 'Approved - visible to receivers',
    'matched'   => 'Matched with a receiver',
    'accepted'  => 'Accepted by receiver',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resource Posted – <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= BASE_URL ?>/index.php"><span class="brand-dot"></span> EcoLink
            <small style="display:block; font-weight:400; font-size:0.72rem; color:var(--ink-soft);">Smart surplus redistribution</small>
        </a>
    </div>
</header>

<div class="status-card">
    <div class="status-icon"><span class="inner">✓</span></div>
    <h1>Resource Submitted!</h1>
    <p>An admin will review your resource. Once approved, receivers can see it and send you requests.</p>

    <div class="status-detail">
        <div>
            <div class="k">Resource ID</div>
            <div class="v">EL-<?= str_pad((string)$donation['donation_id'], 4, '0', STR_PAD_LEFT) ?></div>
        </div>
        <div>
            <div class="k">Category</div>
            <div class="v"><?= e($donation['category_name']) ?></div>
        </div>
        <div>
            <div class="k">Status</div>
            <div class="v" style="color:var(--forest-2);"><?= e($statusLabels[$donation['status']] ?? ucfirst($donation['status'])) ?></div>
        </div>
        <div>
            <div class="k">Quantity</div>
            <div class="v"><?= (int)$donation['quantity'] ?> <?= e($donation['unit']) ?></div>
        </div>
    </div>

    <div class="status-actions">
        <a class="btn-accent" href="<?= BASE_URL ?>/donor/my_donations.php">View My Donation</a>
        <div class="row">
            <a class="btn-soft" href="<?= BASE_URL ?>/donor/dashboard.php">Go to Dashboard</a>
            <a class="btn-secondary" href="<?= BASE_URL ?>/donor/upload_resource.php">Post Another</a>
        </div>
    </div>
</div>

<p class="auth-footnote">EcoLink • Smart surplus redistribution platform</p>
</body>
</html>
