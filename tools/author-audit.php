<?php
/** How many authors are worth giving a real page, and where the books actually are. */
require __DIR__ . '/../db.php';

$pdo = db();

echo "  author book-count distribution\n";
$dist = $pdo->query(
    'SELECT n, COUNT(*) c FROM (SELECT Author, COUNT(*) n FROM products GROUP BY Author) t
      GROUP BY n ORDER BY n'
)->fetchAll();
$cum = 0;
foreach ($dist as $r) {
    $cum += (int) $r['c'];
    printf("    %5d books: %4d authors   (cum %4d)\n", $r['n'], $r['c'], $cum);
}

$q = static fn(string $s) => (int) $pdo->query($s)->fetchColumn();
$total = $q('SELECT COUNT(DISTINCT Author) FROM products');

echo "\n  thresholds\n";
foreach ([1, 2, 3, 5, 10, 20] as $t) {
    $a = $q("SELECT COUNT(*) FROM (SELECT Author FROM products GROUP BY Author HAVING COUNT(*) > $t) x");
    printf("    >%2d books: %4d authors (%3.0f%% of authors)\n", $t, $a, $a / $total * 100);
}

echo "\n  junk author values that are not people\n";
foreach ([
    "empty"            => "SELECT COUNT(*) FROM products WHERE Author = ''",
    "Unknown"          => "SELECT COUNT(*) FROM products WHERE Author = 'Unknown'",
    "www.DiscoverSikhism.com" => "SELECT COUNT(DISTINCT Author) FROM products WHERE Author LIKE '%DiscoverSikhism%'",
    "looks like a URL" => "SELECT COUNT(DISTINCT Author) FROM products WHERE Author LIKE '%.com%' OR Author LIKE '%www.%'",
] as $label => $sql) {
    printf("    %-24s %d\n", $label, $q($sql));
}

echo "\n  top authors by catalogue size\n";
foreach ($pdo->query(
    'SELECT Author, COUNT(*) n FROM products GROUP BY Author ORDER BY n DESC LIMIT 15'
) as $r) {
    printf("    %-46s %5d\n", mb_strimwidth((string) $r['Author'], 0, 46), $r['n']);
}