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

export function pageTextOf(pageText) {
  return (pageText.items || []).map((i) => i.str).join(JOIN)
}

/**
 * Walk every page and extract its text.
 *
 * Serialised on purpose: one getPage in flight at a time. A Promise.all over a
 * 300-page book would open 300 simultaneous range requests against Archive.org
 * and stall the reader behind them.
 */
export async function buildIndex(doc, { onProgress, signal } = {}) {
  const map = new Map()
  const total = doc.numPages

  for (let n = 1; n <= total; n++) {
    if (signal && signal.aborted) break
    let items = []
    try {
      const page = await doc.getPage(n)
      const content = await page.getTextContent()
      items = (content.items || [])
        .filter((it) => it.str !== undefined && it.str !== null && it.str !== '')
        .map((it) => ({ str: it.str, transform: it.transform }))
    } catch {
      // A page that will not parse is a blank page as far as search is
      // concerned. It must not abort the walk - a single unreadable page in a
      // 300-page scan must not cost the reader the other 299.
      items = []
    }
    map.set(n, { text: items.map((i) => i.str).join(JOIN), items })
    if (onProgress) onProgress(n, total)
    // Yield so indexing never blocks a page turn or a keystroke.
    if (n % 8 === 0) await new Promise((r) => setTimeout(r, 0))
  }

  return map
}

/** How many pages have been walked. */
export function indexedPages(map) {
  return map.size
}

/** How many pages actually carry text - a scan with none reports 0. */
export function pagesWithText(map) {
  let n = 0
  for (const [, v] of map) if ((v.text || '').trim()) n++
  return n
}

/** Page text, or '' when out of range. Never undefined. */
export function pageText(map, n) {
  const entry = map.get(n)
  return entry ? entry.text : ''
}
