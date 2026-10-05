<?php
require '/home/jatt/BookStore-v2/db.php';

$pdo = db();
$total = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$q = fn(string $s) => (int) $pdo->query($s)->fetchColumn();

echo "catalogue: $total books\n\n";
echo "  metadata coverage\n";
printf("    Description present:   %5d  (%.0f%%)\n", $q("SELECT COUNT(*) FROM products WHERE Description IS NOT NULL AND CHAR_LENGTH(TRIM(Description)) > 40"), $q("SELECT COUNT(*) FROM products WHERE Description IS NOT NULL AND CHAR_LENGTH(TRIM(Description)) > 40") / $total * 100);
printf("    Subject present:       %5d  (%.0f%%)\n", $q("SELECT COUNT(*) FROM products WHERE Subject IS NOT NULL AND CHAR_LENGTH(TRIM(Subject)) > 2"), $q("SELECT COUNT(*) FROM products WHERE Subject IS NOT NULL AND CHAR_LENGTH(TRIM(Subject)) > 2") / $total * 100);
printf("    Year present:          %5d  (%.0f%%)\n", $q('SELECT COUNT(*) FROM products WHERE Year > 0'), $q('SELECT COUNT(*) FROM products WHERE Year > 0') / $total * 100);
printf("    Publisher not generic: %5d  (%.0f%%)\n", $q("SELECT COUNT(*) FROM products WHERE Publisher IS NOT NULL AND Publisher <> '' AND Publisher <> 'Internet Archive'"), $q("SELECT COUNT(*) FROM products WHERE Publisher IS NOT NULL AND Publisher <> '' AND Publisher <> 'Internet Archive'") / $total * 100);
printf("    Description > 400 char:%5d\n", $q("SELECT COUNT(*) FROM products WHERE CHAR_LENGTH(Description) > 400"));
printf("    Description < 120 char:%5d\n", $q("SELECT COUNT(*) FROM products WHERE CHAR_LENGTH(Description) < 120"));

echo "\n  description length distribution\n";
foreach ([['<120', '<120'], ['120-400', 'BETWEEN 120 AND 400'], ['>400', '>400']] as [$label, $test]) {
    printf("    %-10s %5d\n", $label, $q("SELECT COUNT(*) FROM products WHERE CHAR_LENGTH(Description) $test"));
}

echo "\n  sample of what we hold\n";
foreach ($pdo->query('SELECT PID, Title, Year, Publisher, LEFT(Description,150) d FROM products
                      WHERE CHAR_LENGTH(Description) > 200 ORDER BY Downloads DESC LIMIT 6') as $r) {
    printf("    %-30s %s\n", mb_strimwidth($r['Title'], 0, 30), mb_strimwidth(preg_replace('/\s+/', ' ', (string) $r['d']), 0, 92));
}