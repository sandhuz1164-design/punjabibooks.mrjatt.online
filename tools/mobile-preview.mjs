#!/usr/bin/env node
/**
 * Mobile preview shots for the bookstore.
 *
 * Everything needed is already installed (Playwright + Chromium). No install.
 *
 *   node tools/mobile-preview.mjs                      # key pages, 3 devices
 *   node tools/mobile-preview.mjs /catalogue.html     # one page
 *   node tools/mobile-preview.mjs /books/foo.html iphone
 *
 * Output goes to /tmp/opencode/mobile/<device>-<page>.png
 */
import { chromium, devices } from '/run/media/jatt/9890EF3990EF1C8E/ubuntujatt/projects/scraper/node_modules/playwright/index.mjs'
import { mkdirSync } from 'node:fs'

const BASE = process.env.BASE || 'http://127.0.0.1:8081'
const OUT = '/tmp/opencode/mobile'
mkdirSync(OUT, { recursive: true })

// Real device metrics. isMobile + hasTouch make the page lay out and behave
// like a phone, not just a narrow window.
const PHONES = {
  iphone:  { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true },
  android: { viewport: { width: 360, height: 800 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true },
  small:   { viewport: { width: 320, height: 640 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
}

const DEFAULT_PAGES = [
  '/index.html',
  '/catalogue.html',
  '/browse/sikh-literature.html',
  '/books/panjabi-sanskrit-kosh.html',
  '/search.html',
]

const args = process.argv.slice(2)
const pages = args.filter(a => a.startsWith('/'))
const deviceArg = args.find(a => !a.startsWith('/'))
const chosen = deviceArg ? { [deviceArg]: PHONES[deviceArg] } : PHONES
const list = pages.length ? pages : DEFAULT_PAGES

const browser = await chromium.launch()
const report = []

for (const [name, cfg] of Object.entries(chosen)) {
  if (!cfg) { console.error(`unknown device "${name}" - try: ${Object.keys(PHONES).join(', ')}`); process.exit(1) }
  const ctx = await browser.newContext(cfg)
  const page = await ctx.newPage()

  for (const p of list) {
    const slug = (p.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'home')
    try {
      await page.goto(BASE + p, { waitUntil: 'domcontentloaded', timeout: 60000 })
      // Let lazy covers start arriving so the shot is not all empty boxes.
      await page.evaluate(async () => {
        for (let y = 0; y < Math.min(document.body.scrollHeight, 2500); y += 300) {
          window.scrollTo(0, y); await new Promise(r => setTimeout(r, 30))
        }
        window.scrollTo(0, 0)
      })
      await page.waitForTimeout(3500)

      const file = `${OUT}/${name}-${slug}.png`
      await page.screenshot({ path: file })

      const m = await page.evaluate(() => {
        const de = document.documentElement
        const small = new Set()
        document.querySelectorAll('a,button,input,select').forEach(el => {
          const r = el.getBoundingClientRect()
          if (r.width && r.height && (r.height < 44 || r.width < 44)) {
            small.add((el.className || el.tagName).toString().trim().split(/\s+/)[0].slice(0, 18))
          }
        })
        return {
          overflow: de.scrollWidth - de.clientWidth,
          height: de.scrollHeight,
          nodes: document.querySelectorAll('*').length,
          smallTargets: small.size,
          cards: document.querySelectorAll('.card').length,
        }
      })
      report.push({ device: name, page: p, ...m, file })
      const flag = m.overflow > 0 ? `  OVERFLOW +${m.overflow}px` : ''
      console.log(`${name.padEnd(8)} ${p.padEnd(34)} h=${String(m.height).padStart(7)} cards=${String(m.cards).padStart(4)} nodes=${String(m.nodes).padStart(6)} small=${String(m.smallTargets).padStart(4)}${flag}`)
    } catch (e) {
      console.log(`${name.padEnd(8)} ${p.padEnd(34)} FAILED: ${String(e.message).slice(0, 60)}`)
    }
  }
  await ctx.close()
}

await browser.close()
console.log(`\nshots in ${OUT}`)
const bad = report.filter(r => r.overflow > 0)
if (bad.length) {
  console.log(`\n${bad.length} page(s) with horizontal overflow:`)
  bad.forEach(b => console.log(`  ${b.device} ${b.page} +${b.overflow}px`))
}
