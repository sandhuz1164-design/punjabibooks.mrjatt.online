// Ask Archive.org what it holds for each author name, and report identifiers the
// catalogue does not already have.
//
//   node tools/fetch-by-author.mjs [--limit N] [--concurrency N] [--resume]
//
// Resumable: results append to /tmp/opencode/authors/hits.jsonl keyed by the
// lowercased author, so a re-run skips completed names. 669 sequential searches
// would be slow; this runs 8 at a time.
//
// Written in node rather than PHP because this PHP build has no curl extension,
// so curl_multi is unavailable.

import { readFileSync, writeFileSync, existsSync, appendFileSync } from 'node:fs'

const OUT = '/tmp/opencode/authors/hits.jsonl'
const UA = 'StrandBookstore/1.0 (+local library; catalogue gap analysis)'
const ROWS = 200

const argv = process.argv.slice(2)
const opt = (name, dflt) => {
  const i = argv.indexOf(`--${name}`)
  if (i === -1) return dflt
  const next = argv[i + 1]
  return next && !next.startsWith('--') ? next : dflt
}
const limit = Number(opt('limit', 0))
const concurrency = Number(opt('concurrency', 8))

const names = readFileSync('/tmp/opencode/authors/all.txt', 'utf8')
  .split('\n').map((s) => s.trim()).filter(Boolean)

const done = new Set()
if (existsSync(OUT)) {
  for (const line of readFileSync(OUT, 'utf8').split('\n')) {
    if (!line.trim()) continue
    try {
      const d = JSON.parse(line)
      if (d.key) done.add(d.key)
    } catch { /* a truncated line from an interrupted run; ignore */ }
  }
}
console.log(`  authors: ${names.length}, already done: ${done.size}`)

let todo = names.filter((n) => !done.has(n.toLowerCase()))
if (limit > 0) todo = todo.slice(0, limit)
console.log(`  to search: ${todo.length}`)

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

async function searchAuthor(name) {
  const byId = new Map()
  let total = -1

  // Two query shapes, unioned:
  //   creator:"Name"  exact creator string - authoritative for "books by exactly
  //                  this person", and it is what the catalogue's Author column
  //                  is meant to mirror.
  //   creator:Name    token AND, which also catches items whose creator string
  //                  varies ("Vir Singh", "Bhai Vir Singh, editor") and would
  //                  otherwise be reported as missing when they are not.
  // Both are needed: the exact form alone under-reports, the token form alone
  // pulls in unrelated authors who happen to share a word.
  // mediatype:texts is mandatory on BOTH forms. It was missing from this query
  // when the two-form version was first written, and the result was a gap report
  // full of audio: "Darshan Singh" showed 130 missing books that were in fact
  // CD audio of Guru Granth Sahib recitations, mediatype=audio.
  const queries = [
    `creator:"${name.replace(/"/g, '')}" AND mediatype:texts`,
    `creator:${name.replace(/"/g, '')} AND mediatype:texts`,
  ]

  for (const q of queries) {
    for (let page = 1; page <= 50; page++) {
      const url = 'https://archive.org/advancedsearch.php?q=' + encodeURIComponent(q)
        + '&fl[]=identifier&fl[]=title&fl[]=creator&fl[]=language&fl[]=year'
        + '&sort[]=identifier asc&rows=' + ROWS + '&page=' + page + '&output=json'
      let d
      try {
        const res = await fetch(url, { headers: { 'User-Agent': UA }, signal: AbortSignal.timeout(60000) })
        if (!res.ok) break
        d = await res.json()
      } catch {
        break
      }
      const n = Number(d?.response?.numFound ?? 0)
      if (page === 1 && queries.indexOf(q) === 0) total = n
      const docs = d?.response?.docs ?? []
      for (const doc of docs) {
        if (doc.identifier && !byId.has(doc.identifier)) {
          byId.set(doc.identifier, { ...doc, exact: q.includes('"') })
        }
      }
      if (docs.length < ROWS) break
      await sleep(150)
    }
    await sleep(100)
  }

  return { ids: [...byId.values()], total }
}

const started = Date.now()
let written = 0
let cursor = 0
let failures = 0

async function worker() {
  while (true) {
    const i = cursor++
    if (i >= todo.length) return
    const name = todo[i]
    const { ids, total } = await searchAuthor(name)
    if (total < 0) failures++
    appendFileSync(OUT, JSON.stringify({
      key: name.toLowerCase(), author: name, total, ids,
    }) + '\n')
    written++
    if (written % 25 === 0) {
      const secs = (Date.now() - started) / 1000
      console.log(`  ${written}/${todo.length}  (${secs.toFixed(0)}s)`)
    }
  }
}

await Promise.all(Array.from({ length: concurrency }, worker))
console.log(`  done: ${written} authors in ${((Date.now() - started) / 1000).toFixed(0)}s, ${failures} failed`)