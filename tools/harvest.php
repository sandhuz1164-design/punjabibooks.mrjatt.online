#!/usr/bin/env php
<?php
/**
 * Harvest Punjabi texts from Archive.org and cache metadata JSON files.
 * Output: /tmp/opencode/ia-cache/<identifier>.json
 */

declare(strict_types=1);

const CACHE_DIR = '/tmp/opencode/ia-cache';
const ITEMS_PER_PAGE = 500;
const DELAY_MS = 200;

function ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

function fetch_json(string $url): ?array {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'StrandBookstoreHarvest/1.0 (+local library)'
        ]
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) {
        return null;
    }
    $json = json_decode($data, true);
    return is_array($json) ? $json : null;
}

function harvest_page(int $page): array {
    $q = urlencode('language:punjabi AND mediatype:texts');
    $url = "https://archive.org/advancedsearch.php"
        . "?q=$q"
        . "&fl=identifier,title,creator,subject,publisher,description,rights,licenseurl,publicdate,addeddate,uploader,page-progression,mediatype"
        . "&rows=" . ITEMS_PER_PAGE
        . "&page=$page"
        . "&output=json"
        . "&save=yes";
    
    $result = fetch_json($url);
    if (!$result || !isset($result['response']['docs'])) {
        return [];
    }
    return $result['response']['docs'];
}

function fetch_metadata(string $ident): ?array {
    $url = "https://archive.org/metadata/$ident";
    return fetch_json($url);
}

ensure_dir(CACHE_DIR);

echo "Harvesting Punjabi texts from Archive.org...\n";

$total = 0;
$page = 1;
$ids = [];

// First, get all identifiers via search
while (true) {
    echo "  Search page $page...\n";
    $docs = harvest_page($page);
    if (empty($docs)) {
        break;
    }
    foreach ($docs as $doc) {
        $ids[] = $doc['identifier'];
    }
    if (count($docs) < ITEMS_PER_PAGE) {
        break;
    }
    $page++;
    usleep(DELAY_MS * 1000);
}

echo "Found " . count($ids) . " identifiers. Fetching metadata...\n";

$done = 0;
foreach ($ids as $ident) {
    $file = CACHE_DIR . '/' . $ident . '.json';
    if (file_exists($file)) {
        $done++;
        continue;
    }
    
    $meta = fetch_metadata($ident);
    if ($meta) {
        file_put_contents($file, json_encode($meta, JSON_UNESCAPED_UNICODE));
        $done++;
        if ($done % 50 === 0) {
            echo "  Fetched $done/" . count($ids) . "...\n";
        }
    }
    usleep(DELAY_MS * 1000);
}

echo "Done. Cached $done items to $CACHE_DIR\n";
