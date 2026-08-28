const { test, expect } = require('@playwright/test');
const path = require('path');

test.use({ channel: 'chrome' });
const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const portalRoot = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal');
const tokensPath = path.join(portalRoot, 'assets', 'design-tokens.css');
const studioCssPath = path.join(portalRoot, 'assets', 'ui-studio.css');
const studioJsPath = path.join(portalRoot, 'assets', 'ui-studio.js');

const fixture = `<!doctype html><html><head><style>:root{--shell-mobile-nav-h:4.75rem}</style></head><body>
  <button id="openUiStudioBtn" type="button">Open UI Studio</button>
  <main class="merd-page-shell">
    <section id="demoPanel" class="portal-panel">
      <header class="merd-mobile-page-head"><div class="merd-mobile-page-copy"><span class="merd-mobile-context">Demo client</span><p>Demo helper</p></div></header>
      <article id="cardA" class="controls-card"><strong>Card A</strong></article>
      <article id="cardB" class="controls-card"><strong>Card B</strong></article>
    </section>
    <section id="secondPanel" class="portal-panel">
      <header class="merd-mobile-page-head"><div class="merd-mobile-page-copy"><span class="merd-mobile-context">Second client</span><p>Second helper</p></div></header>
    </section>
  </main>
</body></html>`;

async function mount(page, isDev = true, width = 1280) {
  await page.setViewportSize({ width, height: width <= 820 ? 783 : 844 });
  await page.addInitScript(() => {
    window.__studioCopied = '';
    const clipboard = { writeText: async text => { window.__studioCopied = String(text); } };
    try { Object.defineProperty(navigator, 'clipboard', { configurable: true, value: clipboard }); }
    catch (_) { try { navigator.clipboard.writeText = clipboard.writeText; } catch (_) {} }
  });
  await page.route('https://merdpos-smoke.invalid/ui-studio', route => route.fulfill({ status: 200, contentType: 'text/html', body: fixture }));
  await page.goto('https://merdpos-smoke.invalid/ui-studio');
  await page.addStyleTag({ path: tokensPath });
  await page.addStyleTag({ path: studioCssPath });
  await page.addScriptTag({ content: `window.MERDPOS_AUTH={is_dev:${isDev ? 'true' : 'false'},role_key:${isDev ? '"DEV"' : '"ADMIN"'}};` });
  await page.addScriptTag({ path: studioJsPath });
}
async function reloadRuntime(page) {
  await page.reload();
  await page.addStyleTag({ path: tokensPath });
  await page.addStyleTag({ path: studioCssPath });
  await page.addScriptTag({ content: 'window.MERDPOS_AUTH={is_dev:true,role_key:"DEV"};' });
  await page.addScriptTag({ path: studioJsPath });
}
const hub = page => page.locator('.merd-ui-hub');
const item = (page, name) => page.getByRole('menuitem', { name, exact: true });

async function choose(page, name) {
  await item(page, name).click();
}

test('UI Studio is unavailable without actual DEV identity', async ({ page }) => {
  await mount(page, false);
  await expect(page.locator('.merd-ui-hub')).toHaveCount(0);
  await expect(page.locator('.merd-ui-menu')).toHaveCount(0);
  expect(await page.evaluate(() => typeof window.MERDPOS_UI_STUDIO)).toBe('undefined');
});

test('DEV edits and moves through native circular menus without an inspector panel', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  await mount(page, true, 1280);
  await page.locator('#openUiStudioBtn').click();
  await expect(hub(page)).toBeVisible();
  await expect(page.locator('.merd-ui-studio')).toHaveCount(0);
  await expect(page.locator('textarea[data-studio-output]')).toHaveCount(0);

  await hub(page).click();
  await choose(page, 'Select');
  await page.locator('#cardA').click();

  await hub(page).click();
  await choose(page, 'Color');
  await choose(page, 'Violet');
  await choose(page, 'Back');
  await choose(page, 'Layout');
  await choose(page, 'Padding');
  await choose(page, '24');
  await choose(page, 'Back');
  await choose(page, 'Back');
  await choose(page, 'Move');
  await choose(page, 'After');
  await page.locator('#cardB').click();

  const previewText = await page.locator('#merdUiStudioPreviewStyle').evaluate(el => el.textContent || '');
  expect(previewText).toContain('background-color:var(--color-brand-violet)');
  expect(previewText).toContain('padding:24px');
  expect(await page.locator('#demoPanel > article').evaluateAll(nodes => nodes.map(node => node.id))).toEqual(['cardB','cardA']);
  const payload = await page.evaluate(() => window.MERDPOS_UI_STUDIO.getChangeSet());
  expect(payload.patches).toEqual(expect.arrayContaining([
    expect.objectContaining({ kind:'style', selector:'#cardA', property:'background-color', value:'var(--color-brand-violet)' }),
    expect.objectContaining({ kind:'style', selector:'#cardA', property:'padding', value:'24px' }),
    expect.objectContaining({ kind:'move', selector:'#cardA', target:'#cardB', position:'after' }),
  ]));

  await reloadRuntime(page);
  expect(await page.locator('#demoPanel > article').evaluateAll(nodes => nodes.map(node => node.id))).toEqual(['cardB','cardA']);
  const restoredCss = await page.locator('#merdUiStudioPreviewStyle').evaluate(el => el.textContent || '');
  expect(restoredCss).toContain('background-color:var(--color-brand-violet)');
  expect(restoredCss).toContain('padding:24px');
  expect(pageErrors).toEqual([]);
});

test('scope and palette layers apply one edit across all pages', async ({ page }) => {
  await mount(page, true, 1280);
  await page.locator('#openUiStudioBtn').click();
  await hub(page).click();
  await choose(page, 'Select');
  await page.locator('#demoPanel .merd-mobile-context').click();

  await hub(page).click();
  await choose(page, 'Scope');
  await choose(page, 'All pages');
  await choose(page, 'Back');
  await choose(page, 'Color');
  await choose(page, 'BG');
  await choose(page, 'Cyan');

  const colors = await page.locator('.merd-mobile-page-head .merd-mobile-context').evaluateAll(nodes => nodes.map(node => getComputedStyle(node).color));
  expect(new Set(colors).size).toBe(1);
  expect(colors[0]).toBe('rgb(18, 189, 243)');

  await choose(page, 'Back');
  await choose(page, 'Select');
  await page.locator('#demoPanel .merd-mobile-page-copy > p').click();
  await hub(page).click();
  await choose(page, 'Hide');
  expect(await page.locator('.merd-mobile-page-head .merd-mobile-page-copy > p').evaluateAll(nodes => nodes.every(node => getComputedStyle(node).display === 'none'))).toBe(true);

  const payload = await page.evaluate(() => window.MERDPOS_UI_STUDIO.getChangeSet());
  expect(payload.patches).toEqual(expect.arrayContaining([
    expect.objectContaining({ scope:'pages', selector:'.merd-mobile-page-head span.merd-mobile-context', property:'color', value:'var(--color-brand-cyan)' }),
    expect.objectContaining({ scope:'pages', selector:'.merd-mobile-page-head p', property:'display', value:'none' }),
  ]));
});

async function menuBoxes(page) {
  return page.locator('.merd-ui-menu-item').evaluateAll(nodes => nodes.map(node => {
    const r=node.getBoundingClientRect(); return {x:r.x,y:r.y,width:r.width,height:r.height};
  }));
}
function expectBoxesInside(boxes, viewport, bottomReserve = 0) {
  expect(boxes.length).toBeGreaterThan(0);
  for (const box of boxes) {
    expect(box.x).toBeGreaterThanOrEqual(-1);
    expect(box.y).toBeGreaterThanOrEqual(-1);
    expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 1);
    expect(box.y + box.height).toBeLessThanOrEqual(viewport.height - bottomReserve + 1);
  }
}

test('native circular Studio is draggable, adaptive, nested, and exposes chat handoff on mobile', async ({ page }) => {
  const pageErrors=[];
  page.on('pageerror',error=>pageErrors.push(String(error?.message||error)));
  await mount(page,true,384);
  await page.locator('#openUiStudioBtn').click();
  await expect(hub(page)).toBeVisible();
  await expect(page.locator('.merd-ui-studio')).toHaveCount(0);
  await expect(page.locator('textarea')).toHaveCount(0);

  await hub(page).click();
  await choose(page,'Select');
  await page.locator('#cardA').click();

  const before=await hub(page).boundingBox();
  await page.mouse.move(before.x+before.width/2,before.y+before.height/2);
  await page.mouse.down();
  await page.mouse.move(58,128,{steps:8});
  await page.mouse.up();
  const after=await hub(page).boundingBox();
  expect(after.x).toBeLessThan(before.x);
  expect(after.y).toBeLessThan(before.y);

  await hub(page).click();
  await expect(item(page,'Color')).toBeVisible();
  expectBoxesInside(await menuBoxes(page),page.viewportSize(),76);
  await choose(page,'Color');
  for(const name of ['White','Canvas','Navy','Cyan','Violet'])await expect(item(page,name)).toBeVisible();
  expectBoxesInside(await menuBoxes(page),page.viewportSize(),76);
  await choose(page,'Violet');
  await choose(page,'Back');
  await page.getByRole('menuitem',{name:/^Changes/}).click();
  await expect(item(page,'Copy')).toBeVisible();
  await expect(item(page,'Chat')).toBeVisible();
  await choose(page,'Chat');
  await expect.poll(()=>page.evaluate(()=>window.__studioCopied)).toContain('Apply these MERDPOS UI Studio preview changes');
  await expect.poll(()=>page.evaluate(()=>window.__studioCopied)).toContain('background-color');
  expect(pageErrors).toEqual([]);
});
