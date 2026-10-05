// Fetch the opening of each book's text from Archive.org and cache it locally.
//
// Why not the PDFs: the catalogue's PDFs total 109 GB. Archive.org publishes a plain-text
// OCR rendering per item instead, and we only need the title page, preface and contents -
// a single HTTP Range request for the first 64 KB gets that. Measured cost for the whole
// catalogue is ~370 MB and a few hours, not 109 GB.
//
// Two things the first version got wrong, both found by measuring rather than assuming:
//
//  1. The text file is NOT reliably named "<identifier>_djvu.txt". Archive.org keeps the
//     original uploaded filename, so item "shere-punjab-maharaja-ranjit-singh" stores it
//     as "Shere Punjab Maharaja Ranjit Singh_djvu.txt". Guessing the name 404'd on 45% of
//     items. The metadata API has to be asked for the real name.
//
//  2. A 64 KB head is sometimes mostly DjVu OCR noise. Archive.org also exposes per-book
//     full-text search via its "search inside" endpoint; when the OCR is too poor to use,
//     we record that fact instead of storing garbage.
//
// Everything is written to disk so the network is touched exactly once per book, and the
// run resumes from where it stopped.

import { mkdirSync, existsSync, writeFileSync, readFileSync, appendFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

const OUT = process.env.TEXT_OUT || '/run/media/jatt/9890EF3990EF1C8E/bookstore-text'
const LIST = process.env.PID_LIST || '/tmp/opencode/booktext/pids.txt'
const CONCURRENCY = Number(process.env.CONCURRENCY || 6)
const BYTES = Number(process.env.HEAD_BYTES || 65536)
const LIMIT = Number(process.env.LIMIT || 0)
const UA = 'BookStore-v2 catalogue enrichment (local project)'

mkdirSync(join(OUT, 'heads'), { recursive: true })
const manifest = join(OUT, 'manifest.jsonl')

// ---- resume --------------------------------------------------------------

const done = new Set()
if (existsSync(manifest)) {
  for (const line of readFileSync(manifest, 'utf8').split('\n')) {
    if (!line) continue
    try { done.add(JSON.parse(line).pid) } catch { /* truncated final line */ }
  }
}

const all = readFileSync(LIST, 'utf8').split('\n').map(s => s.trim()).filter(Boolean)
  .map(l => l.split('\t'))
// Filter first, then limit. Slicing before filtering re-selected the already-done
// prefix of the list and silently fetched nothing on every resumed run.
const pending = all.filter(([pid]) => !done.has(pid))
const queue = LIMIT > 0 ? pending.slice(0, LIMIT) : pending
console.log(`  ${queue.length} to fetch, ${done.size} already done, ${CONCURRENCY} concurrent`)

// ---- name resolution -----------------------------------------------------

const nameCache = new Map()

async function resolveTextFile(archiveId) {
  if (nameCache.has(archiveId)) return nameCache.get(archiveId)
  let result = null
  try {
    const res = await fetch(`https://archive.org/metadata/${encodeURIComponent(archiveId)}`,
      { headers: { 'User-Agent': UA } })
    if (res.ok) {
      const j = await res.json()
      const files = j.files || []
      const txt = files.find(f => /_djvu\.txt$/i.test(f.name || ''))
        || files.find(f => /_text\.pdf$/i.test(f.name || ''))
        || files.find(f => /\.txt$/i.test(f.name || '') && !/hocr|searchtext/i.test(f.name))
      if (txt) {
        result = { name: txt.name, size: Number(txt.size || 0) }
      }
    }
  } catch { /* fall through to the guess */ }
  if (!result) {
    // last resort: the conventional name
    result = { name: `${archiveId}_djvu.txt`, size: 0, guessed: true }
  }
  nameCache.set(archiveId, result)
  return result
}

// ---- fetch ---------------------------------------------------------------

async function fetchHead([pid, archiveId]) {
  if (!archiveId) return { pid, status: 'no-archive-id' }
  const file = join(OUT, 'heads', `${pid.replace(/[^\w.-]/g, '_')}.txt`)

  const { name, size, guessed } = await resolveTextFile(archiveId)
  const url = `https://archive.org/download/${encodeURIComponent(archiveId)}/${encodeURIComponent(name)}`

  for (let attempt = 0; attempt < 2; attempt++) {
    try {
      const res = await fetch(url, {
        headers: { Range: `bytes=0-${BYTES - 1}`, 'User-Agent': UA },
        redirect: 'follow',
      })
      if (res.status === 404) {
        if (guessed) return { pid, archiveId, status: 'no-text-file', name }
        nameCache.set(archiveId, { name: `${archiveId}_djvu.txt`, size: 0, guessed: true })
        continue
      }
      if (res.status === 416) {
        // file smaller than the range; ask for the whole thing
        const whole = await fetch(url, { headers: { 'User-Agent': UA } })
        if (!whole.ok) return { pid, archiveId, status: `http-${whole.status}` }
        const buf = Buffer.from(await whole.arrayBuffer())
        writeFileSync(file, buf)
        return { pid, archiveId, status: 'ok', bytes: buf.length, name, whole: true }
      }
      if (!res.ok) return { pid, archiveId, status: `http-${res.status}` }

      const buf = Buffer.from(await res.arrayBuffer())
      if (!buf.length) return { pid, archiveId, status: 'empty', name }
      writeFileSync(file, buf)
      return { pid, archiveId, status: 'ok', bytes: buf.length, name, totalBytes: size }
    } catch (e) {
      if (attempt === 1) return { pid, archiveId, status: 'error', error: String(e.message || e).slice(0, 140) }
    }
  }
  return { pid, archiveId, status: 'unresolved' }
}

// ---- run -----------------------------------------------------------------

const out = existsSync(manifest) ? appendFileSync.bind(null, manifest) : null
const stream = { write: (s) => appendFileSync(manifest, s) }

let n = 0
const stats = {}
const started = Date.now()

async function worker() {
  for (;;) {
    const job = queue.pop()
    if (!job) return
    const r = await fetchHead(job)
    stats[r.status] = (stats[r.status] || 0) + 1
    stream.write(JSON.stringify(r) + '\n')
    n++
    if (n % 100 === 0) {
      const rate = n / ((Date.now() - started) / 1000)
      const left = Math.round((queue.length - n) / rate / 60)
      console.log(`  ${n}/${queue.length}  ${rate.toFixed(1)}/s  ~${left} min left  ${JSON.stringify(stats)}`)
    }
  }
}

await Promise.all(Array.from({ length: CONCURRENCY }, worker))
console.log(`\n  done: ${JSON.stringify(stats)}`)
console.log(`  text cached in ${join(OUT, 'heads')}`)