<?php
/**
 * EcoLink - Scheduled matching runner.
 * Intended to be run from the command line (or Windows Task Scheduler / cron)
 * every few minutes, e.g.:
 *   php /path/to/ecolink/cron/run_matching.php
 * Re-scores every donation still 'available' against currently verified
 * receivers, so new donors/receivers/demand-profile updates keep getting
 * matched even without a page visit triggering it.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../matching/PriorityMatcher.php';

$db = getDB();
$matcher = new PriorityMatcher($db);
$results = $matcher->matchAllPending();

$matchedCount = 0;
foreach ($results as $donationId => $scored) {
    if (!empty($scored)) $matchedCount++;
}

echo date('Y-m-d H:i:s') . " - Processed " . count($results) . " pending donation(s), "
    . $matchedCount . " matched.\n";
