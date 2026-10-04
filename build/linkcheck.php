<?php
$root = __DIR__ . '/../public';
$pages = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'html') {
        $pages[] = $f->getPathname();
    }
}

$broken = [];
$checked = 0;
$ok = 0;
// Refs carrying a query string point at a dynamic endpoint (e.g.
// /api/stream.php?pid=...&dl=1). realpath() cannot resolve their arguments, so
// they used to land in $broken - 17,095 phantom failures at 5,709 books. Check
// that the script behind them exists, but do not claim the arguments were
// verified; that needs an HTTP probe.
$dynamic = 0;
$dynamicScripts = [];

foreach ($pages as $p) {
    $dir = dirname($p);
    $html = file_get_contents($p);
    if (preg_match_all('/(?:href|src)="([^"]+)"/i', $html, $m)) {
        foreach ($m[1] as $ref) {
            if (preg_match('#^(https?:|mailto:|tel:|data:|//)#i', $ref)) {
                continue;
            }
            $ref = html_entity_decode($ref, ENT_QUOTES, 'UTF-8');
            $path = explode('#', $ref)[0];
            if ($path === '') {
                continue;
            }
            $checked++;
            $hasQuery = str_contains($path, '?');
            if ($hasQuery) {
                $path = explode('?', $path)[0];
            }
            if (preg_match('#^/api/([a-z0-9_-]+\.php)$#i', $path, $m)) {
                // tools/router.php serves /api/<name>.php out of proxy/, and there
                // is no public/api directory on disk, so resolve it the same way.
                $abs = realpath(dirname(__DIR__) . '/proxy/' . $m[1]);
            } else {
                $abs = str_starts_with($path, '/') ? $root . $path : $dir . '/' . $path;
                $abs = realpath($abs);
            }
            if ($abs !== false && is_file($abs)) {
                if ($hasQuery) {
                    $dynamic++;
                    $dynamicScripts[$abs] = true;
                } else {
                    $ok++;
                }
            } else {
                $broken[] = str_replace($root . '/', '', $p) . '  ->  ' . $ref;
            }
        }
    }
}

echo 'HTML pages:        ' . count($pages) . "\n";
echo 'internal links:    ' . $checked . "\n";
echo 'resolved:          ' . $ok . "\n";
echo 'dynamic endpoints: ' . $dynamic . ' across ' . count($dynamicScripts) . " script(s), args not verified\n";
foreach (array_keys($dynamicScripts) as $s) {
    echo '  ' . str_replace($root . '/', '', $s) . "\n";
}
echo 'broken:            ' . count($broken) . "\n";
foreach (array_slice($broken, 0, 25) as $b) {
    echo "  $b\n";
}

$books = glob($root . '/books/*.html');
echo "\nbook pages:        " . count($books) . "\n";
$empty = 0;
foreach ($books as $b) {
    if (filesize($b) < 500) {
        $empty++;
    }
}
echo "suspiciously small: {$empty}\n";
