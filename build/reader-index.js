// Per-page text extraction for the reader.
//
// Why this reads the PDF and not Archive.org's *_djvu.txt: measured on
// 25665157-Brahmin-Sikh-History_djvu.txt (1,625,020 bytes), that file contains
// zero form-feed characters, so it carries no page boundaries and a match in it
// cannot be located on a page. getTextContent() gives us the text *and* the page
// it came from in one step, from the byte range the reader is already fetching,
// so search adds no second download and no new endpoint.
//
// The items array is kept, not just the joined string, because two later needs
// depend on it: a term can span two items (it exists in the joined text but in
// no single item), and highlighting needs each item's transform.

/** Join rule used everywhere an offset must map back onto items. */
export const JOIN = ' '

/**
 * Walk every page and extract its text.
 *
 * Serialised on purpose: one getPage in flight at a time. A Promise.all over a
 * 300-page book would open 300 simultaneous range requests against Archive.org
 * and stall the reader behind them.
 */
export async function buildIndex(doc, { onPage, signal } = {}) {
  const map = new Map()
  const total = doc.numPages

  for (let n = 1; n <= total; n++) {
    if (signal && signal.aborted) break
    let items = []
    let ok = true
    try {
      const page = await doc.getPage(n)
      const content = await page.getTextContent()
      items = (content.items || [])
        .filter((it) => it.str !== undefined && it.str !== null)
        .map((it) => ({ str: it.str, transform: it.transform }))
    } catch {
      // A page that will not parse is a blank page as far as search is
      // concerned. It must not abort the walk - a single unreadable page in a
      // 300-page scan must not cost the reader the other 299.
      items = []
      ok = false
    }
    // Every item PDF.js reports is kept, including '' and ' ' ones, so index i
    // is always item i - which is what textDivs and the DOM spans are indexed
    // by. Dropping empties here silently shifted every later index and put
    // highlights on the wrong word.
    //
    // The cost is that the joined text gains runs of spaces wherever PDF.js
    // emitted an explicit space item. That is cosmetic: search and the
    // offset arithmetic are unaffected, and they are what depend on the
    // alignment.
    map.set(n, { text: items.map((i) => i.str).join(JOIN), items })
    // Distinguishes "this page has no text" from "this page could not be read",
    // which is what lets the reader be honest about partial coverage.
    if (onPage) onPage(n, ok)
    // Yield so indexing never blocks a page turn or a keystroke.
    if (n % 8 === 0) await new Promise((r) => setTimeout(r, 0))
  }

  return map
}

/** How many pages have been walked. */
export function indexedPages(map) {
  return map.size
}

/** Page text, or '' when out of range. Never undefined. */
export function pageText(map, n) {
  const entry = map.get(n)
  return entry ? entry.text : ''
}

/**
 * Which items a hit covers, and the character range within each.
 *
 * The offsets matter: a hit usually covers only part of an item. On one measured
 * book, 78% of page-1 characters sat in items of 30+ characters, so highlighting
 * whole items over-marks by ~5x. Returning `{i, start, end}` lets the caller
 * measure just the matched substring.
 *
 * The offset can land mid-item, so the first and last items are included even
 * when the hit starts or ends inside them. Returns [] when nothing overlaps.
 */
export function locateItems(items, index, length) {
  const spans = []
  let acc = 0
  for (let i = 0; i < items.length; i++) {
    const start = acc
    const end = acc + items[i].str.length
    // Half-open [start, end) joined by a single space between items.
    if (end > index && start < index + length) {
      const from = Math.max(0, index - start)
      const to = Math.min(items[i].str.length, index + length - start)
      spans.push({ i, start: from, end: to })
    }
    acc = end + JOIN.length
  }
  return spans
}

/**
 * Every occurrence of `term`, as {page, index, length}, sorted by page then
 * position. An empty term matches nothing rather than every position.
 *
 * The term is matched against the space-joined page text, so a phrase split
 * across two text items still matches. Offsets address that joined string.
 *
 * No case folding and no Unicode normalisation: the corpus is Gurmukhi and
 * folding would both miss real variants and invent matches that are not there.
 * Callers wanting a case-insensitive search pass a lowercased term.
 */
export function findAll(map, term, numPages) {
  if (!term) return []
  const hits = []
  const limit = Math.min(numPages ?? map.size, map.size)
  for (let n = 1; n <= limit; n++) {
    const text = pageText(map, n)
    if (!text) continue
    let from = text.indexOf(term)
    while (from !== -1) {
      hits.push({ page: n, index: from, length: term.length })
      from = text.indexOf(term, from + term.length)
    }
  }
  return hits
}
