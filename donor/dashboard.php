<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
requireRole('donor');

$db = getDB();
$uid = currentUserId();

$counts = $db->prepare(
    "SELECT status, COUNT(*) as c FROM donations WHERE donor_id = ? GROUP BY status"
);
$counts->execute([$uid]);
$byStatus = array_fill_keys(['pending_approval','available','accepted','pickup','delivered','completed','rejected'], 0);
foreach ($counts->fetchAll() as $row) $byStatus[$row['status']] = (int)$row['c'];

$recent = $db->prepare(
    "SELECT d.*, c.name AS category_name
     FROM donations d JOIN resource_categories c ON c.category_id = d.category_id
     WHERE d.donor_id = ? ORDER BY d.created_at DESC LIMIT 5"
);
$recent->execute([$uid]);

$pageTitle = 'Donor Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Good morning, <?= e($_SESSION['full_name']) ?> 👋</h1>
<p>Here's an overview of your EcoLink activity.</p>

<div class="profile-banner">
    <div>
        <div class="eyebrow">Your Donor Profile</div>
        <h2>Have something to give?</h2>
        <p>Share a useful surplus resource and help it reach someone in need.</p>
    </div>
    <a class="btn-primary" href="<?= BASE_URL ?>/donor/upload_resource.php">+ Add Resource</a>
</div>

<section class="cards-row">
    <?php foreach ($byStatus as $status => $c): ?>
        <div class="mini-card">
            <span class="eyebrow"><?= e(ucfirst(str_replace('_',' ',$status))) ?></span>
            <strong><?= $c ?></strong>
        </div>
    <?php endforeach; ?>
</section>

<h2>Recent Donations</h2>
<table class="data-table">
    <thead><tr><th>Title</th><th>Category</th><th>Qty</th><th>Status</th><th>Uploaded</th></tr></thead>
    <tbody>
    <?php foreach ($recent->fetchAll() as $r): ?>
        <tr>
            <td><?= e($r['title']) ?></td>
            <td><?= e($r['category_name']) ?></td>
            <td><?= (int)$r['quantity'] ?> <?= e($r['unit']) ?></td>
            <td><span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td><?= e(date('d M Y', strtotime($r['created_at']))) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($recent->rowCount() === 0): ?>
        <tr><td colspan="5">No donations yet. <a href="<?= BASE_URL ?>/donor/upload_resource.php">Upload your first one</a>.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
