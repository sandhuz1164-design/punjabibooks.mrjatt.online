// Find readable books whose PDF has no extractable text layer.
//
// Run: node tools/probe-text-layers.mjs [limit]
// Prints a JSON array of PIDs, largest-first.
//
// Text-layer presence is a property of the catalogue, not of any one page, and
// answering it needs a browser. So it is answered once, here, instead of inside
// a test: the fallback test used to open up to 40 reader pages hunting for a
// no-text book, each waiting on a full page-by-page index of up to 674 pages,
// which ran past 15 minutes. This probes concurrently and caps itself.
//
// A page counts as having text when it yields at least MIN_CHARS of letters.
// Requiring a *letter* (not just any character) is what separates a real text
// layer from an image-only scan: the first version accepted any digit or
// punctuation, and AkalTakhtHukumnama10June1978Nirankaris - a 0.03 MB single-page
// image scan - reported "withText" on a single character, so no candidate ever
// looked text-less and the probe returned an empty list.
const MIN_CHARS = 40

import { chromium, READER_URL } from '../browser.mjs'

const LIMIT = Number(process.argv[2] || 40)
// Opening a reader page means PDF.js fetching byte ranges from Archive.org, and a
// 46 MB scan took 29s to reach first paint. 45s left 30 of 30 candidates
// reporting "could not open", which read as "the corpus has no text-less books"
// - the opposite of the truth. Probe in batches so the wall-clock cost stays
// bounded while every candidate still gets a realistic chance.
const OPEN_TIMEOUT_MS = Number(process.env.PROBE_TIMEOUT || 90000)
const CONCURRENCY = Number(process.env.PROBE_CONCURRENCY || 4)

// Candidates are the *smallest* readable books, largest last.
//
// The first version of this probe took the largest 30 and every one of them was
// a 40-90 MB encyclopaedia: PDF.js needed 79-115s of byte-range fetching just to
// reach first paint, so all 30 timed out and the probe reported "0 without
// text" - which reads as "no book lacks a text layer", the exact opposite of
// what was true. Small books reach first paint in a couple of seconds, which is
// what makes this affordable at all.
//
// The books that lack text layers are overwhelmingly the old cheap scans, so
// this ordering finds them and costs seconds instead of minutes.
const pids = JSON.parse(await (async () => {
  const { execFileSync } = await import('node:child_process')
  return execFileSync('/run/media/jatt/9890EF3990EF1C8E/devstack/php-pdo.sh', [
    '-r',
    `require '/home/jatt/BookStore-v2/db.php';
     echo json_encode(db()->query("SELECT PID FROM products
       WHERE PdfSize > 0 AND PdfSize <= 419430400
       ORDER BY PdfSize ASC LIMIT ${Number(LIMIT)}")
       ->fetchAll(PDO::FETCH_COLUMN));`,
  ], { encoding: 'utf8' })
})())

const browser = await chromium.launch()

async function probeOne(pid) {
  const page = await browser.newPage({ viewport: { width: 900, height: 700 } })
  try {
    // Set before any script on the page runs.
    await page.addInitScript((n) => { window.__probeMinChars = n }, MIN_CHARS)
    await page.goto(READER_URL(pid), { waitUntil: 'domcontentloaded', timeout: 30000 })
    await page.waitForFunction(() => window.__readerReady === true, null, { timeout: OPEN_TIMEOUT_MS })
    const probe = await page.evaluate(async () => {
      // Wait for the index rather than re-walking pages here: the reader builds
      // it in the background anyway, and a probe that opened its own copy of
      // every page doubled the Archive.org traffic for no extra information.
      await new Promise((resolve) => {
        if (window.__indexDone) return resolve()
        const t = setInterval(() => {
          if (window.__indexDone) { clearInterval(t); resolve() }
        }, 150)
        setTimeout(() => { clearInterval(t); resolve() }, 240000)
      })
      if (!window.__indexDone) return { timedOut: true, withText: 1, numPages: 0 }

      let withText = 0
      for (const [, v] of window.__readerIndex) {
        const letters = (v.text || '').match(/\p{L}/gu)
        if (letters && letters.length >= window.__probeMinChars) withText++
      }
      return { numPages: window.__readerIndex.size, withText }
    })
    return { pid, ...probe, noText: probe.withText === 0 }
  } catch {
    return { pid, error: true, noText: false }
  } finally {
    await page.close()
  }
}

const results = []
for (let i = 0; i < pids.length; i += CONCURRENCY) {
  const batch = pids.slice(i, i + CONCURRENCY)
  results.push(...await Promise.all(batch.map(probeOne)))
  process.stderr.write(`  ${Math.min(i + CONCURRENCY, pids.length)}/${pids.length}\r`)
}
process.stderr.write('\n')
await browser.close()

const noText = results.filter((r) => r.noText).map((r) => r.pid)
const errored = results.filter((r) => r.error).length
process.stderr.write(
  `probed ${results.length}, ${noText.length} without text, ${errored} could not open\n`)
console.log(JSON.stringify(noText))
