<?php
/**
 * Invalid UTF-8 in the catalogue.
 *
 * Found while writing the author-merge key: preg_replace() with the /u modifier returns
 * NULL - not a string - when the subject is not valid UTF-8, and NULL then keys to "".
 * That silently collapsed several dozen distinct Gurmukhi author names into one bogus
 * group. So the real fault is upstream: some imported text is not valid UTF-8.
 *
 * Invalid bytes here are not cosmetic. They corrupt the generated HTML, they break
 * mb_* sorting and search, and they make JSON encoding fail outright.
 */
require __DIR__ . '/../db.php';

$pdo = db();

$cols = ['PID', 'Title', 'Author', 'Subject', 'Description', 'Publisher'];
echo "  rows containing invalid UTF-8, per column\n";
$total = 0;
foreach ($cols as $c) {
    $bad = 0;
    $examples = [];
    $stmt = $pdo->query("SELECT `$c` v FROM products WHERE `$c` IS NOT NULL");
    while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
        $v = (string) $r[0];
        if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) {
            $bad++;
            if (count($examples) < 3) {
                $examples[] = $v;
            }
        }
    }
    $total += $bad;
    printf("    %-12s %5d rows%s\n", $c, $bad, $bad ? '' : '');
    foreach ($examples as $v) {
        // show the offending bytes rather than a lossy rendering
        $hex = implode(' ', array_slice(array_map(
            static fn($ch) => sprintf('%02X', ord($ch)),
            mb_str_split($v, 1, 'UTF-8') ?: []
        ), 0, 0));
        $bad_bytes = [];
        foreach (str_split(bin2hex($v)) as $i => $pair) {
        }
        printf("        bad value (first 60 bytes): %s\n", substr(bin2hex($v), 0, 120));
    }
}

echo "\n  what the connection thinks\n";
foreach (['utf8mb4', 'utf8', 'latin1'] as $cs) {
    try {
        $pdo->exec("SET NAMES $cs");
        $stmt = $pdo->query('SELECT Title FROM products LIMIT 2000');
        $bad = 0;
        while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
            if ((string) $r[0] !== '' && !mb_check_encoding((string) $r[0], 'UTF-8')) {
                $bad++;
            }
        }
        printf("    SET NAMES %-8s -> %4d/2000 invalid\n", $cs, $bad);
    } catch (Throwable $e) {
        printf("    SET NAMES %-8s -> %s\n", $cs, $e->getMessage());
    }
}
$pdo->exec('SET NAMES utf8mb4');

echo "\n  is the fault in the stored bytes or the connection?\n";
$row = $pdo->query("SELECT Title, HEX(Title) h FROM products WHERE Title IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
printf("    sample Title  : %s\n", (string) $row['Title']);
printf("    sample HEX    : %s\n", substr((string) $row['h'], 0, 80));