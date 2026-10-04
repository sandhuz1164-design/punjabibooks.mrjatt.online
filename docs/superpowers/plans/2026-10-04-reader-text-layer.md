# Reader Text Layer and Find-in-Book Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a reader select and copy text from a page, and search inside a book by keyword, with page-accurate jump-to-match.

**Architecture:** Text comes from the PDF byte stream the reader already fetches — `page.getTextContent()` — never from a second Archive.org download. A page-text index (`Map<pageNo, string>`) is built lazily in the background and backs the search box; matches carry the text-item transforms from `getTextContent()`, so a hit can be highlighted in place using absolute positioning derived from the same viewport the canvas uses.

**Tech Stack:** PDF.js 4.6.82 (already vendored, `assets-src/vendor/pdfjs/pdf.min.mjs`), PHP 8 for page generation, vanilla browser JS, Playwright via `tools/browser.mjs` for tests. No new dependencies.

**Spec:** none — this plan is the specification. It was written from an investigation of the vendored PDF.js build and the current reader, and two of its decisions exist only because that investigation contradicted the obvious approach (see Global Constraints).

## Global Constraints

- **No new dependencies.** PDF.js stays at the vendored 4.6.82. Do not fetch `pdf_viewer.css` from the network; vendor a trimmed ruleset.
- **Never download a whole book.** All text derives from the byte stream already in play via `getDocument({ disableAutoFetch: true })`. No new Archive.org endpoint.
- **Text search must not use `djvu.txt`.** Verified: Archive.org's `*_djvu.txt` contains **zero form feed characters**, so it carries no page boundaries and a match cannot be located. `TextUrl` stays in the database but is not a search source. 5,594 of 5,709 rows have one, which is why this had to be checked rather than assumed.
- **Archive.org is never fetched from the browser.** Existing same-origin `/api/stream.php` only. Text must not add a cross-origin request.
- **Reader chrome stays as built:** site header and footer remain hidden on reader pages (`body_class => 'is-reader'`), desktop toolbar stays ~50px, and the bottom Previous/Next bar stays. New controls go inside the existing toolbar, not as a new bar.
- **Gurmukhi text must round-trip byte-for-byte.** No `normalize()`, no case folding beyond ASCII, no NFC/NFKC. Search is exact-substring unless a test says otherwise.
- **Zero new console errors and zero horizontal overflow at 320px** after every task.
- **Commits:** one per task, message from that task's final step.

## Review Focus

Each line is an input class the happy-path tests do not reach. Each already has an owning task below.

1. **A scan with no embedded text layer** (`getTextContent()` yields zero items on every page). Find must say the text is unavailable, never "0 results". → Task 5
2. **A 300+ page book.** Indexing must not block first paint, and must not fire 300 concurrent `getTextContent()` calls at Archive.org. → Task 2
3. **A Gurmukhi search term.** `ਸਿੱਖ` must match `ਸਿੱਖ` and must not be normalised into something else; a Latin-digit mix like `ਅੰਕ 5` must survive. → Task 3
4. **A term split across a line break**, so it is two text items on the page and never appears inside one `item.str`. → Task 3
5. **A blank or illustration-only page** with zero text items partway through the book. Must not abort the index or corrupt the match count. → Task 2

## File Map

| File | Responsibility |
|---|---|
| `assets-src/vendor/pdfjs/textlayer.css` | Trimmed `.textLayer` ruleset (new, ~40 lines) |
| `assets-src/css/reader-find.css` | Find box, match counter, highlight styles (new) |
| `build/reader.php` | Toolbar markup + the reader module script (modify) |
| `build/reader-index.js` | Text extraction, index, matcher, highlighter (new, loaded as a module) |
| `tools/browser.mjs` | Single place that resolves Playwright (new) |
| `tools/t/reader-text.test.mjs` | Tests for Tasks 1-5 (new) |

`build/reader-index.js` is a separate file rather than more inline PHP because the logic in Tasks 2-4 is the only genuinely testable JS in the project; keeping it out of a PHP string makes it importable by the test file. The reader page loads it with a relative `import`.

---

## Task 1: Browser harness and text selection

**Files:**
- Create: `tools/browser.mjs`
- Create: `assets-src/vendor/pdfjs/textlayer.css`
- Modify: `build/reader.php` (toolbar markup ~line 130-152; script block ~160-282; add `<div class="textLayer">` inside `#readerStage`)
- Modify: `assets-src/css/style.css` (stage positioning so the layer overlays the canvas)
- Test: `tools/t/reader-text.test.mjs`

**Interfaces:**
- Consumes: nothing (first task).
- Produces:
  - `tools/browser.mjs` exporting `chromium` and `READER_URL`. Resolution order: `$PLAYWRIGHT_PATH`, then `playwright` from node_modules, then the absolute path currently hardcoded in `tools/mobile-preview.mjs`. One place, so no other file names an ntfs3 path.
  - `build/reader-index.js` does **not** exist yet; this task inlines nothing reusable, it only adds selection.
  - The stage gains exactly one child `<div id="pdfTextLayer" class="textLayer">`, an absolutely-positioned sibling of `#pdfCanvas`.

- [ ] **Step 1: Write the failing test**

In `tools/t/reader-text.test.mjs`:

```js
// opens CFG.file for PID "HarpalSinghPannu-7KUFixXT" (68 pages, has a text layer),
// waits for #pdfTextLayer to contain at least one <span>, then selects the text
// via a Range over the layer's text nodes and returns window.getSelection().toString().
// Assert the string is longer than 40 characters after trimming and collapsing whitespace.
```

Also assert `#readerStage` computed `position` is not `static`, since the layer needs a positioned ancestor.

- [ ] **Step 2: Run the test to verify it fails**

Run: `node tools/t/reader-text.test.mjs selection`
Expected: FAIL — `#pdfTextLayer` does not exist, so `waitForSelector` times out.

- [ ] **Step 3: Vendor the trimmed text-layer CSS**

Create `assets-src/vendor/pdfjs/textlayer.css` containing the `.textLayer` rules that PDF.js 4.x requires for selection to work: the container gets `position: absolute; inset: 0; overflow: clip; line-height: 1; transform-origin: 0 0;` and `span, br` get `position: absolute; color: transparent; white-space: pre; cursor: text; transform-origin: 0% 0%;`, with `::selection` background rules. Omit everything from `pdf_viewer.css` about annotations, thumbnails, toolbars and presentation.

- [ ] **Step 4: Add the layer element and link the CSS**

In `build/reader.php`, inside `#readerStage`, add `<div id="pdfTextLayer" class="textLayer"></div>` next to `#pdfCanvas`. Add `textlayer.css` to the page head, and set `#readerStage { position: relative; }` in `assets-src/css/style.css`.

- [ ] **Step 5: Render the text layer after the canvas**

In `render()`, after `await task.promise`, replace the layer's contents and render PDF.js's own text layer, cancelling any in-flight layer render alongside `task.cancel()`:

```js
const layer = new pdfjsLib.TextLayer({
  textContentSource: p.streamTextContent({ includeMarkedContent: true }),
  container: $("pdfTextLayer"),
  viewport: vp,
});
await layer.render();
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `node tools/t/reader-text.test.mjs selection`
Expected: PASS — selection string > 40 chars, stage is positioned.

- [ ] **Step 7: Commit**

```bash
git add tools/browser.mjs tools/t/reader-text.test.mjs assets-src/vendor/pdfjs/textlayer.css assets-src/css/style.css build/reader.php
git commit -m "feat(reader): selectable text layer"
```

---

## Task 2: Page-text index

**Files:**
- Create: `build/reader-index.js`
- Modify: `build/reader.php` (import the module, kick off indexing after `doc` loads)
- Test: `tools/t/reader-text.test.mjs`

**Interfaces:**
- Consumes: `tools/browser.mjs`; the stage/canvas from Task 1.
- Produces, exported from `build/reader-index.js`:
  - `buildIndex(doc, { onProgress, signal })` → `Promise<Map<number, PageText>>`, keyed by 1-based page number.
  - `PageText` is `{ text: string, items: Array<{ str: string, transform: number[] }> }`. The `items` array is part of the shape from the start, not an amendment later: Task 3 needs item boundaries to search across them (Review Focus #4) and Task 4 needs `transform` to position highlights. `text` is the items' `str` joined by a single space.
  - A page with no text items maps to `{ text: "", items: [] }`, never `undefined` — Review Focus #5.
  - `indexedPages(map)` → `number` — how many pages have been walked so far.
  - `pageText(map, n)` → `string`, `""` when out of range.

- [ ] **Step 1: Write the failing test**

```js
// After the reader settles, evaluate in page context:
//   const m = await window.__readerIndex;
// assert m instanceof Map
// assert m.size === await window.__readerDoc.numPages   // every page keyed
// assert indexedPages(m) === numPages                   // progress reaches the end
// assert (await pageText(m, 1)).length > 0               // page 1 has text
```

Then assert the reader canvas became interactive within 2s of load — i.e. indexing did not block first paint.

- [ ] **Step 2: Run the test to verify it fails**

Run: `node tools/t/reader-text.test.mjs index`
Expected: FAIL — `window.__readerIndex` is undefined.

- [ ] **Step 3: Implement `buildIndex`**

In `build/reader-index.js`, walk pages 1..`doc.numPages`, awaiting `doc.getPage(n)` then `(await p.getTextContent()).items.map(i => i.str).join(" ")`, storing `""` for a page whose items array is empty. Serialize the loop: never more than one `getPage` in flight, and `await` the previous iteration's text extraction before requesting the next page. This is the fix for Review Focus #2 and #5 — a naive `Promise.all` over 300 pages would stall the reader and hammer Archive.org.

- [ ] **Step 4: Wire it up and expose it for tests**

In `build/reader.php`, `import { buildIndex, indexedPages, pageText } from '<root>reader-index.js'` and start indexing after `doc = await t.promise;`, without awaiting it. Assign `window.__readerIndex`, `window.__readerDoc`, and the three functions for the test to read.

- [ ] **Step 5: Run the test to verify it passes**

Run: `node tools/t/reader-text.test.mjs index`
Expected: PASS — map sized to `numPages`, progress complete, page 1 non-empty, first paint not blocked.

- [ ] **Step 6: Commit**

```bash
git add build/reader-index.js build/reader.php tools/t/reader-text.test.mjs
git commit -m "feat(reader): lazy per-page text index"
```

---

## Task 3: Find-in-book

**Files:**
- Modify: `build/reader.php` (find controls in the existing toolbar)
- Create: `assets-src/css/reader-find.css`
- Modify: `build/reader-index.js` (add the matcher)
- Test: `tools/t/reader-text.test.mjs`

**Interfaces:**
- Consumes: `pageText(map, n)`, `indexedPages(map)` from Task 2.
- Produces, added to `build/reader-index.js`:
  - `findAll(map, term, numPages)` → `Array<{page: number, index: number, length: number}>` sorted by page then index. Empty term → `[]`.
  - Searches `PageText.text`, which is the space-joined `items`, so a term spanning two items matches here. Its character offsets then address the joined string; Task 4 maps an offset back onto item indices via the same join rule.
  - Toolbar gains: `#findInput` (search field), `#findCount` (live region reading "N of M"), `#findPrev`, `#findNext`, `#findClear`. No new bar; these sit in the existing toolbar.

- [ ] **Step 1: Write the failing test**

```js
// Type a word that appears on page 1 of the test book into #findInput.
// assert #findCount matches /1 of [1-9]\d*/
// assert the current page indicator reads 1
// click #findNext; assert the page changed to the page of match 2
```

Three cases, all asserted:
- a plain ASCII word matches;
- a Gurmukhi term (`ਸਿੱਖ`) matches and `findAll` returns a hit on a page whose text contains it — guards Review Focus #3;
- a term built from the last word of one line and the first word of the next is found by `findAll` — guards Review Focus #4. Derive it from the index itself rather than hardcoding, so the test cannot rot.

- [ ] **Step 2: Run the test to verify it fails**

Run: `node tools/t/reader-text.test.mjs find`
Expected: FAIL — `#findInput` does not exist.

- [ ] **Step 3: Implement `findAll` and the controls**

`findAll` walks pages 1..numPages and pushes a hit per occurrence in `page.text`. The `#findCount` element is `aria-live="polite"` and reads `N of M`, or `No matches` at zero. Enter and Shift+Enter move between matches; `#findPrev`/`#findNext` wrap. Selecting a match calls the existing `go(page)` — do not add a second page-setting path.

- [ ] **Step 4: Run the test to verify it passes**

Run: `node tools/t/reader-text.test.mjs find`
Expected: PASS — all three cases.

- [ ] **Step 5: Commit**

```bash
git add build/reader.php build/reader-index.js assets-src/css/reader-find.css tools/t/reader-text.test.mjs
git commit -m "feat(reader): find in book with match count"
```

---

## Task 4: Highlight the match

**Files:**
- Modify: `build/reader-index.js` (locate hits within `items`)
- Modify: `assets-src/css/reader-find.css`
- Modify: `build/reader.php` (clear highlights on render)
- Test: `tools/t/reader-text.test.mjs`

**Interfaces:**
- Consumes: `findAll` and the per-page `items` array from Tasks 2-3; `vp` from `render()`.
- Produces:
  - `locateItems(items, index, term)` → `Array<number>` — indices of the items covered by the hit starting at character offset `index`, handling a term that begins mid-item and ends mid-item.
  - `.hl` spans injected into `#pdfTextLayer`, positioned from each item's `transform` scaled by the viewport, matching how PDF.js positioned the layer.

- [ ] **Step 1: Write the failing test**

```js
// Select the second match, assert document.querySelectorAll('#pdfTextLayer .hl').length > 0
// and assert the highlight's bounding box lies inside the canvas bounding box.
// Then turn the page: assert no .hl remains.
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `node tools/t/reader-text.test.mjs highlight`
Expected: FAIL — zero `.hl` elements.

- [ ] **Step 3: Implement `locateItems` and the highlight pass**

Walk `items` accumulating `str.length` (plus the single joining space between items) to find which items a hit spans. For each, create `<span class="hl">` inside that item's existing span and position it with the item's `transform` scaled to CSS pixels. Wrapping rather than re-rendering avoids fighting PDF.js's own transform maths.

- [ ] **Step 4: Clear highlights on every render**

In `render()`, remove existing `.hl` nodes before drawing the new text layer, so zoom and page turns cannot leave stale highlights.

- [ ] **Step 5: Run the test to verify it passes**

Run: `node tools/t/reader-text.test.mjs highlight`
Expected: PASS — highlights present and within the canvas, cleared on page turn.

- [ ] **Step 6: Commit**

```bash
git add build/reader-index.js build/reader.php assets-src/css/reader-find.css tools/t/reader-text.test.mjs
git commit -m "feat(reader): highlight the active match"
```

---

## Task 5: No-text-layer fallback

**Files:**
- Modify: `build/reader.php` (notice element + wiring)
- Modify: `assets-src/css/reader-find.css`
- Test: `tools/t/reader-text.test.mjs`

**Interfaces:**
- Consumes: `indexedPages` from Task 2; `CFG.archive` already present on the reader page.
- Produces: `#findNone` notice element. When the index completes and every page mapped to `""`, the find box is disabled, `#findNone` is shown reading that this scan has no searchable text and that Archive.org's copy is linked via `CFG.archive`. When at least one page has text, the box stays enabled and `#findNone` stays hidden — including mid-index, where the box must remain usable rather than flashing a false "no text".

- [ ] **Step 1: Write the failing test**

Two cases against books chosen for their properties, looked up from the database rather than hardcoded:
- a `Pid` whose PDF has a text layer: after indexing, `#findInput` is **not** disabled and `#findNone` is hidden. Guards the false-positive direction.
- a `Pid` whose PDF has no text layer: after indexing, `#findInput` is disabled and `#findNone` is visible and its text matches `/no searchable text/i`, and the notice contains an Archive.org link. Assert the count element does **not** read `0 of 0` — the "silently reports zero results" failure this task exists to prevent.

- [ ] **Step 2: Run the test to verify it fails**

Run: `node tools/t/reader-text.test.mjs fallback`
Expected: FAIL — no `#findNone` element exists.

- [ ] **Step 3: Implement the fallback**

After indexing completes, evaluate `indexedPages(map)` against a count of pages with non-empty text. Only when that count is zero, disable the box and reveal `#findNone`. Use a dedicated `#indexing` hint for the in-progress state so "still working" is never rendered as "nothing found".

- [ ] **Step 4: Run the test to verify it passes**

Run: `node tools/t/reader-text.test.mjs fallback`
Expected: PASS — both cases.

- [ ] **Step 5: Full regression, mobile, and commit**

Run: `node tools/t/reader-text.test.mjs`
Expected: PASS on all five groups. Then confirm no console errors and no horizontal overflow at 320px using `tools/mobile-preview.mjs`, and confirm `php build/linkcheck.php` still reports `broken: 0`.

```bash
git add build/reader.php assets-src/css/reader-find.css tools/t/reader-text.test.mjs
git commit -m "feat(reader): explain scans that have no searchable text"
```
