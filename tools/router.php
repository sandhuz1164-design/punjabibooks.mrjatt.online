<?php
/**
 * Router for the PHP built-in server (development only).
 *
 *   php -S 127.0.0.1:8081 -t public tools/router.php
 *
 * The document root stays pure static HTML/CSS/JS so it can be deployed to any
 * static host, while /api/* is handled by the PHP endpoints that live outside
 * the web root.
 */

declare(strict_types=1);

$root = dirname(__DIR__) . '/public';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// API endpoints are served from the proxy directory.
if (preg_match('#^/api/([a-z0-9_-]+\.php)$#i', $path, $m)) {
    $target = dirname(__DIR__) . '/proxy/' . $m[1];
    if (is_file($target)) {
        require $target;
        return true;
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unknown endpoint.\n";
    return true;
}

// Everything else falls through to the static document root.
$file = realpath($root . urldecode($path));
if ($file !== false && is_file($file) && str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
    return false; // let the built-in server handle it
}

// Directory index, e.g. /book/ -> /book/index.html
if ($file !== false && is_dir($file) && is_file(rtrim($file, '/') . '/index.html')) {
    return false;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>404</title>'
   . '<p style="font:16px/1.6 system-ui;margin:3rem auto;max-width:34rem">'
   . '<strong>404 &mdash; page not found</strong><br>'
   . '<a href="/">Back to the library</a></p>';
return true;
