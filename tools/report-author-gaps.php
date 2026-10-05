<?php
/**
 * Diff the Archive.org harvest for each author against the catalogue, and report
 * what is missing.
 *
 *   php tools/report-author-gaps.php
 *
 * A book counts as missing when Archive.org returns it for that author's creator
 * query and no row in `products` carries its PID. Both the exact creator phrase
 * and the looser token query were harvested (see fetch-by-author.mjs), so a book
 * attributed to a spelling variant still counts as present.
 */

require_once __DIR__ . '/../db.php';

const HITS = '/tmp/opencode/authors/hits.jsonl';

$pdo = db();
$known = [];
foreach ($pdo->query('SELECT ArchiveId FROM products') as $r) {
    $known[(string) $r['ArchiveId']] = true;
}
echo 'catalogue PIDs: ' . count($known) . "\n";

$byAuthor = [];
$nonPunjabi = [];
$totalFound = 0;
$totalMissing = 0;
$totalNonPunjabi = 0;
$authorCount = 0;
$authorsWithGaps = 0;
$failed = 0;

/**
 * Is this item Punjabi library material?
 *
 * Archive.org holds far more by these authors than is Punjabi. Anita Ganeri has
 * 1,139 items - "Ancient Greeks", "Buddhist", "Ancient Romans" - English
 * children's history. Amar Chitra Katha's 259 are Tinkle comics. None of it
 * belongs in a Punjabi library, and importing it would swamp the catalogue with
 * exactly the kind of off-topic filler the earlier imports were filtered to
 * exclude.
 *
 * The bar is deliberately the same as the existing harvests: Punjabi per
 * Archive.org's own language field, or Gurmukhi script in the text. Reports both
 * so the excluded volume is visible rather than silently dropped.
 */
function is_punjabi(array $doc): bool
{
    // Archive.org returns language, creator and title as either a string or a
    // list, so each needs flattening before it can be tested.
    $flat = function ($v): string {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $x) {
                $out[] = is_array($x) ? implode(' ', $x) : (string) $x;
            }
            return implode('; ', $out);
        }
        return (string) $v;
    };
    $lang = mb_strtolower($flat($doc['language'] ?? ''));
    if (str_contains($lang, 'pan') || str_contains($lang, 'punjab')) {
        return true;
    }
    // No language recorded: fall back to Gurmukhi in the title or creator.
    $blob = $flat($doc['title'] ?? '') . ' ' . $flat($doc['creator'] ?? '');
    return (bool) preg_match('/[\x{0A00}-\x{0A7F}]/u', $blob);
}

foreach (file(HITS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $d = json_decode($line, true);
    if (!is_array($d) || ($d['total'] ?? -1) < 0) {
        $failed++;
        continue;
    }
    $author = (string) $d['author'];
    $authorCount++;

    $missing = [];
    $offTopic = 0;
    foreach ($d['ids'] as $doc) {
        $pid = (string) ($doc['identifier'] ?? '');
        if ($pid === '' || isset($known[$pid])) {
            continue;
        }
        if (!is_punjabi($doc)) {
            $offTopic++;
            continue;
        }
        $c = $doc['creator'] ?? '';
        if (is_array($c)) {
            $c = implode('; ', $c);
        }
        $missing[] = [
            'pid' => $pid,
            'title' => (string) ($doc['title'] ?? ''),
            'creator' => (string) $c,
            'year' => (string) ($doc['year'] ?? ''),
        ];
    }

    $totalFound += count($d['ids']);
    $totalNonPunjabi += $offTopic;
    if (!$missing) {
        if ($offTopic) {
            $nonPunjabi[$author] = $offTopic;
        }
        continue;
    }
    $authorsWithGaps++;
    $totalMissing += count($missing);
    $byAuthor[$author] = $missing;
}

printf("authors searched:    %d  (failed: %d)\n", $authorCount, $failed);
printf("books seen on IA:    %d\n", $totalFound);
printf("authors with gaps:   %d\n", $authorsWithGaps);
printf("MISSING (Punjabi):   %d   <- the answer\n", $totalMissing);
printf("excluded off-topic:  %d   (English/other-language by the same authors)\n", $totalNonPunjabi);

// Biggest gaps first - those are where the missing books actually are.
$ordered = $byAuthor;
uasort($ordered, fn($a, $b) => count($b) <=> count($a));

$lines = [];
$lines[] = "MISSING BOOKS BY AUTHOR (Archive.org has them, catalogue does not)";
$lines[] = str_repeat('=', 78);
$lines[] = sprintf('%d authors, %d books', count($byAuthor), $totalMissing);
$lines[] = '';
$i = 0;
foreach ($ordered as $author => $books) {
    $i++;
    $lines[] = sprintf('%d) %s  -- %d missing', $i, $author, count($books));
    foreach ($books as $b) {
        $lines[] = sprintf('     %-46s %s', mb_strimwidth($b['pid'], 0, 46),
            mb_strimwidth($b['title'], 0, 56));
    }
    $lines[] = '';
}
file_put_contents('/tmp/opencode/authors/missing-by-author.txt', implode("\n", $lines) . "\n");

// A flat, importable list of the missing PIDs.
$flat = [];
foreach ($byAuthor as $books) {
    foreach ($books as $b) {
        $flat[$b['pid']] = $b;
    }
}
ksort($flat);
$ids = array_keys($flat);
file_put_contents('/tmp/opencode/authors/missing-ids.txt', implode("\n", $ids) . "\n");

$csv = "pid,title,creator,year\n";
foreach ($flat as $pid => $b) {
    $csv .= '"' . str_replace('"', '""', $pid) . '","'
        . str_replace('"', '""', $b['title']) . '","'
        . str_replace('"', '""', $b['creator']) . '","'
        . str_replace('"', '""', $b['year']) . '"' . "\n";
}
file_put_contents('/tmp/opencode/authors/missing.csv', $csv);

echo "wrote:\n";
echo '  missing-by-author.txt  (' . count($lines) . " lines, human readable)\n";
echo '  missing-ids.txt        (' . count($ids) . " identifiers, for the importer)\n";
echo '  missing.csv            (' . count($flat) . " rows)\n\n";
echo "top 25 authors by missing count:\n";
$n = 0;
foreach ($ordered as $author => $books) {
    printf("  %4d  %s\n", count($books), mb_strimwidth($author, 0, 60));
    if (++$n >= 25) {
        break;
    }
}