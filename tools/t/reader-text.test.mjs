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

// 68 pages, and its PDF carries an embedded text layer, so it is the fixture for
// everything that needs searchable text.
const TEXT_PID = 'HarpalSinghPannu-7KUFixXT'

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

    // Pick a real word out of the index instead of hardcoding one, so the test
    // cannot rot when the fixture changes.
    const term = await page.evaluate(() => {
      const m = window.__readerIndex
      for (const [, v] of m) {
        const w = (v.text || '').match(/[A-Za-z]{5,}/)
        if (w) return w[0]
      }
      return ''
    })
    ok(term.length >= 5, 'found a searchable word in the index', term)

    await page.fill('#findInput', term)
    await page.waitForFunction(() => /\d+ of \d+/.test(
      document.getElementById('findCount').textContent), null, { timeout: 20000 })
    const countText = await page.textContent('#findCount')
    const m = countText.match(/(\d+) of (\d+)/)
    ok(m && Number(m[2]) > 0, 'ASCII term matches', countText)

    const firstPage = await page.evaluate((t) => {
      const hits = window.findAll(window.__readerIndex, t, window.__readerDoc.numPages)
      return hits.length ? hits[0].page : null
    }, term)
    ok(firstPage !== null, 'findAll returns a page for the term')

    // Gurmukhi must round-trip: no normalisation may alter it. Review Focus #3.
    const gurmukhi = await page.evaluate(() => {
      const re = /[਀-੿]{4,}/g
      for (const [, v] of window.__readerIndex) {
        const hit = (v.text || '').match(re)
        if (hit) return hit[0]
      }
      return ''
    })
    if (gurmukhi) {
      const gHits = await page.evaluate((t) =>
        window.findAll(window.__readerIndex, t, window.__readerDoc.numPages).length, gurmukhi)
      ok(gHits > 0, 'Gurmukhi term matches byte-for-byte', `${gurmukhi} -> ${gHits} hits`)
    } else {
      console.log('  skip Gurmukhi case: no Gurmukhi run in this fixture')
    }

    // A term spanning two text items. Review Focus #4.
    const across = await page.evaluate(() => {
      const page1 = window.__readerIndex.get(1)
      const its = page1.items
      for (let i = 0; i < its.length - 1; i++) {
        const a = (its[i].str || '').replace(/\s+$/, '')
        const b = (its[i + 1].str || '').replace(/^\s+/, '')
        if (a.length >= 2 && b.length >= 2) return a.slice(-3) + b.slice(0, 3)
      }
      return ''
    })
    if (across) {
      const aHits = await page.evaluate((t) =>
        window.findAll(window.__readerIndex, t, window.__readerDoc.numPages).length, across)
      ok(aHits > 0, 'term spanning two items is found', `${across} -> ${aHits}`)
    } else {
      console.log('  skip cross-item case: fixture has no adjacent item pair')
    }

    // Next/prev must move between matches.
    const navigated = await page.evaluate(async () => {
      const inp = document.getElementById('findInput')
      const t = inp.value
      const hits = window.findAll(window.__readerIndex, t, window.__readerDoc.numPages)
      if (hits.length < 2) return { skipped: true, hits: hits.length }
      document.getElementById('findNext').click()
      await new Promise((r) => setTimeout(r, 400))
      const after1 = window.__readerPage
      document.getElementById('findNext').click()
      await new Promise((r) => setTimeout(r, 400))
      const after2 = window.__readerPage
      return { skipped: false, after1, after2, p1: hits[0].page, p2: hits[1].page }
    })
    if (navigated.skipped) {
      console.log('  skip next/prev case: fewer than 2 matches')
    } else {
      eq(navigated.after1, navigated.p1, 'findNext jumps to the first match page')
      eq(navigated.after2, navigated.p2, 'findNext advances to the second match page')
    }

    eq(errors.length, 0, 'no console errors', errors.join(' | '))
  })
}

// ---------------------------------------------------------------- highlight

async function testHighlight() {
  console.log('\nhighlight')
  await withReader(TEXT_PID, async (page, errors) => {
    await opened(page)
    await page.waitForFunction(() => window.__indexDone === true, null, { timeout: 120000 })

    const term = await page.evaluate(() => {
      for (const [, v] of window.__readerIndex) {
        const w = (v.text || '').match(/[A-Za-z]{5,}/)
        if (w) return w[0]
      }
      return ''
    })
    await page.fill('#findInput', term)
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

    const afterTurn = await page.evaluate(async () => {
      document.getElementById('pgNext').click()
      await new Promise((r) => setTimeout(r, 700))
      return document.querySelectorAll('#pdfTextLayer .hl').length
    })
    eq(afterTurn, 0, 'highlights are cleared when the page turns')

    eq(errors.length, 0, 'no console errors', errors.join(' | '))
  })
}

// ----------------------------------------------------------------- fallback

async function testFallback() {
  console.log('\nfallback')
  const { execFileSync } = require('node:child_process')

  // Find a book with no embedded text layer, so the fallback case is real rather
  // than asserted against a made-up PID.
  const pids = JSON.parse(execFileSync('/run/media/jatt/9890EF3990EF1C8E/devstack/php-pdo.sh', [
    '-r',
    `require '/home/jatt/BookStore-v2/db.php';
     $p = db()->query("SELECT PID FROM products WHERE PdfSize>0 AND PdfSize<=419430400
                       ORDER BY PdfSize DESC LIMIT 40")->fetchAll(PDO::FETCH_COLUMN);
     echo json_encode($p);`,
  ], { encoding: 'utf8' }))

  let noTextPid = null
  const browser = await chromium.launch()
  try {
    for (const pid of pids) {
      const page = await browser.newPage({ viewport: { width: 1280, height: 900 } })
      try {
        await page.goto(READER_URL(pid), { waitUntil: 'domcontentloaded', timeout: 60000 })
        await page.waitForFunction(() => window.__readerReady === true, null, { timeout: 60000 })
        await page.waitForFunction(() => window.__indexDone === true, null, { timeout: 60000 })
        const total = await page.evaluate(() => {
          let n = 0
          for (const [, v] of window.__readerIndex) if ((v.text || '').trim()) n++
          return n
        })
        if (total === 0) { noTextPid = pid; break }
      } catch {
        // Skip books this browser session cannot open; they are not the subject.
      } finally {
        await page.close()
      }
    }
  } finally {
    await browser.close()
  }

  ok(noTextPid !== null, 'found a readable book with no text layer to test against',
    `scanned ${pids.length} candidates`)

  if (noTextPid) {
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
