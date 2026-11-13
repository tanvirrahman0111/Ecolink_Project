<?php
/**
 * EcoLink - public activity feed for the live pop-ups.
 * Read-only. Returns only first names + area + item titles (no emails/phones).
 * Everything is derived from existing tables, so no other page needs changing.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/hub.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = getDB();
$now = $db->query("SELECT NOW()")->fetchColumn();

$since = $_GET['since'] ?? '';
$initial = !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since);
if ($initial) {
    $since = $db->query("SELECT DATE_SUB(NOW(), INTERVAL 2 DAY)")->fetchColumn();
} else {
    // small overlap so same-second inserts are never missed (client de-duplicates by key)
    $s = $db->prepare("SELECT GREATEST(DATE_SUB(?, INTERVAL 5 SECOND), DATE_SUB(NOW(), INTERVAL 2 DAY))");
    $s->execute([$since]);
    $since = $s->fetchColumn();
}

function firstName(?string $n): string {
    $n = trim((string)$n);
    $p = preg_split('/\s+/', $n);
    return $p[0] !== '' ? mb_substr($p[0], 0, 20) : 'Someone';
}
function shortArea(?string $a): string {
    $a = trim((string)$a);
    return $a === '' ? '' : mb_substr(trim(explode(',', $a)[0]), 0, 30);
}
function clip(string $s, int $n = 48): string { return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s; }

$events = [];
$run = function (string $sql, callable $map) use ($db, $since, &$events) {
    try {
        $st = $db->prepare($sql);
        $st->execute([$since]);
        foreach ($st->fetchAll() as $r) $events[] = $map($r);
    } catch (Throwable $e) { /* a missing optional table must never break the feed */ }
};

// 1) New users
$run("SELECT user_id, full_name, role, organization_name, pickup_area, created_at AS ts,
             TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
      FROM users WHERE role IN ('donor','receiver') AND created_at > ? ORDER BY created_at DESC LIMIT 10",
    function ($r) {
        $who = ($r['role'] === 'receiver' && $r['organization_name']) ? $r['organization_name'] : firstName($r['full_name']);
        $area = shortArea($r['pickup_area']);
        return ['key' => 'u' . $r['user_id'], 'type' => 'user', 'ts' => $r['ts'], 'age' => (int)$r['age'],
                'title' => 'New ' . $r['role'] . ' joined',
                'text'  => clip($who) . ' just joined EcoLink' . ($area ? ' from ' . $area : '') . '. Welcome! 🎉'];
    });

// 2) Donor wants to give something (shown once an admin approves it)
$run("SELECT d.donation_id, d.title, d.quantity, d.unit, d.approved_at AS ts, u.full_name, u.pickup_area,
             TIMESTAMPDIFF(SECOND, d.approved_at, NOW()) AS age
      FROM donations d JOIN users u ON u.user_id = d.donor_id
      WHERE d.approved_at IS NOT NULL AND d.status <> 'rejected' AND d.approved_at > ?
      ORDER BY d.approved_at DESC LIMIT 10",
    function ($r) {
        $area = shortArea($r['pickup_area']);
        return ['key' => 'd' . $r['donation_id'], 'type' => 'donation', 'ts' => $r['ts'], 'age' => (int)$r['age'],
                'title' => 'New donation available',
                'text'  => firstName($r['full_name']) . ' is donating ' . (int)$r['quantity'] . ' ' . clip($r['unit'], 12) . ' of “' . clip($r['title']) . '”' . ($area ? ' · ' . $area : '') . '.'];
    });

// 3) Receiver actually received something
$run("SELECT l.log_id, d.title, l.changed_at AS ts, u.full_name, u.organization_name,
             TIMESTAMPDIFF(SECOND, l.changed_at, NOW()) AS age
      FROM lifecycle_log l JOIN donations d ON d.donation_id = l.donation_id
      JOIN users u ON u.user_id = d.matched_receiver_id
      WHERE l.status = 'completed' AND l.changed_at > ? ORDER BY l.changed_at DESC LIMIT 10",
    function ($r) {
        return ['key' => 'r' . $r['log_id'], 'type' => 'received', 'ts' => $r['ts'], 'age' => (int)$r['age'],
                'title' => 'Donation received ✔',
                'text'  => clip($r['organization_name'] ?: firstName($r['full_name'])) . ' received “' . clip($r['title']) . '”. Another life touched!'];
    });

// 4) Delivery hub activity
try {
    ensureHubTables($db);
    syncHubAssignments($db);
    $run("SELECT hub_id, name, area, created_at AS ts, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
          FROM delivery_hubs WHERE is_active = 1 AND created_at > ? ORDER BY created_at DESC LIMIT 5",
        function ($r) {
            return ['key' => 'h' . $r['hub_id'], 'type' => 'hub', 'ts' => $r['ts'], 'age' => (int)$r['age'],
                    'title' => 'New delivery hub',
                    'text'  => clip($r['name'], 40) . ' now serves ' . clip($r['area'], 30) . '. Drop & collect nearby!'];
        });
    $run("SELECT i.hub_item_id, i.stored_at AS ts, h.name AS hub, d.title, TIMESTAMPDIFF(SECOND, i.stored_at, NOW()) AS age
          FROM hub_items i JOIN delivery_hubs h ON h.hub_id = i.hub_id JOIN donations d ON d.donation_id = i.donation_id
          WHERE i.stored_at IS NOT NULL AND i.stored_at > ? ORDER BY i.stored_at DESC LIMIT 10",
        function ($r) {
            return ['key' => 'hs' . $r['hub_item_id'], 'type' => 'hub', 'ts' => $r['ts'], 'age' => (int)$r['age'],
                    'title' => 'Item stored at hub',
                    'text'  => '“' . clip($r['title']) . '” is waiting at ' . clip($r['hub'], 40) . ' for pickup.'];
        });
} catch (Throwable $e) {}

usort($events, fn($a, $b) => strcmp($a['ts'], $b['ts']));
if ($initial) $events = array_slice($events, -3);   // first visit: only the 3 latest
else          $events = array_slice($events, -8);

echo json_encode(['now' => $now, 'events' => $events], JSON_UNESCAPED_UNICODE);
