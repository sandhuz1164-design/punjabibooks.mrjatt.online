export default async function run(page, ui) {
  const out = {};

  // ---- sorting on a category page ----
  await page.goto('http://127.0.0.1:8081/browse/literature-fiction.html');
  await page.waitForSelector('#grid .card');

  const prices = () => page.$$eval('#grid .card', els => els.map(e => parseFloat(e.getAttribute('data-price'))));
  const titles = () => page.$$eval('#grid .card', els => els.map(e => e.getAttribute('data-title')));

  out.cardCount = (await prices()).length;
  out.defaultAsc = (await prices()).slice(0, 3);
  out.defaultSortedAsc = out.defaultAsc.every((v, i, a) => i === 0 || a[i - 1] <= v);

  // click "Price: high to low"
  const snap = await ui.snapshot();
  const btn = snap.match(/@(e\d+) button "Price: high to low"/)?.[1];
  out.foundSortButton = Boolean(btn);
  if (btn) {
    await ui.click(btn);
    await page.waitForTimeout(300);
    const desc = (await prices()).slice(0, 3);
    out.afterDesc = desc;
    out.defaultSortedDesc = desc.every((v, i, a) => i === 0 || a[i - 1] >= v);
    out.pressedState = await page.$$eval('#sortbar button', bs =>
      bs.filter(b => b.getAttribute('aria-pressed') === 'true').map(b => b.textContent.trim()));
  }

  // title sort
  const snap2 = await ui.snapshot();
  const tbtn = snap2.match(/@(e\d+) button "Title A–Z"/)?.[1];
  if (tbtn) {
    await ui.click(tbtn);
    await page.waitForTimeout(300);
    const t = await titles();
    out.titleSorted = t.every((v, i, a) => i === 0 ||
      a[i - 1].localeCompare(v) <= 0);
  }

  // ---- client-side search ----
  await page.goto('http://127.0.0.1:8081/search.html');
  await page.waitForSelector('#q');
  await page.waitForFunction(() => document.querySelectorAll('#grid .card').length > 0, null, { timeout: 8000 })
    .catch(() => {});

  out.indexLoaded = (await page.$$eval('#grid .card', e => e.length)) > 0;
  out.initialCount = await page.$$eval('#grid .card', e => e.length);

  await page.fill('#q', 'rowling');
  await page.waitForTimeout(500);
  out.searchCount = await page.$$eval('#grid .card', e => e.length);
  out.searchResults = await page.$$eval('#grid .card .card-title a', els =>
    els.slice(0, 5).map(e => e.textContent.trim()));
  out.markHighlighted = await page.$$eval('#grid mark', e => e.length);

  await page.fill('#q', 'zzzznotarealbook');
  await page.waitForTimeout(400);
  out.emptyState = await page.$$eval('#grid .empty', e => e.length);
  out.countText = await page.$eval('#search-count', e => e.textContent.trim());

  return out;
}
