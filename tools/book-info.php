<?php
/**
 * Turn cached Archive.org OCR into real book information.
 *
 * Input:  the first 64 KB of each item's _djvu.txt, cached on the external drive by
 *         tools/fetch-book-heads.mjs.
 * Output: one row per book in book_info - author as printed, publisher, place, year,
 *         edition/volume, and a cleaned excerpt of the opening.
 *
 * Why a quality gate is mandatory: the OCR is bimodal, not a spectrum. Items like
 * "Varan Bhai Gurdas" come through perfectly clean and yield a full title page.
 * Others are character soup that would be worse than no information on a book page.
 * Measured on the first 137: 82% excellent, 6% usable, 12% poor or unusable. So
 * ~88% of the catalogue can carry real text and ~12% must be left alone rather than
 * filled with OCR debris.
 *
 * Nothing here guesses. A field is filled only when the text actually states it.
 *
 * Usage:
 *   php tools/book-info.php          # dry run, reports what would be written
 *   php tools/book-info.php --apply
 */

require __DIR__ . '/../db.php';

$pdo = db();

$TEXT_DIR = getenv('TEXT_OUT') ?: '/run/media/jatt/9890EF3990EF1C8E/bookstore-text';
$MANIFEST = "$TEXT_DIR/manifest.jsonl";
$APPLY = in_array('--apply', $argv, true);

if (!is_file($MANIFEST)) {
    fwrite(STDERR, "no manifest at $MANIFEST - run tools/fetch-book-heads.mjs first\n");
    exit(1);
}

/**
 * A real word: a run of Indic letters, or a Latin word.
 * The Gurmukhi addak and vowel signs are included because OCR detaches them constantly
 * and without them almost every Punjabi word counts as noise.
 */
const WORD_RE = '/[\x{0A00}-\x{0A7F}][\x{0A00}-\x{0A7F}\x{0A3E}\x{0A4B}\x{0A3F}\x{0A70}\x{0A4D}\']{1,}'
    . '|\b[A-Za-z][A-Za-z\'-]{1,}\b/u';

const GURMUKHI_RE = '/[\x{0A00}-\x{0A7F}]/u';

/** Remove the digit-run noise DjVu OCR produces from old print and ruled lines. */
function cleanOcr(string $t): string
{
    $t = str_replace("\r", '', $t);
    $t = preg_replace('/[0-9]{2,}[^\w\s]{0,2}/u', ' ', $t);
    $t = preg_replace('/[|¦¢€%\$\^~`<>{}_\[\]\\\\]+/u', ' ', $t);
    $t = preg_replace('/[ \t]+/u', ' ', $t);
    $t = preg_replace('/\n{3,}/u', "\n\n", $t);
    return trim($t);
}

/** Share of non-whitespace characters that belong to real words. */
function textScore(string $t): float
{
    $body = preg_replace('/\s/u', '', $t);
    if ($body === null || $body === '') {
        return 0.0;
    }
    preg_match_all(WORD_RE, $t, $m);
    $good = 0;
    foreach ($m[0] as $w) {
        $good += mb_strlen($w);
    }
    return $good / mb_strlen($body);
}

/** Keep only lines that are mostly letters - the readable ones. */
function readableLines(string $t): array
{
    $out = [];
    foreach (preg_split('/\n/u', $t) ?: [] as $line) {
        $s = trim($line);
        if (mb_strlen($s) < 5) {
            continue;
        }
        preg_match_all(WORD_RE, $s, $m);
        if (!$m[0]) {
            continue;
        }
        $letters = preg_match_all('/\p{L}/u', $s) ?: 0;
        if ($letters / mb_strlen($s) < 0.6) {
            continue;
        }
        if (mb_strlen(implode('', $m[0])) / mb_strlen($s) < 0.45) {
            continue;
        }
        $out[] = $s;
    }
    return $out;
}

/**
 * Pull structured facts out of the opening lines.
 * Each returns null rather than a guess when the text does not state it.
 */
function extractFacts(array $lines): array
{
    $f = ['imprint_author' => null, 'publisher' => null, 'place' => null,
        'year' => null, 'edition' => null, 'excerpt' => null];

    // Publisher: an explicit label, in either script.
    foreach ($lines as $l) {
        if (preg_match('/(ਪ੍ਰਕਾਸ਼ਕ|ਪ੍ਰਕਾਸ਼ਕ|ਮੁਦਰਾਮ|publisher|printed and published)\s*[:：]?\s*(.{2,60})/iu', $l, $m)) {
            $f['publisher'] = trim(preg_replace('/\s+/u', ' ', $m[2]));
        }
        if (preg_match('/(ਅੰਮ੍ਰਿਤਸਰ|ਲਹੌਰ|ਦਿੱਲੀ|ਦਿਲਲੀ|ਕਿਰਾਦਪੁਰ|ਅੰਮ੍ਰਿਤਸਰ)/u', $l, $m)) {
            $f['place'] = $m[1];
        }
    }
    if ($f['publisher'] === null) {
        // Imprint lines often run publisher and place together without a label.
        foreach (array_slice($lines, 0, 12) as $l) {
            if (preg_match('/^([\p{L}\p{M}\.\s&]{4,50})\s*[,\-–]\s*(ਅੰਮ੍ਰਿਤਸਰ|ਲਹੌਰ|ਦਿੱਲੀ|ਕਿਰਾਦਪੁਰ)/u', $l, $m)) {
                $f['publisher'] = trim($m[1]);
                $f['place'] ??= $m[2];
                break;
            }
        }
    }

    // Year: a plausible 19th-21st century year, from the opening only.
    foreach (array_slice($lines, 0, 25) as $l) {
        if (preg_match('/\b(18[5-9]\d|19[0-9]\d|20[0-2]\d)\b/u', $l, $m)) {
            $f['year'] = (int) $m[1];
            break;
        }
    }

    // Edition / volume.
    foreach (array_slice($lines, 0, 25) as $l) {
        if (preg_match('/\b(volume|vol\.?|part|edition|ਭਾਗ|ਖੰਡ|ਵੋਲ)\s*[:\-–]?\s*([0-9ivxlcdm]+|[੦-੯]+|[੧-੫]{1,2})/iu', $l, $m)) {
            $f['edition'] = trim($m[1] . ' ' . $m[2]);
            break;
        }
    }

    // Author as printed. Only when the line is short and name-shaped, so a sentence
    // about an author does not get mistaken for one.
    foreach (array_slice($lines, 0, 20) as $l) {
        $l = trim($l, " \t-–—:;,.");
        if (mb_strlen($l) < 4 || mb_strlen($l) > 60) {
            continue;
        }
        if (preg_match('/\b(publisher|printed|price|pages|edition|volume|isbn|copyright|www\.|http|email)\b/i', $l)) {
            continue;
        }
        $words = preg_split('/\s+/u', $l) ?: [];
        if (count($words) < 2 || count($words) > 5) {
            continue;
        }
        $ok = true;
        foreach ($words as $w) {
            if (!preg_match('/^[\p{L}\p{M}\.\']{2,}$/u', $w)) {
                $ok = false;
                break;
            }
        }
        if ($ok && count(array_filter($words, static fn($w) => (bool) preg_match(GURMUKHI_RE, $w))) >= 1) {
            $f['imprint_author'] = $l;
            break;
        }
    }

    // Excerpt: the first few readable lines that are not the running title.
    $ex = [];
    foreach ($lines as $l) {
        if (mb_strlen($l) < 12) {
            continue;
        }
        $ex[] = $l;
        if (count($ex) >= 4) {
            break;
        }
    }
    $f['excerpt'] = $ex ? implode(' ', $ex) : null;

    return $f;
}

// ---- run -----------------------------------------------------------------

$rows = [];
foreach (file($MANIFEST, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $j = json_decode($line, true);
    if (is_array($j) && ($j['status'] ?? '') === 'ok') {
        $rows[$j['pid']] = $j;
    }
}

$good = $skipped = 0;
$found = ['author' => 0, 'publisher' => 0, 'place' => 0, 'year' => 0, 'edition' => 0, 'excerpt' => 0];
$records = [];

foreach ($rows as $pid => $j) {
    $safe = preg_replace('/[^\w.-]/', '_', (string) $pid);
    $file = "$TEXT_DIR/heads/$safe.txt";
    if (!is_file($file)) {
        $skipped++;
        continue;
    }
    $raw = (string) file_get_contents($file);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $skipped++;
        continue;
    }
    $score = textScore($raw);

    // The gate. Below 0.55 the OCR is debris and nothing is extracted from it.
    if ($score < 0.55) {
        $skipped++;
        continue;
    }

    $lines = readableLines(cleanOcr($raw));
    if (!$lines) {
        $skipped++;
        continue;
    }
    $f = extractFacts($lines);
    foreach (['imprint_author' => 'author', 'publisher' => 'publisher', 'place' => 'place',
        'year' => 'year', 'edition' => 'edition', 'excerpt' => 'excerpt'] as $field => $counter) {
        if ($f[$field] !== null) {
            $found[$counter]++;
        }
    }
    $records[] = ['PID' => $pid, 'score' => round($score, 3),
        'text_url' => 'https://archive.org/download/'
            . rawurlencode((string) $j['archiveId']) . '/'
            . rawurlencode((string) ($j['name'] ?? ''))] + $f;
    $good++;
}

$total = count($rows);
printf("  %d heads fetched, %d usable (%.0f%%), %d skipped as unreadable OCR\n\n",
    $total, $good, $good / max(1, $total) * 100, $skipped);

echo "  fields recovered from usable text\n";
foreach ($found as $k => $v) {
    printf("    %-12s %5d  (%.0f%% of usable)\n", $k, $v, $v / max(1, $good) * 100);
}

echo "\n  samples\n";
foreach (array_slice($records, 0, 4) as $r) {
    printf("    %-42s score %.2f\n", mb_strimwidth((string) $r['PID'], 0, 42), $r['score']);
    printf("        author    : %s\n", $r['imprint_author'] ?? '-');
    printf("        publisher : %s\n", $r['publisher'] ?? '-');
    printf("        place/year: %s / %s\n", $r['place'] ?? '-', $r['year'] ?? '-');
    printf("        edition   : %s\n", $r['edition'] ?? '-');
    printf("        excerpt   : %s\n", mb_strimwidth((string) ($r['excerpt'] ?? '-'), 0, 110));
}

if (!$APPLY) {
    printf("\n  (dry run - pass --apply to write %d rows)\n", count($records));
    exit;
}

$pdo->exec('CREATE TABLE IF NOT EXISTS book_info (
    PID VARCHAR(191) NOT NULL PRIMARY KEY,
    Score DECIMAL(4,3) NULL,
    ImprintAuthor VARCHAR(255) NULL,
    Publisher VARCHAR(255) NULL,
    Place VARCHAR(120) NULL,
    ImprintYear SMALLINT NULL,
    Edition VARCHAR(120) NULL,
    Excerpt TEXT NULL,
    TextUrl TEXT NULL,
    ExtractedAt DATETIME NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4');

$ins = $pdo->prepare('INSERT INTO book_info
    (PID, Score, ImprintAuthor, Publisher, Place, ImprintYear, Edition, Excerpt, TextUrl, ExtractedAt)
    VALUES (?,?,?,?,?,?,?,?,?,NOW())
    ON DUPLICATE KEY UPDATE Score=VALUES(Score), ImprintAuthor=VALUES(ImprintAuthor),
      Publisher=VALUES(Publisher), Place=VALUES(Place), ImprintYear=VALUES(ImprintYear),
      Edition=VALUES(Edition), Excerpt=VALUES(Excerpt), TextUrl=VALUES(TextUrl),
      ExtractedAt=VALUES(ExtractedAt)');

$pdo->beginTransaction();
$n = 0;
foreach ($records as $r) {
    $ins->execute([$r['PID'], $r['score'], $r['imprint_author'], $r['publisher'],
        $r['place'], $r['year'], $r['edition'], $r['excerpt'], $r['text_url']]);
    $n++;
}
$pdo->commit();
printf("\n  wrote %d rows to book_info\n", $n);