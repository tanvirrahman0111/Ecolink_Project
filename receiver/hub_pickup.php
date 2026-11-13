<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/hub.php';
requireRole('receiver');

$db = getDB();
ensureHubTables($db);
syncHubAssignments($db);
$uid = currentUserId();

$u = $db->prepare("SELECT full_name, organization_name FROM users WHERE user_id = ?");
$u->execute([$uid]);
$me = $u->fetch();

$st = $db->prepare(
    "SELECT i.*, h.name AS hub_name, h.area, h.address AS hub_address, h.phone AS hub_phone, h.open_hours,
            d.title, d.quantity, d.unit, d.status AS donation_status
     FROM hub_items i JOIN delivery_hubs h ON h.hub_id = i.hub_id JOIN donations d ON d.donation_id = i.donation_id
     WHERE i.receiver_id = ? AND i.status <> 'cancelled'
     ORDER BY (i.status = 'stored') DESC, (i.status = 'awaiting_dropoff') DESC, i.created_at DESC"
);
$st->execute([$uid]);
$rows = $st->fetchAll();

$pageTitle = 'Hub Pickup';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Hub Pickup</h1>
<p>Donors leave items at a delivery hub. When yours is ready, go to the hub and show the Pickup ID below to the hub staff — they verify it and complete your delivery.</p>

<?php foreach ($rows as $r): $ready = $r['status'] === 'stored'; ?>
    <div class="mini-card" style="margin-bottom:16px; <?= $ready ? 'border-color:var(--green); box-shadow:0 8px 24px rgba(47,122,69,.18);' : '' ?>">
        <div style="display:flex; gap:20px; flex-wrap:wrap; justify-content:space-between; align-items:center;">
            <div style="flex:1; min-width:240px;">
                <span class="eyebrow">📍 <?= e($r['area']) ?></span>
                <h3 style="margin:2px 0 6px;"><?= e($r['title']) ?> <small>(<?= (int)$r['quantity'] ?> <?= e($r['unit']) ?>)</small></h3>
                <p style="margin:4px 0;"><strong><?= e($r['hub_name']) ?></strong><br>
                   <?= e($r['hub_address']) ?><?= $r['hub_phone'] ? '<br>📞 ' . e($r['hub_phone']) : '' ?><?= $r['open_hours'] ? '<br>🕒 ' . e($r['open_hours']) : '' ?></p>
                <span class="badge <?= $r['status'] === 'stored' ? 'badge-pickup' : ($r['status'] === 'collected' ? 'badge-completed' : 'badge-accepted') ?>"><?= e(hubStatusLabel($r['status'])) ?></span>

            </div>
            <?php if ($r['status'] !== 'collected'): ?>
            <div class="pickup-id-card <?= $ready ? 'is-ready' : '' ?>">
                <small>PICKUP ID</small>
                <div class="pid"><?= e($r['pickup_code']) ?></div>
                <small><?= e($me['organization_name'] ?: $me['full_name']) ?> · Receiver #<?= (int)$uid ?></small>
                <small class="pid-note"><?= $ready ? '✅ Ready — bring this ID' : '⏳ Not at the hub yet' ?></small>
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$rows): ?>
    <p style="color:var(--ink-soft);">Nothing here yet. When a donor accepts your request and chooses a hub, your Pickup ID shows up on this page.</p>
<?php endif; ?>

<style>
.pickup-id-card { text-align:center; padding:16px 26px; border:2px dashed var(--mint-line); border-radius:14px; background:var(--mint); min-width:220px; }
.pickup-id-card small { display:block; color:var(--ink-soft); letter-spacing:.08em; font-size:.7rem; font-weight:700; }
.pickup-id-card .pid { font-size:1.9rem; font-weight:800; letter-spacing:.12em; color:var(--forest-2); margin:6px 0; font-family:ui-monospace,Menlo,Consolas,monospace; }
.pickup-id-card.is-ready { background:var(--green-light); border-color:var(--green); border-style:solid; }
.pickup-id-card .pid-note { margin-top:6px; letter-spacing:0; font-size:.8rem; }
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
