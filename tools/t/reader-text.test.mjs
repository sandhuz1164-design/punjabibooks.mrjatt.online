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

// The reader's JavaScript is assembled by concatenating PHP strings, so a stray
// backtick or an unbalanced brace in a PHP *comment* becomes a syntax error in
// the served page - and it kills the whole module, so every other assertion
// fails on a timeout rather than on anything real. A PHP comment containing a
// backtick did exactly that, and read as five unrelated failures. Check the
// served script parses before anything else.
async function testReaderScriptParses() {
  console.log('\nreader script')
  const res = await fetch(READER_URL(TEXT_PID))
  const html = await res.text()
  const m = html.match(/<script type="module">([\s\S]*?)<\/script>/)
  ok(Boolean(m), 'reader page carries a module script')
  if (!m) return

  const src = m[1]
  // Strip static imports: they cannot be evaluated outside a module, and their
  // presence is not what this check is about. The `m` flag alone is not enough -
  // reader.php emits the first import after a newline but the rest of the body
  // on one line, so anchor on a leading statement too.
  // The rest is compiled but not run, so a stray backtick or unbalanced brace in
  // a PHP comment surfaces here instead of killing the whole reader module in
  // the browser, where it presents as every other assertion timing out.
  // reader.php concatenates the whole module into one line, so a `//` comment in
  // the generated JS would swallow everything after it - and a `//` also occurs
  // harmlessly inside strings (the archive.org URL). Rewrite both forms before
  // parsing: strings become placeholders, line comments are stripped, and the
  // placeholders are restored afterwards.
  let body = src
    .replace(/^\s*import\s[^;]*;/gm, '')
    .replace(/^import\s[^;]*;/, '')
  const literals = []
  body = body
    .replace(/`(?:[^`\\]|\\.)*`|"(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*'/g, (m) => {
      literals.push(m)
      return `__LIT${literals.length - 1}__`
    })
    .replace(/\/\/[^\n]*/g, '')
  body = body.replace(/__LIT(\d+)__/g, (_, i) => literals[Number(i)])
  try {
    // async/await is only legal inside an async context, which new Function is
    // not; wrapping in an async IIFE gives the same parse without executing.
    new Function(`return (async () => { ${body} })`)
    ok(true, 'reader script parses as valid JavaScript', `${src.length} chars`)
  } catch (err) {
    ok(false, 'reader script parses as valid JavaScript',
      `${String(err && err.message)} (at char ${(err && err.stack ? String(err.stack).match(/<anonymous>:1:(\d+)/)?.[1] : '?')})`)
  }
}

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
    // Geometry, not just presence.
    //
    // PDF.js sizes the text layer and its spans in calc() terms of
    // --scale-factor. With that variable unset the layer collapses to zero width
    // and every span lands at left:0 with an inherited font-size - while a
    // programmatic Range over the text nodes still "works", because Range
    // selection never consults geometry. Asserting on a Range therefore proved
    // nothing about whether a reader can select text; only real hit-testing can.
    const geo = await page.evaluate(() => {
      const layer = document.getElementById('pdfTextLayer')
      const canvas = document.getElementById('pdfCanvas')
      const lr = layer.getBoundingClientRect()
      const cr = canvas.getBoundingClientRect()
      const span = [...layer.querySelectorAll('span')].find((s) => s.getBoundingClientRect().width > 0)
      return {
        layerW: Math.round(lr.width), layerH: Math.round(lr.height),
        canvasW: Math.round(cr.width), canvasH: Math.round(cr.height),
        scaleFactor: getComputedStyle(layer).getPropertyValue('--scale-factor').trim(),
        spanWidth: span ? Math.round(span.getBoundingClientRect().width) : 0,
      }
    })
    ok(geo.scaleFactor !== '' && geo.scaleFactor !== '(unset)',
      '--scale-factor is set on the text layer', JSON.stringify(geo.scaleFactor))
    ok(Math.abs(geo.layerW - geo.canvasW) <= 2 && Math.abs(geo.layerH - geo.canvasH) <= 2,
      'text layer box matches the canvas box',
      `layer ${geo.layerW}x${geo.layerH} vs canvas ${geo.canvasW}x${geo.canvasH}`)
    ok(geo.spanWidth > 5, 'layer spans have real width', `${geo.spanWidth}px`)

    // A drag, not a Range: this is what a reader actually does.
    //
    // Scroll the target into the viewport first and read its box back afterwards
    // - PDF.js positions spans in absolute page coordinates, so a box read
    // before scrolling points somewhere else on screen entirely, and the drag
    // lands on whatever happens to be there.
    const dragTarget = await page.evaluate(() => {
      const span = [...document.querySelectorAll('#pdfTextLayer span')]
        .find((s) => {
          const r = s.getBoundingClientRect()
          return (s.textContent || '').trim().length > 3 && r.width > 20
        })
      if (!span) return null
      span.scrollIntoView({ block: 'center', behavior: 'instant' })
      const r = span.getBoundingClientRect()
      return {
        x: Math.round(r.left + r.width / 2),
        y: Math.round(r.top + r.height / 2),
        width: Math.round(r.width),
        text: (span.textContent || '').trim(),
      }
    })
    ok(dragTarget !== null, 'found a span to drag across', JSON.stringify(dragTarget && dragTarget.text))

    if (dragTarget) {
      const reach = Math.max(20, Math.min(60, Math.floor(dragTarget.width / 2)))
      await page.mouse.move(dragTarget.x - reach, dragTarget.y)
      await page.mouse.down()
      await page.mouse.move(dragTarget.x + reach, dragTarget.y, { steps: 15 })
      await page.mouse.up()
      await page.waitForTimeout(350)

      const dragged = await page.evaluate(() => (window.getSelection() || '').toString())
      const collapsed = dragged.replace(/\s+/g, ' ').trim()
      ok(collapsed.length > 0, 'a real mouse drag selects text', JSON.stringify(collapsed.slice(0, 40)))
      // Dragging left-to-right across a word's middle selects a suffix of it, so
      // compare on a shared substring rather than requiring the whole word.
      const want = dragTarget.text.replace(/\s/g, '')
      const got = collapsed.replace(/\s/g, '')
      ok(got.length >= 3 && want.includes(got) || got.includes(want),
        'the drag selected text from the span it crossed',
        `span ${JSON.stringify(want.slice(0, 16))}, selected ${JSON.stringify(got.slice(0, 16))}`)
    }

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
    // Pick a term that lives inside a single text item on its own page.
    //
    // The earlier version took the most frequent word in the whole book, which
    // landed mid-line and highlighted only the tail of a 210px span - correct
    // behaviour for a hit that starts inside an item, but it made the size
    // assertion meaningless. Single-item matches keep this test about the box.
    const term = await page.evaluate(() => {
      for (const [, pt] of window.__readerIndex) {
        for (const it of pt.items || []) {
          const w = (it.str || '').trim()
          if (w.length >= 4 && !/\s/.test(w)) return w
        }
      }
      return ''
    })
    ok(term.length >= 4, 'fixture offers a whole-word match inside one item', term)
    // Jump to the match's own page and let the reader settle there before
    // measuring. Asserting against whatever page the reader happens to be on
    // measures the wrong layer - the highlight lands on page 12 while the
    // comparison runs against page 1's spans.
    await page.fill('#findInput', term)
    await page.waitForFunction(() => /\d+ of \d+/.test(
      document.getElementById('findCount').textContent), null, { timeout: 20000 })
    await page.waitForFunction((want) => {
      const hit = window.findAll(window.__readerIndex, want, window.__readerDoc.numPages)[0]
      return hit && window.__renderedPage === hit.page
    }, term, { timeout: 20000 })
    await page.waitForFunction(() => window.__highlightCount > 0, null, { timeout: 20000 })
    // Wait for the reader's own counter to report hits before asking about
    // highlights, so a zero-match search cannot masquerade as a highlight bug.
    await page.waitForFunction(() => /\d+ of \d+/.test(
      document.getElementById('findCount').textContent), null, { timeout: 20000 })
    ok(true, 'search produced matches before highlight is checked', term)
    await page.waitForFunction(() => window.__highlightCount > 0, null, { timeout: 20000 })

    // Position, not just presence. The box has to sit on the word it claims to
    // mark: the previous implementation drew a plausible box roughly 600px from
    // the match, 4x too tall, with no top at all, and the only assertion was
    // "inside the canvas" - which a box in the wrong place satisfies.
    const aligned = await page.evaluate((want) => {
      const layer = document.getElementById('pdfTextLayer')
      const hl = layer.querySelector('.hl')
      if (!hl) return { none: true }
      const hr = hl.getBoundingClientRect()
      // The span is found by position, not by content: PDF.js emits a span for
    // whitespace-only items too, so filtering them out first (as an earlier
    // version did) made the search compare the highlight against spans from the
    // wrong item.
    const textOf = (el) => (el.textContent || '').trim()
    const all = [...layer.querySelectorAll('span:not(.hl)')]
    const candidates = all.map((s) => {
      const r = s.getBoundingClientRect()
      return { text: textOf(s), r,
        overlap: Math.max(0, Math.min(r.right, hr.right) - Math.max(r.left, hr.left)) }
    }).filter((c) => c.overlap > 0)
    const best = candidates.sort((a, b) => b.overlap - a.overlap)[0]
    return best ? {
      overlapPx: Math.round(best.overlap),
      hlW: Math.round(hr.width), hlH: Math.round(hr.height),
      spanW: Math.round(best.r.width), spanH: Math.round(best.r.height),
      overlapHasTerm: best.text.replace(/\s/g, '').includes(want.replace(/\s/g, '')),
      spanText: best.text.slice(0, 20), want,
    } : { noOverlap: true, hlW: Math.round(hr.width) }
    }, term)
    ok(!aligned.none && !aligned.noOverlap,
      'highlight overlaps the text it marks', JSON.stringify(aligned))
    ok(aligned.overlapHasTerm === true,
      'the overlapped span contains the search term', JSON.stringify(aligned))
    ok(Math.abs(aligned.hlW - aligned.spanW) <= 4 && Math.abs(aligned.hlH - aligned.spanH) <= 4,
      'highlight box matches the span it covers in size',
      `hl ${aligned.hlW}x${aligned.hlH} vs span ${aligned.spanW}x${aligned.spanH}`)
    ok(aligned.hlW < aligned.spanW * 4,
      'highlight is the width of its text, not the whole line',
      `hl ${aligned.hlW}px vs span ${aligned.spanW}px`)

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

// A search typed before the index exists must not report a negative result.
// Review Focus #1 in the window before the fallback notice can appear.
async function testMidIndexSearch() {
  console.log('\nmid-index search')
  await withReader(TEXT_PID, async (page, errors) => {
    await opened(page)
    // Fill the box as early as possible: the reader is ready before the index
    // finishes, which is the whole point of the test.
    await page.fill('#findInput', 'ਸਿੰਘ')
    const during = await page.evaluate(() => ({
      done: !!window.__indexDone,
      count: document.getElementById('findCount').textContent.trim(),
    }))
    ok(during.count !== 'No matches',
      'a search before indexing finishes does not claim "No matches"',
      `count was ${JSON.stringify(during.count)}, indexDone=${during.done}`)
    ok(/searching/i.test(during.count) || during.done,
      'it either reports it is still searching, or the index was already done',
      JSON.stringify(during.count))

    if (!during.done) {
      // And once the index lands, the same search must produce a real answer.
      await page.waitForFunction(() => window.__indexDone === true, null, { timeout: 120000 })
      await page.waitForFunction(() => /\d+ of \d+|No matches/.test(
        document.getElementById('findCount').textContent), null, { timeout: 20000 })
      const after = await page.textContent('#findCount')
      ok(/\d+ of \d+/.test(after),
        'the deferred search resolves to a real count once indexed', after)
    }
    eq(errors.length, 0, 'no console errors', errors.join(' | '))
  })
}

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
  script: testReaderScriptParses,
  selection: testSelection,
  index: testIndex,
  find: testFind,
  highlight: testHighlight,
  midindex: testMidIndexSearch,
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
