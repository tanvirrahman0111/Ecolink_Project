<?php
/**
 * EcoLink - Priority-Based Matching Algorithm
 * ---------------------------------------------------------------------
 * For a given donation, this scores every eligible, verified receiver on
 * four category-weighted factors and returns the ranked candidate list:
 *
 *   1. Urgency score     - how time-sensitive the donation is (mainly
 *                           driven by expiry_datetime; always 100 for
 *                           non-perishable categories).
 *   2. Distance score    - proximity between donor and receiver
 *                           (haversine distance, closer = higher score).
 *   3. Demand score      - how well the donation matches the receiver's
 *                           active demand profile for that category
 *                           (priority_level + whether they still need it).
 *   4. Capacity score    - the receiver's stated intake capacity (1-10),
 *                           so an org that can actually absorb the
 *                           donation is favored over one near its limit.
 *
 * total_score = w_urgency*urgency + w_distance*distance
 *             + w_demand*demand   + w_capacity*capacity
 *
 * All four sub-scores are normalized to a 0-100 scale before weighting,
 * so total_score is also 0-100. Weights are category-specific and stored
 * in resource_categories (see sql/schema.sql).
 * ---------------------------------------------------------------------
 */

class PriorityMatcher
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Run matching for a single donation. Persists every scored candidate
     * to `matches` and returns the ranked list. Only when $autoAssign is true
     * does it also change the donation's status / matched_receiver_id.
     */
    public function matchDonation(int $donationId, bool $autoAssign = false): array
    {
        $donation = $this->getDonation($donationId);
        if (!$donation) {
            throw new InvalidArgumentException("Donation #$donationId not found.");
        }
        if ($donation['status'] !== 'available') {
            return []; // already matched / not eligible for (re)matching
        }

        $category = $this->getCategory((int)$donation['category_id']);
        $candidates = $this->getEligibleReceivers((int)$donation['category_id']);

        $scored = [];
        foreach ($candidates as $receiver) {
            $urgency  = $this->urgencyScore($donation);
            $distance = $this->distanceScore($donation, $receiver);
            $demand   = $this->demandScore($donation, $receiver);
            $capacity = $this->capacityScore($receiver);

            $total = round(
                $category['weight_urgency']  * $urgency +
                $category['weight_distance'] * $distance +
                $category['weight_demand']   * $demand +
                $category['weight_capacity'] * $capacity,
                2
            );

            $scored[] = [
                'receiver_id'    => (int)$receiver['user_id'],
                'receiver_name'  => $receiver['full_name'],
                'urgency_score'  => round($urgency, 2),
                'distance_score' => round($distance, 2),
                'demand_score'   => round($demand, 2),
                'capacity_score' => round($capacity, 2),
                'total_score'    => $total,
            ];
        }

        // Highest total_score first; tie-break on demand_score, then distance
        usort($scored, function ($a, $b) {
            return [$b['total_score'], $b['demand_score'], $b['distance_score']]
                <=> [$a['total_score'], $a['demand_score'], $a['distance_score']];
        });

        $this->persistMatches($donationId, $scored);

        // By default the scores are only stored as suggestions/analytics.
        // The donation stays 'available' so every receiver can see it;
        // the real pairing happens when a receiver requests it and the
        // donor accepts. Pass $autoAssign = true for the old behaviour.
        if ($autoAssign && !empty($scored)) {
            $this->applyBestMatch($donationId, $scored[0]);
        }

        return $scored;
    }

    /** Re-run matching for every donation still marked 'available'. */
    public function matchAllPending(): array
    {
        $stmt = $this->db->query("SELECT donation_id FROM donations WHERE status = 'available'");
        $results = [];
        foreach ($stmt->fetchAll() as $row) {
            $results[$row['donation_id']] = $this->matchDonation((int)$row['donation_id']);
        }
        return $results;
    }

    // -------------------------------------------------------------
    // Sub-score calculators (each normalized to 0-100)
    // -------------------------------------------------------------

    private function urgencyScore(array $donation): float
    {
        if (empty($donation['expiry_datetime'])) {
            return 60.0; // non-perishable: moderate constant urgency
        }
        $now    = new DateTime();
        $expiry = new DateTime($donation['expiry_datetime']);
        $hoursLeft = ($expiry->getTimestamp() - $now->getTimestamp()) / 3600;

        if ($hoursLeft <= 0) return 0.0;        // already expired, lowest urgency to match
        if ($hoursLeft <= 6) return 100.0;       // very urgent
        if ($hoursLeft <= 24) return 85.0;
        if ($hoursLeft <= 72) return 60.0;
        if ($hoursLeft <= 168) return 35.0;
        return 15.0;                             // more than a week left
    }

    private function distanceScore(array $donation, array $receiver): float
    {
        if (empty($donation['latitude']) || empty($donation['longitude']) ||
            empty($receiver['latitude']) || empty($receiver['longitude'])) {
            return 50.0; // unknown location: neutral score
        }
        $km = $this->haversineKm(
            (float)$donation['latitude'], (float)$donation['longitude'],
            (float)$receiver['latitude'], (float)$receiver['longitude']
        );
        // Linear decay: 0 km -> 100, 20+ km -> 0
        $score = 100 - ($km * 5);
        return max(0.0, min(100.0, $score));
    }

    private function demandScore(array $donation, array $receiver): float
    {
        $stmt = $this->db->prepare(
            "SELECT priority_level, needed_quantity FROM demand_profiles
             WHERE receiver_id = ? AND category_id = ? AND is_active = 1"
        );
        $stmt->execute([$receiver['user_id'], $donation['category_id']]);
        $demand = $stmt->fetch();

        if (!$demand) {
            return 20.0; // no stated demand for this category: low but nonzero
        }

        $priorityScores = ['low' => 40, 'medium' => 65, 'high' => 85, 'critical' => 100];
        $base = $priorityScores[$demand['priority_level']] ?? 50;

        // Bonus if the donated quantity helps meaningfully cover what's needed
        if ((int)$demand['needed_quantity'] > 0) {
            $coverage = min(1.0, $donation['quantity'] / (int)$demand['needed_quantity']);
            $base = min(100.0, $base + ($coverage * 10));
        }
        return $base;
    }

    private function capacityScore(array $receiver): float
    {
        $capacity = (int)($receiver['capacity_score'] ?? 5); // 1-10 scale
        return max(0.0, min(100.0, $capacity * 10));
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    // -------------------------------------------------------------
    // Persistence helpers
    // -------------------------------------------------------------

    private function getDonation(int $donationId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM donations WHERE donation_id = ?");
        $stmt->execute([$donationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function getCategory(int $categoryId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM resource_categories WHERE category_id = ?");
        $stmt->execute([$categoryId]);
        return $stmt->fetch();
    }

    /** Verified, active receivers only. */
    private function getEligibleReceivers(int $categoryId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM users
             WHERE role = 'receiver' AND is_verified = 1 AND status = 'active'"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function persistMatches(int $donationId, array $scored): void
    {
        $del = $this->db->prepare("DELETE FROM matches WHERE donation_id = ?");
        $del->execute([$donationId]);

        $ins = $this->db->prepare(
            "INSERT INTO matches
                (donation_id, receiver_id, urgency_score, distance_score,
                 demand_score, capacity_score, total_score, is_selected)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($scored as $i => $m) {
            $ins->execute([
                $donationId, $m['receiver_id'], $m['urgency_score'], $m['distance_score'],
                $m['demand_score'], $m['capacity_score'], $m['total_score'], $i === 0 ? 1 : 0,
            ]);
        }
    }

    private function applyBestMatch(int $donationId, array $best): void
    {
        $upd = $this->db->prepare(
            "UPDATE donations
             SET status = 'matched', matched_receiver_id = ?, matched_score = ?
             WHERE donation_id = ?"
        );
        $upd->execute([$best['receiver_id'], $best['total_score'], $donationId]);

        $log = $this->db->prepare(
            "INSERT INTO lifecycle_log (donation_id, status, note) VALUES (?, 'matched', ?)"
        );
        $log->execute([$donationId, "Auto-matched to receiver #{$best['receiver_id']} (score {$best['total_score']})"]);

        $notify = $this->db->prepare(
            "INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'matching', ?, ?)"
        );
        $notify->execute([
            $best['receiver_id'],
            'New donation matched to you',
            "A donation was matched to your demand profile with a priority score of {$best['total_score']}/100. Review it in your dashboard.",
        ]);
    }
}
