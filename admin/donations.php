<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/media.php';
requireRole('admin');

$db = getDB();

$stageCounts = $db->query("SELECT status, COUNT(*) c FROM donations GROUP BY status")->fetchAll();
$stages = array_fill_keys(['pending_approval','rejected','available','matched','accepted','pickup','delivered','completed','cancelled'], 0);
foreach ($stageCounts as $row) $stages[$row['status']] = (int)$row['c'];

$active = $db->query(
    "SELECT d.*, donor.full_name AS donor_name, recv.full_name AS receiver_name, recv.organization_name
     FROM donations d
     JOIN users donor ON donor.user_id = d.donor_id
     LEFT JOIN users recv ON recv.user_id = d.matched_receiver_id
     WHERE d.status NOT IN ('completed','cancelled','rejected')
     ORDER BY d.updated_at DESC LIMIT 20"
)->fetchAll();

$inProgress = $stages['pending_approval'] + $stages['available'] + $stages['matched'] + $stages['accepted'] + $stages['pickup'] + $stages['delivered'];

$pageTitle = 'Donation Lifecycle';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Donation Lifecycle Monitoring</h1>
<p>Monitor donation progress from resource availability to final completion.</p>

<section class="cards-row">
    <div class="mini-card"><span class="eyebrow">Active Donations</span><strong><?= $inProgress ?></strong><span>in progress</span></div>
    <div class="mini-card"><span class="eyebrow">Pickup Stage</span><strong><?= $stages['pickup'] ?></strong><span>awaiting pickup</span></div>
    <div class="mini-card"><span class="eyebrow">Delivered</span><strong><?= $stages['delivered'] ?></strong><span>awaiting completion</span></div>
    <div class="mini-card"><span class="eyebrow">Completed</span><strong><?= $stages['completed'] ?></strong><span>successful donations</span></div>
</section>

<h2>Lifecycle Overview</h2>
<div class="mini-card">
    <div class="stage-flow">
        <?php
        $flow = [
            1 => ['Available', $stages['available'] + $stages['pending_approval']],
            2 => ['Matched',   $stages['matched']],
            3 => ['Accepted',  $stages['accepted']],
            4 => ['Pickup',    $stages['pickup']],
            5 => ['Delivered', $stages['delivered']],
            6 => ['Completed', $stages['completed']],
        ];
        foreach ($flow as $num => $pair):
            [$label, $count] = $pair;
            $cls = $count > 0 ? 'done' : 'pending';
        ?>
            <div class="stage <?= $cls ?>">
                <div class="bar"></div>
                <div class="num"><?= $num ?></div>
                <strong><?= $label ?></strong>
                <small><?= $count ?></small>
            </div>
        <?php endforeach; ?>
    </div>
    <p style="color:var(--ink-soft); font-size:0.85rem; margin:0;">Current stage distribution across active and completed donation records.</p>
</div>

<h2>Active Donation Records</h2>
<table class="data-table">
    <thead><tr><th>Donation</th><th>Donor</th><th>Receiver</th><th>Stage</th><th>Updated</th></tr></thead>
    <tbody>
    <?php foreach ($active as $d): ?>
        <tr>
            <td><a href="<?= donationUrl((int)$d['donation_id']) ?>"><strong>ECO-<?= str_pad((string)$d['donation_id'], 5, '0', STR_PAD_LEFT) ?></strong></a><br><small><?= e($d['title']) ?> · <?= (int)$d['quantity'] ?> <?= e($d['unit']) ?></small></td>
            <td><?= e($d['donor_name']) ?></td>
            <td><?= e($d['organization_name'] ?: $d['receiver_name'] ?: '—') ?></td>
            <td><span class="badge badge-<?= e($d['status']) ?>"><?= e(ucfirst($d['status'])) ?></span></td>
            <td><?= e(date('M d, H:i', strtotime($d['updated_at']))) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$active): ?><tr><td colspan="5">No active donations right now.</td></tr><?php endif; ?>
    </tbody>
</table>

<div class="alert" style="background:var(--mint); border:1px solid var(--mint-line); color:var(--forest-2);">
    <strong>Lifecycle:</strong> Available → Matched → Accepted → Pickup → Delivered → Completed<br>
    Admin monitors status only; physical pickup and last-mile delivery remain outside the system's first-release scope.
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
