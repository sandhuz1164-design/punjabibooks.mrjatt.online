// Unit tests for build/reader-index.js - no browser, no network.
//
// The plan created this file as a separate module precisely because it is the
// only testable JS in the project, then every test drove it through window.* in
// a real browser over a real PDF. That covered the happy path and left the
// awkward parts unguarded: blank pages, pages that fail to parse, aborts, and
// every locateItems boundary. All of it is reachable here with a stub doc.
//
//   node tools/t/reader-index.test.mjs

import { buildIndex, findAll, locateItems, pageText, indexedPages, JOIN } from '../../build/reader-index.js'

let pass = 0, fail = 0
const failures = []

function eq(got, want, label) {
  const same = Object.is(got, want) || JSON.stringify(got) === JSON.stringify(want)
  if (same) { pass++; console.log(`  ok   ${label}`) }
  else {
    fail++; failures.push(label)
    console.log(`  FAIL ${label} -> got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`)
  }
}
function ok(cond, label, detail) {
  if (cond) { pass++; console.log(`  ok   ${label}`) }
  else { fail++; failures.push(label); console.log(`  FAIL ${label}${detail ? ` -> ${detail}` : ''}`) }
}

/** A doc whose pages have the given items; a null entry makes the page throw. */
function stubDoc(pages) {
  return {
    numPages: pages.length,
    async getPage(n) {
      const items = pages[n - 1]
      if (items === null) throw new Error('page ' + n + ' is damaged')
      return { async getTextContent() { return { items } } }
    },
  }
}

const item = (str) => ({ str, transform: [1, 0, 0, 1, 0, 0] })

// --------------------------------------------------------------- buildIndex

console.log('\nbuildIndex')
{
  const map = await buildIndex(stubDoc([[item('ਪਹਿਲਾ'), item(' '), item('ਦੂਜਾ')]]))
  eq(indexedPages(map), 1, 'index has one entry per page')
  // Every item PDF.js reports is kept, so index i is item i - the invariant the
  // DOM span mapping depends on. That means an explicit ' ' item contributes a
  // second space on top of the join separator. Cosmetic, and asserted so a
  // future "tidy this up" change cannot break the alignment silently.
  eq(pageText(map, 1), 'ਪਹਿਲਾ   ਦੂਜਾ', 'items are joined with a space, spaces included')
  eq(map.get(1).items.length, 3, 'every item is kept, so indices align with PDF.js')
  eq(pageText(map, 99), '', 'out-of-range page is an empty string, not undefined')
}

{
  // Review Focus #5: a blank page mid-book must not abort the walk or corrupt
  // the count for its neighbours.
  const map = await buildIndex(stubDoc([[item('one')], [], [item('three')]]))
  eq(indexedPages(map), 3, 'a blank page does not abort the walk')
  eq(pageText(map, 2), '', 'the blank page is recorded as empty text')
  eq(pageText(map, 3), 'three', 'pages after a blank page are still indexed')
}

{
  // A page that throws is a blank page as far as search goes, and onPage must
  // report it as unreadable so the reader can say "40 of 380 pages unreadable"
  // rather than implying the whole book was searched.
  const seen = []
  const map = await buildIndex(
    stubDoc([[item('one')], null, [item('three')]]),
    { onPage: (n, ok) => seen.push([n, ok]) },
  )
  eq(indexedPages(map), 3, 'a page that throws does not abort the walk')
  eq(seen, [[1, true], [2, false], [3, true]], 'onPage reports the failure per page')
}

{
  const ctrl = { aborted: false }
  const map = await buildIndex(stubDoc([[item('a')], [item('b')], [item('c')]]), { signal: ctrl })
  eq(indexedPages(map), 3, 'a full walk visits every page')
  ctrl.aborted = true
  const partial = await buildIndex(stubDoc([[item('a')], [item('b')], [item('c')]]), { signal: ctrl })
  eq(indexedPages(partial), 0, 'an already-aborted signal stops before the first page')
}

{
  // Review Focus #2: indexing must not open requests in parallel. The stub
  // records the high-water mark of concurrent getPage calls.
  let inFlight = 0, peak = 0
  const doc = {
    numPages: 12,
    async getPage() {
      peak = Math.max(peak, ++inFlight)
      await new Promise((r) => setTimeout(r, 1))
      inFlight--
      return { async getTextContent() { return { items: [item('x')] } } }
    },
  }
  await buildIndex(doc)
  eq(peak, 1, 'only one page is fetched at a time')
}

// ----------------------------------------------------------------- findAll

console.log('\nfindAll')
{
  const map = await buildIndex(stubDoc([
    [item('ਪਹਿਲਾ ਸਿੰਘ'), item(' '), item('ਦੂਜਾ')],
    [item('ਤੀਜਾ ਸਿੰਘ ਸਿੰਘ')],
  ]))
  const hits = findAll(map, 'ਸਿੰਘ', 2)
  eq(hits.length, 3, 'every occurrence is found across pages')
  eq(hits.map((h) => h.page), [1, 2, 2], 'hits are ordered by page')
  eq(findAll(map, '', 2), [], 'an empty term matches nothing, not every position')
  eq(findAll(map, 'ਸਿੰਘ', 1).length, 1, 'numPages clamps the search')
  eq(findAll(map, 'ਸਿੰਘ', 99).length, 3, 'a numPages beyond the index is harmless')
  // Derived, not hardcoded: Gurmukhi matras are separate code points, so
  // 'ਪਹਿਲਾ' is 5 units and hardcoding an offset was a trap.
  const want0 = pageText(map, 1).indexOf('ਸਿੰਘ')
  eq(hits[0], { page: 1, index: want0, length: 'ਸਿੰਘ'.length },
    'offsets address the joined text')
  eq(pageText(map, 1).slice(hits[0].index, hits[0].index + hits[0].length), 'ਸਿੰਘ',
    'the first hit offset slices out exactly the term')
  ok(hits.every((h) => pageText(map, h.page).indexOf('ਸਿੰਘ', h.index) === h.index),
    'each hit offset actually lands on the term')
  eq(findAll(map, 'ਲੱਭਦਾ', 2), [], 'a term that is not in the book returns nothing')
}

{
  // Gurmukhi must round-trip with no normalisation. Review Focus #3.
  const map = await buildIndex(stubDoc([[item('ਸਿੱਖ ਸਿੱਖੀ')], [item('ਅੰਕ 5')]]))
  // Substring semantics: 'ਸਿੱਖ' is a prefix of 'ਸਿੱਖੀ', so it matches twice. That is
  // what a reader typing a prefix wants, and it is why the plan forbids
  // normalisation - folding would also match spellings that are not in the book.
  eq(findAll(map, 'ਸਿੰਘ', 2), [], 'a different letter does not match')
  eq(findAll(map, 'ਸਿੱਖੀ', 2).length, 1, 'the longer spelling matches its own form')
  eq(findAll(map, 'ਅੰਕ 5', 2).length, 1, 'a Gurmukhi-plus-digit term matches')
  eq(findAll(map, 'ਸਿੱਖ ', 2).length, 1, 'a term including the trailing space matches exactly once')
}

// -------------------------------------------------------------- locateItems

console.log('\nlocateItems')
{
  const items = [item('ਪਹਿਲਾ'), item(' '), item('ਦੂਜਾ')]
  const joined = items.map((i) => i.str).join(JOIN)

  eq(locateItems(items, joined.indexOf('ਪਹਿਲਾ'), 5), [{ i: 0, start: 0, end: 5 }],
    'a whole-item match returns the whole item')
  eq(locateItems(items, 2, 3), [{ i: 0, start: 2, end: 5 }],
    'a partial match returns only the covered range')
  eq(locateItems(items, joined.indexOf('ਦੂਜਾ'), 4), [{ i: 2, start: 0, end: 4 }],
    'the last item is found across the join space')
  eq(locateItems(items, 4, 4), [{ i: 0, start: 4, end: 5 }, { i: 1, start: 0, end: 1 }],
    'a match spanning two items returns both, with correct ranges')
  eq(locateItems(items, joined.length, 3), [], 'a match past the end returns nothing')
  eq(locateItems(items, joined.length + 50, 3), [], 'a match far past the end returns nothing')
  eq(locateItems(items, 0, 0), [], 'a zero-length match returns nothing')

  // The returned ranges must slice back to the searched text, which is the whole
  // point of carrying offsets rather than item indices.
  // Rejoining the slices with a separator would not reproduce the original span,
  // because the separators between two *covered* items are already inside the
  // whitespace item rather than added again. Assert the slices themselves, and
  // that the covered item indices ascend.
  const multi = locateItems(items, 4, 4)
  eq(multi.map((m) => items[m.i].str.slice(m.start, m.end)), ['ਾ', ' '],
    'each returned range slices its own item correctly')
  ok(multi.every((m, k) => k === 0 || m.i > multi[k - 1].i),
    'covered item indices ascend')

  // One long item: the case that over-marked by ~5x.
  const long = [item('x'.repeat(80))]
  const part = locateItems(long, 10, 5)
  eq(part, [{ i: 0, start: 10, end: 15 }], 'a long item yields only the covered range')
  ok(part[0].end - part[0].start === 5, 'the range is the term width, not the item width')
}

console.log(`\n${pass} passed, ${fail} failed`)
if (fail) {
  console.log('failed: ' + failures.join('; '))
  process.exit(1)
}
