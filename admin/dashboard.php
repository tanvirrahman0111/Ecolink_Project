<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../matching/PriorityMatcher.php';
requireRole('admin');

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_matching'])) {
    $matcher = new PriorityMatcher($db);
    $results = $matcher->matchAllPending();
    flash('success', 'Matching run complete for ' . count($results) . ' pending donation(s).');
    redirect('/admin/dashboard.php');
}

$stats = $db->query(
    "SELECT
        (SELECT COUNT(*) FROM users WHERE role = 'donor') AS total_donors,
        (SELECT COUNT(*) FROM users WHERE role = 'receiver') AS total_receivers,
        (SELECT COUNT(*) FROM users WHERE role = 'receiver' AND is_verified = 0) AS unverified_receivers,
        (SELECT COUNT(*) FROM donations WHERE status = 'pending_approval') AS pending_approval,
        (SELECT COUNT(*) FROM donations WHERE status = 'available') AS available_donations,
        (SELECT COUNT(*) FROM donations WHERE status NOT IN ('completed','cancelled','rejected','pending_approval','available')) AS in_progress,
        (SELECT COUNT(*) FROM donations WHERE status = 'completed') AS completed_donations
    "
)->fetch();

$categoryBreakdown = $db->query(
    "SELECT c.name, COUNT(d.donation_id) AS total
     FROM resource_categories c LEFT JOIN donations d ON d.category_id = c.category_id
     GROUP BY c.category_id, c.name ORDER BY total DESC"
)->fetchAll();

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Admin Dashboard</h1>
<p>Platform-wide overview and controls for EcoLink.</p>

<div class="profile-banner">
    <div>
        <div class="eyebrow">Smart Matching Engine</div>
        <h2>Keep donations flowing to the right receivers.</h2>
        <p>EcoLink compares resource category, quantity, condition, location, and receiver needs to auto-match pending donations.</p>
    </div>
    <form method="post">
        <button class="btn-primary" type="submit" name="run_matching" value="1">Run Matching Now</button>
    </form>
</div>

<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Donors</span><strong><?= (int)$stats['total_donors'] ?></strong></div>
    <div class="mini-card"><span class="eyebrow">Receivers</span><strong><?= (int)$stats['total_receivers'] ?></strong></div>
    <div class="mini-card"><span class="eyebrow">Pending Verification</span><strong><?= (int)$stats['unverified_receivers'] ?></strong></div>
    <div class="mini-card"><span class="eyebrow">Awaiting Approval</span><strong><?= (int)$stats['pending_approval'] ?></strong><a href="<?= BASE_URL ?>/admin/approve_donations.php">Review →</a></div>
    <div class="mini-card"><span class="eyebrow">Open to Receivers</span><strong><?= (int)$stats['available_donations'] ?></strong></div>
    <div class="mini-card"><span class="eyebrow">In Progress</span><strong><?= (int)$stats['in_progress'] ?></strong></div>
    <div class="mini-card"><span class="eyebrow">Completed</span><strong><?= (int)$stats['completed_donations'] ?></strong></div>
</section>

<div class="page-actions">
    <a class="btn-secondary" href="<?= BASE_URL ?>/admin/approve_donations.php">Donation Approvals</a>
    <a class="btn-secondary" href="<?= BASE_URL ?>/admin/verify_users.php">Verification Queue</a>
    <a class="btn-secondary" href="<?= BASE_URL ?>/admin/matches.php">Match Monitoring</a>
    <a class="btn-secondary" href="<?= BASE_URL ?>/admin/donations.php">Donation Lifecycle</a>
    <a class="btn-secondary" href="<?= BASE_URL ?>/admin/impact_dashboard.php">Impact Dashboard</a>
    <a class="btn-secondary" href="<?= BASE_URL ?>/admin/moderation.php">Moderation Center</a>
</div>

<h2>Donations by category</h2>
<table class="data-table">
    <thead><tr><th>Category</th><th>Total Donations</th></tr></thead>
    <tbody>
    <?php foreach ($categoryBreakdown as $c): ?>
        <tr><td><?= e($c['name']) ?></td><td><?= (int)$c['total'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
