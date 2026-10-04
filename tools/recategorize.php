<?php
/**
 * Re-categorise books whose stored Category disagrees with what the importer's
 * own categorize() would decide from their Archive.org metadata.
 *
 * Why this exists: categorize() used to build its search blob with
 * (string)$m['creator'] and implode(' ', (array)$m['subject']). Archive.org
 * returns those as a string, a list, or a list of lists, so the cast produced the
 * literal "Array" and implode dropped every inner element - the rules were
 * matching against a title-only blob. Fixed in tools/import.php; this pass
 * repairs rows already written under the old behaviour.
 *
 * Only rows carrying Sikh markers outside 'Sikh Literature' are examined: those
 * are the ones the blind blob could plausibly have got wrong. Rows the rules
 * place in 'Magazines' (Sikh-topic periodicals) are left alone.
 *
 * Usage: php tools/recategorize.php [--dry-run]
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/categorize.php';

const CACHE_DIR = '/tmp/opencode/ia-cache';

function api_metadata(string $ident): ?array
{
    $cached = CACHE_DIR . '/' . $ident . '.json';
    if (is_file($cached)) {
        $json = json_decode((string) @file_get_contents($cached), true);
        if (is_array($json) && isset($json['metadata'])) {
            return $json;
        }
    }
    $ctx = stream_context_create(['http' => [
        'timeout' => 60,
        'user_agent' => 'StrandBookstore/1.0 (+local library)',
    ]]);
    $raw = @file_get_contents("https://archive.org/metadata/$ident", false, $ctx);
    if ($raw === false) {
        return null;
    }
    $d = json_decode($raw, true);
    if (!is_array($d) || !isset($d['metadata'])) {
        return null;
    }
    // Populate the cache so a re-run is free.
    @mkdir(CACHE_DIR, 0777, true);
    @file_put_contents($cached, $raw);
    return $d;
}

$dryRun = in_array('--dry-run', $argv, true);
// --all re-checks the entire catalogue. The old blob was title+subject only
// whenever creator came back as an array, so any book whose *author* carried the
// signal (e.g. "ਭਾਗ ਸਿੱਖੀ") fell through to the 'Punjabi Books' default. Those
// rows carry no Sikh marker in title/subject, so the narrow default query misses
// them. Nearly all metadata is already cached, so this costs no network.
$all = in_array('--all', $argv, true);

$sql = "SELECT PID, Category, Title FROM products";
if (!$all) {
    $sql .= " WHERE Category <> 'Sikh Literature'
        AND (LOWER(Author) LIKE '%sikh%' OR Subject LIKE '%sikh%' OR Subject LIKE '%ਸਿੱਖ%'
             OR Title LIKE '%ਸਿੱਖ%' OR Description LIKE '%ਸਿੱਖ%')";
}
$rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);

printf("examining %d rows%s\n", count($rows), $all ? ' (--all)' : '');

$up = db()->prepare('UPDATE products SET Category = ? WHERE PID = ?');
$changed = [];
$unchanged = 0;
$noMeta = 0;

foreach ($rows as $r) {
    $meta = api_metadata($r['PID']);
    if (!$meta) {
        $noMeta++;
        continue;
    }
    $new = categorize($meta['metadata'] ?? [], plain_title($meta['metadata']['title'] ?? $r['Title']));
    if ($new === 'Skipped') {
        $noMeta++;
        continue;
    }
    if ($new === $r['Category']) {
        $unchanged++;
        continue;
    }
    $changed[] = [$r['PID'], $r['Category'], $new, $r['Title']];
}

printf("\nunchanged:  %d\n", $unchanged);
printf("no metadata: %d\n", $noMeta);
printf("to update:  %d\n\n", count($changed));

foreach ($changed as $i => [$pid, $from, $to, $title]) {
    printf("  %-38s %-18s -> %-18s %s\n",
        mb_strimwidth($pid, 0, 38), mb_strimwidth($from, 0, 18), mb_strimwidth($to, 0, 18),
        mb_strimwidth((string) $title, 0, 34));
    if ($dryRun) {
        continue;
    }
    $up->execute([$to, $pid]);
}

echo $dryRun ? "\n(dry run - nothing written)\n" : "\nupdated " . count($changed) . " rows\n";
