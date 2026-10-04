<?php
require_once __DIR__ . '/../db.php';

$cols = [
    'ArchiveId'    => 'varchar(150) NULL',
    'PdfFile'      => 'varchar(300) NULL',
    'PdfSize'      => 'bigint NULL',
    'ReadUrl'      => 'varchar(600) NULL',
    'DownloadUrl'  => 'varchar(600) NULL',
    'TextUrl'      => 'varchar(600) NULL',
    'EpubUrl'      => 'varchar(600) NULL',
    'DetailUrl'    => 'varchar(300) NULL',
    'Subject'      => 'text NULL',
    'Rights'       => 'varchar(255) NULL',
    'Year'         => 'smallint NULL',
    'SourceName'   => 'varchar(120) NULL',
    'SourceUpload' => 'varchar(150) NULL',
    'AddedAt'      => 'datetime NULL',
    'MobiUrl'      => 'text NULL',
    'DjvuUrl'      => 'text NULL',
    'ZipUrl'       => 'text NULL',
];

$have = array_column(rows('SHOW COLUMNS FROM products'), 'Field');

foreach ($cols as $name => $def) {
    if (in_array($name, $have, true)) {
        echo "skip   $name\n";
        continue;
    }
    db()->exec("ALTER TABLE products ADD COLUMN `$name` $def");
    echo "add    $name $def\n";
}

// Archive.org file names are long and get percent-encoded (Gurmukhi/Urdu script
// expands 3x), so URL columns need generous width.
$widths = [
    'PID'         => 'varchar(150)',
    'ArchiveId'   => 'varchar(150)',
    'Rights'      => 'varchar(500)',
    'SourceName'  => 'varchar(120)',
    'SourceUpload' => 'varchar(200)',
];

foreach ($widths as $col => $def) {
    $cur = one('SELECT COLUMN_TYPE t FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['products', $col]);
    if (!$cur || $cur['t'] === $def) {
        continue;
    }
    db()->exec("ALTER TABLE products MODIFY `$col` $def");
    echo "widen  $col: {$cur['t']} -> $def\n";
}

// Long URLs must be TEXT: several 2000-char utf8mb4 varchars overflow the
// 65535-byte row limit (TEXT is exempt from that budget).
foreach (['ReadUrl', 'DownloadUrl', 'TextUrl', 'EpubUrl', 'MobiUrl', 'DjvuUrl', 'ZipUrl', 'DetailUrl', 'PdfFile'] as $col) {
    $cur = one('SELECT DATA_TYPE d FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['products', $col]);
    if (!$cur) {
        continue;
    }
    if (strtolower($cur['d']) !== 'text') {
        db()->exec("ALTER TABLE products MODIFY `$col` text NULL");
        echo "textify $col ({$cur['d']} -> text)\n";
    }
}

echo "\ndone\n";