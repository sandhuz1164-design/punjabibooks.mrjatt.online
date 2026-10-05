<?php
/**
 * Wikipedia enrichment, with a relevance guard.
 *
 * Measured problem this exists to solve: a naive title search against the Wikipedia API
 * returns confidently wrong articles. "Samarth Kosh Kirpal Singh" resolved to Lata
 * Mangeshkar, "Mehtab Singh Master" to Yadavindra Singh. Attaching a pop singer's
 * biography to a Sikh poetry book is worse than showing no blurb at all, so a match has
 * to earn its place.
 *
 * A candidate is accepted only when it clears three independent bars:
 *   1. topical   - the article sits in a Sikh/Punjabi/Indian-studies category
 *   2. lexical   - significant tokens from the book title appear in the article title
 *   3. not vague - the article is not a disambiguation or list page
 *
 * Usage:
 *   php tools/wiki-match.php            # report yield on a sample, changes nothing
 *   php tools/wiki-match.php --apply    # write accepted matches into the products table
 */
require __DIR__ . '/../db.php';

$API = 'https://en.wikipedia.org/w/api.php';
const UA = 'BookStore-v2 catalogue enrichment (local project; contact: local)';

// Words too common to carry meaning, and the ones Archive metadata adds to everything.
const STOP = ['the','a','an','of','and','or','in','on','for','to','with','from','by','vol','volume',
    'part','book','books','pdf','complete','full','edition','illustrated','index','copy','text',
    'contents','sikh','punjabi','punjab','gurmukhi','gurmat','granth','sahib','ji','lite','new','old',
    'first','second','third','fourth','five','six','of','is','it','as','at','an','be'];

function tokens(string $s): array
{
    $t = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s)) ?: [];
    return array_values(array_filter(array_unique($t), static fn($w) => mb_strlen($w) > 2 && !in_array($w, STOP, true)));
}

function wikiSearch(string $q, int $limit = 3): array
{
    global $API;
    $url = $API . '?' . http_build_query([
        'action' => 'query', 'format' => 'json', 'utf8' => 1,
        'generator' => 'search', 'gsrsearch' => $q, 'gsrlimit' => $limit,
        'prop' => 'extracts|categories', 'exintro' => 1, 'explaintext' => 1,
        'exsentences' => 3, 'cllimit' => 'max', 'redirects' => 1,
    ]);
    $ctx = stream_context_create(['http' => ['timeout' => 25, 'header' => 'User-Agent: ' . UA]]);
    $raw = @file_get_contents($url, false, $ctx);
    $j = json_decode((string) $raw, true);
    if (!isset($j['query']['pages'])) {
        return [];
    }
    $out = [];
    foreach ($j['query']['pages'] as $p) {
        $out[] = [
            'title' => $p['title'] ?? '',
            'extract' => trim($p['extract'] ?? ''),
            'cats' => array_column($p['categories'] ?? [], '*'),
            'pageid' => $p['pageid'] ?? 0,
        ];
    }
    return $out;
}

const TOPICAL = '/sikh|punjab|gurmukhi|khalsa|gurdwara|guru|dasam granth|bhagat|hindu|islam|indian|zoroastrian|braj|sanskrit|amrit|martyr/i';

/**
 * @return array{ok:bool,why:string,url:string,extract:string}
 */
function judge(string $title, string $author, array $cand): array
{
    $miss = static fn(string $w) => ['', $url = '', $extract = ''];
    $none = static fn(string $w) => ['ok' => false, 'why' => $w, 'url' => '', 'extract' => ''];

    if ($cand['title'] === '' || $cand['extract'] === '') {
        return $none('empty');
    }
    // Disambiguation and list pages are never a book description.
    if (preg_match('/\(disambiguation\)|\bList of\b/i', $cand['title'])) {
        return $none('disambiguation');
    }
    $catBlob = implode(' ', $cand['cats']);
    if (!preg_match(TOPICAL, $catBlob)) {
        return $none('off-topic categories');
    }

    // Lexical bar: enough of the title's significant words must appear in the
    // article title. Deliberately strict - a miss just means no blurb, which is fine.
    $bt = tokens($title);
    if (!$bt) {
        return $none('no significant title tokens');
    }
    $at = mb_strtolower($cand['title']);
    $hit = 0;
    foreach ($bt as $w) {
        if (mb_strpos($at, $w) !== false) {
            $hit++;
        }
    }
    $score = $hit / count($bt);
    if ($score < 0.5) {
        return $none(sprintf('lexical %.0f%%', $score * 100));
    }

    return [
        'ok' => true,
        'why' => sprintf('lexical %.0f%%', $score * 100),
        'url' => 'https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $cand['title'])),
        'extract' => $cand['extract'],
    ];
}

$pdo = db();
$apply = in_array('--apply', $argv, true);

// Sample across the whole catalogue, weighted to the books readers actually open,
// but not exclusively - otherwise the yield estimate only describes the top of the list.
$rows = $pdo->query(
    'SELECT PID, Title, Author, Description FROM products
      WHERE CHAR_LENGTH(Title) > 8
      ORDER BY (CHAR_LENGTH(TRIM(Description)) > 40) DESC, Downloads DESC, RAND()
      LIMIT 60'
)->fetchAll();

$accepted = 0;
$reasons = [];
$results = [];

foreach ($rows as $r) {
    $cands = wikiSearch($r['Title']);
    $best = null;
    foreach ($cands as $c) {
        $v = judge($r['Title'], $r['Author'], $c);
        if ($v['ok']) {
            $best = $v + ['title' => $c['title']];
            break;
        }
        if ($best === null) {
            $reasons[$v['why']] = ($reasons[$v['why']] ?? 0) + 1;
        }
    }
    if ($best) {
        $accepted++;
        $results[] = ['PID' => $r['PID'], 'Title' => $r['Title'], 'w' => $best];
    }
}

printf("  sampled %d books\n", count($rows));
printf("  accepted %d  (%.0f%%)\n\n", $accepted, $accepted / max(1, count($rows)) * 100);

echo "  rejection reasons\n";
arsort($reasons);
foreach ($reasons as $why => $n) {
    printf("    %-28s %d\n", $why, $n);
}

echo "\n  accepted matches\n";
foreach ($results as $x) {
    printf("    %-46s -> %s\n", mb_strimwidth($x['Title'], 0, 46), mb_strimwidth($x['w']['title'], 0, 40));
}

if (!$apply) {
    echo "\n  (dry run - pass --apply to write these to the database)\n";
    exit;
}

$pdo->prepare('ALTER TABLE products ADD COLUMN WikiUrl TEXT NULL')->execute();
$pdo->prepare('ALTER TABLE products ADD COLUMN WikiExtract TEXT NULL')->execute();

$up = $pdo->prepare('UPDATE products SET WikiUrl = ?, WikiExtract = ? WHERE PID = ?');
$n = 0;
foreach ($results as $x) {
    $up->execute([$x['w']['url'], $x['w']['extract'], $x['PID']]);
    $n += $up->rowCount();
}
printf("\n  wrote %d rows\n", $n);