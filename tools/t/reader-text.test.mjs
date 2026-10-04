// Tests for the reader's text layer, text index, find-in-book and highlighting.
//
//   node tools/t/reader-text.test.mjs            all groups
//   node tools/t/reader-text.test.mjs selection  one group
//
// Groups map to the plan's tasks. Each opens a real reader page in Chromium and
// asserts on what the reader actually rendered - no mocks, because the thing
// being tested (PDF.js text extraction, CSS positioning) only misbehaves in a
// real layout engine.

import { chromium, READER_URL, BASE, require } from '../browser.mjs'

// Text-layer quality varies wildly between Archive.org scans, so the fixture is
// chosen for what its PDF actually extracts, not for convenience:
//
//   punjabi-vyakaran-te-rachnawali  clean Gurmukhi, 62 Gurmukhi chars on page 1
//   HarpalSinghPannu-7KUFixXT       68pp, extracts mojibake (bytes, not glyphs)
//   MahanKosh-VolumeOne             English running head only, 674 pages
//
// The first is the searchable-text fixture. The second was the original choice
// and was wrong: its "no Gurmukhi run in this fixture" skip was hiding a real
// defect rather than an absent test case.
const TEXT_PID = 'punjabi-vyakaran-te-rachnawali'

// Extracts text, but as mojibake: the PDF's font has no usable ToUnicode map.
// Search across it must still work (the bytes are consistent and searchable),
// which is what GURMUKHI_UNRELIABLE_PID pins.
const MOJIBAKE_PID = 'HarpalSinghPannu-7KUFixXT'

let pass = 0
let fail = 0
const failures = []

function ok(cond, label, detail) {
  if (cond) {
    pass++
    console.log(`  ok   ${label}`)
  } else {
    fail++
    failures.push(label)
    console.log(`  FAIL ${label}${detail !== undefined ? ` -> ${detail}` : ''}`)
  }
}

function eq(got, want, label) {
  ok(Object.is(got, want) || JSON.stringify(got) === JSON.stringify(want), label,
    `got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`)
}

/** Wait until the reader has a live document and has drawn at least one page. */
async function opened(page) {
  await page.waitForFunction(() => window.__readerReady === true, null, { timeout: 90000 })
}

async function withReader(pid, fn) {
  const browser = await chromium.launch()
  try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } })
    const errors = []
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()))
    page.on('pageerror', (e) => errors.push(String(e)))
    await page.goto(READER_URL(pid), { waitUntil: 'domcontentloaded' })
    await fn(page, errors)
    return errors
  } finally {
    await browser.close()
  }
}

// ---------------------------------------------------------------- selection

async function testSelection() {
  console.log('\nselection')
  await withReader(TEXT_PID, async (page, errors) => {
    const layerExists = await page.locator('#pdfTextLayer').count()
    ok(layerExists === 1, 'reader has exactly one #pdfTextLayer', `found ${layerExists}`)

    await page.waitForSelector('#pdfTextLayer span', { timeout: 60000 })
    const spanCount = await page.locator('#pdfTextLayer span').count()
    ok(spanCount > 0, 'text layer rendered spans', `${spanCount} spans`)

    // The layer must be selectable, which means transparent glyphs sitting over
    // the canvas - not a visible second copy of the text.
    const colour = await page.evaluate(() => {
      const s = document.querySelector('#pdfTextLayer span')
      return s ? getComputedStyle(s).color : ''
    })
    ok(/rgba\(0,\s*0,\s*0,\s*0\)|transparent/.test(colour),
      'layer text is transparent so it does not double-render', colour)

    const positioned = await page.evaluate(() => {
      const s = document.querySelector('#pdfTextLayer span')
      return s ? getComputedStyle(s).position : ''
    })
    eq(positioned, 'absolute', 'layer spans are absolutely positioned')

    const stagePosition = await page.evaluate(() =>
      getComputedStyle(document.getElementById('readerStage')).position)
    ok(stagePosition !== 'static', 'stage is a positioned ancestor', stagePosition)

    // The real assertion: text is selectable and comes back as readable text.
    const selected = await page.evaluate(() => {
      const layer = document.getElementById('pdfTextLayer')
      const walker = document.createTreeWalker(layer, NodeFilter.SHOW_TEXT)
      const nodes = []
      let node
      while ((node = walker.nextNode()) && nodes.length < 40) {
        if (node.nodeValue && node.nodeValue.trim()) nodes.push(node)
      }
      if (nodes.length < 2) return ''
      const range = document.createRange()
      range.setStart(nodes[0], 0)
      const last = nodes[nodes.length - 1]
      range.setEnd(last, last.nodeValue.length)
      const sel = window.getSelection()
      sel.removeAllRanges()
      sel.addRange(range)
      return sel.toString()
    })
    const collapsed = selected.replace(/\s+/g, ' ').trim()
    ok(collapsed.length > 40, 'selecting across the layer yields real text',
      `${collapsed.length} chars: ${JSON.stringify(collapsed.slice(0, 60))}`)

    eq(errors.length, 0, 'no console errors', errors.join(' | '))
  })
}

// -------------------------------------------------------------------- index

async function testIndex() {
  console.log('\nindex')
  await withReader(TEXT_PID, async (page, errors) => {
    await opened(page)
    await page.waitForFunction(() => window.__indexDone === true, null, { timeout: 120000 })

    const info = await page.evaluate(() => ({
      numPages: window.__readerDoc.numPages,
      size: window.__readerIndex.size,
      firstLen: window.pageText(window.__readerIndex, 1).length,
      blankIsEmptyString: (() => {
        const v = window.pageText(window.__readerIndex, 99999)
        return v === ''
      })(),
      hasItems: (window.__readerIndex.get(1) || {}).items?.length > 0,
      itemHasTransform: (() => {
        const it = (window.__readerIndex.get(1) || {}).items?.[0]
        return Array.isArray(it?.transform)
      })(),
    }))

    eq(info.size, info.numPages, 'index has an entry for every page')
    ok(info.firstLen > 0, 'page 1 has extracted text', `${info.firstLen} chars`)
    ok(info.blankIsEmptyString, 'out-of-range page reads as empty string, not undefined')
    ok(info.hasItems, 'page 1 kept its text items')
    ok(info.itemHasTransform, 'items carry a transform for highlighting')
    eq(errors.length, 0, 'no console errors', errors.join(' | '))
  })
}

// --------------------------------------------------------------------- find

async function testFind() {
  console.log('\nfind')
  await withReader(TEXT_PID, async (page, errors) => {
    await opened(page)
    await page.waitForFunction(() => window.__indexDone === true, null, { timeout: 120000 })

    ok(await page.locator('#findInput').count() === 1, 'find box exists')
    ok(await page.locator('#findCount').count() === 1, 'match counter exists')
    const live = await page.getAttribute('#findCount', 'aria-live')
    eq(live, 'polite', 'counter is a polite live region')

    // Derive the search term from the index rather than hardcoding one, so the
    // test cannot rot when the fixture changes. Scans every page, not just
    // page 1: this fixture's page 1 is a title page.
    //
    // Prefers the longest run of letters in either script - this corpus is
    // Gurmukhi, so demanding [A-Za-z] would have found nothing on all 380 pages
    // and quietly skipped the case that matters most.
    const term = await page.evaluate(() => {
      const letters = /[A-Za-z]{5,}|[\u0A00-\u0A7F]{4,}/gu
      const counts = new Map()
      for (const [, v] of window.__readerIndex) {
        for (const m of (v.text || '').match(letters) || []) {
          counts.set(m, (counts.get(m) || 0) + 1)
        }
      }
      // Prefer a term with several occurrences, so next/prev has two targets.
      let best = '', bestN = 0
      for (const [w, n] of counts) {
        if (n > bestN || (n === bestN && w.length > best.length)) { best = w; bestN = n }
      }
      return { word: best, count: bestN, script: /[\u0A00-\u0A7F]/.test(best) ? 'gurmukhi' : 'latin' }
    })
    ok(term.word.length >= 4, 'found a searchable term in the index',
      `${JSON.stringify(term.word)} x${term.count} (${term.script})`)
    ok(term.count >= 2, 'the chosen term occurs at least twice', `x${term.count}`)

    await page.fill('#findInput', term.word)
    await page.waitForFunction(() => /\d+ of \d+/.test(
      document.getElementById('findCount').textContent), null, { timeout: 20000 })
    const countText = await page.textContent('#findCount')
    const m = countText.match(/(\d+) of (\d+)/)
    ok(m && Number(m[2]) > 0, 'term matches', countText)
    ok(Number(m[1]) === 1, 'counter starts at the first match', countText)

    const firstPage = await page.evaluate((t) => {
      const hits = window.findAll(window.__readerIndex, t, window.__readerDoc.numPages)
      return hits.length ? hits[0].page : null
    }, term.word)
    ok(firstPage !== null, 'findAll returns a page for the term')
    eq(Number(await page.inputValue('#pgNum')), firstPage,
      'the reader jumped to that page')

    // Gurmukhi must round-trip byte-for-byte. Review Focus #3. Asserted as a
    // hard failure, not a skip: this fixture is all Gurmukhi on all 380 pages,
    // so "no Gurmukhi found" means the search path dropped the script.
    const gurmukhi = await page.evaluate(() => {
      for (const [, v] of window.__readerIndex) {
        const hit = (v.text || '').match(/[\u0A00-\u0A7F]{4,}/)
        if (hit) return hit[0]
      }
      return ''
    })
    ok(gurmukhi.length >= 4, 'fixture yields a Gurmukhi term', JSON.stringify(gurmukhi))
    const gHits = await page.evaluate((t) =>
      window.findAll(window.__readerIndex, t, window.__readerDoc.numPages).length, gurmukhi)
    ok(gHits > 0, 'Gurmukhi term matches byte-for-byte', `${gurmukhi} -> ${gHits} hits`)

    // A Latin-digit Gurmukhi mix must survive too.
    const mixed = await page.evaluate(() => {
      for (const [, v] of window.__readerIndex) {
        const hit = (v.text || '').match(/[\u0A00-\u0A7F]+\s?\d+/)
        if (hit) return hit[0]
      }
      return ''
    })
    if (mixed) {
      const mHits = await page.evaluate((t) =>
        window.findAll(window.__readerIndex, t, window.__readerDoc.numPages).length, mixed)
      ok(mHits > 0, 'mixed Gurmukhi+digits term matches', `${mixed} -> ${mHits}`)
    } else {
      console.log('  note: no Gurmukhi+digits pair in this fixture')
    }

    // A term spanning two text items. Review Focus #4.
    //
    // PDF.js emits inter-word spaces as their own one-character items (" "), so
    // a join boundary never lands mid-word - a real line wrap happens *inside* an
    // item's str, not between items. The cross-item case that genuinely exists
    // is therefore two adjacent non-space items separated by the join space, and
    // the term has to include that space to match.
    const across = await page.evaluate(() => {
      for (const [, pt] of window.__readerIndex) {
        const its = pt.items
        for (let i = 0; i < its.length - 1; i++) {
          const a = its[i].str.replace(/\s+$/, '')
          const b = its[i + 1].str.replace(/^\s+/, '')
          if (its[i + 1].str.trim() === '') continue   // a real gap, not adjacent
          if (a.length < 3 || b.length < 3) continue
          if (!/[\p{L}]/u.test(a) || !/[\p{L}]/u.test(b)) continue
          const term = a.slice(-3) + ' ' + b.slice(0, 3)
          if (pt.text.includes(term)) return term
        }
      }
      return ''
    })
    ok(across.length > 0, 'fixture offers two adjacent word items', JSON.stringify(across))
    const aHits = await page.evaluate((t) =>
      window.findAll(window.__readerIndex, t, window.__readerDoc.numPages).length, across)
    ok(aHits > 0, 'term spanning the join between two items is found',
      `${JSON.stringify(across)} -> ${aHits}`)

    // Next/prev must move between matches. Typing already jumps to match 1, so
    // the first click lands on match 2 - these assertions start from there.
    const navigated = await page.evaluate(async () => {
      const t = document.getElementById('findInput').value
      const hits = window.findAll(window.__readerIndex, t, window.__readerDoc.numPages)
      if (hits.length < 2) return { skipped: true, hits: hits.length }
      const at = () => Number(document.getElementById('pgNum').value)
      const start = at()
      document.getElementById('findNext').click()
      await new Promise((r) => setTimeout(r, 500))
      const after1 = at()
      document.getElementById('findPrev').click()
      await new Promise((r) => setTimeout(r, 500))
      const back = at()
      document.getElementById('findNext').click()
      await new Promise((r) => setTimeout(r, 500))
      const after2 = at()
      const atLabel = () => document.getElementById('findCount').textContent
      const labelAfter1 = atLabel()
      document.getElementById('findPrev').click()
      await new Promise((r) => setTimeout(r, 500))
      const labelAfterPrev = atLabel()
      return { skipped: false, start, after1, after2, back,
        labelAfter1, labelAfterPrev,
        p1: hits[0].page, p2: hits[1].page }
    })
    if (navigated.skipped) {
      ok(false, 'chosen term has at least two matches', `only ${navigated.hits}`)
    } else {
      eq(navigated.start, navigated.p1, 'typing already jumped to match 1')
      eq(navigated.after1, navigated.p2, 'findNext advances to match 2')
      eq(navigated.back, navigated.start, 'findPrev returns to match 1')
      ok(/^2 of \d+$/.test(navigated.labelAfter1), 'counter reads 2 after one step',
        navigated.labelAfter1)
      ok(/^1 of \d+$/.test(navigated.labelAfterPrev), 'counter reads 1 after stepping back',
        navigated.labelAfterPrev)
    }

    // Escape and the clear button must both empty the search.
    const cleared = await page.evaluate(async () => {
      document.getElementById('findClear').click()
      await new Promise((r) => setTimeout(r, 200))
      return {
        value: document.getElementById('findInput').value,
        count: document.getElementById('findCount').textContent.trim(),
      }
    })
    eq(cleared.value, '', 'clear empties the box')
    eq(cleared.count, '', 'clear empties the counter')

    eq(errors.length, 0, 'no console errors', errors.join(' | '))
  })
}

// ---------------------------------------------------------------- highlight

async function testHighlight() {
  console.log('\nhighlight')
  await withReader(TEXT_PID, async (page, errors) => {
    await opened(page)
    await page.waitForFunction(() => window.__indexDone === true, null, { timeout: 120000 })

    // Read the term out of the box the test itself filled, rather than
    // recomputing it. A separate recomputation can pick a different word than
    // the one the reader actually holds, which reads as "no highlight" rather
    // than as "the test asked for something else".
    const term = await page.evaluate(() => {
      const letters = /[A-Za-z]{5,}|[\u0A00-\u0A7F]{4,}/gu
      const counts = new Map()
      for (const [, v] of window.__readerIndex) {
        for (const m of (v.text || '').match(letters) || []) {
          counts.set(m, (counts.get(m) || 0) + 1)
        }
      }
      let best = '', n = 0
      for (const [w, c] of counts) if (c > n || (c === n && w.length > best.length)) { best = w; n = c }
      return best
    })
    await page.fill('#findInput', term)
    // Wait for the reader's own counter to report hits before asking about
    // highlights, so a zero-match search cannot masquerade as a highlight bug.
    await page.waitForFunction(() => /\d+ of \d+/.test(
      document.getElementById('findCount').textContent), null, { timeout: 20000 })
    ok(true, 'search produced matches before highlight is checked', term)
    await page.waitForFunction(() => window.__highlightCount > 0, null, { timeout: 20000 })

    const hl = await page.evaluate(() => {
      const spans = [...document.querySelectorAll('#pdfTextLayer .hl')]
      const canvas = document.getElementById('pdfCanvas').getBoundingClientRect()
      const inside = spans.filter((s) => {
        const r = s.getBoundingClientRect()
        return r.width > 0 && r.height > 0 &&
          r.left >= canvas.left - 2 && r.right <= canvas.right + 2 &&
          r.top >= canvas.top - 2 && r.bottom <= canvas.bottom + 2
      })
      return { total: spans.length, inside: inside.length }
    })
    ok(hl.total > 0, 'highlight spans exist', `${hl.total}`)
    ok(hl.inside === hl.total, 'every highlight sits inside the canvas', JSON.stringify(hl))

    // Turning the page must leave no highlight from the page just left.
    //
    // This polls until the turn completes rather than sleeping a fixed time.
    // Page turn is canvas render -> text layer render -> requestAnimationFrame
    // repaint, and under a loaded machine that chain can outlast any single
    // guessed delay - which showed up as a 2-of-3 flake before polling.
    const afterTurn = await page.evaluate(async () => {
      const pageBefore = Number(document.getElementById('pgNum').value)
      document.getElementById('pgNext').click()
      const deadline = Date.now() + 15000
      while (Date.now() < deadline) {
        const moved = Number(document.getElementById('pgNum').value) !== pageBefore
        // The canvas must have been redrawn, or we would be reading the old
        // layer's highlights and calling them stale.
        const drawn = window.__renderCount > 0
        if (moved && drawn && window.__highlightCount === 0) {
          return { stale: document.querySelectorAll('#pdfTextLayer .hl').length, moved: true }
        }
        await new Promise((r) => setTimeout(r, 100))
      }
      return {
        stale: document.querySelectorAll('#pdfTextLayer .hl').length,
        moved: Number(document.getElementById('pgNum').value) !== pageBefore,
        timeout: true,
      }
    })
    ok(afterTurn.moved, 'page actually turned')
    eq(afterTurn.stale, 0, 'highlights are cleared when the page turns')

    eq(errors.length, 0, 'no console errors', errors.join(' | '))
  })
}

// ----------------------------------------------------------------- fallback

async function testFallback() {
  console.log('\nfallback')
  const { execFileSync } = require('node:child_process')

  // Find a book with no embedded text layer, so the fallback case is real rather
  // than asserted against a made-up PID.
  // A book whose PDF carries no extractable text.
  //
  // Read from a checked-in fixture rather than hunted for at test time. The
  // first version of this test opened up to 40 reader pages looking for a
  // candidate, each waiting on a full index: it ran past 15 minutes and had to be
  // killed. "Which books lack a text layer" is a property of the corpus, so
  // tools/t/probe-text-layers.mjs answers it once, on demand, and the answer is
  // committed. Regenerate with:
  //   node tools/t/probe-text-layers.mjs 24 > tools/t/no-text-pids.json
  const { readFileSync } = require('node:fs')
  const here = new URL('.', import.meta.url).pathname
  const noTextPids = JSON.parse(readFileSync(here + 'no-text-pids.json', 'utf8'))

  ok(noTextPids.length > 0, 'fixture names a readable book with no text layer',
    `${noTextPids.length} such books`)

  if (noTextPids.length) {
    const noTextPid = noTextPids[0]
    console.log(`  using ${noTextPid}`)
    await withReader(noTextPid, async (page) => {
      await opened(page)
      await page.waitForFunction(() => window.__indexDone === true, null, { timeout: 120000 })

      const state = await page.evaluate(() => ({
        disabled: document.getElementById('findInput').disabled,
        noticeShown: !document.getElementById('findNone').hidden,
        noticeText: (document.getElementById('findNone').textContent || '').trim(),
        hasArchiveLink: !!document.querySelector('#findNone a[href*="archive.org"]'),
        countText: (document.getElementById('findCount').textContent || '').trim(),
      }))
      ok(state.disabled, 'find box is disabled when there is no text')
      ok(state.noticeShown, 'notice is shown instead of a bare zero')
      ok(/no searchable text/i.test(state.noticeText), 'notice says there is no searchable text',
        JSON.stringify(state.noticeText.slice(0, 70)))
      ok(state.hasArchiveLink, 'notice links to Archive.org')
      ok(!/0 of 0/.test(state.countText),
        'counter does not masquerade as "0 of 0"', state.countText)
    })
  }

  // The false-positive direction: a book WITH text must not get the notice.
  await withReader(TEXT_PID, async (page) => {
    await opened(page)
    await page.waitForFunction(() => window.__indexDone === true, null, { timeout: 120000 })
    const state = await page.evaluate(() => ({
      disabled: document.getElementById('findInput').disabled,
      noticeShown: !document.getElementById('findNone').hidden,
    }))
    ok(!state.disabled, 'find box stays enabled for a book that has text')
    ok(!state.noticeShown, 'notice stays hidden for a book that has text')
  })
}

// --------------------------------------------------------------------- main

const GROUPS = {
  selection: testSelection,
  index: testIndex,
  find: testFind,
  highlight: testHighlight,
  fallback: testFallback,
}

const want = process.argv[2]
const names = want ? [want] : Object.keys(GROUPS)
const unknown = names.filter((n) => !GROUPS[n])
if (unknown.length) {
  console.error(`unknown group(s): ${unknown.join(', ')}\navailable: ${Object.keys(GROUPS).join(', ')}`)
  process.exit(2)
}

console.log(`reader tests (base ${BASE})`)
for (const n of names) {
  try {
    await GROUPS[n]()
  } catch (err) {
    fail++
    failures.push(`${n} threw`)
    console.log(`  FAIL ${n} threw: ${err && err.message ? err.message : err}`)
  }
}

console.log(`\n${pass} passed, ${fail} failed`)
if (fail) {
  console.log('failed: ' + failures.join('; '))
  process.exit(1)
}
