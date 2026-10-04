<?php
require_once __DIR__ . '/../db.php';

$username = $argv[1] ?? 'admin';
$password = $argv[2] ?? null;

if ($password === null) {
    fwrite(STDERR, "usage: make-admin.php <username> <password>\n");
    exit(1);
}

if (strlen($password) < 10) {
    fwrite(STDERR, "password must be at least 10 characters\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

db()->prepare(
    'INSERT INTO admins (UserName, Password) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE Password = VALUES(Password)'
)->execute([$username, $hash]);

echo "admin '{$username}' created/updated\n";
echo 'hash algorithm: ' . password_get_info($hash)['algoName'] . "\n";
