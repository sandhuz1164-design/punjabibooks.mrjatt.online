<?php
require_once __DIR__ . '/../db.php';

$tables = ['products', 'users', 'cart'];

foreach ($tables as $t) {
    $exists = one('SELECT TABLE_NAME FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
    if (!$exists) {
        echo "skip   $t (missing)\n";
        continue;
    }
    $before = one('SELECT ENGINE e, TABLE_COLLATION c FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
    db()->exec("ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    db()->exec("ALTER TABLE `$t` ENGINE=InnoDB");
    $after = one('SELECT ENGINE e, TABLE_COLLATION c FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
    printf("%-9s %s/%s  ->  %s/%s\n", $t, $before['e'], $before['c'], $after['e'], $after['c']);
}

$ver = one('SELECT VERSION() v')['v'];
echo "\nmariadb: $ver\n";
echo "transaction test: ";
db()->beginTransaction();
db()->exec("INSERT INTO products (PID,Title,Author,MRP,Price,Available) VALUES ('__tx_test','t','t',0,0,1)");
db()->rollBack();
$left = (int) (one("SELECT COUNT(*) c FROM products WHERE PID = '__tx_test'")['c'] ?? 0);
echo $left === 0 ? "rollback works (InnoDB)\n" : "ROLLBACK FAILED - not transactional\n";