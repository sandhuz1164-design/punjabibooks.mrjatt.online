<?php
/**
 * Merge author values that differ only in punctuation, spacing or a title.
 *
 * Only the "safe" bucket from author-normalise.php is applied. Subset matches are
 * deliberately left alone: "Dr. Kirpal Singh" inside "Kirpal Singh Giani" may well be
 * two different people, and merging them would silently attribute one person's books to
 * another. Report those; do not act on them.
 *
 * Changing an author value changes its page URL, so the old -> new mapping is written
 * to build/author-redirects.json for build.php to emit as redirects. Without that,
 * every previously published author URL would 404.
 */
require __DIR__ . '/../db.php';
require __DIR__ . '/author-normalise.php';

$pdo = db();
$apply = in_array('--apply', $argv, true);
$g = authorGroups($pdo)['safe'];

$redirects = [];
$moved = 0;
$rows = 0;
$log = [];

foreach ($g as $key => $set) {
    // Canonical = the spelling carrying the most books, but ties are broken towards the
    // cleanest form: fewest title words (Dr., Baba, Sant, Guru), then no punctuation,
    // then shorter. Otherwise the winner is whichever variant happened to gain a suffix,
    // and every author page ends up titled "Amar Bharti Dr.".
    $titleCount = static function (string $name): int {
        $n = 0;
        foreach (authorTokens($name) as $t) {
        }
        $clean = preg_replace('/[\(\[\{].*?[\)\]\}]/u', '', $name);
        foreach (['Dr.', 'Prof.', 'Bhai', 'Baba', 'Sant ', 'Guru ', 'Giani ', 'Sardar '] as $t) {
            $n += substr_count((string) $clean, $t);
        }
        return $n;
    };
    usort($set, static function ($a, $b) use ($titleCount) {
        return [$b[1], $titleCount($a[0]), substr_count($a[0], '-'), mb_strlen($a[0])]
            <=> [$a[1], $titleCount($b[0]), substr_count($b[0], '-'), mb_strlen($b[0])];
    });
    $canonical = $set[0][0];

    foreach (array_slice($set, 1) as [$variant, $n]) {
        if ($variant === $canonical) {
            continue;
        }
        $redirects[$variant] = $canonical;
        $moved++;
        $rows += $n;
        $log[] = sprintf('    %5d  %-52s -> %s', $n, $variant, $canonical);
    }
}

printf("  %d author values will fold into a canonical spelling (%d books)\n", $moved, $rows);
printf("  %d groups affected\n\n", count($g));

if (!$apply) {
    echo implode("\n", array_slice($log, 0, 20)), "\n";
    printf("\n  ... %d more\n", max(0, count($log) - 20));
    echo "  (dry run - pass --apply)\n";
    exit;
}

$pdo->beginTransaction();
$up = $pdo->prepare('UPDATE products SET Author = ? WHERE Author = ?');
$done = 0;
foreach ($redirects as $variant => $canonical) {
    $up->execute([$canonical, $variant]);
    $done += $up->rowCount();
}
$pdo->commit();

echo implode("\n", array_slice($log, 0, 20)), "\n";
printf("\n  ... %d more\n", max(0, count($log) - 20));
printf("\n  updated %d rows\n", $done);

// Where the canonical name lives now, for redirect generation.
$out = __DIR__ . '/../build/author-redirects.json';
file_put_contents($out, json_encode($redirects, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
printf("  wrote %s (%d redirects)\n", basename($out), count($redirects));

$after = (int) $pdo->query('SELECT COUNT(DISTINCT Author) FROM products')->fetchColumn();
printf("  distinct authors: %d -> %d\n", 5781 ? $after + $moved : $after, $after);