<?php
require_once __DIR__ . '/../db.php';

const CACHE_DIR = '/tmp/opencode/ia-cache';
// Identifier list to import. Override with IDS_FILE=/path/to/ids.txt to import a
// different harvest without editing this file.
const IDS_FILE = '/tmp/opencode/gurmukhi-new.txt';
const API_DELAY_MS = 150; // be nice to Archive.org

$dryRun = in_array('--dry-run', $argv, true);
ob_implicit_flush(true);

const PDF_RANK = [
    'Text PDF'           => 5,
    'Additional Text PDF' => 4,
    'Searchable PDF'     => 3,
    'Image Container PDF' => 2,
];

function api_fetch(string $url): ?array
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'StrandBookstore/1.0 (+local library)'
        ]
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) {
        return null;
    }
    $json = json_decode($data, true);
    return is_array($json) ? $json : null;
}

function api_search_identifiers(): array
{
    // Curated id list wins when present: built from
    // (language:pan OR language:"Panjabi") AND mediatype:texts, filtered to
    // items whose indexed title/subject/creator actually contains Gurmukhi
    // script (U+0A00-U+0A7F), which drops the Latin-script Punjab Gazettes.
    $file = getenv('IDS_FILE') ?: IDS_FILE;
    if (is_file($file)) {
        $lines = array_filter(array_map('trim', file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
        return array_values(array_unique($lines));
    }

    $ids = [];
    $page = 1;
    $rows = 500;
    $q = urlencode('language:punjabi AND mediatype:texts');
    $fields = 'identifier';
    
    while (true) {
        $url = "https://archive.org/advancedsearch.php"
            . "?q=$q"
            . "&fl=$fields"
            . "&rows=$rows"
            . "&page=$page"
            . "&output=json"
            . "&save=yes";
        
        $result = api_fetch($url);
        if (!$result || !isset($result['response']['docs'])) {
            break;
        }
        foreach ($result['response']['docs'] as $doc) {
            if (!empty($doc['identifier'])) {
                $ids[] = (string) $doc['identifier'];
            }
        }
        if (count($result['response']['docs']) < $rows) {
            break;
        }
        $page++;
        usleep(API_DELAY_MS * 1000);
    }
    return array_values(array_unique($ids));
}

function api_metadata(string $ident): ?array
{
    $cached = CACHE_DIR . '/' . $ident . '.json';
    if (is_file($cached)) {
        $json = json_decode((string) @file_get_contents($cached), true);
        if (is_array($json) && isset($json['metadata'])) {
            return $json;
        }
    }
    $url = "https://archive.org/metadata/$ident";
    return api_fetch($url);
}

function clean_utf8($s, int $max = 0): string
{
    $s = (string) $s;
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $s);
    return $max > 0 ? mb_substr(trim((string) $s), 0, $max) : trim((string) $s);
}

function archive_date($s): ?string
{
    $s = trim((string) $s);
    if ($s === '') {
        return null;
    }
    $ts = strtotime($s);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

function creator_name($c): string
{
    $c = clean_utf8($c, 250);
    if ($c === '' || preg_match('/^[\d\s+()-]+$/', $c)) {
        return 'Unknown';
    }
    return $c;
}

function strip_html($s): string
{
    $s = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $s);
    $s = preg_replace('#<br\s*/?>#i', ' ', $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

function plain_title($t): string
{
    $t = strip_html($t);
    $t = preg_replace('/\s*[-\(]\s*(Free\s+)?(PDF|sample|Wps Word).*$/iu', '', $t);
    return trim((string) $t);
}

function norm_key($t): string
{
    $t = mb_strtolower(trim((string) $t));
    $t = preg_replace('/[^\p{L}\p{N}]+/u', '', $t);
    return (string) $t;
}

function extract_year($m): ?int
{
    foreach (['year', 'date', 'publicdate'] as $k) {
        if (!empty($m[$k]) && preg_match('/(\d{4})/', (string) $m[$k], $mm)) {
            $y = (int) $mm[1];
            if ($y >= 1500 && $y <= (int) date('Y') + 1) {
                return $y;
            }
        }
    }
    return null;
}

function pick_files(array $files): array
{
    $out = ['pdf' => null, 'pdf_rank' => -1, 'pdf_size' => 0, 'text' => null, 'epub' => null,
            'mobi' => null, 'djvu' => null, 'zip' => null, 'zip_rank' => 0];
    foreach ($files as $f) {
        $name = (string) ($f['name'] ?? '');
        $low  = strtolower($name);
        $fmt  = (string) ($f['format'] ?? '');
        $size = (int) ($f['size'] ?? 0);

        if (str_contains($low, 'meta.xml') || str_contains($low, '_files.xml') || str_contains($low, '_meta.sqlite')) {
            continue;
        }
        if ($fmt === 'LCP Encrypted EPUB' || str_contains($low, 'encrypted')) {
            continue;
        }

        if ($low !== '' && str_ends_with($low, '.pdf')) {
            $rank = PDF_RANK[$fmt] ?? 1;
            if ($rank > $out['pdf_rank']) {
                $out['pdf'] = $name;
                $out['pdf_rank'] = $rank;
                $out['pdf_size'] = $size;
            }
        }
        if ($fmt === 'DjVuTXT' || str_ends_with($low, '_djvu.txt')) {
            if ($out['text'] === null || $size > 0) {
                $out['text'] = $name;
            }
        }
        if ($fmt === 'EPUB' || str_ends_with($low, '.epub')) {
            if ($out['epub'] === null) {
                $out['epub'] = $name;
            }
        }
        if ($fmt === 'Mobipocket' || str_ends_with($low, '.mobi')) {
            if ($out['mobi'] === null) {
                $out['mobi'] = $name;
            }
        }
        if ($fmt === 'DjVu' || str_ends_with($low, '.djvu')) {
            if ($out['djvu'] === null) {
                $out['djvu'] = $name;
            }
        }
        if (str_ends_with($low, '.zip')) {
            $pref = str_ends_with($low, '_jp2.zip') ? 3 : (str_ends_with($low, '_images.zip') ? 2 : 1);
            if ($pref > ($out['zip_rank'] ?? 0)) {
                $out['zip'] = $name;
                $out['zip_rank'] = $pref;
            }
        }
    }
    return $out;
}

const PERIODICAL_HINTS = [
    'ਸਮਤਾ', 'ਸੇਧ', 'ਵਤਨ', 'ਵਤਨੋਂ', 'ਸਿਰਜਣਾ', 'ਤਰਕਸ਼ੀਲ', 'ਮੰਚਣ', 'ਦਰਪਣ', 'ਪ੍ਰਚਾਪੀ', 'ਲੋਕ-ਰਾਜ਼',
    'samta', 'sedh', 'vatan', 'sirjana', 'tarksheel', 'manchan', 'darpan', 'prachapi', 'watan',
    'sukhwant', 'sukhwan',
];

const JUNK_HINTS = ['angreji123456', 'screenshot-', 'types de cancer', 'um constant', 'punjab_assembly_election'];

function categorize(array $m, string $title): string
{
    $creator = $m['creator'] ?? '';
    if (is_array($creator)) {
        $creator = implode(' ', $creator);
    }
    $blob = mb_strtolower($title . ' ' . implode(' ', (array) ($m['subject'] ?? [])) . ' ' . (string) $creator);

    foreach (JUNK_HINTS as $j) {
        if (str_contains($blob, $j)) {
            return 'Skipped';
        }
    }
    foreach (PERIODICAL_HINTS as $p) {
        if (str_contains($blob, $p)) {
            return 'Magazines';
        }
    }
    $rules = [
        'Sikh Literature' => ['sikhism', 'sikhi', 'sikh', 'granth', 'ਸਿੱਖ', 'ਗ੍ਰੰਥ', 'ਭਾਗ ਸਿੱਖੀ'],
        'Poetry'          => ['kavita', 'poetry', 'ਕਵਿਤਾ', 'ਕੁਹਾਣੀ', 'ਗੀਤ'],
        'Fiction'         => ['novel', 'kahani', 'ਕਹਾਣੀ', 'ਦਾਪਾਨ', 'ਉਪਨਿਯਾਸ'],
        'Grammar'         => ['grammar', 'vyakaran', 'ਵਿਆਕਰਨ', 'ਲੁਗਤ'],
        'Dictionary'      => ['dictionary', 'lughat', 'ਲੁਘਤ', 'kosh'],
        'History'         => ['history', 'itihas', 'ਇਤਿਹਾਸ', 'ਤਰੀਖ'],
        'Punjabi Literature' => ['ਪੰਜਾਬੀ ਸਾਹਿਤ', 'punjabi literature', 'punjabi adab', 'ਪੰਜਾਬੀ ਅਤੇ ਹਿੰਦੀ'],
    ];
    foreach ($rules as $cat => $hints) {
        foreach ($hints as $h) {
            if (str_contains($blob, $h)) {
                return $cat;
            }
        }
    }
    return 'Punjabi Books';
}

$items = [];
$stats = ['scanned' => 0, 'no_pdf_or_text' => 0, 'not_texts' => 0, 'junk' => 0, 'no_title' => 0, 'kept' => 0, 'dup' => 0];

echo "Fetching Punjabi text identifiers from Archive.org search API...\n";
$identifiers = api_search_identifiers();
echo "Found " . count($identifiers) . " identifiers. Fetching metadata...\n";

$done = 0;
foreach ($identifiers as $ident) {
    $stats['scanned']++;
    $meta = api_metadata($ident);
    if (!$meta) {
        $stats['not_texts']++;
        continue;
    }
    $m = $meta['metadata'] ?? [];

    if (($m['mediatype'] ?? '') !== 'texts') {
        $stats['not_texts']++;
        continue;
    }

    $files = pick_files($meta['files'] ?? []);
    if ($files['pdf'] === null && $files['text'] === null) {
        $stats['no_pdf_or_text']++;
        continue;
    }

    $title = plain_title($m['title'] ?? $ident);
    if ($title === '') {
        $stats['no_title']++;
        continue;
    }

    $cat = categorize($m, $title);
    if ($cat === 'Skipped') {
        $stats['junk']++;
        continue;
    }

    $creator = $m['creator'] ?? '';
    if (is_array($creator)) {
        $creator = implode('; ', $creator);
    }
    $creator = strip_html($creator);

    $subject = $m['subject'] ?? [];
    if (is_array($subject)) {
        $subject = implode('; ', $subject);
    }
    $subject = strip_html($subject);

    $desc = clean_utf8(strip_html($m['description'] ?? ''), 2000);

    $rights = (string) ($m['rights'] ?? '');
    if ($rights === '' && !empty($m['licenseurl'])) {
        $rights = (string) $m['licenseurl'];
    }

    $item = [
        'pid'        => $ident,
        'title'      => clean_utf8($title, 250),
        'author'     => creator_name($creator),
        'category'   => $cat,
        'publisher'  => clean_utf8(strip_html($m['publisher'] ?? ''), 250) ?: 'Internet Archive',
        'language'   => 'Punjabi',
        'page'       => isset($m['page-progression']) ? null : null,
        'weight'     => null,
        'mrp'        => 0,
        'price'      => 0,
        'discount'   => 0,
        'available'  => 1,
        'edition'    => null,
        'description'=> $desc,
        'archive_id' => $ident,
        'pdf_file'   => $files['pdf'],
        'pdf_size'   => $files['pdf_size'],
        'read_url'   => "https://archive.org/details/$ident?view=theater&ui=embed&wrapper=false",
        'dl_url'     => $files['pdf'] ? "https://archive.org/download/$ident/" . rawurlencode($files['pdf']) : null,
        'text_url'   => $files['text'] ? "https://archive.org/download/$ident/" . rawurlencode($files['text']) : null,
        'epub_url'   => $files['epub'] ? "https://archive.org/download/$ident/" . rawurlencode($files['epub']) : null,
        'mobi_url'   => $files['mobi'] ? "https://archive.org/download/$ident/" . rawurlencode($files['mobi']) : null,
        'djvu_url'   => $files['djvu'] ? "https://archive.org/download/$ident/" . rawurlencode($files['djvu']) : null,
        'zip_url'    => $files['zip'] ? "https://archive.org/download/$ident/" . rawurlencode($files['zip']) : null,
        'detail_url' => "https://archive.org/details/$ident",
        'subject'    => clean_utf8($subject, 900),
        'rights'     => clean_utf8($rights, 250),
        'year'       => extract_year($m),
        'source'     => 'Internet Archive',
        'uploader'   => clean_utf8((string) ($m['uploader'] ?? ''), 140),
        'added_at'   => archive_date($m['publicdate'] ?? ($m['addeddate'] ?? '')),
    ];

    $items[norm_key($title) . '|' . ($item['year'] ?? '')] = $item;

    $done++;
    if ($done % 50 === 0) {
        echo "  Fetched $done/" . count($identifiers) . "...\n";
    }
    // only throttle when we actually hit the network; a cache hit costs nothing
    if (!is_file(CACHE_DIR . '/' . $ident . '.json')) {
        usleep(API_DELAY_MS * 1000);
    }
}

$stats['kept'] = count($items);

echo "\nScanned:      {$stats['scanned']}\n";
echo "Not texts:    {$stats['not_texts']}\n";
echo "No pdf/text:  {$stats['no_pdf_or_text']}\n";
echo "Junk:         {$stats['junk']}\n";
echo "No title:     {$stats['no_title']}\n";
echo "After dedupe: {$stats['kept']}\n\n";

$withPdf = 0;
$totalGb = 0;
$cats = [];
foreach ($items as $it) {
    if ($it['pdf_file'] !== null) {
        $withPdf++;
        $totalGb += $it['pdf_size'];
    }
    $cats[$it['category']] = ($cats[$it['category']] ?? 0) + 1;
}
arsort($cats);
echo "With PDF: $withPdf | with download: $withPdf\n";
echo 'Combined PDF size if all downloaded: ' . round($totalGb / 1024 / 1024 / 1024, 1) . " GB\n\n";
echo "Categories:\n";
foreach ($cats as $c => $n) {
    echo sprintf("  %-24s %d\n", $c, $n);
}

if ($dryRun) {
    echo "\n(dry run - nothing written)\n";
    exit;
}

$insertCols = ['PID', 'Title', 'Author', 'Category', 'Publisher', 'Edition', 'Language', 'page', 'weight',
    'MRP', 'Price', 'Discount', 'Available', 'Description',
    'ArchiveId', 'PdfFile', 'PdfSize', 'ReadUrl', 'DownloadUrl', 'TextUrl', 'EpubUrl', 'DetailUrl',
    'Subject', 'Rights', 'Year', 'SourceName', 'SourceUpload', 'AddedAt',
    'MobiUrl', 'DjvuUrl', 'ZipUrl'];

$valueKeys = ['pid', 'title', 'author', 'category', 'publisher', 'edition', 'language', 'page', 'weight',
    'mrp', 'price', 'discount', 'available', 'description',
    'archive_id', 'pdf_file', 'pdf_size', 'read_url', 'dl_url', 'text_url', 'epub_url', 'detail_url',
    'subject', 'rights', 'year', 'source', 'uploader', 'added_at',
    'mobi_url', 'djvu_url', 'zip_url'];

if (count($insertCols) !== count($valueKeys)) {
    exit("FATAL: column/value count mismatch: " . count($insertCols) . ' vs ' . count($valueKeys) . "\n");
}

// Upsert on the PID primary key: a re-run is idempotent, so an interrupted
// import resumes instead of starting over. No DELETE, no all-or-nothing
// transaction -- products is MyISAM, so every row is committed as written.
$updates = [];
foreach (array_slice($insertCols, 1) as $col) {
    $updates[] = "$col=VALUES($col)";
}
$sql = 'INSERT INTO products (' . implode(',', $insertCols) . ') VALUES ('
    . implode(',', array_fill(0, count($insertCols), '?')) . ')'
    . ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);

$existing = [];
foreach (rows('SELECT PID FROM products') as $r) {
    $existing[(string) $r['PID']] = true;
}
echo count($existing) . " identifiers already in db; importing the rest...\n";

$st = db()->prepare($sql);
$chunk = 100;
$n = 0;
$skipped = 0;
$pending = 0;

try {
    foreach ($items as $it) {
        $pid = (string) ($it['pid'] ?? '');
        if ($pid === '' || isset($existing[$pid])) {
            $skipped++;
            continue;
        }
        $row = [];
        foreach ($valueKeys as $k) {
            $row[] = $it[$k] ?? null;
        }
        $st->execute($row);
        $existing[$pid] = true;
        $n++;
        if (++$pending >= $chunk) {
            $pending = 0;
            echo "  committed $n new ($skipped already present)\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "\nimport stopped: " . $e->getMessage() . "\n");
    fwrite(STDERR, "already committed and safe: $n new rows\n");
    exit(1);
}

echo "\nImported $n new books, skipped $skipped already in db.\n";
echo 'products in db: ' . (int) (one('SELECT COUNT(*) c FROM products')['c'] ?? 0) . "\n";
