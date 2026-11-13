<?php
/**
 * EcoLink - Request Matcher
 * ---------------------------------------------------------------------
 * Scores available donations against ONE specific receiver request
 * (as opposed to PriorityMatcher, which auto-matches a donation against
 * every receiver's category-level demand profile). Used by the
 * "Find Resources" / "Resource Details" / "Smart Matching" pages where
 * a receiver or donor is comparing candidates for a single request.
 *
 * Score components (0-100 each, simple rule-based — intentionally
 * lighter-weight than PriorityMatcher since this is a one-off,
 * user-reviewed comparison rather than a fully automated pairing):
 *   - Quantity fit : does the donation's quantity cover the requested range?
 *   - Condition fit: does the donation's condition meet the preference?
 *   - Distance     : how close is the donor to the receiver?
 * total = 0.35*quantity + 0.25*condition + 0.40*distance
 * ---------------------------------------------------------------------
 */
class RequestMatcher
{
    private PDO $db;
    private const CONDITION_RANK = ['fair' => 1, 'good' => 2, 'like_new' => 3, 'new' => 4];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Candidate donations for a request, ranked by score (highest first). */
    public function findMatches(array $request, int $limit = 12): array
    {
        $stmt = $this->db->prepare(
            "SELECT d.*, u.full_name AS donor_name
             FROM donations d JOIN users u ON u.user_id = d.donor_id
             WHERE d.category_id = ? AND d.status = 'available'
             ORDER BY d.created_at DESC"
        );
        $stmt->execute([$request['category_id']]);
        $candidates = $stmt->fetchAll();

        $scored = [];
        foreach ($candidates as $donation) {
            $quantity = $this->quantityScore($donation, $request);
            $condition = $this->conditionScore($donation, $request);
            $distance = $this->distanceScore($donation, $request);
            $total = round(0.35 * $quantity + 0.25 * $condition + 0.40 * $distance, 0);

            $scored[] = [
                'donation'        => $donation,
                'quantity_score'  => $quantity,
                'condition_score' => $condition,
                'distance_score'  => $distance,
                'total_score'     => (int)$total,
                'reasons'         => $this->reasons($donation, $request, $quantity, $condition, $distance),
            ];
        }

        usort($scored, fn($a, $b) => $b['total_score'] <=> $a['total_score']);
        return array_slice($scored, 0, $limit);
    }

    /** Reverse direction: candidate receiver requests for ONE specific donation (donor's "Smart Matching" view). */
    public function findRequestsForDonation(array $donation, int $limit = 12): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.*, u.full_name AS receiver_name, u.organization_name, u.is_verified
             FROM requests r JOIN users u ON u.user_id = r.receiver_id
             WHERE r.category_id = ? AND r.status = 'searching'
             ORDER BY r.created_at DESC"
        );
        $stmt->execute([$donation['category_id']]);
        $candidates = $stmt->fetchAll();

        $scored = [];
        foreach ($candidates as $request) {
            $s = $this->scoreOne($donation, $request);
            $scored[] = ['request' => $request] + $s;
        }
        usort($scored, fn($a, $b) => $b['total_score'] <=> $a['total_score']);
        return array_slice($scored, 0, $limit);
    }

    /** Score ONE specific donation against a request (used on the detail page). */
    public function scoreOne(array $donation, array $request): array
    {
        $quantity = $this->quantityScore($donation, $request);
        $condition = $this->conditionScore($donation, $request);
        $distance = $this->distanceScore($donation, $request);
        $total = round(0.35 * $quantity + 0.25 * $condition + 0.40 * $distance, 0);
        return [
            'quantity_score' => $quantity, 'condition_score' => $condition, 'distance_score' => $distance,
            'total_score' => (int)$total, 'reasons' => $this->reasons($donation, $request, $quantity, $condition, $distance),
        ];
    }

    public static function label(int $score): string
    {
        if ($score >= 90) return 'Excellent Match';
        if ($score >= 80) return 'Strong Match';
        if ($score >= 65) return 'Good Match';
        return 'Fair Match';
    }

    private function quantityScore(array $donation, array $request): float
    {
        $qty = (int)$donation['quantity'];
        $min = (int)$request['quantity_min'];
        $max = (int)$request['quantity_max'];
        if ($qty >= $min && $qty <= max($min, $max)) return 100.0;
        if ($qty > $max) return 85.0; // more than enough is still usable
        if ($min > 0) return max(0.0, min(80.0, ($qty / $min) * 80));
        return 50.0;
    }

    private function conditionScore(array $donation, array $request): float
    {
        if ($request['condition_preference'] === 'any') return 100.0;
        $have = self::CONDITION_RANK[$donation['condition_grade']] ?? 2;
        $want = self::CONDITION_RANK[$request['condition_preference']] ?? 2;
        if ($have >= $want) return 100.0;
        return max(0.0, 100 - (($want - $have) * 25));
    }

    private function distanceScore(array $donation, array $request): float
    {
        if (empty($donation['latitude']) || empty($donation['longitude']) ||
            empty($request['latitude']) || empty($request['longitude'])) {
            return 60.0;
        }
        $km = $this->haversineKm(
            (float)$donation['latitude'], (float)$donation['longitude'],
            (float)$request['latitude'], (float)$request['longitude']
        );
        return max(0.0, min(100.0, 100 - ($km * 5)));
    }

    private function reasons(array $donation, array $request, float $q, float $c, float $d): array
    {
        $reasons = [];
        if ($q >= 85) $reasons[] = 'Quantity fits your requested range';
        if ($c >= 100) $reasons[] = 'Condition meets your preference';
        if ($d >= 70) $reasons[] = 'Nearby pickup area';
        $reasons[] = 'Category matches your request';
        return $reasons;
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
}
