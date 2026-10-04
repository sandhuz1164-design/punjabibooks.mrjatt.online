<?php
/**
 * Reader page body for one book.
 *
 * Reading is "hybrid": the page always loads PDF.js, but the bytes come from
 * /api/stream.php, which serves the copy already on this computer when there is
 * one and otherwise pipes only the requested byte ranges straight from
 * Archive.org. Nothing is written to disk by the reader itself.
 *
 * A book that has no PDF, or one too large to stream through the server, is
 * rendered as a plain panel pointing at the Archive.org reader and downloads.
 *
 * @var string $root site-relative path prefix ('' from /read/, so none)
 */

const READER_MAX_STREAM_BYTES = 400 * 1024 * 1024;

/** True when /api/stream.php can serve this book (cached, or streamable). */
function reader_can_stream(array $b): bool
{
    if (empty($b['DownloadUrl'])) {
        return false;
    }
    if (!preg_match('#^https://archive\.org/download/#i', (string) $b['DownloadUrl'])) {
        return false;
    }
    return (int) ($b['PdfSize'] ?? 0) <= READER_MAX_STREAM_BYTES;
}

function reader_fallback(array $b, string $root = '', string $why = ''): string
{
    $archive = (string) ($b['ReadUrl'] ?: '');
    $pdf     = (string) ($b['DownloadUrl'] ?: '');
    $slug    = book_slug((string) $b['PID']);

    $lead = $why === 'too-large'
        ? 'This scan is ' . size_fmt((int) ($b['PdfSize'] ?? 0))
          . ', which is too large to stream through the library. '
          . 'Read it on Archive.org, or download the PDF to keep.'
        : 'No PDF scan is attached to this item. '
          . 'Read it on Archive.org, or pick another format below.';

    $h = '<section class="reader-fallback">'
        . '<h2>Read this book</h2>'
        . '<p>' . e($lead) . '</p>'
        . '<p class="reader-fallback-actions">';

    if ($archive !== '') {
        $h .= '<a class="btn-read" href="' . e($archive)
            . '" target="_blank" rel="noopener nofollow">Read on Archive.org</a> ';
    }
    if ($pdf !== '') {
        $h .= '<a class="btn-dl" href="' . e($pdf) . '" download>Download PDF</a> ';
    }
    if (!empty($b['TextUrl'])) {
        $h .= '<a class="btn-ghost" href="' . e((string) $b['TextUrl'])
            . '" target="_blank" rel="noopener nofollow">Plain text</a> ';
    }
    if (!empty($b['EpubUrl'])) {
        $h .= '<a class="btn-ghost" href="' . e((string) $b['EpubUrl'])
            . '" download>EPUB</a> ';
    }

    $h .= '</p>'
        . '<p><a class="muted" href="' . e($root . 'books/' . $slug . '.html') . '">&larr; Back to book details</a></p>'
        . '</section>';

    return $h;
}

function reader_body(array $b, string $root = ''): string
{
    $pid  = (string) $b['PID'];
    $slug = book_slug($pid);

    $backUrl = $root . 'books/' . $slug . '.html';
    $archive = (string) ($b['ReadUrl'] ?: '');
    $text    = (string) ($b['TextUrl'] ?: '');
    $pdf     = (string) ($b['DownloadUrl'] ?: '');

    if (!reader_can_stream($b)) {
        $why = empty($b['DownloadUrl']) ? 'no-pdf' : 'too-large';
        return reader_fallback($b, $root, $why);
    }

    // Same-origin endpoint; serves the local cache when present, otherwise
    // streams the requested ranges from Archive.org.
    $streamUrl = '/api/stream.php?pid=' . rawurlencode($pid);
    $metaBits = [];
    if (!empty($b['Year'])) {
        $metaBits[] = (string) (int) $b['Year'];
    }
    if (!empty($b['Publisher'])) {
        $metaBits[] = (string) $b['Publisher'];
    }
    if (!empty($b['PdfSize'])) {
        $metaBits[] = size_fmt((int) $b['PdfSize']);
    }

    $cfg = json_encode([
        'file'    => $streamUrl,
        'probe'   => $streamUrl . '&probe=1',
        'dl'      => $streamUrl . '&dl=1',
        'archive' => $archive,
        'title'   => (string) $b['Title'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $h = '<section class="reader-shell">'
        . '<div class="reader-top">'
        . '<a class="btn-ghost" href="' . e($backUrl) . '">&larr; Details</a>'
        . '<div class="reader-tools" role="group" aria-label="Reader controls">'
        . '<button type="button" id="pgPrev" title="Previous page" aria-label="Previous page">&lsaquo;</button>'
        . '<label class="sr-only" for="pgNum">Page number</label>'
        . '<input id="pgNum" type="text" inputmode="numeric" value="1" size="4">'
        . '<span class="pg-total">/ <span id="pgCount">&mdash;</span></span>'
        . '<button type="button" id="pgNext" title="Next page" aria-label="Next page">&rsaquo;</button>'
        . '<span class="sep"></span>'
        . '<button type="button" id="zoomOut" title="Zoom out" aria-label="Zoom out">&minus;</button>'
        . '<span id="zoomVal" class="zoom-val">100%</span>'
        . '<button type="button" id="zoomIn" title="Zoom in" aria-label="Zoom in">+</button>'
        . '<button type="button" id="fitWidth" aria-label="Fit to width" title="Fit width">Fit</button>'
        . '<button type="button" id="fullscreen" aria-label="Toggle full screen" title="Full screen">Full</button>'
        . '</div>'
        . '<div class="reader-links">'
        . '<span id="srcTag" class="src-tag" title="Where the pages come from">checking&hellip;</span>'
        . '<a class="btn-dl" href="' . e($streamUrl) . '&amp;dl=1" download>Download PDF'
        . dl_size($b) . '</a>'
        . ($text !== '' ? '<a class="btn-ghost" href="' . e($text)
            . '" target="_blank" rel="noopener nofollow">Plain text</a>' : '')
        . ($archive !== '' ? '<a class="btn-ghost" href="' . e($archive)
            . '" target="_blank" rel="noopener nofollow">Archive.org</a>' : '')
        . '</div>'
        . '</div>'
        . '<p class="reader-meta">' . e((string) $b['Title'])
        . ($b['Author'] ? ' &middot; ' . e((string) $b['Author']) : '')
        . ($metaBits ? ' &middot; ' . e(implode(' · ', $metaBits)) : '')
        . '</p>'
        . '<div id="readerStatus" class="reader-status" role="status">Opening the book&hellip;</div>'
        . '<div id="readerStage" class="reader-stage" hidden>'
        . '<canvas id="pdfCanvas"></canvas>'
        . '</div>'
        // Page-turn bar. The top toolbar sits above the page and scrolls away, so
        // reading means reaching back up to turn the page; this stays put.
        . '<div class="reader-bottom" id="readerBottom" hidden>'
        . '<button type="button" class="pg-turn" id="pgPrevB" aria-label="Previous page">'
        . '<span aria-hidden="true">&larr;</span> Previous</button>'
        . '<span class="pg-turn-mid">Page '
        . '<label class="sr-only" for="pgNumB">Page number</label>'
        . '<input id="pgNumB" type="text" inputmode="numeric" value="1" size="4">'
        . ' of <span id="pgCountB">&mdash;</span></span>'
        . '<button type="button" class="pg-turn" id="pgNextB" aria-label="Next page">'
        . 'Next <span aria-hidden="true">&rarr;</span></button>'
        . '</div>'
        . '<noscript><p class="reader-status">This reader needs JavaScript. '
        . ($archive !== '' ? '<a href="' . e($archive)
            . '" target="_blank" rel="noopener nofollow">Read on Archive.org</a> instead.' : '')
        . '</p></noscript>'
        . '</section>';

    $h .= '<script type="module">'
        . 'import * as pdfjsLib from "' . $root . 'assets/vendor/pdfjs/pdf.min.mjs";'
        . 'pdfjsLib.GlobalWorkerOptions.workerSrc = "'
        . $root . 'assets/vendor/pdfjs/pdf.worker.min.mjs";'
        . 'const CFG = ' . $cfg . ';'
        . 'const $ = (id) => document.getElementById(id);'
        . 'const canvas = $("pdfCanvas"), stage = $("readerStage"), status = $("readerStatus");'
        . 'const numEl = $("pgNum"), cntEl = $("pgCount"), zoomEl = $("zoomVal"), srcTag = $("srcTag");'
        . 'const numElB = $("pgNumB"), cntElB = $("pgCountB"), bottom = $("readerBottom");'
        . 'let doc = null, page = 1, scale = 1, fit = 1, task = null, rendering = false;'
        . 'const ctx = canvas.getContext("2d", { alpha: false });'
        . 'const say = (t) => { status.textContent = t; };'
        . 'function fail(html) {'
        . '  stage.hidden = true; status.hidden = false;'
        . '  status.innerHTML = html;'
        . '}'
        . 'async function load() {'
        . '  try {'
        . '    srcTag.textContent = "checking";'
        . '    const pr = await fetch(CFG.probe, { cache: "no-store" }).then((r) => r.json());'
        . '    srcTag.textContent = pr.cached ? "on this device" : "streaming";'
        . '    srcTag.className = "src-tag " + (pr.cached ? "local" : "remote");'
        . '    srcTag.title = pr.cached'
        . '      ? "Reading the copy stored on this computer."'
        . '      : "Not downloaded yet - only the pages you turn to are fetched from Archive.org.";'
        . '    if (!pr.streamable) {'
        . '      fail("<p>This book cannot be streamed here.</p>");'
        . '      return;'
        . '    }'
        . '    say("Fetching the file…");'
        // Passing the known length up front is what makes PDF.js use byte-range
        // requests from the very first byte. Without it PDF.js fetches the whole
        // file before rendering anything, which for a 300 MB scan means a long
        // wait and a full download.
        . '    const t = pdfjsLib.getDocument({'
        . '      url: CFG.file,'
        . '      length: pr.size || undefined,'
        . '      rangeChunkSize: 65536,'
        . '      disableAutoFetch: true,'
        . '    });'
        . '    doc = await t.promise;'
        . '    cntEl.textContent = doc.numPages;'
        . '    cntElB.textContent = doc.numPages;'
        . '    numEl.max = doc.numPages;'
        . '    numElB.max = doc.numPages;'
        . '    stage.hidden = false;'
        . '    bottom.hidden = false;'
        . '    sync();'
        . '    await render();'
        . '    status.hidden = true;'
        . '  } catch (err) {'
        . '    fail("<p>The book could not be opened ("'
        . '      + (err && err.message ? err.message : err) + ").</p>"'
        . '      + (CFG.archive ? "<p><a href=\\"" + CFG.archive + "\\" target=\\"_blank\\"'
        . '        rel=\\"noopener nofollow\\">Read it on Archive.org instead</a></p>" : ""));'
        . '  }'
        . '}'
        . 'async function render() {'
        . '  if (!doc || rendering) return;'
        . '  rendering = true;'
        . '  try {'
        . '    if (task) { try { task.cancel(); } catch (x) {} }'
        . '    const p = await doc.getPage(page);'
        . '    const base = p.getViewport({ scale: 1 });'
        . '    fit = Math.max(320, stage.clientWidth - 24) / base.width;'
        . '    const vp = p.getViewport({ scale: scale * fit });'
        . '    const dpr = Math.min(window.devicePixelRatio || 1, 2);'
        . '    canvas.width = Math.floor(vp.width * dpr);'
        . '    canvas.height = Math.floor(vp.height * dpr);'
        . '    canvas.style.width = Math.floor(vp.width) + "px";'
        . '    canvas.style.height = Math.floor(vp.height) + "px";'
        . '    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);'
        . '    task = p.render({ canvasContext: ctx, viewport: vp });'
        . '    await task.promise;'
        . '    task = null;'
        . '    zoomEl.textContent = Math.round(scale * 100) + "%";'
        . '  } catch (err) {'
        . '    if (err && err.name === "RenderingCancelledException") return;'
        . '    say("Page " + page + " failed: " + (err && err.message ? err.message : err));'
        . '  } finally { rendering = false; }'
        . '}'
        . 'function go(n) {'
        . '  if (!doc) return;'
        . '  const next = Math.max(1, Math.min(doc.numPages, n));'
        . '  if (next === page) return;'
        . '  page = next; numEl.value = page; numElB.value = page; sync(); render();'
        . '}'
        // Both toolbars show the same page, so they have to be kept in step and
        // the end-of-book buttons have to disable rather than silently no-op.
        . 'function sync() {'
        . '  numEl.value = page; numElB.value = page;'
        . '  const first = page <= 1, last = !doc || page >= doc.numPages;'
        . '  $("pgPrev").disabled = first; $("pgPrevB").disabled = first;'
        . '  $("pgNext").disabled = last; $("pgNextB").disabled = last;'
        . '}'
        . '$("pgPrev").onclick = () => go(page - 1);'
        . '$("pgNext").onclick = () => go(page + 1);'
        . '$("pgPrevB").onclick = () => go(page - 1);'
        . '$("pgNextB").onclick = () => go(page + 1);'
        . 'numEl.onchange = () => go(parseInt(numEl.value, 10) || 1);'
        . 'numElB.onchange = () => go(parseInt(numElB.value, 10) || 1);'
        . '$("zoomIn").onclick  = () => { scale = Math.min(4, scale * 1.25); render(); };'
        . '$("zoomOut").onclick = () => { scale = Math.max(0.3, scale / 1.25); render(); };'
        . '$("fitWidth").onclick = () => { scale = 1; render(); };'
        . '$("fullscreen").onclick = () => {'
        . '  const el = document.querySelector(".reader-shell");'
        . '  if (document.fullscreenElement) document.exitFullscreen();'
        . '  else if (el && el.requestFullscreen) el.requestFullscreen();'
        . '};'
        . 'document.addEventListener("keydown", (ev) => {'
        . '  if (ev.target.tagName === "INPUT") return;'
        . '  if (ev.key === "ArrowLeft" || ev.key === "PageUp") { ev.preventDefault(); go(page - 1); }'
        . '  if (ev.key === "ArrowRight" || ev.key === "PageDown" || ev.key === " ") { ev.preventDefault(); go(page + 1); }'
        . '  if (ev.key === "Home") { ev.preventDefault(); go(1); }'
        . '  if (ev.key === "End")  { ev.preventDefault(); go(doc ? doc.numPages : 1); }'
        . '});'
        // Arrow keys can walk off the end of the book without touching go()'s
        // early return, so re-sync after every keypress too.
        . 'document.addEventListener("keyup", sync);'
        . 'let rt;'
        . 'window.addEventListener("resize", () => { clearTimeout(rt); rt = setTimeout(render, 250); });'
        . 'load();'
        . '</script>';

    return $h;
}
