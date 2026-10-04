<?php
/**
 * Streams a book PDF to the reader without writing it to disk.
 *
 *   GET /api/stream.php?pid=<PID>[&dl=1]
 *
 * Two modes, which is what makes the reader "hybrid":
 *   1. If the PDF has already been cached in the local library folder it is
 *      served straight off disk (fast, and works offline).
 *   2. Otherwise the bytes are piped through from archive.org, forwarding the
 *      client's Range header so PDF.js only pulls the parts it needs. Nothing
 *      is stored.
 *
 * The upstream URL is always looked up in our own catalogue by PID; a caller
 * can never make this endpoint fetch an arbitrary address.
 */

declare(strict_types=1);

// A warning printed into the body would corrupt a PDF byte stream (or a JSON
// probe), so diagnostics go to the error log only.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../db.php';

// Local library folder. Overridable with BOOK_LIBRARY_DIR so the cache can live
// on durable storage: the original /run/media default is an ntfs3 autofs mount,
// which silently loses written files on an unclean unmount. Not a const because
// getenv() is not allowed in a constant expression.
function library_dir(): string
{
    static $dir = null;
    if ($dir === null) {
        $dir = getenv('BOOK_LIBRARY_DIR') ?: '/home/jatt/book-library';
    }
    return $dir;
}

// Refuse to stream absurd single files through the server; those are better
// left to the Archive.org reader or a direct download.
const MAX_STREAM_BYTES = 400 * 1024 * 1024;

function cache_path_for(string $pid): string
{
    $h = sha1($pid);
    return library_dir() . '/' . $h[0] . '/' . $h[1] . '/' . $pid . '.pdf';
}

function stream_log(string $line): void
{
    if (!getenv('STREAM_DEBUG')) {
        return;
    }
    file_put_contents('/tmp/opencode/stream.log', $line, FILE_APPEND);
}

function bail(int $code, string $msg): never
{
    stream_log(sprintf(
        '%s BAIL %d %s range=%s',
        date('H:i:s'),
        $code,
        $msg,
        $_SERVER['HTTP_RANGE'] ?? '-'
    ));
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg . "\n";
    exit;
}

$pid = trim((string) ($_GET['pid'] ?? ''));
if ($pid === '' || !preg_match('/^[A-Za-z0-9_.-]{1,150}$/', $pid)) {
    bail(400, 'Missing or invalid pid.');
}

$book = one('SELECT PID, Title, DownloadUrl, PdfSize FROM products WHERE PID = ? LIMIT 1', [$pid]);
if (!$book) {
    bail(404, 'No such book.');
}

$download = !empty($_GET['dl']);
$cached = cache_path_for($pid);
$haveCache = is_file($cached) && filesize($cached) > 0;
$knownSize = (int) ($book['PdfSize'] ?? 0);
$hasPdf = (string) $book['DownloadUrl'] !== ''
    && (bool) preg_match('#^https://archive\.org/download/#i', (string) $book['DownloadUrl']);

$dbg = static function (string $what) use ($pid, $download): void {
    stream_log(sprintf(
        '%s pid=%s %s %s range=%s probe=%s dl=%s',
        date('H:i:s'),
        $pid,
        $what,
        $_SERVER['REQUEST_METHOD'] ?? '?',
        $_SERVER['HTTP_RANGE'] ?? '-',
        !empty($_GET['probe']) ? 'y' : 'n',
        $download ? 'y' : 'n'
    ) . "\n");
};
$dbg('start');

/* ---------- 0. cheap metadata probe for the reader UI ---------- */
if (!empty($_GET['probe'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $streamable = $haveCache || ($hasPdf && $knownSize <= MAX_STREAM_BYTES);
    echo json_encode([
        'cached'     => $haveCache,
        'size'       => $haveCache ? filesize($cached) : $knownSize,
        'streamable' => $streamable,
        'reason'     => $streamable ? ''
            : (!$hasPdf ? 'no-pdf' : 'too-large'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------- 1. serve the local cache when we have it ---------- */
if ($haveCache) {
    $size = filesize($cached);
    $fh = fopen($cached, 'rb');
    if ($fh === false) {
        bail(500, 'Could not open the cached file.');
    }
    header('Content-Type: application/pdf');
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=86400');
    header('X-Served-From: cache');

    $start = 0;
    $end = $size - 1;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
        if ($m[1] !== '') {
            $start = (int) $m[1];
            if ($m[2] !== '') {
                $end = min($end, (int) $m[2]);
            }
        } elseif ($m[2] !== '') {
            $start = max(0, $size - (int) $m[2]);
        }
        if ($start > $end || $start >= $size) {
            header("Content-Range: bytes */$size");
            http_response_code(416);
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }

    if ($download) {
        header('Content-Disposition: attachment; filename="' . $pid . '.pdf"');
    }
    header('Content-Length: ' . ($end - $start + 1));
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) {
        $chunk = fread($fh, (int) min(262144, $left));
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        $left -= strlen($chunk);
        flush();
        if (connection_aborted()) {
            break;
        }
    }
    $dbg(sprintf('cache-sent %d-%d of %d', $start, $end, $size));
    fclose($fh);
    exit;
}

/* ---------- 2. otherwise pipe through from archive.org ---------- */

$url = (string) $book['DownloadUrl'];
if ($url === '' || !preg_match('#^https://archive\.org/download/#i', $url)) {
    bail(404, 'This book has no downloadable file.');
}

if ($knownSize > MAX_STREAM_BYTES) {
    bail(413, sprintf(
        'This file is %.0f MB, too large to stream. Use the Archive.org reader or download it.',
        $knownSize / 1048576
    ));
}

$headers = [
    'User-Agent: StrandBookstoreReader/1.0 (+local library reader)',
    'Accept: application/pdf,*/*',
];
if (!empty($_SERVER['HTTP_RANGE'])) {
    $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

$ctx = stream_context_create(['http' => [
    'method'        => $_SERVER['REQUEST_METHOD'] === 'HEAD' ? 'HEAD' : 'GET',
    'header'        => implode("\r\n", $headers),
    'timeout'       => 60,
    'follow_location' => 1,
    'max_redirects' => 5,
    'ignore_errors' => true,
]]);

$up = @fopen($url, 'rb', false, $ctx);
if ($up === false) {
    bail(502, 'Could not reach archive.org.');
}

$meta = $http_response_header ?? [];
$status = 200;
$passthrough = [];
foreach ($meta as $line) {
    // Each redirect hop starts a fresh header block, so reset as we see a
    // status line and only keep headers belonging to the final response.
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
        $status = (int) $m[1];
        $passthrough = [];
        continue;
    }
    if (preg_match('#^(content-type|content-length|content-range|accept-ranges|last-modified):\s*(.*)$#i', $line, $m)) {
        $passthrough[strtolower($m[1])] = trim($m[2]);
    }
}

if ($status >= 400) {
    fclose($up);
    bail(502, "archive.org returned HTTP $status.");
}

http_response_code($status);
header('Content-Type: application/pdf');
header('X-Served-From: archive.org');
header('Cache-Control: private, max-age=3600');
header('Accept-Ranges: bytes');

// PDF.js needs to see a ranged response for its own range requests, but for the
// reader's *first* request (which carries no Range) sending Content-Length makes
// PDF.js treat the fetch as "fetch the whole file" and wait for every byte before
// rendering. Leaving it off makes the reply chunked, which puts PDF.js into
// streaming mode: it renders page 1 as soon as those bytes arrive. A download
// still gets an exact length so browsers show real progress.
$clientWantsWholeFile = ($_SERVER['HTTP_RANGE'] ?? '') === '';
if (!$download && $clientWantsWholeFile && $passthrough) {
    unset($passthrough['content-length']);
}
foreach ($passthrough as $k => $v) {
    if ($k === 'content-type') {
        continue;
    }
    header($k . ': ' . $v);
}
if ($download) {
    header('Content-Disposition: attachment; filename="' . $pid . '.pdf"');
}

$dbg(sprintf('archive-sending status=%d ct=%s len=%s range=%s',
    $status, $passthrough['content-type'] ?? '-', $passthrough['content-length'] ?? '-',
    $passthrough['content-range'] ?? '-'));
$sent = 0;
while (!feof($up)) {
    $chunk = fread($up, 262144);
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $sent += strlen($chunk);
    flush();
    if (connection_aborted()) {
        break;
    }
}
$dbg("archive-done sent=$sent");
fclose($up);
