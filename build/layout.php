<?php

function slug(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

function flat(string $s): string
{
    $s = str_replace(["\r\n", "\r", "\x0B", "\x0C"], ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function book_summary(string $raw): string
{
    $kept = [];
    foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^(authored by|edition|publication year|publisher name|weight|languages?|pages|no\.?\s*of\s*pages|isbn|printed in|binding|print type|book type)\b\s*[:\-]?/i', $line)) {
            continue;
        }
        $kept[] = $line;
    }
    $text = flat(implode(' ', $kept));
    return $text === '' ? '' : $text;
}

function clip(string $s, int $max): string
{
    if (mb_strlen($s) <= $max) {
        return $s;
    }
    return rtrim(mb_substr($s, 0, $max - 3), " ,.;:-") . '...';
}

function meta_desc(string $title, string $author, string $blurb, array $b): string
{
    if (mb_strlen($blurb) >= 60) {
        return clip($title . ' by ' . $author . '. ' . $blurb, 158);
    }
    $bits = [];
    if (!empty($b['SourceName'])) {
        $bits[] = 'Free to read';
    }
    if (!empty($b['Publisher'])) {
        $bits[] = (string) $b['Publisher'];
    }
    if (!empty($b['page'])) {
        $bits[] = (int) $b['page'] . ' pages';
    }
    $tail = $bits ? implode(', ', $bits) . '.' : '';
    return clip(trim($title . ' by ' . $author . '. ' . $tail), 158);
}

$GLOBALS['SLUGS'] = ['book' => [], 'cat' => [], 'author' => [], 'subject' => []];

function slug_map(string $kind, array $names): array
{
    $map = [];
    $used = [];
    foreach ($names as $n) {
        $n = (string) $n;
        $base = slug($n);
        $s = $base;
        $i = 2;
        while (isset($used[$s])) {
            $s = $base . '-' . $i;
            $i++;
        }
        $used[$s] = true;
        $map[$n] = $s;
    }
    $GLOBALS['SLUGS'][$kind] = $map;
    return $map;
}

function book_slug(string $pid): string
{
    return $GLOBALS['SLUGS']['book'][$pid] ?? slug($pid);
}

function cat_slug(string $label): string
{
    return $GLOBALS['SLUGS']['cat'][$label] ?? slug($label);
}

function author_slug(string $name): string
{
    return $GLOBALS['SLUGS']['author'][$name] ?? slug($name);
}

function subject_slug(string $name): string
{
    return $GLOBALS['SLUGS']['subject'][$name] ?? slug($name);
}

/**
 * Which script a string is written in. Archive.org mixes scripts freely inside
 * one language tag, so "Punjabi" alone does not tell a reader whether they can
 * read the title. Judged on the title because that is what a reader scans.
 */
function script_of(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return 'unknown';
    }
    $gurmukhi = preg_match_all('/[\x{0A00}-\x{0A7F}]/u', $s);
    $latin    = preg_match_all('/[A-Za-z]/', $s);
    if ($gurmukhi > 0 && $latin > 0) {
        return 'mixed';
    }
    if ($gurmukhi > 0) {
        return 'gurmukhi';
    }
    if ($latin > 0) {
        return 'roman';
    }
    return 'unknown';
}

const SCRIPT_LABELS = [
    'gurmukhi' => 'Gurmukhi',
    'roman'    => 'Roman',
    'mixed'    => 'Mixed',
    'unknown'  => 'Other',
];

// How many subject headings a book contributes and shows. These must be the
// same number: the index builds a page per subject, so a book linking to an
// 8th subject that was never indexed is a dead link.
const SUBJECTS_PER_BOOK = 8;

// Archive.org's subject field is full of one-word noise ("PA", "Blue",
// "Regarding") that makes an index useless if it is listed verbatim.
const SUBJECT_STOPWORDS = [
    'pa', 'main page', 'regarding', 'solutions', 'blue', 'red', 'general',
    'miscellaneous', 'other', 'unknown', 'none', 'india', 'page', 'pages',
    'home', 'index', 'about', 'contact', 'faq', 'help', 'news', 'blog',
];

/**
 * Subject headings for a book. Archive.org stores these as one ';'-joined
 * string; the useful ones are short, and anything long is prose, not a heading.
 */
function subjects_of(array $b, int $max = SUBJECTS_PER_BOOK): array
{
    $out = [];
    foreach (explode(';', (string) ($b['Subject'] ?? '')) as $s) {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        if ($s === '' || mb_strlen($s) > 60) {
            continue;
        }
        $key = mb_strtolower($s);
        if (in_array($key, SUBJECT_STOPWORDS, true)) {
            continue;
        }
        $out[$key] = $s;
    }
    return array_slice($out, 0, $max);
}

function script_badge(array $b): string
{
    $s = script_of((string) ($b['Title'] ?? ''));
    return '<span class="script-tag" data-script="' . e($s) . '">' . e(SCRIPT_LABELS[$s]) . '</span>';
}

function book_cover(array $b, string $root = ''): ?string
{
    if (!empty($b['ArchiveId'])) {
        return 'https://archive.org/services/img/' . rawurlencode($b['ArchiveId']);
    }
    return null;
}

function is_free(array $b): bool
{
    return (float) $b['Price'] <= 0 && !empty($b['ReadUrl']);
}

/**
 * $eager marks covers that are on screen at first paint (the first row of a
 * grid). They must not be lazy: lazy-loading the visible row delays the one
 * thing the visitor came for.
 */
function card(array $b, string $root = '', bool $eager = false): string
{
    $pid = (string) $b['PID'];
    $cover = book_cover($b, $root);
    $price = (float) $b['Price'];
    $mrp = (float) $b['MRP'];
    $disc = (int) $b['Discount'];
    $out = (float) $b['Available'];
    $free = is_free($b);
    $url = $root . 'books/' . book_slug($pid) . '.html';
    $subs = subjects_of($b, 4);
    $script = script_of((string) $b['Title']);

    $h = '<article class="card' . ($free ? ' free' : '') . '"'
        . ' data-year="' . (int) ($b['Year'] ?? 0) . '"'
        . ' data-author="' . e(author_label((string) $b['Author'])) . '"'
        . ' data-title="' . e((string) $b['Title']) . '"'
        . ' data-dl="' . (int) ($b['Downloads'] ?? 0) . '"'
        . ' data-script="' . e($script) . '"'
        . ' data-cat="' . e(canon_category((string) $b['Category'])) . '"'
        . ' data-pdf="' . (!empty($b['PdfFile']) ? '1' : '0') . '"'
        . ' data-subject="' . e(implode('|', array_map('mb_strtolower', $subs))) . '"'
        . '>'
        . '<a class="card-media" href="' . $url . '">';

    if ($cover) {
        $h .= '<img src="' . $cover . '" alt="Cover of ' . e($b['Title']) . '"'
            . ($eager ? ' fetchpriority="high"' : ' loading="lazy"')
            . ' decoding="async" width="200" height="280">';
    } else {
        $h .= '<span class="card-nocover" aria-hidden="true"><span>&#128214;</span>Free PDF</span>';
    }
    // Script sits on the cover itself: whether a reader can read the title is
    // the first thing to know, and it belongs with the image it describes.
    $h .= '<span class="script-tag" data-script="' . e($script) . '">'
        . e(SCRIPT_LABELS[$script]) . '</span>';

    $h .= '</a><div class="card-body">'
        . '<h3 class="card-title"><a href="' . $url . '">' . e($b['Title']) . '</a></h3>'
        . '</div></article>';
    return $h;
}

/**
 * Display name for an author field. One upload account accounts for ~1,600 of
 * the 5,564 books and "www.DiscoverSikhism.com" is a truncated, meaningless
 * author line on a card. The raw value stays in the database and on the author
 * page; only the label shown on cards and matched by the filter is shortened.
 */
const AUTHOR_ALIASES = [
    'www.discoversikhism.com' => 'Punjabi Digital Library',
];

function author_label(string $author): string
{
    $key = mb_strtolower(trim($author));
    return AUTHOR_ALIASES[$key] ?? $author;
}

/**
 * File size appended to the download button. Knowing the size before clicking
 * matters here: these scans range from a few hundred KB to 300 MB.
 */
function dl_size(array $b): string
{
    $bytes = (int) ($b['PdfSize'] ?? 0);
    if ($bytes <= 0) {
        return '';
    }
    return ' (<span class="dl-size">' . e(size_fmt($bytes)) . '</span>)';
}

function read_actions(array $b, bool $inline = true, string $root = ''): string
{
    $pid  = (string) ($b['PID'] ?? '');
    $slug = book_slug($pid);
    $h = '<div class="read-actions">';

    // The library's own reader is the primary way to read: it streams the file
    // (or plays the copy already on this computer) and needs no third-party page.
    if (reader_can_stream($b)) {
        $h .= '<a class="btn-read" href="' . $root . 'read/' . e($slug) . '.html">Read online</a>';
    } elseif (!empty($b['ReadUrl'])) {
        $h .= '<a class="btn-read" href="' . e((string) $b['ReadUrl'])
            . '" target="_blank" rel="noopener nofollow">Read online</a>';
    }

    if (reader_can_stream($b)) {
        $h .= '<a class="btn-dl" href="/api/stream.php?pid=' . rawurlencode($pid)
            . '&amp;dl=1" download>Download PDF' . dl_size($b) . '</a>';
    } elseif (!empty($b['DownloadUrl'])) {
        $h .= '<a class="btn-dl" href="' . e((string) $b['DownloadUrl'])
            . '" download>Download PDF' . dl_size($b) . '</a>';
    }
    if (!empty($b['TextUrl'])) {
        $h .= '<a class="btn-ghost" href="' . e((string) $b['TextUrl'])
            . '" target="_blank" rel="noopener nofollow">Plain text</a>';
    }
    if (!empty($b['EpubUrl'])) {
        $h .= '<a class="btn-ghost" href="' . e((string) $b['EpubUrl'])
            . '" download>EPUB</a>';
    }
    if (!empty($b['MobiUrl'])) {
        $h .= '<a class="btn-ghost" href="' . e((string) $b['MobiUrl'])
            . '" download>MOBI (Kindle)</a>';
    }
    if (!empty($b['DjvuUrl'])) {
        $h .= '<a class="btn-ghost" href="' . e((string) $b['DjvuUrl'])
            . '" download>DjVu</a>';
    }
    if (!empty($b['ZipUrl'])) {
        $h .= '<a class="btn-ghost" href="' . e((string) $b['ZipUrl'])
            . '" download>Scan images (ZIP)</a>';
    }
    if (!empty($b['DetailUrl'])) {
        $h .= '<a class="btn-ghost" href="' . e((string) $b['DetailUrl'])
            . '" target="_blank" rel="noopener nofollow">Source</a>';
    }
    return $h . '</div>';
}

function reader_embed(array $b, string $root = ''): string
{
    $archive = (string) ($b['ReadUrl'] ?: '');
    $slug    = book_slug((string) ($b['PID'] ?? ''));
    $title   = (string) ($b['Title'] ?? '');
    $stream  = reader_can_stream($b);

    // Deliberately not an iframe: embedding the Archive.org reader on every book
    // page pulls a heavy third-party app into 1,400+ pages. We link out instead.
    $h = '<section class="reader">'
        . '<h2>Read this book</h2>';

    if ($stream) {
        $h .= '<p class="reader-lede">Open it in the library reader. '
            . 'Pages are fetched as you turn them, so it starts quickly even on a '
            . 'slow connection.</p>'
            . '<p class="reader-cta">'
            . '<a class="btn read" href="' . $root . 'read/' . e($slug) . '.html">'
            . 'Open the reader</a>';
    } elseif ($archive !== '') {
        $h .= '<p class="reader-lede">This scan is too large to stream here, '
            . 'so it opens in the Archive.org reader.</p>'
            . '<p class="reader-cta">'
            . '<a class="btn read" href="' . e($archive)
            . '" target="_blank" rel="noopener nofollow">Read on Archive.org</a>';
    } else {
        return '';
    }

    if ($archive !== '' && $stream) {
        $h .= ' <a class="btn ghost" href="' . e($archive)
            . '" target="_blank" rel="noopener nofollow">Open on Archive.org</a>';
    }

    return $h . '</p></section>';
}

function info_rows(array $b): string
{
    $rows = [
        'Title'    => $b['Title'] ?? '',
        'Author'   => $b['Author'] ?? '',
        'Language' => $b['Language'] ?? '',
        'Published' => !empty($b['Year']) ? (string) (int) $b['Year'] : '',
        'Publisher' => $b['Publisher'] ?? '',
        'Edition'  => $b['Edition'] ?? '',
        'Category' => $b['Category'] ?? '',
    ];
    $out = '<dl class="spec">';
    foreach ($rows as $k => $v) {
        $v = trim((string) $v);
        if ($v === '') {
            continue;
        }
        $out .= '<dt>' . e($k) . '</dt><dd>' . e($v) . '</dd>';
    }
    $out .= '</dl>';
    return $out;
}

function source_rows(array $b): string
{
    $rows = [
        'Source'          => 'Internet Archive',
        'Archive item'    => (string) ($b['ArchiveId'] ?? ''),
        'PDF file'        => (string) ($b['PdfFile'] ?? ''),
        'File size'       => !empty($b['PdfSize']) ? size_fmt((int) $b['PdfSize']) : '',
        'Rights'          => (string) ($b['Rights'] ?? ''),
        'Subjects'        => (string) ($b['Subject'] ?? ''),
        'Uploaded by'     => (string) ($b['SourceUpload'] ?? ''),
    ];
    $out = '<dl class="spec">';
    foreach ($rows as $k => $v) {
        $v = trim((string) $v);
        if ($v === '') {
            continue;
        }
        $out .= '<dt>' . e($k) . '</dt><dd>' . e($v) . '</dd>';
    }
    $out .= '</dl>';
    return $out;
}

function reads_fmt(int $n): string
{
    if ($n >= 1000000) {
        return round($n / 1000000, 1) . 'M';
    }
    if ($n >= 1000) {
        return round($n / 1000, $n >= 10000 ? 0 : 1) . 'k';
    }
    return (string) $n;
}

function size_fmt(int $bytes): string
{    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 0) . ' KB';
    }
    return $bytes . ' B';
}

function page(string $title, string $desc, string $body, array $opt = []): string
{
    $root = $opt['root'] ?? '';
    $canonical = $opt['canonical'] ?? '';
    $extra = $opt['head'] ?? '';
    $depth = $opt['depth'] ?? 0;
    $nav = $opt['nav'] ?? [];

    $h = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n"
        . "<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . '<title>' . e($title) . ' &middot; Strand Bookstore</title>' . "\n"
        . '<meta name="description" content="' . e($desc) . '">' . "\n";
    if ($canonical !== '') {
        $h .= '<link rel="canonical" href="' . e($canonical) . '">' . "\n";
    }
    $h .= '<meta property="og:type" content="' . e($opt['og_type'] ?? 'website') . '">' . "\n"
        . '<meta property="og:title" content="' . e($title) . '">' . "\n"
        . '<meta property="og:description" content="' . e($desc) . '">' . "\n"
        . '<meta property="og:url" content="' . e($canonical) . '">' . "\n"
        . '<meta name="twitter:card" content="summary_large_image">' . "\n"
        . '<link rel="stylesheet" href="' . $root . 'assets/css/style.css">' . "\n"
        . $extra
        . "</head>\n"
        . '<body class="' . e(trim('body-page ' . ($opt['body_class'] ?? ''))) . "\">\n"
        . '<a class="skip" href="#main">Skip to content</a>';

    $h .= header_html($root, $nav, $opt['active'] ?? '');
    $h .= "\n<main id=\"main\">\n" . $body . "\n</main>\n";
    $h .= footer_html($root);
    $h .= "\n<script src=\"" . $root . "assets/js/app.js\" defer></script>\n";
    if (!empty($opt['search_js'])) {
        $h .= "\n<script src=\"" . $root . "assets/js/search.js\" defer></script>\n";
    }
    $h .= "</body>\n</html>\n";
    return $h;
}

function header_html(string $root, array $nav, string $active): string
{
    $h = '<header class="site-head"><div class="wrap head-inner">'
        . '<a class="brand" href="' . $root . 'index.html">'
        . '<img src="' . $root . 'assets/img/logo.svg" alt="" width="34" height="34">'
        . '<span>Strand<em>Bookstore</em></span></a>';

    $h .= '<form class="search" action="' . $root . 'search.html" method="get" role="search">'
        . '<input type="search" name="q" placeholder="Search books, authors, publishers&hellip;" aria-label="Search books">'
        . '<button type="submit">Search</button></form>';

    $h .= '<nav class="site-nav" aria-label="Main">'
        . '<a href="' . $root . 'index.html"' . ($active === 'home' ? ' class="on"' : '') . '>Home</a>'
        . '<div class="drop"><button type="button" aria-haspopup="true">Categories</button><div class="drop-menu">';
    foreach ($nav as $c) {
        $h .= '<a href="' . $root . 'browse/' . cat_slug($c['label']) . '.html">' . e($c['label'])
            . '<span class="n">' . (int) $c['count'] . '</span></a>';
    }
    $h .= '</div></div>'
        . '<a href="' . $root . 'catalogue.html"' . ($active === 'catalogue' ? ' class="on"' : '') . '>All books</a>'
        . '<a href="' . $root . 'authors.html"' . ($active === 'authors' ? ' class="on"' : '') . '>Authors</a>'
        . '<a href="' . $root . 'subjects.html"' . ($active === 'subjects' ? ' class="on"' : '') . '>Subjects</a>'
        . '</nav></div></header>';
    return $h;
}

function footer_html(string $root): string
{
    $y = date('Y');
    return '<footer class="site-foot"><div class="wrap foot-inner">'
        . '<p class="foot-brand">Strand<em>Bookstore</em></p>'
        . '<p>A free-to-read library of Punjabi books &mdash; read online or download the PDF. No login, no cart, no payment.</p>'
        . '<p class="foot-note">Texts and PDFs hosted by <a href="https://archive.org/" target="_blank" rel="noopener nofollow">the Internet Archive</a>. '
        . 'Each book links back to its source item, where the copyright and licence terms are stated. &middot; ' . $y . '</p>'
        . '</div></footer>';
}

function sort_bar(string $root, string $id = 'sortbar', string $default = 'title'): string
{
    $opts = [
        'popular'   => 'Most read',
        'title'     => 'Title A&ndash;Z',
        'author'    => 'Author A&ndash;Z',
        'new'       => 'Newest first',
        'old'       => 'Oldest first',
    ];
    $h = '<div class="sortbar" id="' . e($id) . '" role="group" aria-label="Sort books"'
        . ' data-default-sort="' . e($default) . '">'
        . '<span class="sortbar-label">Sort</span>';
    foreach ($opts as $key => $label) {
        $h .= '<button type="button" data-sort="' . e($key) . '"'
            . ($key === $default ? ' aria-pressed="true"' : '') . '>' . $label . '</button>';
    }
    return $h . '</div>';
}

/**
 * Client-side filter row. Every control maps to a data-* attribute already on
 * the cards, so filtering never touches the network.
 */
/**
 * Numbered pager with a window around the current page, so a 98-page category
 * does not render 98 links. $base is the page-1 path without .html relative to
 * the site root (e.g. 'browse/sikh-literature'); $root is the current page's
 * own prefix ('../' one level down), so links resolve from wherever they sit.
 */
function pager_html(int $page, int $pages, string $base, string $root = ''): string
{
    if ($pages < 2) {
        return '';
    }
    $url = fn(int $n) => $root . ($n === 1 ? "$base.html" : "$base-$n.html");

    $h = '<nav class="pager" aria-label="Pagination">';
    if ($page > 1) {
        $h .= '<a class="pg-prev" href="' . $url($page - 1) . '" rel="prev">Previous</a>';
    }
    $lo = max(1, $page - 3);
    $hi = min($pages, $page + 3);
    if ($lo > 1) {
        $h .= '<a href="' . $url(1) . '">1</a>';
        if ($lo > 2) {
            $h .= '<span class="pg-gap">&hellip;</span>';
        }
    }
    for ($i = $lo; $i <= $hi; $i++) {
        $h .= $i === $page
            ? '<span class="pg-on" aria-current="page">' . $i . '</span>'
            : '<a href="' . $url($i) . '">' . $i . '</a>';
    }
    if ($hi < $pages) {
        if ($hi < $pages - 1) {
            $h .= '<span class="pg-gap">&hellip;</span>';
        }
        $h .= '<a href="' . $url($pages) . '">' . $pages . '</a>';
    }
    if ($page < $pages) {
        $h .= '<a class="pg-next" href="' . $url($page + 1) . '" rel="next">Next</a>';
    }
    return $h . '</nav>';
}

/** Heading + lede + sort + filter + one page of cards + pager. */
function listing_body(
    string $h1,
    string $lede,
    array $list,
    int $page,
    int $pages,
    int $total,
    string $base,
    string $root,
    array $scripts,
    array $cats
): string {
    $body = '<h1 class="page-h1">' . e($h1) . '</h1>'
        . '<p class="lede">' . e($lede) . '</p>'
        . sort_bar($root)
        . filter_bar($scripts, $cats)
        . '<p class="empty" id="filter-empty" hidden>No books on this page match these filters.</p>'
        . '<div class="grid" id="grid">';
    foreach ($list as $i => $b) {
        $body .= card($b, $root, $i < 6);
    }
    return $body . '</div>' . pager_html($page, $pages, $base, $root);
}

function filter_bar(array $scripts = [], array $cats = []): string
{
    $h = '<div class="filterbar" id="filterbar">'
        . '<button type="button" class="filter-toggle" id="filter-toggle" aria-expanded="false" aria-controls="filter-controls">'
        . '<span>Filter</span><span class="filter-toggle-n" id="filter-toggle-n" hidden></span></button>'
        . '<div class="filter-controls" id="filter-controls">'
        . '<span class="filterbar-label">Filter</span>'
        . '<label class="filter"><span class="sr-only">Filter by script</span>'
        . '<select data-filter="script">'
        . '<option value="">Any script</option>';
    foreach ($scripts as $s) {
        $h .= '<option value="' . e($s) . '">' . e(SCRIPT_LABELS[$s] ?? $s) . '</option>';
    }
    $h .= '</select></label>'
        . '<label class="filter"><span class="sr-only">Filter by category</span>'
        . '<select data-filter="cat"><option value="">Any category</option>';
    foreach ($cats as $c) {
        $h .= '<option value="' . e($c) . '">' . e($c) . '</option>';
    }
    $h .= '</select></label>'
        . '<label class="filter"><span class="sr-only">Filter by author</span>'
        . '<input type="search" data-filter="author" placeholder="Author&hellip;" autocomplete="off"></label>'
        . '<label class="filter"><span class="sr-only">Filter by subject</span>'
        . '<input type="search" data-filter="subject" placeholder="Subject&hellip;" autocomplete="off"></label>'
        . '<label class="filter-check"><input type="checkbox" data-filter="pdf">'
        . '<span>Has PDF</span></label>'
        . '<button type="button" class="filter-clear" id="filter-clear" hidden>Clear</button>'
        . '<span class="filter-count" id="filter-count" aria-live="polite"></span>'
        . '</div></div>';
    return $h;
}
