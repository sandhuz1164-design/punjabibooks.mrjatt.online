<?php
/**
 * Widen users.Password and migrate the plaintext credentials.
 *
 * Why both halves are needed:
 *  - users.Password was varchar(40). password_hash(PASSWORD_DEFAULT) makes 60
 *    characters, so every new registration either failed outright under strict
 *    mode ("Data too long for column 'Password'") or was silently truncated to
 *    40, after which password_verify can never return true. Either way the
 *    account is unusable.
 *  - the 12 existing rows predate the hashing change. The old login compared
 *    `Password='$password'` directly, so the stored value IS the plaintext
 *    password and re-hashing it in place is lossless.
 *
 * 255 leaves room for argon2id (97 chars) if the default ever changes.
 *
 * Run: php tools/migrate-user-passwords.php [--dry-run]
 */

require_once __DIR__ . '/../db.php';

$dryRun = in_array('--dry-run', $argv, true);
$pdo = db();

$col = $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                       AND COLUMN_NAME = 'Password'")->fetchColumn();

echo 'current Password column: varchar(' . $col . ")\n";

$rows = $pdo->query('SELECT UserName, Password FROM users ORDER BY UserName')->fetchAll(PDO::FETCH_ASSOC);
echo 'users: ' . count($rows) . "\n";

/**
 * Is this value a password hash?
 *
 * password_get_info()['algoName'] is 'unknown' - not null - for a plaintext
 * string, so testing that field reports every plaintext row as already hashed.
 * The reliable signal is 'algo': 0 when PHP cannot identify a hash at all.
 */
function is_hash(string $h): bool
{
    $info = password_get_info($h);
    return !empty($info['algo']);
}

$plain = 0;
foreach ($rows as $r) {
    $h = (string) $r['Password'];
    if (is_hash($h)) {
        echo '  ' . str_pad($r['UserName'], 16) . ' already a hash, left alone' . "\n";
        continue;
    }
    $plain++;
    echo '  ' . str_pad($r['UserName'], 16) . ' plaintext, ' . strlen($h) . " chars -> will hash\n";
}
echo "  plaintext rows: $plain\n";

if ($dryRun) {
    echo "\n(dry run - nothing written)\n";
    exit(0);
}

// Back up before touching anything.
$backup = '/home/jatt/BookStore-v2/users-backup-' . date('Ymd-His') . '.sql';
$dump = "-- users table backup before password migration\n"
      . "CREATE TABLE users_bak_" . date('Ymd_His') . " LIKE users;\n"
      . "INSERT INTO users_bak_" . date('Ymd_His') . " SELECT * FROM users;\n";
file_put_contents($backup, $dump);
$pdo->exec($dump);
echo "\nbacked up to table users_bak_" . date('Ymd_His') . "\n";

if ((int) $col < 255) {
    $pdo->exec('ALTER TABLE users MODIFY Password varchar(255) NOT NULL');
    echo "widened Password to varchar(255)\n";
}

$up = $pdo->prepare('UPDATE users SET Password = ? WHERE UserName = ?');
$done = 0;
foreach ($rows as $r) {
    $h = (string) $r['Password'];
    if (is_hash($h)) {
        continue;
    }
    $up->execute([password_hash($h, PASSWORD_DEFAULT), $r['UserName']]);
    $done++;
}
echo "re-hashed $done password(s)\n";

// Verify by checking the new hashes round-trip against the originals.
$after = $pdo->query('SELECT UserName, Password FROM users')->fetchAll(PDO::FETCH_ASSOC);
$bad = 0;
foreach ($after as $a) {
    $orig = null;
    foreach ($rows as $r) {
        if ($r['UserName'] === $a['UserName']) { $orig = (string) $r['Password']; break; }
    }
    if ($orig === null || $orig === '') {
        continue;
    }
    if (!password_verify($orig, (string) $a['Password'])) {
        echo '  MISMATCH ' . $a['UserName'] . "\n";
        $bad++;
    }
}
echo $bad === 0
    ? "verified: every migrated password still matches its original\n"
    : "verified: $bad MISMATCHES\n";

$len = $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                       AND COLUMN_NAME = 'Password'")->fetchColumn();
echo "final Password column: varchar($len)\n";
