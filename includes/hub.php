<?php
/**
 * EcoLink - Delivery Hub helpers.
 *
 * A hub is an area-based drop-off / pick-up point. Several donors in the same
 * area leave their items at one hub, and each receiver collects theirs there by
 * showing a personal Pickup ID. Hub tables are created automatically on first
 * use, so no manual SQL import is needed (sql/migration_delivery_hub.sql is the
 * same schema if you prefer to import it).
 */

function ensureHubTables(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS delivery_hubs (
        hub_id      INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(120) NOT NULL,
        area        VARCHAR(120) NOT NULL,
        address     VARCHAR(255) NOT NULL,
        phone       VARCHAR(40)  NULL,
        open_hours  VARCHAR(120) NULL,
        manager_user_id INT NULL,
        is_active   TINYINT(1)   NOT NULL DEFAULT 1,
        created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_hub_area (area)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS hub_items (
        hub_item_id  INT AUTO_INCREMENT PRIMARY KEY,
        hub_id       INT NOT NULL,
        donation_id  INT NOT NULL,
        donor_id     INT NOT NULL,
        receiver_id  INT NULL,
        pickup_code  VARCHAR(16) NULL,
        status       ENUM('awaiting_dropoff','stored','collected','cancelled') NOT NULL DEFAULT 'awaiting_dropoff',
        created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        stored_at    DATETIME NULL,
        collected_at DATETIME NULL,
        UNIQUE KEY uq_hub_donation (donation_id),
        UNIQUE KEY uq_hub_code (pickup_code),
        INDEX idx_hub_status (hub_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $c = $db->query("SHOW COLUMNS FROM hub_items LIKE 'receiver_id'")->fetch();
    if ($c && strtoupper($c['Null']) === 'NO') {
        $db->exec("ALTER TABLE hub_items MODIFY receiver_id INT NULL, MODIFY pickup_code VARCHAR(16) NULL");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS hub_staff (
        user_id     INT NOT NULL PRIMARY KEY,
        hub_id      INT NOT NULL,
        status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_hs_hub (hub_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Hub staff accounts: add the 'hub' login role and the hub -> manager link (safe to run repeatedly).
    $col = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if ($col && stripos($col['Type'], "'hub'") === false) {
        $db->exec("ALTER TABLE users MODIFY role ENUM('donor','receiver','admin','hub') NOT NULL");
    }
    if (!$db->query("SHOW COLUMNS FROM delivery_hubs LIKE 'manager_user_id'")->fetch()) {
        $db->exec("ALTER TABLE delivery_hubs ADD manager_user_id INT NULL AFTER open_hours");
    }
    $done = true;
}

/** Hub that a logged-in, admin-approved hub-staff user works at (null if none / still pending). */
function staffHubId(PDO $db, int $userId): ?int {
    $st = $db->prepare("SELECT h.hub_id FROM hub_staff s JOIN delivery_hubs h ON h.hub_id = s.hub_id
                        WHERE s.user_id = ? AND s.status = 'approved' AND h.is_active = 1 LIMIT 1");
    $st->execute([$userId]);
    $id = $st->fetchColumn();
    if (!$id) {   // accounts created before the hub_staff table existed
        $st = $db->prepare("SELECT hub_id FROM delivery_hubs WHERE manager_user_id = ? AND is_active = 1 LIMIT 1");
        $st->execute([$userId]);
        $id = $st->fetchColumn();
    }
    return $id ? (int)$id : null;
}

/** 'pending' | 'rejected' | 'approved' | null for a hub-staff user. */
function staffStatus(PDO $db, int $userId): ?string {
    $st = $db->prepare("SELECT status FROM hub_staff WHERE user_id = ?");
    $st->execute([$userId]);
    $v = $st->fetchColumn();
    return $v ?: null;
}

/** Unique, easy-to-read Pickup ID such as EH-7K3QXM (no 0/O/1/I confusion). */
function generatePickupCode(PDO $db): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $check = $db->prepare("SELECT 1 FROM hub_items WHERE pickup_code = ?");
    for ($try = 0; $try < 20; $try++) {
        $code = 'EH-';
        for ($i = 0; $i < 6; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $check->execute([$code]);
        if (!$check->fetchColumn()) return $code;
    }
    return 'EH-' . strtoupper(bin2hex(random_bytes(4)));
}

function normalizeCode(string $raw): string {
    $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
    return (str_starts_with($c, 'EH') ? 'EH-' . substr($c, 2) : $c);
}

/** Same area? Loose, case-insensitive containment either way ("Uttara" vs "Uttara, Dhaka"). */
function sameArea(?string $a, ?string $b): bool {
    $a = mb_strtolower(trim((string)$a)); $b = mb_strtolower(trim((string)$b));
    if ($a === '' || $b === '') return false;
    foreach (array_filter(array_map('trim', explode(',', $a))) as $part) {
        if (mb_strlen($part) >= 3 && str_contains($b, $part)) return true;
    }
    foreach (array_filter(array_map('trim', explode(',', $b))) as $part) {
        if (mb_strlen($part) >= 3 && str_contains($a, $part)) return true;
    }
    return false;
}

function hubStatusLabel(string $s): string {
    return [
        'awaiting_dropoff' => 'Waiting for donor drop-off',
        'stored'           => 'Stored at hub - ready for pickup',
        'collected'        => 'Delivered to receiver',
        'cancelled'        => 'Cancelled',
    ][$s] ?? ucfirst($s);
}

function hubNotify(PDO $db, int $userId, string $title, string $message): void {
    $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'lifecycle', ?, ?)")
       ->execute([$userId, $title, $message]);
}

/** Move a donation's lifecycle forward and keep linked request + audit log in sync (same as the donor page does). */
function hubAdvanceDonation(PDO $db, int $donationId, string $status, string $note, int $actorId): void {
    $db->prepare("UPDATE donations SET status = ? WHERE donation_id = ?")->execute([$status, $donationId]);
    $db->prepare("INSERT INTO lifecycle_log (donation_id, status, note, changed_by) VALUES (?, ?, ?, ?)")
       ->execute([$donationId, $status, $note, $actorId ?: null]);
    $db->prepare("UPDATE requests SET status = ? WHERE matched_donation_id = ?")->execute([$status, $donationId]);
}


/** Notify every approved staff member of a hub. */
function hubNotifyStaff(PDO $db, int $hubId, string $title, string $message): void {
    $st = $db->prepare("SELECT user_id FROM hub_staff WHERE hub_id = ? AND status = 'approved'");
    $st->execute([$hubId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) hubNotify($db, (int)$uid, $title, $message);
}

/**
 * Once a receiver is confirmed for a donation (status accepted/pickup/delivered), give the
 * hub item that receiver and a personal Pickup ID, and tell the receiver where to go.
 * Runs lazily (hub desk, receiver/donor hub pages, activity feed) so existing accept code needs no change.
 */
function syncHubAssignments(PDO $db): void {
    static $running = false;
    if ($running) return;
    $running = true;

    // (a) Receiver changed / declined after an ID was issued -> drop the old assignment so a new ID is issued.
    $db->exec("UPDATE hub_items i JOIN donations d ON d.donation_id = i.donation_id
               SET i.receiver_id = NULL, i.pickup_code = NULL
               WHERE i.receiver_id IS NOT NULL AND i.status IN ('awaiting_dropoff','stored')
                 AND (d.matched_receiver_id IS NULL OR d.matched_receiver_id <> i.receiver_id)");

    // (b) Confirmed donation but the donor never picked a hub -> auto-pick the hub in the donor's area.
    $noHub = $db->query("SELECT d.donation_id, d.donor_id, d.title,
                                COALESCE(NULLIF(u.pickup_area,''), u.address) AS donor_area
                         FROM donations d JOIN users u ON u.user_id = d.donor_id
                         WHERE d.matched_receiver_id IS NOT NULL AND d.status IN ('accepted','pickup','delivered')
                           AND NOT EXISTS (SELECT 1 FROM hub_items i WHERE i.donation_id = d.donation_id)")->fetchAll();
    if ($noHub) {
        $hubs = $db->query("SELECT * FROM delivery_hubs WHERE is_active = 1 ORDER BY area, name")->fetchAll();
        foreach ($hubs ? $noHub : [] as $n) {
            $pick = $hubs[0];
            foreach ($hubs as $h) if (sameArea($h['area'], $n['donor_area'])) { $pick = $h; break; }
            $db->prepare("INSERT IGNORE INTO hub_items (hub_id, donation_id, donor_id) VALUES (?, ?, ?)")
               ->execute([$pick['hub_id'], $n['donation_id'], $n['donor_id']]);
            hubNotify($db, (int)$n['donor_id'], 'Drop your item at a hub',
                'A receiver is confirmed for "' . mb_substr($n['title'], 0, 80) . '". Please bring it to ' . $pick['name'] . ' (' . $pick['address'] . ').');
            hubNotifyStaff($db, (int)$pick['hub_id'], 'Incoming donation', 'A donor will bring "' . mb_substr($n['title'], 0, 80) . '" (donation #' . $n['donation_id'] . ') to your hub.');
        }
    }

    $rows = $db->query(
        "SELECT i.hub_item_id, i.hub_id, i.status AS item_status, d.donation_id, d.title, d.matched_receiver_id, d.status AS donation_status,
                h.name AS hub_name, h.address AS hub_address
         FROM hub_items i
         JOIN donations d ON d.donation_id = i.donation_id
         JOIN delivery_hubs h ON h.hub_id = i.hub_id
         WHERE i.receiver_id IS NULL AND d.matched_receiver_id IS NOT NULL
           AND d.status IN ('accepted','pickup','delivered')
           AND i.status IN ('awaiting_dropoff','stored')"
    )->fetchAll();
    foreach ($rows as $r) {
        $code = generatePickupCode($db);
        $db->prepare("UPDATE hub_items SET receiver_id = ?, pickup_code = ? WHERE hub_item_id = ? AND receiver_id IS NULL")
           ->execute([$r['matched_receiver_id'], $code, $r['hub_item_id']]);
        $ready = $r['item_status'] === 'stored';
        hubNotify($db, (int)$r['matched_receiver_id'], $ready ? 'Your item is ready at the hub!' : 'Collect your item from a hub',
            '"' . mb_substr($r['title'], 0, 80) . '" ' . ($ready ? 'is at ' : 'will be at ') . $r['hub_name'] . ' (' . $r['hub_address'] . '). Your Pickup ID is ' . $code
            . ($ready ? ' - show it to the hub staff to collect.' : ' - we will tell you when it is ready.'));
        hubNotifyStaff($db, (int)$r['hub_id'], 'Receiver confirmed', 'A receiver is now assigned to "' . mb_substr($r['title'], 0, 80) . '" (donation #' . $r['donation_id'] . ').');
        if ($ready && $r['donation_status'] === 'accepted') {
            hubAdvanceDonation($db, (int)$r['donation_id'], 'pickup', 'Item is at ' . $r['hub_name'] . ', waiting for receiver pickup', 0);
        }
    }
    $running = false;
}

/** Hub staff confirms the receiver collected it: donation delivered AND completed, impact recorded. */
function hubCompleteDelivery(PDO $db, array $donation, string $hubName, int $actorId): void {
    $id = (int)$donation['donation_id'];
    if ($donation['status'] !== 'delivered') {
        hubAdvanceDonation($db, $id, 'delivered', 'Handed to receiver at ' . $hubName . ' (Pickup ID verified by hub staff)', $actorId);
    }
    $db->prepare("UPDATE donations SET status = 'completed' WHERE donation_id = ?")->execute([$id]);
    $db->prepare("INSERT INTO lifecycle_log (donation_id, status, note, changed_by) VALUES (?, 'completed', ?, ?)")
       ->execute([$id, 'Delivery completed and confirmed by hub staff at ' . $hubName, $actorId]);
    $db->prepare("UPDATE requests SET status = 'completed' WHERE matched_donation_id = ?")->execute([$id]);

    $cat = $db->prepare("SELECT name FROM resource_categories WHERE category_id = ?");
    $cat->execute([$donation['category_id']]);
    $catName = $cat->fetchColumn();
    $weight = round($donation['quantity'] * (WEIGHT_FACTORS[$catName] ?? 1.0), 2);
    $co2    = round($donation['quantity'] * (CO2_FACTORS[$catName] ?? 1.0), 2);
    $dup = $db->prepare("SELECT 1 FROM impact_log WHERE donation_id = ?");
    $dup->execute([$id]);
    if (!$dup->fetchColumn()) {
        $db->prepare("INSERT INTO impact_log (donation_id, category_id, quantity, estimated_weight_kg, estimated_co2_saved_kg, beneficiary_id) VALUES (?, ?, ?, ?, ?, ?)")
           ->execute([$id, $donation['category_id'], $donation['quantity'], $weight, $co2, $donation['matched_receiver_id']]);
    }
}


/** The hub item (with hub + receiver details) for one donation, or null. */
function hubItemForDonation(PDO $db, int $donationId): ?array {
    $st = $db->prepare("SELECT i.*, h.name AS hub_name, h.area AS hub_area, h.address AS hub_address, h.phone AS hub_phone, h.open_hours
                        FROM hub_items i JOIN delivery_hubs h ON h.hub_id = i.hub_id
                        WHERE i.donation_id = ? AND i.status <> 'cancelled'");
    $st->execute([$donationId]);
    return $st->fetch() ?: null;
}

/**
 * Hub / Pickup ID box for tracking pages.
 * $viewer = 'receiver' (shows the personal Pickup ID) | 'donor' | 'admin' (no ID needed).
 */
function renderHubTrackingCard(?array $item, string $viewer = 'receiver', ?string $donationStatus = null): string {
    $o = '<div class="mini-card hub-track" style="margin-top:16px;"><span class="eyebrow">📍 Delivery Hub</span>';
    if (!$item) {
        $o .= '<p style="margin:6px 0 0;color:var(--ink-soft);">' . ($viewer === 'receiver'
            ? 'Your Pickup ID appears here as soon as you are confirmed for this item and the donor has a hub.'
            : 'No delivery hub is linked to this donation yet.') . '</p></div>';
        return $o;
    }
    $ready = $item['status'] === 'stored';
    $o .= '<h3 style="margin:2px 0 6px;">' . e($item['hub_name']) . '</h3>'
        . '<p style="margin:4px 0;">' . e($item['hub_address'])
        . ($item['hub_phone'] ? '<br>📞 ' . e($item['hub_phone']) : '')
        . ($item['open_hours'] ? '<br>🕒 ' . e($item['open_hours']) : '') . '</p>'
        . '<span class="badge ' . ($ready ? 'badge-pickup' : ($item['status'] === 'collected' ? 'badge-completed' : 'badge-accepted')) . '">' . e(hubStatusLabel($item['status'])) . '</span>';
    if ($viewer === 'receiver') {
        if ($item['status'] === 'collected') {
            $o .= '<p style="margin:10px 0 0;">✅ You collected this item at the hub.</p>';
        } elseif ($item['pickup_code']) {
            $o .= '<div class="pickup-id-card ' . ($ready ? 'is-ready' : '') . '" style="margin-top:12px;display:inline-block;">'
                . '<small>YOUR PICKUP ID</small><div class="pid">' . e($item['pickup_code']) . '</div>'
                . '<small>' . ($ready ? '✅ Ready — show this ID to the hub staff' : '⏳ Not at the hub yet — we will notify you') . '</small></div>';
        } else {
            $o .= '<p style="margin:10px 0 0;color:var(--ink-soft);">Your Pickup ID will appear here once you accept / are confirmed for this donation.</p>';
        }
    } elseif ($viewer === 'donor' && $item['status'] === 'awaiting_dropoff') {
        $o .= '<p style="margin:10px 0 0;">➡️ Please take this item to the hub. Hub staff will confirm they received it.</p>';
    }
    return $o . '</div>
<style>.pickup-id-card{text-align:center;padding:14px 24px;border:2px dashed var(--mint-line);border-radius:14px;background:var(--mint)}
.pickup-id-card small{display:block;color:var(--ink-soft);letter-spacing:.08em;font-size:.7rem;font-weight:700}
.pickup-id-card .pid{font-size:1.8rem;font-weight:800;letter-spacing:.12em;color:var(--forest-2);margin:6px 0;font-family:ui-monospace,Menlo,Consolas,monospace}
.pickup-id-card.is-ready{background:var(--green-light);border-color:var(--green);border-style:solid}</style>';
}
