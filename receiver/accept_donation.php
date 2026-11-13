<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../matching/PriorityMatcher.php';
require_once __DIR__ . '/../includes/hub.php';
requireRole('receiver');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/receiver/browse_matches.php');

$db = getDB();
$uid = currentUserId();
$donationId = (int)($_POST['donation_id'] ?? 0);
$action = $_POST['action'] ?? '';

$stmt = $db->prepare("SELECT * FROM donations WHERE donation_id = ? AND matched_receiver_id = ?");
$stmt->execute([$donationId, $uid]);
$donation = $stmt->fetch();

if (!$donation) {
    flash('error', 'Donation not found.');
    redirect('/receiver/browse_matches.php');
}

if ($action === 'accept' && $donation['status'] === 'matched') {
    $db->prepare("UPDATE donations SET status = 'accepted' WHERE donation_id = ?")->execute([$donationId]);
    $db->prepare("INSERT INTO lifecycle_log (donation_id, status, changed_by) VALUES (?, 'accepted', ?)")->execute([$donationId, $uid]);
    $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'request', ?, ?)")->execute([
        $donation['donor_id'], 'Donation accepted', 'Your donation "' . $donation['title'] . '" was accepted by the matched receiver.',
    ]);
    ensureHubTables($db);
    syncHubAssignments($db);   // issues the hub Pickup ID right away
    flash('success', 'Donation accepted. Your Pickup ID and the hub address are on the tracking page / Hub Pickup.');
    redirect('/receiver/hub_pickup.php');

} elseif ($action === 'decline' && $donation['status'] === 'matched') {
    // Reopen the donation and let the matcher find the next-best receiver.
    $db->prepare("UPDATE donations SET status = 'available', matched_receiver_id = NULL, matched_score = NULL WHERE donation_id = ?")
       ->execute([$donationId]);
    $db->prepare("INSERT INTO lifecycle_log (donation_id, status, note, changed_by) VALUES (?, 'available', 'Declined by receiver, re-queued', ?)")
       ->execute([$donationId, $uid]);
    try { (new PriorityMatcher($db))->matchDonation($donationId); } catch (Throwable $e) {}
    ensureHubTables($db);
    syncHubAssignments($db);
    flash('success', 'Match declined. The donation has been re-queued for matching.');

} elseif ($action === 'complete' && $donation['status'] === 'delivered') {
    $db->prepare("UPDATE donations SET status = 'completed' WHERE donation_id = ?")->execute([$donationId]);
    $db->prepare("INSERT INTO lifecycle_log (donation_id, status, changed_by) VALUES (?, 'completed', ?)")->execute([$donationId, $uid]);

    // Record impact metrics for the dashboard/analytics.
    $cat = $db->prepare("SELECT name FROM resource_categories WHERE category_id = ?");
    $cat->execute([$donation['category_id']]);
    $catName = $cat->fetchColumn();
    $weightFactor = WEIGHT_FACTORS[$catName] ?? 1.0;
    $co2Factor    = CO2_FACTORS[$catName] ?? 1.0;
    $weight = round($donation['quantity'] * $weightFactor, 2);
    $co2    = round($donation['quantity'] * $co2Factor, 2);

    $db->prepare(
        "INSERT INTO impact_log (donation_id, category_id, quantity, estimated_weight_kg, estimated_co2_saved_kg, beneficiary_id)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$donationId, $donation['category_id'], $donation['quantity'], $weight, $co2, $uid]);

    // Keep the linked request (if any) in sync with the donation's completion.
    $db->prepare("UPDATE requests SET status = 'completed' WHERE matched_donation_id = ? AND receiver_id = ?")
       ->execute([$donationId, $uid]);

    flash('success', 'Thank you for confirming receipt! Impact metrics updated.');
    redirect('/receiver/request_history.php');
} else {
    flash('error', 'That action is not valid for this donation\'s current status.');
}

redirect('/receiver/browse_matches.php');
