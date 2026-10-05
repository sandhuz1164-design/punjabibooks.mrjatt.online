<?php
/** Report author duplicates, split into safe-to-merge and needs-a-human. */
require __DIR__ . '/../db.php';
require __DIR__ . '/author-normalise.php';

$pdo = db();
$g = authorGroups($pdo);

$sum = static fn($x) => array_sum(array_column($x[0], 1)) + array_sum(array_column($x[1], 1));

uasort($g['safe'], static fn($a, $b) => array_sum(array_column($b, 1)) <=> array_sum(array_column($a, 1)));

printf("  SAFE (identical token sets - punctuation/spacing variants)\n");
printf("    %d groups\n\n", count($g['safe']));
$n = 0;
foreach ($g['safe'] as $key => $set) {
    usort($set, static fn($a, $b) => $b[1] <=> $a[1]);
    if (++$n > 12) {
        break;
    }
    printf("    %s\n", $key);
    foreach ($set as [$name, $c]) {
        printf("        %5d  %s\n", $c, $name);
    }
    printf("        => %d\n\n", array_sum(array_column($set, 1)));
}

printf("  UNSAFE (subset matches - may be different people, NOT merged)\n");
printf("    %d candidate pairs\n", count($g['unsafe']));
$n = 0;
foreach ($g['unsafe'] as $pair => $set) {
    if (++$n > 10) {
        break;
    }
    printf("    %-34s vs %s\n", mb_strimwidth($pair, 0, 34), mb_strimwidth(explode(' ~ ', $pair)[1], 0, 34));
    foreach ($set[1] as [$name, $c]) {
        printf("        %5d  %s\n", $c, $name);
    }
}
