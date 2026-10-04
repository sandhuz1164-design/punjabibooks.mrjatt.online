<?php
/**
 * Fill products.Downloads from the Internet Archive search index.
 *
 * Archive.org exposes a lifetime `downloads` count per item and nothing
 * finer-grained (no views, no weekly figures, no trending endpoint), so this
 * is the popularity signal the site has to work with.
 *
 * Asking for one identifier at a time would be 2,875 requests; the search API
 * accepts an OR'd identifier list, so 50 per request brings it to ~58.
 *
 * Re-runnable: it only ever writes Downloads, never deletes rows.
 */
require_once __DIR__ . '/../db.php';

const BATCH = 50;
const FIELD = 'identifier,downloads';

function search_batch(array $pids): array
{
    $ors = array_map(fn($p) => '"' . str_replace(['\\', '"'], '', $p) . '"', $pids);
    $url = 'https://archive.org/advancedsearch.php?q=' . urlencode('identifier:(' . implode(' OR ', $ors) . ')')
        . '&fl=' . urlencode(FIELD)
        . '&rows=' . (count($pids) + 10)
        . '&output=json';

    $ctx = stream_context_create(['http' => [
        'timeout' => 60,
        'user_agent' => 'StrandBookstoreHarvest/1.0 (+local library)',
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return [];
    }
    $d = json_decode($raw, true);
    return $d['response']['docs'] ?? [];
}

$pids = array_map('strval', array_column(rows('SELECT PID FROM products'), 'PID'));
$total = count($pids);
echo "Fetching archive.org download counts for $total books...\n";

$st = db()->prepare('UPDATE products SET Downloads = ? WHERE PID = ?');
$seen = 0;
$hit = 0;
$batches = 0;

foreach (array_chunk($pids, BATCH) as $chunk) {
    $docs = search_batch($chunk);
    $batches++;
    $byId = [];
    foreach ($docs as $d) {
        if (!empty($d['identifier'])) {
            $byId[(string) $d['identifier']] = max(0, (int) ($d['downloads'] ?? 0));
        }
    }
    foreach ($chunk as $pid) {
        // Items with no download figure stay at 0 and sort last, which is the
        // honest reading: unknown traffic, not "no reads".
        $st->execute([$byId[$pid] ?? 0, $pid]);
        $seen++;
        if (isset($byId[$pid])) {
            $hit++;
        }
    }
    echo "  batch $batches: $seen/$total looked up, $hit with a download figure\n";
    usleep(200000);
}

$sum = one('SELECT COUNT(*) c, SUM(Downloads > 0) s, MAX(Downloads) m, SUM(Downloads) t FROM products');
echo "\nLooked up:        $seen\n";
echo "With downloads:   " . (int) ($sum['s'] ?? 0) . "\n";
echo "Zero (no data):   " . ((int) ($sum['c'] ?? 0) - (int) ($sum['s'] ?? 0)) . "\n";
echo 'Highest:          ' . (int) ($sum['m'] ?? 0) . "\n";
echo 'Combined:         ' . number_format((int) ($sum['t'] ?? 0)) . "\n";
