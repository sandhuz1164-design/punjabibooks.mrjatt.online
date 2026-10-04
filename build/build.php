<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/reader.php';

$PUB = __DIR__ . '/../public';
$ASSETS = __DIR__ . '/../assets';
$SITE = 'https://strandbookstore.example';

// Catalogue page size. 21 keeps a page readable without scrolling forever and
// keeps the generated HTML small enough to serve as static files. Every listing
// on the site uses this, so no page ever shows more than 21 books.
const CATALOGUE_PER_PAGE = 21;

// How many of the catalogue the homepage shows before the "View all" button.
const HOMEPAGE_CATALOGUE_COUNT = 12;

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = "$dir/$f";
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

function put(string $rel, string $contents): void
{
    global $PUB;
    $path = "$PUB/$rel";
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, $contents);
}

function copy_tree(string $src, string $dst): void
{
    if (!is_dir($dst)) {
        mkdir($dst, 0777, true);
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $target = $dst . '/' . substr($f->getPathname(), strlen($src) + 1);
        $f->isDir() ? @mkdir($target, 0777, true) : copy($f->getPathname(), $target);
    }
}

$books = rows("SELECT * FROM products ORDER BY Title");
// ONLY_PID=<pid> builds a single book. Every loop below iterates $books, so this
// yields a coherent one-book build in under a second instead of the ~4 minutes a
// full 5,709-book build takes - which is the difference between a testable
// edit/verify loop and not. Anything built this way is a partial public/ tree:
// run a full build (no ONLY_PID) before shipping.
if (($onlyPid = getenv('ONLY_PID')) !== '' && $onlyPid !== false) {
    $books = array_values(array_filter(
        $books,
        fn(array $b): bool => (string) $b['PID'] === $onlyPid
    ));
}
$cats = categories();
$nav = $cats;
$pages = [];

echo "Cleaning output...\n";
rrmdir($PUB);
rrmdir($ASSETS);
mkdir("$PUB/assets", 0777, true);

copy_tree(__DIR__ . '/../img', "$PUB/assets/img");
copy_tree(__DIR__ . '/../fonts', "$PUB/assets/fonts");
copy_tree(__DIR__ . '/../assets-src/css', "$PUB/assets/css");
copy_tree(__DIR__ . '/../assets-src/js', "$PUB/assets/js");
copy_tree(__DIR__ . '/../assets-src/vendor/pdfjs', "$PUB/assets/vendor/pdfjs");
echo "Assets copied.\n";

function byCategory(array $books, array $cats): array
{
    $out = [];
    foreach ($books as $b) {
        $key = canon_category((string) $b['Category']);
        $out[$key][] = $b;
    }
    ksort($out);
    return $out;
}

function byAuthor(array $books): array
{
    $groups = [];
    $spellings = [];
    foreach ($books as $b) {
        $raw = trim((string) $b['Author']);
        $key = mb_strtolower(flat($raw));
        $groups[$key][] = $b;
        $spellings[$key][$raw] = ($spellings[$key][$raw] ?? 0) + 1;
    }
    $out = [];
    foreach ($groups as $key => $list) {
        arsort($spellings[$key]);
        $out[array_key_first($spellings[$key])] = $list;
    }
    ksort($out);
    return $out;
}

function bySubject(array $books): array
{
    $groups = [];
    foreach ($books as $b) {
        foreach (subjects_of($b) as $key => $label) {
            $groups[$label][$key][] = $b;
        }
    }
    ksort($groups);
    return $groups;
}

$byCat = byCategory($books, $cats);
$byAuthor = byAuthor($books);
$bySubject = bySubject($books);

// Subject slugs can only be assigned once every heading is known, otherwise a
// heading first seen on a book page would never resolve to its index entry.
slug_map('book', array_column($books, 'PID'));
slug_map('cat', array_keys($byCat));
slug_map('author', array_keys($byAuthor));
slug_map('subject', array_keys($bySubject));

$cover_missing = 0;
foreach ($books as $b) {
    if (book_cover($b) === null) {
        $cover_missing++;
    }
}

// "Most read" = lifetime downloads recorded by the Internet Archive. Items with
// no figure sort last rather than being treated as zero-interest.
$byPopular = $books;
usort($byPopular, function ($a, $b) {
    return ((int) ($b['Downloads'] ?? 0)) <=> ((int) ($a['Downloads'] ?? 0))
        || strcasecmp((string) $a['Title'], (string) $b['Title']);
});
$popularBooks = array_slice($byPopular, 0, 12);

// Script mix across the whole catalogue, for the filter dropdowns.
$scriptCounts = [];
foreach ($books as $b) {
    $s = script_of((string) $b['Title']);
    $scriptCounts[$s] = ($scriptCounts[$s] ?? 0) + 1;
}
arsort($scriptCounts);

$catLabels = array_keys($byCat);

// Reverse lookups used by the book pages.
$byPid = [];
foreach ($books as $b) {
    $byPid[(string) $b['PID']] = $b;
}
$bySubjectKey = [];
foreach ($bySubject as $label => $keys) {
    foreach ($keys as $key => $list) {
        $bySubjectKey[$key] = $list;
    }
}

/**
 * "More like this": books sharing subject headings, with a nudge for the same
 * author. Scored rather than taken in catalogue order so the closest match wins.
 */
function similar_books(array $b, array $bySubjectKey, array $byPid, array $byAuthor, int $limit = 4): array
{
    $pid = (string) $b['PID'];
    $score = [];
    foreach (subjects_of($b) as $key => $_label) {
        foreach ($bySubjectKey[$key] ?? [] as $other) {
            $oid = (string) $other['PID'];
            if ($oid !== $pid) {
                $score[$oid] = ($score[$oid] ?? 0) + 1;
            }
        }
    }
    // Same author outranks a single shared heading.
    $author = (string) $b['Author'];
    if ($author !== '' && $author !== 'Unknown') {
        foreach ($byAuthor[$author] ?? [] as $other) {
            $oid = (string) $other['PID'];
            if ($oid !== $pid) {
                $score[$oid] = ($score[$oid] ?? 0) + 3;
            }
        }
    }
    arsort($score);
    $out = [];
    foreach (array_keys($score) as $oid) {
        if (isset($byPid[$oid])) {
            $out[] = $byPid[$oid];
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

$popularAuthors = array_slice(
    array_map(fn($n) => ['name' => $n, 'count' => count($byAuthor[$n])], array_keys($byAuthor)),
    0,
    10
);
uasort($popularAuthors, fn($x, $y) => $y['count'] <=> $x['count']);

/* ---------------- book pages ---------------- */
echo "Book pages (" . count($books) . ")...\n";
$index_entries = [];

foreach ($books as $b) {
    $pid = (string) $b['PID'];
    $title = (string) $b['Title'];
    $author = (string) $b['Author'];
    $price = (float) $b['Price'];
    $mrp = (float) $b['MRP'];
    $disc = (int) $b['Discount'];
    $catLabel = canon_category((string) $b['Category']);
    $root = '../';
    $url = "$SITE/books/" . book_slug($pid) . ".html";
    $cover = book_cover($b, $root);

    $summary = book_summary((string) $b['Description']);
    $desc = meta_desc($title, $author, $summary, $b);

    $free = is_free($b);

    $ld = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Book',
        'name'        => $title,
        'author'      => ['@type' => 'Person', 'name' => $author],
        'publisher'   => ['@type' => 'Organization', 'name' => (string) $b['Publisher']],
        'inLanguage'  => (string) $b['Language'],
        'bookFormat'  => 'https://schema.org/PDF',
        'numberOfPages' => $b['page'] !== null ? (int) $b['page'] : null,
        'description' => clip($summary, 500),
        'url'         => $url,
    ];
    if (!empty($b['Year'])) {
        $ld['datePublished'] = (string) (int) $b['Year'];
    }
    if (!empty($b['Subject'])) {
        $ld['about'] = array_values(array_filter(array_map('trim', explode(';', (string) $b['Subject']))));
    }
    if ($free) {
        $ld['isAccessibleForFree'] = true;
        $ld['potentialAction'] = [[
            '@type'  => 'ReadAction',
            'target' => [(string) $b['ReadUrl']],
        ]];
    }
    $ld['offers'] = [
        '@type'         => 'Offer',
        'price'         => $price,
        'priceCurrency' => 'INR',
        'availability'  => ((int) $b['Available']) > 0
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock',
    ];
    if ($cover) {
        $ld['image'] = $url;
    }
    if ($disc > 0) {
        $ld['offers']['priceSpecification'] = [
            '@type' => 'UnitPriceSpecification',
            'price' => $mrp,
            'priceCurrency' => 'INR',
        ];
    }

    $og = '<meta property="og:image" content="' . e($url) . '">' . "\n";
    $head = '<script type="application/ld+json">'
        . json_encode(array_filter($ld, fn($v) => $v !== null && $v !== ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . '</script>' . "\n" . $og;

    $body = '<nav class="crumbs"><a href="../index.html">Home</a>'
        . '<a href="../browse/' . cat_slug($catLabel) . '.html">' . e($catLabel) . '</a>'
        . '<span>' . e($title) . '</span></nav>';

    $body .= '<div class="book">';
    if ($cover) {
        $body .= '<div class="book-media"><img src="' . $cover . '" alt="Cover of ' . e($title) . '" width="340" height="476" decoding="async"></div>';
    } else {
        $body .= '<div class="book-media"><span class="card-nocover"><span>&#128214;</span>Free PDF</span></div>';
    }
    $body .= '<div class="book-main">'
        . '<h1>' . e($title) . '</h1>'
        . '<p class="book-by">by <a href="../authors/' . author_slug($author) . '.html">' . e($author) . '</a></p>';

    if ($free) {
        $body .= '<p class="book-free"><span class="tag-free">Free to read</span> No login, no payment.</p>';
    } else {
        $body .= '<p class="book-price"><span class="now">' . money($price) . '</span>';
        if ($disc > 0) {
            $body .= '<s>' . money($mrp) . '</s><span class="off">' . $disc . '% off</span>';
        }
        $body .= '</p>'
            . '<p class="book-stock' . ((int) $b['Available'] > 0 ? '' : ' out') . '">'
            . (((int) $b['Available']) > 0 ? e((string) (int) $b['Available']) . ' in stock' : 'Out of stock') . '</p>';
    }

    $body .= read_actions($b, true, $root)
        . '<div class="spec-block"><h2>Details</h2>' . info_rows($b) . '</div>'
        . '<div class="spec-block"><h2>Source</h2>' . source_rows($b) . '</div>'
        . '</div></div>';

    if ($summary !== '') {
        $body .= '<section class="desc"><h2>About this book</h2><p>'
            . nl2br(e(trim(preg_replace('/[ \t]*[\r\n]+[ \t]*/u', "\n", (string) $b['Description']))))
            . '</p></section>';
    }

    $body .= reader_embed($b, $root);

    $subs = subjects_of($b);
    if ($subs) {
        $body .= '<section class="subs"><h2>Subjects</h2><div class="chips">';
        foreach ($subs as $key => $label) {
            $body .= '<a class="chip" href="../subjects/' . subject_slug($label) . '.html">' . e($label) . '</a>';
        }
        $body .= '</div></section>';
    }

    $rel = array_values(array_filter(
        $byAuthor[$author] ?? [],
        fn($x) => (string) $x['PID'] !== $pid
    ));
    if ($rel) {
        $body .= '<section class="rel"><h2>More by ' . e($author) . '</h2><div class="grid">';
        foreach (array_slice($rel, 0, 4) as $r) {
            $body .= card($r, $root);
        }
        $body .= '</div></section>';
    }

    $like = similar_books($b, $bySubjectKey, $byPid, $byAuthor, 4);
    if ($like) {
        $body .= '<section class="rel"><h2>More like this</h2><div class="grid">';
        foreach ($like as $r) {
            $body .= card($r, $root);
        }
        $body .= '</div></section>';
    }

    put(
        "books/" . book_slug($pid) . ".html",
        page(
            $title,
            $desc,
            $body,
            ['root' => $root, 'canonical' => $url, 'head' => $head, 'nav' => $nav, 'og_type' => 'book']
        )
    );

    // The reader page lives at /read/<slug>.html (one level deep, like books/).
    $slug = book_slug($pid);
    $readerTitle = $title . ' - read online';
    $readerDesc  = 'Read ' . $title . ($author !== '' ? ' by ' . $author : '')
        . ' in the Strand Bookstore reader.';
    put(
        "read/" . $slug . ".html",
        page(
            $readerTitle,
            $readerDesc,
            '<nav class="crumbs" aria-label="Breadcrumb"><a href="' . $root
                . 'index.html">Library</a> <span aria-hidden="true">&rsaquo;</span> '
                . '<a href="' . $root . 'books/' . e($slug) . '.html">' . e(clip($title, 60)) . '</a>'
                . ' <span aria-hidden="true">&rsaquo;</span> Reading</nav>'
                . reader_body($b, $root),
            [
                'root'      => $root,
                'canonical' => $SITE . '/read/' . $slug . '.html',
                'nav'       => $nav,
                // Reader pages should not compete with the book page in search.
                'head'      => '<meta name="robots" content="noindex,follow">' . "\n"
                    // Selection needs PDF.js's .textLayer rules, which are not part
                    // of the site stylesheet.
                    . '<link rel="stylesheet" href="' . $root
                    . 'assets/vendor/pdfjs/textlayer.css">' . "\n",
                // Reading is the whole job here: drop the site header and footer
                // so the reader's own toolbar is the only chrome.
                'body_class' => 'is-reader',
            ]
        )
    );

    $index_entries[] = [
        't' => $title,
        'a' => $author,
        'p' => (string) $b['Publisher'],
        'c' => $catLabel,
        'y' => (int) ($b['Year'] ?? 0),
        's' => (string) ($b['SourceName'] ?: 'Internet Archive'),
        'u' => 'books/' . book_slug($pid) . '.html',
        // The cover is the Archive.org CDN URL, not a local file: this used to
        // point at assets/img/books/<pid>.jpg, which 404s for every book.
        'i' => $cover ?? '',
        'd' => (int) ($b['Downloads'] ?? 0),
        'j' => implode('; ', array_values(subjects_of($b))),
    ];
}

/* ---------------- category pages ---------------- */
echo "Category pages (" . count($byCat) . ")...\n";
$catPageCounts = [];
foreach ($byCat as $label => $list) {
    usort($list, fn($x, $y) => (float) $x['Price'] <=> (float) $y['Price']);
    $base = 'browse/' . cat_slug($label);
    $total = count($list);
    $pages = max(1, (int) ceil($total / CATALOGUE_PER_PAGE));
    $catPageCounts[$base] = $pages;
    $chunks = array_chunk($list, CATALOGUE_PER_PAGE);

    for ($p = 1; $p <= $pages; $p++) {
        $slice = $chunks[$p - 1] ?? [];
        $file = "$base.html";
        if ($p > 1) {
            $file = "$base-$p.html";
        }
        $url = "$SITE/$file";
        $from = ($p - 1) * CATALOGUE_PER_PAGE + 1;
        $to = min($total, $p * CATALOGUE_PER_PAGE);

        $lede = 'Showing ' . $from . '–' . $to . ' of ' . $total
            . ' book' . ($total === 1 ? '' : 's') . ' in ' . $label
            . ', page ' . $p . ' of ' . $pages . '.';

        $body = listing_body(
            $label . ' books',
            $lede,
            $slice,
            $p,
            $pages,
            $total,
            $base,
            '../',
            array_keys($scriptCounts),
            $catLabels
        );

        put(
            $file,
            page(
                $label . ' books' . ($p > 1 ? ' - page ' . $p : ''),
                'Browse ' . $total . ' free-to-read Punjabi books in ' . $label . '. Read online or download the PDF.',
                $body,
                ['root' => '../', 'canonical' => $url, 'nav' => $nav]
            )
        );
    }
    echo "  $label: $pages page" . ($pages === 1 ? '' : 's') . "\n";
}

/* ---------------- author pages ---------------- */
echo "Author pages (" . count($byAuthor) . ")...\n";
$authorPageCounts = [];
foreach ($byAuthor as $author => $list) {
    usort($list, fn($x, $y) => (float) $x['Price'] <=> (float) $y['Price']);
    $base = 'authors/' . author_slug((string) $author);
    $total = count($list);
    $pages = max(1, (int) ceil($total / CATALOGUE_PER_PAGE));
    $authorPageCounts[$base] = $pages;
    $chunks = array_chunk($list, CATALOGUE_PER_PAGE);

    for ($p = 1; $p <= $pages; $p++) {
        $slice = $chunks[$p - 1] ?? [];
        $file = "$base.html";
        if ($p > 1) {
            $file = "$base-$p.html";
        }
        $url = "$SITE/$file";
        $from = ($p - 1) * CATALOGUE_PER_PAGE + 1;
        $to = min($total, $p * CATALOGUE_PER_PAGE);

        $lede = $pages > 1
            ? 'Showing ' . $from . '–' . $to . ' of ' . $total . ' books, page ' . $p . ' of ' . $pages . '.'
            : $total . ' book' . ($total === 1 ? '' : 's') . ' available.';

        $crumbs = '<nav class="crumbs"><a href="../index.html">Home</a>'
            . '<a href="../authors.html">Authors</a><span>' . e((string) $author) . '</span></nav>';

        $body = listing_body(
            'Books by ' . (string) $author,
            $lede,
            $slice,
            $p,
            $pages,
            $total,
            $base,
            '../',
            array_keys($scriptCounts),
            $catLabels
        );
        $body = $crumbs . $body;

        put(
            $file,
            page(
                'Books by ' . $author . ($p > 1 ? ' - page ' . $p : ''),
                'Browse all ' . $total . ' books by ' . $author . ' at Strand Bookstore.',
                $body,
                ['root' => '../', 'canonical' => $url, 'nav' => $nav]
            )
        );
    }
}

/* ---------------- subject pages ---------------- */
echo "Subject pages (" . count($bySubject) . "...\n";
$subjectPageCounts = [];
foreach ($bySubject as $label => $keys) {
    $list = [];
    $seen = [];
    foreach ($keys as $key => $group) {
        foreach ($group as $b) {
            $pid = (string) $b['PID'];
            if (!isset($seen[$pid])) {
                $seen[$pid] = true;
                $list[] = $b;
            }
        }
    }
    if (!$list) {
        continue;
    }
    $base = 'subjects/' . subject_slug((string) $label);
    $total = count($list);
    $pages = max(1, (int) ceil($total / CATALOGUE_PER_PAGE));
    $subjectPageCounts[$base] = $pages;
    $chunks = array_chunk($list, CATALOGUE_PER_PAGE);

    for ($p = 1; $p <= $pages; $p++) {
        $slice = $chunks[$p - 1] ?? [];
        $file = "$base.html";
        if ($p > 1) {
            $file = "$base-$p.html";
        }
        $url = "$SITE/$file";
        $from = ($p - 1) * CATALOGUE_PER_PAGE + 1;
        $to = min($total, $p * CATALOGUE_PER_PAGE);

        $lede = $pages > 1
            ? 'Showing ' . $from . '–' . $to . ' of ' . $total . ' books on this subject, page ' . $p . ' of ' . $pages . '.'
            : $total . ' book' . ($total === 1 ? '' : 's') . ' on this subject.';

        $crumbs = '<nav class="crumbs"><a href="../index.html">Home</a>'
            . '<a href="../subjects.html">Subjects</a><span>' . e((string) $label) . '</span></nav>';

        $body = $crumbs . listing_body(
            (string) $label,
            $lede,
            $slice,
            $p,
            $pages,
            $total,
            $base,
            '../',
            array_keys($scriptCounts),
            $catLabels
        );

        put(
            $file,
            page(
                (string) $label . ' books' . ($p > 1 ? ' - page ' . $p : ''),
                'Browse ' . $total . ' free-to-read Punjabi books on ' . $label . '.',
                $body,
                ['root' => '../', 'canonical' => $url, 'nav' => $nav]
            )
        );
    }
}

/* ---------------- subjects index ---------------- */
echo "Subjects index...\n";
{
    $counts = [];
    foreach ($bySubject as $label => $keys) {
        $n = 0;
        $seen = [];
        foreach ($keys as $group) {
            foreach ($group as $b) {
                $pid = (string) $b['PID'];
                if (!isset($seen[$pid])) {
                    $seen[$pid] = true;
                    $n++;
                }
            }
        }
        if ($n >= 2) {
            $counts[(string) $label] = $n;
        }
    }
    arsort($counts);
    $body = '<h1 class="page-h1">Subjects</h1>'
        . '<p class="lede">' . count($counts) . ' subjects shared by two or more books.</p><div class="chips">';
    foreach ($counts as $label => $n) {
        $body .= '<a class="chip" href="subjects/' . subject_slug((string) $label) . '.html">'
            . e((string) $label) . '<span class="n">' . $n . '</span></a>';
    }
    $body .= '</div>';
    put(
        "subjects.html",
        page(
            'Subjects',
            'Browse ' . count($counts) . ' Punjabi book subjects at Strand Bookstore.',
            $body,
            ['root' => '', 'canonical' => "$SITE/subjects.html", 'nav' => $nav]
        )
    );
}

/* ---------------- authors index ---------------- */
echo "Authors index...\n";
{
    $body = '<h1 class="page-h1">Authors</h1>'
        . '<p class="lede">' . count($byAuthor) . ' authors in the catalogue.</p><div class="chips">';
    foreach (array_keys($byAuthor) as $a) {
        $body .= '<a class="chip" href="authors/' . author_slug((string) $a) . '.html">' . e((string) $a)
            . '<span class="n">' . count($byAuthor[$a]) . '</span></a>';
    }
    $body .= '</div>';
    put(
        "authors.html",
        page(
            'Authors',
            'Browse the catalogue by author. ' . count($byAuthor) . ' authors available.',
            $body,
            ['root' => '', 'canonical' => "$SITE/authors.html", 'nav' => $nav, 'active' => 'authors']
        )
    );
}

/* ---------------- home ---------------- */
echo "Home...\n";
{
    $body = '<section class="hero"><div class="hero-inner">'
        . '<span class="tag-free">Free to read</span>'
        . '<h1>Read Punjabi books free online</h1>'
        . '<p>' . count($books) . ' free Punjabi books and periodicals across ' . count($byCat)
        . ' categories and ' . count($byAuthor) . ' authors &mdash; read in the browser or download the PDF. '
        . 'No login, no cart, no payment. Files are hosted by the Internet Archive.</p>'
        . '<form class="hero-search" action="search.html" method="get" role="search">'
        . '<input type="search" name="q" placeholder="Search title, author or category" aria-label="Search books">'
        . '<button type="submit">Search</button></form>'
        . '</div></section>';

    $body .= '<section class="sec"><h2 class="sec-h">Most read</h2>'
        . '<p class="sec-note">Top 12 of ' . count($books) . ', ranked by lifetime downloads '
        . 'recorded by the Internet Archive.</p>'
        . '<div class="grid">';
    foreach ($popularBooks as $i => $b) {
        $body .= card($b, '', $i < 6);
    }
    $body .= '</div></section>';

    $body .= '<section class="sec" id="catalogue"><h2 class="sec-h">Catalogue</h2>'
        . '<p class="sec-note">First 12 of ' . count($books) . ' books, sorted how you like. '
        . 'The full catalogue is 18 to a page.</p>'
        . sort_bar('')
        . filter_bar(array_keys($scriptCounts), $catLabels)
        . '<p class="empty" id="filter-empty" hidden>No books match these filters.</p>'
        . '<div class="grid" id="grid">';
    foreach (array_slice($books, 0, HOMEPAGE_CATALOGUE_COUNT) as $i => $b) {
        $body .= card($b, '', $i < 6);
    }
    $body .= '</div>'
        . '<p class="more-link"><a class="btn-ghost" href="catalogue.html">View all '
        . count($books) . ' books</a></p></section>';

    $body .= '<section class="sec"><h2 class="sec-h">Categories</h2><div class="chips">';
    foreach ($cats as $c) {
        $body .= '<a class="chip" href="browse/' . cat_slug($c['label']) . '.html">' . e($c['label'])
            . '<span class="n">' . (int) $c['count'] . '</span></a>';
    }
    $body .= '</div></section>';

    $body .= '<section class="sec"><h2 class="sec-h">Browse by author</h2><div class="chips">';
    foreach ($popularAuthors as $a) {
        $body .= '<a class="chip" href="authors/' . author_slug($a['name']) . '.html">' . e($a['name'])
            . '<span class="n">' . (int) $a['count'] . '</span></a>';
    }
    $body .= '</div></section>';

    $topSubs = array_slice($counts, 0, 24, true);
    if ($topSubs) {
        $body .= '<section class="sec"><h2 class="sec-h">Browse by subject</h2><div class="chips">';
        foreach ($topSubs as $label => $n) {
            $body .= '<a class="chip" href="subjects/' . subject_slug((string) $label) . '.html">'
                . e((string) $label) . '<span class="n">' . (int) $n . '</span></a>';
        }
        $body .= '</div></section>';
    }

    put(
        "index.html",
        page(
            'Free Punjabi Books to Read Online',
            'Read ' . count($books) . ' free Punjabi books online or download the PDF. No login or payment. Hosted by the Internet Archive.',
            $body,
            [
                'root' => '',
                'canonical' => "$SITE/",
                'nav' => $nav,
                'active' => 'home',
                'head' => '<link rel="stylesheet" href="assets/css/testdesign/bookliner.css">' . "\n",
            ]
        )
    );
}

/* ---------------- search page ---------------- */
echo "Search page...\n";
{
    $body = '<h1 class="page-h1">Search</h1>'
        . '<p class="lede" id="search-count">Type to filter ' . count($index_entries) . ' books instantly.</p>'
        . '<form class="search search-page" action="search.html" method="get" role="search">'
        . '<input type="search" name="q" id="q" placeholder="Search title, author, publisher, category" aria-label="Search books" autofocus>'
        . '<button type="submit">Search</button></form>'
        . '<div class="grid" id="grid"></div>';
    put(
        "search.html",
        page(
            'Search',
            'Search ' . count($index_entries) . ' books by title, author, publisher or category.',
            $body,
            [
                'root' => '', 'canonical' => "$SITE/search.html", 'nav' => $nav,
                'search_js' => true, 'search_index' => 'search-index.json',
            ]
        )
    );
    put('search-index.json', json_encode($index_entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/* ---------------- paginated catalogue ---------------- */
echo "Catalogue pages (" . CATALOGUE_PER_PAGE . " per page)...\n";
{
    $perPage = CATALOGUE_PER_PAGE;
    $totalBooks = count($books);
    $pages = max(1, (int) ceil($totalBooks / $perPage));
    $chunks = array_chunk($books, $perPage);

    for ($p = 1; $p <= $pages; $p++) {
        $slice = $chunks[$p - 1] ?? [];
        $file = $p === 1 ? 'catalogue.html' : "catalogue-$p.html";
        $url = "$SITE/$file";
        $from = ($p - 1) * $perPage + 1;
        $to = min($totalBooks, $p * $perPage);

        $body = listing_body(
            'All books',
            "Showing {$from}–{$to} of $totalBooks books, page $p of $pages.",
            $slice,
            $p,
            $pages,
            $totalBooks,
            'catalogue',
            '',
            array_keys($scriptCounts),
            $catLabels
        );

        put(
            $file,
            page(
                'All books' . ($p > 1 ? ' - page ' . $p : ''),
                $p === 1
                    ? "Browse all $totalBooks free-to-read Punjabi books, $perPage to a page."
                    : "Page $p of $pages of $totalBooks free-to-read Punjabi books.",
                $body,
                ['root' => '', 'canonical' => $url, 'nav' => $nav]
            )
        );
    }
    echo "  $pages pages of $perPage\n";
}

/* ---------------- 404 ---------------- */
put(
    "404.html",
    page(
        'Page not found',
        'The page you were looking for could not be found.',
        '<h1 class="page-h1">Page not found</h1><p class="lede">That page does not exist.</p>'
        . '<p><a class="btn" href="index.html">Back to home</a></p>',
        ['root' => '', 'canonical' => '', 'nav' => $nav]
    )
);

/* ---------------- sitemap + robots ---------------- */
echo "Sitemap + robots...\n";
{
    $urls = [['loc' => "$SITE/", 'pri' => '1.0'], ['loc' => "$SITE/catalogue.html", 'pri' => '0.9'], ['loc' => "$SITE/authors.html", 'pri' => '0.6'], ['loc' => "$SITE/subjects.html", 'pri' => '0.5'], ['loc' => "$SITE/search.html", 'pri' => '0.4']];
    foreach ($byCat as $label => $list) {
        $urls[] = ['loc' => "$SITE/browse/" . cat_slug($label) . ".html", 'pri' => '0.8'];
    }
    foreach ($byAuthor as $a => $list) {
        $urls[] = ['loc' => "$SITE/authors/" . author_slug((string) $a) . ".html", 'pri' => '0.5'];
    }
    foreach (array_keys($bySubject) as $label) {
        $urls[] = ['loc' => "$SITE/subjects/" . subject_slug((string) $label) . ".html", 'pri' => '0.4'];
    }
    foreach ($books as $b) {
        $urls[] = ['loc' => "$SITE/books/" . book_slug((string) $b['PID']) . ".html", 'pri' => '0.7'];
    }
    $extraPages = 0;
    foreach ([$catPageCounts ?? [], $authorPageCounts ?? [], $subjectPageCounts ?? []] as $set) {
        foreach ($set as $base => $pages) {
            for ($p = 2; $p <= $pages; $p++) {
                $urls[] = ['loc' => "$SITE/$base-$p.html", 'pri' => '0.3'];
                $extraPages++;
            }
        }
    }
    $cataloguePages = max(1, (int) ceil(count($books) / CATALOGUE_PER_PAGE));
    for ($p = 2; $p <= $cataloguePages; $p++) {
        $urls[] = ['loc' => "$SITE/catalogue-$p.html", 'pri' => '0.3'];
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        $xml .= '  <url><loc>' . e($u['loc']) . '</loc><priority>' . $u['pri'] . '</priority></url>' . "\n";
    }
    $xml .= '</urlset>' . "\n";
    put('sitemap.xml', $xml);
    put('robots.txt', "User-agent: *\nAllow: /\nSitemap: $SITE/sitemap.xml\n");
}

$total = count($books) + count($byCat) + count($byAuthor) + count($bySubject)
    + max(1, (int) ceil(count($books) / CATALOGUE_PER_PAGE))
    + array_sum($catPageCounts ?? []) + array_sum($authorPageCounts ?? [])
    + array_sum($subjectPageCounts ?? []) + 4;
echo "\nDone. $total HTML pages written to public/\n";
echo 'Books: ' . count($books) . ' | Categories: ' . count($byCat) . ' | Authors: ' . count($byAuthor) . "\n";
echo 'Missing covers: ' . $cover_missing . "\n";
