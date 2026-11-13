<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/hub.php';
requireRole('donor');

$db = getDB();
ensureHubTables($db);
syncHubAssignments($db);
$uid = currentUserId();

$me = $db->prepare("SELECT COALESCE(NULLIF(pickup_area,''), address) FROM users WHERE user_id = ?");
$me->execute([$uid]);
$myArea = (string)$me->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $donationId = (int)($_POST['donation_id'] ?? 0);
    $hubId = (int)($_POST['hub_id'] ?? 0);

    $st = $db->prepare("SELECT * FROM donations WHERE donation_id = ? AND donor_id = ? AND status NOT IN ('completed','cancelled','rejected','delivered')");
    $st->execute([$donationId, $uid]);
    $d = $st->fetch();
    $hs = $db->prepare("SELECT * FROM delivery_hubs WHERE hub_id = ? AND is_active = 1");
    $hs->execute([$hubId]);
    $hub = $hs->fetch();
    $cur = $db->prepare("SELECT * FROM hub_items WHERE donation_id = ?");
    $cur->execute([$donationId]);
    $item = $cur->fetch();

    if (!$d || !$hub) {
        flash('error', 'Please pick one of your donations and an active hub.');
    } elseif ($item && $item['status'] !== 'awaiting_dropoff') {
        flash('error', 'The hub already received this item, so the hub cannot be changed.');
    } else {
        if ($item) {
            $db->prepare("UPDATE hub_items SET hub_id = ? WHERE hub_item_id = ?")->execute([$hubId, $item['hub_item_id']]);
        } else {
            $db->prepare("INSERT INTO hub_items (hub_id, donation_id, donor_id) VALUES (?, ?, ?)")->execute([$hubId, $donationId, $uid]);
        }
        hubNotifyStaff($db, $hubId, 'Incoming donation', 'A donor will bring "' . mb_substr($d['title'], 0, 80) . '" (donation #' . $donationId . ') to your hub.');
        flash('success', 'Hub saved: ' . $hub['name'] . '. Take the item there — the hub staff will confirm they received it.');
    }
    redirect('/donor/hub_dropoff.php');
}

$hubs = $db->query("SELECT * FROM delivery_hubs WHERE is_active = 1 ORDER BY area, name")->fetchAll();
usort($hubs, fn($a, $b) => (int)sameArea($b['area'], $myArea) <=> (int)sameArea($a['area'], $myArea));

$st = $db->prepare(
    "SELECT d.donation_id, d.title, d.quantity, d.unit, d.status
     FROM donations d
     WHERE d.donor_id = ? AND d.status IN ('pending_approval','available','matched','accepted','pickup')
       AND NOT EXISTS (SELECT 1 FROM hub_items i WHERE i.donation_id = d.donation_id)
     ORDER BY d.created_at DESC"
);
$st->execute([$uid]);
$nohub = $st->fetchAll();

$st = $db->prepare(
    "SELECT i.*, h.name AS hub_name, h.area, h.address AS hub_address, h.phone AS hub_phone, h.open_hours,
            d.title, d.quantity, d.unit, d.status AS donation_status
     FROM hub_items i JOIN delivery_hubs h ON h.hub_id = i.hub_id JOIN donations d ON d.donation_id = i.donation_id
     WHERE i.donor_id = ? ORDER BY i.created_at DESC"
);
$st->execute([$uid]);
$mine = $st->fetchAll();

$pageTitle = 'Delivery Hub';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Delivery Hub</h1>
<p>You choose a delivery hub when you add a donation. Take the item to that hub — the hub staff confirm they received it, and the receiver then collects it there with a Pickup ID.</p>

<?php if ($nohub): ?>
<h2>Donations without a hub (<?= count($nohub) ?>)</h2>
<?php foreach ($nohub as $r): ?>
    <div class="mini-card" style="margin-bottom:14px;">
        <div style="display:flex; gap:16px; flex-wrap:wrap; justify-content:space-between; align-items:center;">
            <div style="flex:1; min-width:220px;">
                <h3 style="margin:0 0 4px;"><?= e($r['title']) ?></h3>
                <small><?= (int)$r['quantity'] ?> <?= e($r['unit']) ?> · <?= e(ucwords(str_replace('_', ' ', $r['status']))) ?></small>
            </div>
            <?php if ($hubs): ?>
            <form method="post" style="display:flex; gap:8px; flex-wrap:wrap;">
                <input type="hidden" name="donation_id" value="<?= (int)$r['donation_id'] ?>">
                <select name="hub_id" required style="padding:10px; border-radius:10px; border:1px solid var(--line); min-width:240px;">
                    <?php foreach ($hubs as $h): ?>
                        <option value="<?= (int)$h['hub_id'] ?>"><?= e($h['name']) ?> — <?= e($h['area']) ?><?= sameArea($h['area'], $myArea) ? ' ⭐ your area' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn-accent" type="submit">Choose this hub</button>
            </form>
            <?php else: ?><small>No hub is open yet — please ask an admin to create one.</small><?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
<?php endif; ?>

<h2>My hub drop-offs</h2>
<table class="data-table">
    <thead><tr><th>Item</th><th>Hub</th><th>Status</th><th>Change hub</th></tr></thead>
    <tbody>
    <?php foreach ($mine as $r): ?>
        <tr>
            <td><strong><?= e($r['title']) ?></strong><br><small><?= (int)$r['quantity'] ?> <?= e($r['unit']) ?> · donation #<?= (int)$r['donation_id'] ?> · <?= e(ucwords(str_replace('_', ' ', $r['donation_status']))) ?></small></td>
            <td><strong><?= e($r['hub_name']) ?></strong><br><small>📍 <?= e($r['hub_address']) ?><?= $r['hub_phone'] ? '<br>📞 ' . e($r['hub_phone']) : '' ?><?= $r['open_hours'] ? '<br>🕒 ' . e($r['open_hours']) : '' ?></small></td>
            <td>
                <span class="badge <?= $r['status'] === 'awaiting_dropoff' ? 'badge-accepted' : ($r['status'] === 'stored' ? 'badge-pickup' : 'badge-completed') ?>"><?= e(hubStatusLabel($r['status'])) ?></span>
                <?php if ($r['status'] === 'awaiting_dropoff' && $r['donation_status'] === 'pending_approval'): ?><br><small>Admin approval pending</small><?php endif; ?>
                <?php if ($r['status'] === 'stored' && !$r['receiver_id']): ?><br><small>Waiting for a receiver</small><?php endif; ?>
            </td>
            <td><?php if ($r['status'] === 'awaiting_dropoff' && $hubs): ?>
                <form method="post" style="display:flex; gap:6px; flex-wrap:wrap;"><input type="hidden" name="donation_id" value="<?= (int)$r['donation_id'] ?>">
                    <select name="hub_id" style="padding:6px; border-radius:8px; border:1px solid var(--line);">
                        <?php foreach ($hubs as $h): ?><option value="<?= (int)$h['hub_id'] ?>" <?= (int)$h['hub_id'] === (int)$r['hub_id'] ? 'selected' : '' ?>><?= e($h['name']) ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn-small btn-secondary" type="submit">Save</button></form>
                <?php else: ?>—<?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$mine): ?><tr><td colspan="4">No hub drop-offs yet. Pick a hub when you add a donation.</td></tr><?php endif; ?>
    </tbody>
</table>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
