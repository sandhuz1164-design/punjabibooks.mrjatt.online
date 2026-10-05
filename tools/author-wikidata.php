<?php
/**
 * Author enrichment from Wikidata.
 *
 * Why Wikidata and not Wikipedia: Wikidata carries the things an author page actually
 * needs that Wikipedia prose does not - a portrait (P18), lifespan (P569/P570),
 * occupation (P106) and the language of their work. It is also structured, so it can be
 * matched without scraping.
 *
 * Guard, for the same reason as tools/wiki-match.php: a naive name search finds
 * confidently wrong people. "Guru Gobind Singh" and "Amar Singh" are common enough that
 * an unchecked lookup will eventually return a different individual. A candidate must
 * clear:
 *   1. topical - Sikh, Punjabi or Indian subject, stated by the entity's own description
 *                or its sitelinks (a punjab.wikipedia.org article is near-conclusive)
 *   2. plausible - a living person cannot have died before they were born, and an
 *                author born in 1950 cannot have written a 1920 treatise
 *   3. unambiguous - the entity must be about a human
 *
 * Nothing is written on a dry run. Dry run first, always.
 */
require __DIR__ . '/../db.php';
require __DIR__ . '/author-normalise.php';

const WD_API = 'https://www.wikidata.org/w/api.php';
const WD_UA = 'BookStore-v2 catalogue enrichment (local project)';
const WD_QID = 'https://www.wikidata.org/wiki/';

function wdGet(string $endpoint, array $params): array
{
    $params['format'] = 'json';
    $params['formatversion'] = '2';
    $url = $endpoint . '?' . http_build_query($params);
    $ctx = stream_context_create(['http' => ['timeout' => 25, 'header' => 'User-Agent: ' . WD_UA]]);
    $j = json_decode((string) @file_get_contents($url, false, $ctx), true);
    return is_array($j) ? $j : [];
}

function wdSearch(string $name): array
{
    return wdGet(WD_API, [
        'action' => 'wbsearchentities', 'search' => $name,
        'language' => 'en', 'uselang' => 'en', 'type' => 'item',
        'limit' => 5, 'format' => 'json',
    ])['search'] ?? [];
}

/** Pull the fields we want out of a getentities response. */
function wdFacts(array $e): array
{
    $get = static fn(string $p) => $e['claims'][$p][0]['mainsnak']['datavalue']['value'] ?? null;

    $year = static function ($v): ?int {
        if (!is_array($v)) {
            return null;
        }
        $t = $v['time'] ?? '';
        return preg_match('/^[+-](\d{4})/', $t, $m) ? (int) $m[1] : null;
    };

    $img = $get('P18');
    $photo = null;
    if (is_array($img)) {
        $photo = 'https://commons.wikimedia.org/wiki/Special:FilePath/'
            . rawurlencode(str_replace(' ', '_', (string) $img));
    }

    $birth = $year($get('P569'));
    $death = $year($get('P570'));

    $occ = [];
    foreach (($e['claims']['P106'] ?? []) as $c) {
        $id = $c['mainsnak']['datavalue']['value']['id'] ?? null;
        if ($id) {
            $occ[] = $id;
        }
    }

    // P31 "instance of" is what says this entity is a human. Occupations (P106) point at
    // things like "writer" (Q49757), so testing P106 for Q5 rejected almost everyone.
    $instance = [];
    foreach (($e['claims']['P31'] ?? []) as $c) {
        $id = $c['mainsnak']['datavalue']['value']['id'] ?? null;
        if ($id) {
            $instance[] = $id;
        }
    }

    $sitelinks = array_keys($e['sitelinks'] ?? []);

    return [
        'qid' => $e['id'] ?? '',
        'label' => $e['labels']['en']['value'] ?? '',
        'description' => $e['descriptions']['en']['value'] ?? '',
        'photo' => $photo,
        'birth' => $birth,
        'death' => $death,
        'occupations' => $occ,
        'instance' => $instance,
        'sitelinks' => $sitelinks,
        'hasPunjabiWiki' => (bool) preg_grep('/^punjab$/', $sitelinks),
    ];
}

/**
 * @return array{ok:bool,why:string,data:array}
 */
function wdJudge(array $facts, int $earliestBookYear, string $authorName): array
{
    $no = static fn(string $w) => ['ok' => false, 'why' => $w, 'data' => []];

    if ($facts['qid'] === '') {
        return $no('no entity');
    }
    // must be a human. Q5 is "human"; some humans are also classed as fictional
    // characters (Q95074) or as groups, which is exactly what we want to reject here.
    if (!in_array('Q5', $facts['instance'], true)) {
        return $no('not a person (P31 lacks Q5)');
    }

    // identity guard - the single most important check.
    // Topical relevance alone is not identity: "Ajmer Singh" (a Punjabi writer in this
    // catalogue) is topically fine against "Ajmer Singh Chopra", but they may be
    // different people, and so may "RANJIT SINGH" against the Maharaja. Require the
    // entity's own label to carry the author's name.
    $nameTokens = authorTokens($authorName);
    $labelTokens = authorTokens((string) $facts['label']);
    if ($nameTokens && $labelTokens) {
        $overlap = count(array_intersect($nameTokens, $labelTokens));
        $need = max(2, (int) ceil(count($nameTokens) / 2));
        if ($overlap < $need) {
            return $no(sprintf('name mismatch: %d/%d tokens vs [%s]',
                $overlap, $need, implode(' ', $labelTokens)));
        }
        // A surname present in one and absent in the other is a red flag even when the
        // given names line up.
        $extra = array_diff($labelTokens, $nameTokens);
        $lost = array_diff($nameTokens, $labelTokens);
        if (count($extra) >= 1 && count($lost) >= 1) {
            return $no(sprintf('surname conflict: label has [%s], author has [%s]',
                implode(' ', $extra), implode(' ', $lost)));
        }
    }

    // topical guard
    $blob = $facts['description'] . ' ' . implode(' ', $facts['sitelinks']);
    $punjabi = $facts['hasPunjabiWiki'];
    if (!$punjabi && !preg_match('/sikh|punjab|punjabi|gurmukhi|khalsa|amrit|gurdwara|india/i', $blob)) {
        return $no('off-topic: ' . mb_strimwidth($facts['description'], 0, 46));
    }

    // plausibility guard - a living author cannot have written a 1920 treatise
    if ($facts['birth'] !== null && $facts['birth'] > 1930 && $earliestBookYear > 0
        && $earliestBookYear < $facts['birth'] - 5) {
        return $no(sprintf('implausible: born %d, book dated %d', $facts['birth'], $earliestBookYear));
    }
    if ($facts['birth'] !== null && $facts['death'] !== null && $facts['death'] < $facts['birth']) {
        return $no('death before birth');
    }

    return ['ok' => true, 'why' => 'matched', 'data' => $facts];
}

$pdo = db();
$apply = in_array('--apply', $argv, true);
$minBooks = 3;

// Only authors with real substance in the catalogue, and never the non-person values.
$authors = $pdo->query(
    'SELECT Author, COUNT(*) n, MIN(NULLIF(Year,0)) y
       FROM products GROUP BY Author HAVING n > ' . ($minBooks - 1) . ' ORDER BY n DESC'
)->fetchAll();

$authors = array_values(array_filter($authors, static fn($a) => !isNonPersonAuthor((string) $a['Author'])));
printf("  %d authors with >%d books\n\n", count($authors), $minBooks - 1);

$hits = [];
$misses = [];
foreach ($authors as $a) {
    $name = (string) $a['Author'];
    // Archive writes "Gurmukhi - Romanised". Try the Romanised half first since that is
    // what Wikidata indexes, then the whole string. Deliberately simple string handling:
    // an earlier regex here ate the start of "Sukhwant Hundal".
    $queries = [$name];
    if (str_contains($name, ' - ')) {
        array_unshift($queries, trim(substr($name, strpos($name, ' - ') + 3)));
    }
    $queries = array_values(array_unique(array_filter($queries, static fn($q) => mb_strlen(trim($q)) >= 3)));

    $cands = [];
    foreach ($queries as $q) {
        foreach (wdSearch($q) as $c) {
            $cands[$c['id']] = $c;
        }
        if (count($cands) >= 3) {
            break;
        }
    }

    $best = null;
    foreach (array_slice($cands, 0, 4, true) as $c) {
        $full = wdGet(WD_API, ['action' => 'wbgetentities', 'ids' => $c['id']])['entities'] ?? [];
        $facts = wdFacts(reset($full) ?: []);
        $v = wdJudge($facts, (int) ($a['y'] ?? 0), $name);
        if ($v['ok']) {
            $best = $v['data'] + ['why' => $v['why']];
            break;
        }
        $misses[] = sprintf('%-40s %s', mb_strimwidth($name, 0, 40), $v['why']);
    }
    if ($best) {
        $hits[] = ['Author' => $name, 'n' => (int) $a['n'], 'facts' => $best];
    }
}

printf("  matched %d of %d authors (%.0f%%)\n", count($hits), count($authors), count($hits) / max(1, count($authors)) * 100);
$withPhoto = count(array_filter($hits, static fn($h) => !empty($h['facts']['photo'])));
printf("  of which %d have a portrait\n\n", $withPhoto);

echo "  matches\n";
foreach ($hits as $h) {
    printf("    %-38s %4d books  %-26s %s\n",
        mb_strimwidth($h['Author'], 0, 38), $h['n'],
        mb_strimwidth((string) $h['facts']['label'], 0, 26),
        $h['facts']['photo'] ? 'photo' : ($h['facts']['hasPunjabiWiki'] ? 'punjabi-wiki' : 'text'));
}

printf("\n  top rejections\n");
$counts = array_count_values(array_map(static fn($m) => preg_replace('/^.{41}/', '', $m), $misses));
arsort($counts);
foreach (array_slice($counts, 0, 8, true) as $why => $n) {
    printf("    %4d  %s\n", $n, $why);
}

if (!$apply) {
    echo "\n  (dry run - pass --apply)\n";
    exit;
}

$pdo->exec('CREATE TABLE IF NOT EXISTS author_meta (
    Author VARCHAR(255) NOT NULL PRIMARY KEY,
    Qid VARCHAR(20) NULL,
    Label VARCHAR(255) NULL,
    Bio VARCHAR(1000) NULL,
    Photo VARCHAR(500) NULL,
    BirthYear SMALLINT NULL,
    DeathYear SMALLINT NULL,
    PunjabiWiki TINYINT NOT NULL DEFAULT 0,
    Source VARCHAR(255) NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4');

$ins = $pdo->prepare('INSERT INTO author_meta
    (Author, Qid, Label, Bio, Photo, BirthYear, DeathYear, PunjabiWiki, Source)
    VALUES (?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE Qid=VALUES(Qid), Label=VALUES(Label), Bio=VALUES(Bio),
      Photo=VALUES(Photo), BirthYear=VALUES(BirthYear), DeathYear=VALUES(DeathYear),
      PunjabiWiki=VALUES(PunjabiWiki), Source=VALUES(Source)');

$n = 0;
foreach ($hits as $h) {
    $f = $h['facts'];
    $ins->execute([
        $h['Author'], $f['qid'], $f['label'], $f['description'], $f['photo'],
        $f['birth'], $f['death'], $f['hasPunjabiWiki'] ? 1 : 0, WD_QID . $f['qid'],
    ]);
    $n++;
}
printf("\n  wrote %d rows to author_meta\n", $n);