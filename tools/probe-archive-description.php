<?php
require '/home/jatt/BookStore-v2/db.php';

$pdo = db();
$rows = $pdo->query('SELECT PID, ArchiveId, Title, CHAR_LENGTH(Description) n, Description
                     FROM products
                     WHERE CHAR_LENGTH(Description) < 120
                     ORDER BY Downloads DESC LIMIT 6')->fetchAll();

foreach ($rows as $r) {
    $meta = @file_get_contents('https://archive.org/metadata/' . rawurlencode((string) $r['ArchiveId']), false, stream_context_create(['http' => ['timeout' => 45]]));
    $j = json_decode((string) $meta, true);
    $m = $j['metadata'] ?? [];

    $strip = static function ($v): string {
        $v = is_string($v) ? $v : (is_array($v) ? implode(' ', $v) : '');
        $v = preg_replace('#<[^>]+>#', ' ', (string) $v);
        $v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', (string) $v));
    };

    printf("\n%s\n  PID %s | archive %s | stored %d chars\n",
        mb_strimwidth((string) $r['Title'], 0, 62), $r['PID'], $r['ArchiveId'], $r['n']);
    printf("  STORED  : %s\n", mb_strimwidth((string) $r['Description'], 0, 88));

    foreach (['description', 'notes', 'summary', 'table_of_contents', 'subject'] as $f) {
        if (!isset($m[$f])) {
            continue;
        }
        $t = $strip($m[$f]);
        if ($t !== '') {
            printf("  %-8s: %s\n", $f, mb_strimwidth($t, 0, 88));
        }
    }
    $extra = 0;
    foreach (['description', 'notes', 'summary', 'table_of_contents'] as $f) {
        if (isset($m[$f])) {
            $extra += mb_strlen($strip($m[$f]));
        }
    }
    printf("  -> extra text available: %d chars%s\n", $extra, $extra > 120 ? '   *** MISSING FROM OUR DB ***' : '');
}