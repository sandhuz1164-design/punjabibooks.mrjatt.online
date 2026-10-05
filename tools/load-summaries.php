<?php
/**
 * Load generated book summaries into the database and onto the book pages.
 *
 * The summaries in data/summaries.json were written by reading the books themselves -
 * their title pages, contents pages and, where OCR allowed it, the opening text. They are
 * not derived from Archive.org's metadata, because that metadata is empty or boilerplate
 * for 78% of the catalogue.
 *
 * Two rules the writing follows, both from things that went wrong first:
 *   - If the text could not be read, the summary says so plainly instead of guessing.
 *     Seven of the first twenty did.
 *   - A title tells you very little. "Sau Sakhi 1" is Bhai Nand Lal's Kalambod, the
 *     earliest Gurmukhi grammar. Where only the title was available, the summary says
 *     that is all it is.
 *
 * Usage:
 *   php tools/load-summaries.php --check    # report coverage, change nothing
 *   php tools/load-summaries.php            # import
 */

require __DIR__ . '/../db.php';

$pdo = db();
$FILE = __DIR__ . '/../data/summaries.json';

if (!is_file($FILE)) {
    fwrite(STDERR, "missing $FILE\n");
    exit(1);
}
$rows = json_decode((string) file_get_contents($FILE), true);
if (!is_array($rows)) {
    fwrite(STDERR, "could not parse $FILE\n");
    exit(1);
}

$pdo->exec('CREATE TABLE IF NOT EXISTS book_summary (
    PID VARCHAR(191) NOT NULL PRIMARY KEY,
    Summary TEXT NOT NULL,
    Source VARCHAR(40) NOT NULL DEFAULT \'read\',
    AddedAt DATETIME NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4');

$total = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$have = (int) $pdo->query('SELECT COUNT(*) FROM book_summary')->fetchColumn();

// Reject anything that does not correspond to a real book, rather than inserting a row
// that will never render.
$known = [];
foreach ($pdo->query('SELECT PID FROM products') as $r) {
    $known[(string) $r['PID']] = true;
}

$valid = 0;
$orphan = [];
foreach ($rows as $r) {
    if (isset($known[(string) ($r['pid'] ?? '')])) {
        $valid++;
    } else {
        $orphan[] = (string) ($r['pid'] ?? '(missing)');
    }
}

printf("  summaries on disk : %d\n", count($rows));
printf("  match a real book : %d\n", $valid);
if ($orphan) {
    printf("  no such book      : %d  (%s)\n", count($orphan), implode(', ', array_slice($orphan, 0, 3)));
}
printf("  already in db     : %d\n", $have);
printf("  catalogue         : %d books, %d covered (%.1f%%)\n", $total, $have, $have / $total * 100);
printf("  still to write    : %d\n", $total - $have);

if (!in_array('--apply', $argv, true)) {
    echo "\n  (dry run - pass --apply to import)\n";
    exit;
}

$ins = $pdo->prepare('INSERT INTO book_summary (PID, Summary, Source, AddedAt)
    VALUES (?,?,\'read\',NOW())
    ON DUPLICATE KEY UPDATE Summary=VALUES(Summary), Source=VALUES(Source), AddedAt=VALUES(AddedAt)');

$pdo->beginTransaction();
$n = 0;
foreach ($rows as $r) {
    if (!isset($known[(string) ($r['pid'] ?? '')])) {
        continue;
    }
    $s = trim((string) ($r['summary'] ?? ''));
    if ($s === '') {
        continue;
    }
    $ins->execute([(string) $r['pid'], $s]);
    $n++;
}
$pdo->commit();

printf("\n  imported %d summaries\n", $n);
printf("  coverage now: %d of %d books (%.1f%%)\n",
    $pdo->query('SELECT COUNT(*) FROM book_summary')->fetchColumn(), $total,
    $pdo->query('SELECT COUNT(*) FROM book_summary')->fetchColumn() / $total * 100);