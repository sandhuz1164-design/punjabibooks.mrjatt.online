// Fetch each book's COMPLETE text from Archive.org and cache it locally.
//
// The head-only pass (tools/fetch-book-heads.mjs) proved that Archive.org's _djvu.txt is
// cheap to fetch and that its OCR is readable about half the time. But the opening of a
// book is its title page, which tells a reader nothing about the contents. To write an
// honest description you need the body.
//
// This downloads the whole text file per item rather than a Range request, because a
// summary has to see the whole book to know what it is about. Files are written to the
// external drive so the network is touched once per book, and the run resumes from a
// manifest so an interruption costs nothing.
//
// Cost, measured not guessed: see tools/fulltext-size-report.php.

import { mkdirSync, existsSync, writeFileSync, readFileSync, appendFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

const OUT = process.env.TEXT_OUT || '/run/media/jatt/9890EF3990EF1C8E/bookstore-text'
const LIST = process.env.PID_LIST || '/tmp/opencode/booktext/pids.txt'
const CONCURRENCY = Number(process.env.CONCURRENCY || 6)
const LIMIT = Number(process.env.LIMIT || 0)
const MAX_BYTES = Number(process.env.MAX_BYTES || 32 * 1024 * 1024)
const UA = 'BookStore-v2 catalogue enrichment (local project)'

const FULL = join(OUT, 'full')
mkdirSync(FULL, { recursive: true })
const manifest = join(OUT, 'full-manifest.jsonl')

const safe = (pid) => pid.replace(/[^\w.-]/g, '_')

// ---- resume --------------------------------------------------------------

const done = new Set()
if (existsSync(manifest)) {
  for (const line of readFileSync(manifest, 'utf8').split('\n')) {
    if (!line) continue
    try {
      const r = JSON.parse(line)
      if (r.status === 'ok' || r.status === 'too-big' || r.status === 'no-text-file') done.add(r.pid)
    } catch { /* truncated line */ }
  }
}

const all = readFileSync(LIST, 'utf8').split('\n').map(s => s.trim()).filter(Boolean).map(l => l.split('\t'))
const pending = all.filter(([pid]) => !done.has(pid))
const queue = LIMIT > 0 ? pending.slice(0, LIMIT) : pending
console.log(`  ${queue.length} to fetch, ${done.size} done, ${CONCURRENCY} concurrent`)

// ---- name resolution, reused from the head pass --------------------------

const nameCache = new Map()
async function resolveTextFile(archiveId) {
  if (nameCache.has(archiveId)) return nameCache.get(archiveId)
  let result = null
  try {
    const res = await fetch(`https://archive.org/metadata/${encodeURIComponent(archiveId)}`,
      { headers: { 'User-Agent': UA } })
    if (res.ok) {
      const files = (await res.json()).files || []
      const t = files.find(f => /_djvu\.txt$/i.test(f.name || ''))
      || files.find(f => /\.txt$/i.test(f.name || '') && !/hocr|searchtext/i.test(f.name))
      if (t) result = { name: t.name, size: Number(t.size || 0) }
    }
  } catch { /* guess below */ }
  if (!result) result = { name: `${archiveId}_djvu.txt`, size: 0, guessed: true }
  nameCache.set(archiveId, result)
  return result
}

// ---- fetch ---------------------------------------------------------------

async function fetchFull([pid, archiveId]) {
  if (!archiveId) return { pid, status: 'no-archive-id' }
  const file = join(FULL, `${safe(pid)}.txt`)
  const { name, size, guessed } = await resolveTextFile(archiveId)

  // Skip the obviously huge ones rather than stalling the queue on one 200MB file.
  if (size > MAX_BYTES) return { pid, archiveId, status: 'too-big', size }

  const url = `https://archive.org/download/${encodeURIComponent(archiveId)}/${encodeURIComponent(name)}`
  try {
    const res = await fetch(url, { headers: { 'User-Agent': UA }, redirect: 'follow' })
    if (res.status === 404) return { pid, archiveId, status: guessed ? 'no-text-file' : 'no-text-file', name }
    if (!res.ok) return { pid, archiveId, status: `http-${res.status}`, name }
    const buf = Buffer.from(await res.arrayBuffer())
    if (!buf.length) return { pid, archiveId, status: 'empty', name }
    writeFileSync(file, buf)
    return { pid, archiveId, status: 'ok', bytes: buf.length, name }
  } catch (e) {
    return { pid, archiveId, status: 'error', error: String(e.message || e).slice(0, 120) }
  }
}

// ---- run -----------------------------------------------------------------

let n = 0
const stats = {}
let bytes = 0
const started = Date.now()

async function worker() {
  for (;;) {
    const job = queue.pop()
    if (!job) return
    const r = await fetchFull(job)
    stats[r.status] = (stats[r.status] || 0) + 1
    bytes += r.bytes || 0
    appendFileSync(manifest, JSON.stringify(r) + '\n')
    n++
    if (n % 50 === 0) {
      const el = (Date.now() - started) / 1000
      const rate = n / el
      console.log(`  ${n}/${queue.length}  ${rate.toFixed(1)}/s  ${(bytes / 1048576).toFixed(0)} MB  `
        + `~${Math.round((queue.length - n) / rate / 60)} min left  ${JSON.stringify(stats)}`)
    }
  }
}

await Promise.all(Array.from({ length: CONCURRENCY }, worker))
console.log(`\n  done: ${JSON.stringify(stats)}`)
console.log(`  ${(bytes / 1048576).toFixed(0)} MB cached in ${FULL}`)