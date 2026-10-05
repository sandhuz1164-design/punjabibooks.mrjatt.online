<?php
/**
 * Count the books each collection in build/collections.php would hold.
 *
 *   php tools/collection-counts.php
 *
 * Measured before any page is generated, because a collection that matches 40
 * books out of 5,781 is not a browse section and a rule with a stray term can
 * swallow the whole catalogue.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../build/collections.php';

$pdo = db();
$total = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
echo "catalogue: $total books\n\n";

$cols = collections();
$rows = $pdo->query('SELECT PID, Title, Subject, Author FROM products')
    ->fetchAll(PDO::FETCH_ASSOC);

printf("  %-26s %7s  %s\n", 'COLLECTION', 'BOOKS', '% OF CATALOGUE');
echo '  ' . str_repeat('-', 52) . "\n";

$sizes = [];
foreach ($cols as $slug => $c) {
    $terms = $c['terms'];
    $n = 0;
    foreach ($rows as $r) {
        $blob = mb_strtolower(implode(' ', [
            (string) $r['Title'], (string) $r['Subject'],
            (string) $r['Author'],
        ]));
        foreach ($terms as $t) {
            if (str_contains($blob, mb_strtolower($t))) {
                $n++;
                break;
            }
        }
    }
    $sizes[$slug] = $n;
    printf("  %-26s %7d  %5.1f%%\n", $c['label'], $n, ($n / max(1, $total)) * 100);
}

echo "\n  checks:\n";
$over = array_filter($sizes, fn($n) => $n > $total * 0.35);
echo $over
    ? '    WARNING: ' . count($over) . " collection(s) match over a third of the catalogue: "
        . implode(', ', array_keys($over)) . "\n"
    : "    no collection matches more than a third of the catalogue\n";
$tiny = array_filter($sizes, fn($n) => $n < 20);
echo $tiny
    ? '    NOTE: ' . count($tiny) . " collection(s) under 20 books: "
        . implode(', ', array_keys($tiny)) . "\n"
    : "    every collection has at least 20 books\n";