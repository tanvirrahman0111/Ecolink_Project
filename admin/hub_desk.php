<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/hub.php';
requireLogin();
$role = currentUserRole();
if (!in_array($role, ['admin', 'hub'], true)) { http_response_code(403); die('Access denied: hub staff or admin only.'); }

$db = getDB();
ensureHubTables($db);
$actorId = currentUserId();
$isStaff = ($role === 'hub');
$myHub = $isStaff ? staffHubId($db, $actorId) : null;
$self = $isStaff ? '/hub/desk.php' : '/admin/hub_desk.php';

if ($isStaff && !$myHub) {
    $stt = staffStatus($db, $actorId);
    $pageTitle = 'Hub Desk';
    require_once __DIR__ . '/../includes/header.php';
    echo '<h1>Hub Desk</h1><div class="mini-card"><h3 style="margin-top:0;">' . ($stt === 'rejected' ? '❌ Your hub staff account was not approved' : '⏳ Waiting for admin verification') . '</h3><p>'
       . ($stt === 'rejected' ? 'Please contact the EcoLink admin.' : 'An admin must verify your account before you can use the Hub Desk. You will get a notification as soon as it is done.') . '</p></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
$hubFilter = $isStaff ? $myHub : (int)($_GET['hub'] ?? 0);

syncHubAssignments($db);

$itemSql = "SELECT i.*, h.name AS hub_name, h.address AS hub_address,
                   d.title, d.quantity, d.unit, d.category_id, d.status AS donation_status, d.matched_receiver_id,
                   du.full_name AS donor_name, du.phone AS donor_phone,
                   ru.full_name AS receiver_name, ru.organization_name AS receiver_org, ru.phone AS receiver_phone
            FROM hub_items i
            JOIN delivery_hubs h ON h.hub_id = i.hub_id
            JOIN donations d ON d.donation_id = i.donation_id
            JOIN users du ON du.user_id = i.donor_id
            LEFT JOIN users ru ON ru.user_id = i.receiver_id";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemId = (int)($_POST['hub_item_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $st = $db->prepare($itemSql . " WHERE i.hub_item_id = ?");
    $st->execute([$itemId]);
    $it = $st->fetch();
    $back = $self . (($hubFilter && !$isStaff) ? '?hub=' . $hubFilter : '');

    if (!$it || ($isStaff && (int)$it['hub_id'] !== $myHub)) {
        flash('error', 'Hub item not found.');
    } elseif ($action === 'store' && $it['status'] === 'awaiting_dropoff') {
        if (in_array($it['donation_status'], ['pending_approval', 'rejected', 'cancelled'], true)) {
            flash('error', 'This donation is not approved by the admin yet, so it cannot be received.');
        } else {
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE hub_items SET status = 'stored', stored_at = NOW() WHERE hub_item_id = ? AND status = 'awaiting_dropoff'")->execute([$itemId]);
                if ($it['donation_status'] === 'accepted') {
                    hubAdvanceDonation($db, (int)$it['donation_id'], 'pickup', 'Received at ' . $it['hub_name'] . ' by hub staff', $actorId);
                }
                hubNotify($db, (int)$it['donor_id'], 'Donation received at hub',
                    $it['hub_name'] . ' confirmed that they received "' . mb_substr($it['title'], 0, 80) . '". Thank you!');
                if ($it['receiver_id']) {
                    hubNotify($db, (int)$it['receiver_id'], 'Your item is ready at the hub!',
                        '"' . mb_substr($it['title'], 0, 80) . '" is now at ' . $it['hub_name'] . ' (' . $it['hub_address'] . '). Show your Pickup ID ' . $it['pickup_code'] . ' to collect it.');
                }
                $db->commit();
                flash('success', 'Marked as received at the hub.' . ($it['receiver_id'] ? ' The receiver has been notified.' : ' It will wait here until a receiver is confirmed.'));
            } catch (Throwable $e) { $db->rollBack(); flash('error', 'Could not update. Please try again.'); }
        }
    } elseif ($action === 'handover' && $it['status'] === 'stored') {
        $entered = normalizeCode($_POST['code'] ?? '');
        if (!$it['receiver_id'] || !$it['pickup_code']) {
            flash('error', 'No receiver is confirmed for this item yet.');
        } elseif ($entered !== $it['pickup_code']) {
            flash('error', 'Pickup ID does not match. Delivery not completed.');
        } elseif (!in_array($it['donation_status'], ['accepted', 'pickup', 'delivered'], true)) {
            flash('error', 'This donation is not ready for hand-over.');
        } else {
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE hub_items SET status = 'collected', collected_at = NOW() WHERE hub_item_id = ? AND status = 'stored'")->execute([$itemId]);
                hubCompleteDelivery($db, $it, $it['hub_name'], $actorId);
                hubNotify($db, (int)$it['receiver_id'], 'Delivery completed', 'You collected "' . mb_substr($it['title'], 0, 80) . '" from ' . $it['hub_name'] . '. Thank you for using EcoLink!');
                hubNotify($db, (int)$it['donor_id'], 'Your donation reached the receiver', '"' . mb_substr($it['title'], 0, 80) . '" was handed to the receiver at ' . $it['hub_name'] . '. Delivery is complete.');
                $db->commit();
                flash('success', 'Delivery completed and recorded.');
            } catch (Throwable $e) { $db->rollBack(); flash('error', 'Could not complete the delivery. Please try again.'); }
        }
    } else {
        flash('error', 'That action is not valid for this item right now.');
    }
    redirect($back);
}

$hubs = $db->query("SELECT hub_id, name, area FROM delivery_hubs" . ($isStaff ? " WHERE hub_id = " . (int)$myHub : '') . " ORDER BY area, name")->fetchAll();

$lookupCode = normalizeCode($_GET['code'] ?? '');
$found = null; $lookedUp = false;
if ($lookupCode !== '' && $lookupCode !== 'EH-') {
    $lookedUp = true;
    $st = $db->prepare($itemSql . " WHERE i.pickup_code = ?" . ($isStaff ? " AND i.hub_id = " . (int)$myHub : ''));
    $st->execute([$lookupCode]);
    $found = $st->fetch() ?: null;
}

$hubCond = $hubFilter ? ' AND i.hub_id = ' . (int)$hubFilter : '';
$incoming = $db->query($itemSql . " WHERE i.status = 'awaiting_dropoff' $hubCond ORDER BY i.created_at ASC")->fetchAll();
$stored   = $db->query($itemSql . " WHERE i.status = 'stored' $hubCond ORDER BY i.stored_at ASC")->fetchAll();
$done     = $db->query($itemSql . " WHERE i.status = 'collected' $hubCond ORDER BY i.collected_at DESC LIMIT 10")->fetchAll();

function rcvName(array $r): string { return $r['receiver_id'] ? ($r['receiver_org'] ?: $r['receiver_name']) : ''; }

$pageTitle = 'Hub Desk';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Hub Desk<?= $isStaff && $hubs ? ' — ' . e($hubs[0]['name']) : '' ?></h1>
<p><strong>1.</strong> When a donor brings an item, press “Yes, received”. <strong>2.</strong> When the receiver comes, check their Pickup ID and complete the delivery.</p>

<form method="get" class="filter-bar">
    <?php if (!$isStaff): ?><label>Hub
        <select name="hub" onchange="this.form.submit()">
            <option value="0">All hubs</option>
            <?php foreach ($hubs as $h): ?>
                <option value="<?= (int)$h['hub_id'] ?>" <?= $hubFilter === (int)$h['hub_id'] ? 'selected' : '' ?>><?= e($h['name']) ?> — <?= e($h['area']) ?></option>
            <?php endforeach; ?>
        </select>
    </label><?php endif; ?>
    <label>Receiver's Pickup ID
        <input type="text" name="code" value="<?= e($_GET['code'] ?? '') ?>" placeholder="EH-XXXXXX" style="text-transform:uppercase; letter-spacing:.08em;">
    </label>
    <button class="btn-accent" type="submit">Look up</button>
</form>

<?php if ($lookedUp): ?>
    <div class="mini-card" style="margin-bottom:22px; border-color:var(--mint-line); background:var(--mint);">
    <?php if (!$found): ?>
        <strong style="font-size:1.1rem;">❌ No item found for “<?= e($lookupCode) ?>”.</strong> <span>Ask the receiver to show the ID from their Hub Pickup page.</span>
    <?php else: ?>
        <span class="eyebrow">Pickup ID <?= e($found['pickup_code']) ?> · <?= e(hubStatusLabel($found['status'])) ?></span>
        <h3 style="margin:2px 0 6px;"><?= e($found['title']) ?> <small>(<?= (int)$found['quantity'] ?> <?= e($found['unit']) ?>)</small></h3>
        <p style="margin:4px 0;">Receiver: <strong><?= e(rcvName($found)) ?></strong> · Receiver ID <strong>#<?= (int)$found['receiver_id'] ?></strong> · 📞 <?= e((string)$found['receiver_phone']) ?><br>
           Hub: <?= e($found['hub_name']) ?></p>
        <?php if ($found['status'] === 'stored'): ?>
            <form method="post" style="margin-top:10px;">
                <input type="hidden" name="action" value="handover"><input type="hidden" name="hub_item_id" value="<?= (int)$found['hub_item_id'] ?>">
                <input type="hidden" name="code" value="<?= e($found['pickup_code']) ?>">
                <button class="btn-primary" type="submit">✔ ID checked — Complete delivery</button>
            </form>
        <?php elseif ($found['status'] === 'awaiting_dropoff'): ?>
            <p style="margin:8px 0 0;">⚠️ The donor has not brought this item yet — press “Yes, received” first when it arrives.</p>
        <?php endif; ?>
    <?php endif; ?>
    </div>
<?php endif; ?>

<h2>① Incoming from donors (<?= count($incoming) ?>)</h2>
<table class="data-table">
    <thead><tr><th>Item</th><th>Donor</th><th>Admin approval</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($incoming as $r): $approved = !in_array($r['donation_status'], ['pending_approval', 'rejected', 'cancelled'], true); ?>
        <tr>
            <td><strong><?= e($r['title']) ?></strong><br><small><?= (int)$r['quantity'] ?> <?= e($r['unit']) ?> · donation #<?= (int)$r['donation_id'] ?><?= !$isStaff ? ' · ' . e($r['hub_name']) : '' ?></small></td>
            <td><?= e($r['donor_name']) ?><br><small><?= e((string)$r['donor_phone']) ?></small></td>
            <td><span class="badge <?= $approved ? 'badge-completed' : 'badge-accepted' ?>"><?= $approved ? 'Approved' : e(ucwords(str_replace('_', ' ', $r['donation_status']))) ?></span></td>
            <td>
                <?php if ($approved): ?>
                    <form method="post"><input type="hidden" name="action" value="store"><input type="hidden" name="hub_item_id" value="<?= (int)$r['hub_item_id'] ?>">
                        <button class="btn-small btn-accent" type="submit">✔ Yes, donation received</button></form>
                <?php else: ?><small>Wait for admin approval</small><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$incoming): ?><tr><td colspan="4">No donations are on their way.</td></tr><?php endif; ?>
    </tbody>
</table>

<h2>② Stored at hub — waiting for receivers (<?= count($stored) ?>)</h2>
<table class="data-table">
    <thead><tr><th>Item</th><th>Receiver</th><th>Complete delivery</th></tr></thead>
    <tbody>
    <?php foreach ($stored as $r): ?>
        <tr>
            <td><strong><?= e($r['title']) ?></strong><br><small><?= (int)$r['quantity'] ?> <?= e($r['unit']) ?> · donation #<?= (int)$r['donation_id'] ?><?= !$isStaff ? ' · ' . e($r['hub_name']) : '' ?></small></td>
            <td><?php if ($r['receiver_id']): ?><?= e(rcvName($r)) ?> <small>(Receiver #<?= (int)$r['receiver_id'] ?>)</small><br><small><?= e((string)$r['receiver_phone']) ?></small>
                <?php else: ?><span class="badge badge-accepted">No receiver confirmed yet</span><?php endif; ?></td>
            <td>
                <?php if ($r['receiver_id']): ?>
                    <form method="post" style="display:flex; gap:6px; flex-wrap:wrap;"><input type="hidden" name="action" value="handover"><input type="hidden" name="hub_item_id" value="<?= (int)$r['hub_item_id'] ?>">
                        <input type="text" name="code" placeholder="Receiver's ID (EH-…)" required style="width:150px; text-transform:uppercase;">
                        <button class="btn-small btn-primary" type="submit">Verify &amp; complete</button></form>
                <?php else: ?><small>Waiting for a receiver</small><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$stored): ?><tr><td colspan="3">Nothing is stored at the hub right now.</td></tr><?php endif; ?>
    </tbody>
</table>

<h2>Recently completed</h2>
<table class="data-table">
    <thead><tr><th>Item</th><th>Hub</th><th>Receiver</th><th>Completed</th></tr></thead>
    <tbody>
    <?php foreach ($done as $r): ?>
        <tr><td><?= e($r['title']) ?></td><td><?= e($r['hub_name']) ?></td><td><?= e(rcvName($r)) ?></td><td><?= e(date('M d, H:i', strtotime($r['collected_at']))) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$done): ?><tr><td colspan="4">No completed deliveries yet.</td></tr><?php endif; ?>
    </tbody>
</table>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
