// Single place that resolves Playwright.
//
// It used to be imported by absolute path from an ntfs3 autofs mount, which is
// the same mount that silently dropped a written file within the hour. Three
// files each hardcoding that path meant three places to fix and no way to point
// the suite somewhere else. Resolution order:
//
//   1. $PLAYWRIGHT_PATH          - explicit override, for when the mount moves
//   2. bare "playwright"         - a normal node_modules install
//   3. the legacy absolute path  - so existing checkouts keep working
//
// Add new browser tests by importing { chromium } from here. Do not name a
// Playwright path anywhere else.

import { createRequire } from 'node:module'

const LEGACY = '/run/media/jatt/9890EF3990EF1C8E/ubuntujatt/projects/scraper/node_modules/playwright/index.mjs'

export const BASE = process.env.READER_BASE || 'http://127.0.0.1:8081'

/** Reader page for a catalogue PID. */
export const READER_URL = (pid) => `${BASE}/read/${slug(pid)}.html`

/** Mirrors book_slug() in build/layout.php. */
export function slug(pid) {
  return String(pid).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
}

async function loadPlaywright() {
  const override = process.env.PLAYWRIGHT_PATH
  if (override) return import(override)
  try {
    return await import('playwright')
  } catch {
    return import(LEGACY)
  }
}

export const { chromium } = await loadPlaywright()

export const require = createRequire(import.meta.url)
