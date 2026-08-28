const { test, expect } = require('@playwright/test');
const path = require('path');

test.use({ channel: 'chrome' });
const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const portalRoot = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal');
const tokensPath = path.join(portalRoot, 'assets', 'design-tokens.css');
const studioCssPath = path.join(portalRoot, 'assets', 'ui-studio.css');
const studioJsPath = path.join(portalRoot, 'assets', 'ui-studio.js');
const circularCssPath = path.join(portalRoot, 'assets', 'vendor', 'circular-menu', 'circular-menu.css');
const circularJsPath = path.join(portalRoot, 'assets', 'vendor', 'circular-menu', 'circular-menu.js');

const fixture = `<!doctype html><html><head></head><body>
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
  await page.route('https://merdpos-smoke.invalid/ui-studio', route => route.fulfill({ status: 200, contentType: 'text/html', body: fixture }));
  await page.goto('https://merdpos-smoke.invalid/ui-studio');
  await page.addStyleTag({ path: tokensPath });
  await page.addStyleTag({ path: studioCssPath });
  if (isDev) { await page.addStyleTag({ path: circularCssPath }); await page.addScriptTag({ path: circularJsPath }); }
  await page.addScriptTag({ content: `window.MERDPOS_AUTH={is_dev:${isDev ? 'true' : 'false'},role_key:${isDev ? '"DEV"' : '"ADMIN"'}};` });
  await page.addScriptTag({ path: studioJsPath });
}

test('UI Studio is unavailable without actual DEV identity', async ({ page }) => {
  await mount(page, false);
  await expect(page.locator('.merd-ui-studio')).toHaveCount(0);
  expect(await page.evaluate(() => typeof window.MERDPOS_UI_STUDIO)).toBe('undefined');
});

test('DEV can build a preview-only style and move change-set', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  await mount(page, true);
  await page.locator('#openUiStudioBtn').click();
  await expect(page.locator('.merd-ui-studio')).toBeVisible();
  await expect(page.locator('.ui-studio-head')).toContainText('PREVIEW ONLY');

  await page.locator('[data-studio-select]').click();
  await page.locator('#cardA').click();
  await expect(page.locator('[data-studio-selector]')).toHaveText('#cardA');
  const colorSelects = page.locator('[data-studio-style-select]');
  await colorSelects.nth(0).selectOption('var(--color-brand-violet)');
  await page.locator('[data-style-prop="padding"]').fill('24px');
  await page.locator('[data-style-prop="padding"]').press('Enter');
  await page.locator('[data-style-prop="padding"]').blur();
  const previewCss = page.locator('#merdUiStudioPreviewStyle');
  const previewText = await previewCss.evaluate(el => el.textContent || '');
  expect(previewText).toContain('[data-ui-studio-runtime-key=');
  expect(previewText).toContain('background-color:var(--color-brand-violet)');
  expect(previewText).toContain('padding:24px');

  await page.locator('[data-studio-move="after"]').click();
  await page.locator('#cardB').click();
  const order = await page.locator('#demoPanel > article').evaluateAll(nodes => nodes.map(node => node.id));
  expect(order).toEqual(['cardB', 'cardA']);
  await expect(page.locator('[data-studio-count]')).toHaveText('3');

  const payload = JSON.parse(await page.locator('[data-studio-output]').inputValue());
  expect(payload.patches).toEqual(expect.arrayContaining([
    expect.objectContaining({ kind: 'style', selector: '#cardA', property: 'background-color', value: 'var(--color-brand-violet)' }),
    expect.objectContaining({ kind: 'style', selector: '#cardA', property: 'padding', value: '24px' }),
    expect.objectContaining({ kind: 'move', selector: '#cardA', target: '#cardB', position: 'after' }),
  ]));
  await page.reload();
  await page.addStyleTag({ path: tokensPath });
  await page.addStyleTag({ path: studioCssPath });
  await page.addStyleTag({ path: circularCssPath }); await page.addScriptTag({ path: circularJsPath });
  await page.addScriptTag({ content: 'window.MERDPOS_AUTH={is_dev:true,role_key:"DEV"};' });
  await page.addScriptTag({ path: studioJsPath });
  const restoredOrder = await page.locator('#demoPanel > article').evaluateAll(nodes => nodes.map(node => node.id));
  expect(restoredOrder).toEqual(['cardB', 'cardA']);
  const restoredCss = await page.locator('#merdUiStudioPreviewStyle').evaluate(el => el.textContent || '');
  expect(restoredCss).toContain('background-color:var(--color-brand-violet)');
  expect(restoredCss).toContain('padding:24px');
  await expect(page.locator('.merd-ui-studio-badge')).toBeVisible();
  expect(pageErrors).toEqual([]);
});


test('scope control can apply one mobile-header edit across all pages', async ({ page }) => {
  await mount(page, true, 1280);
  await page.locator('#openUiStudioBtn').click();
  await page.locator('[data-studio-select]').click();
  await page.locator('#demoPanel .merd-mobile-context').click();
  await page.locator('[data-studio-scope]').selectOption('pages');
  await expect(page.locator('[data-studio-selector]')).toHaveText('.merd-mobile-page-head span.merd-mobile-context');
  await expect(page.locator('[data-studio-match-count]')).toHaveText('2 matches');
  await page.locator('[data-studio-style-select]').nth(1).selectOption('var(--color-brand-cyan)');
  const colors = await page.locator('.merd-mobile-page-head .merd-mobile-context').evaluateAll(nodes => nodes.map(node => getComputedStyle(node).color));
  expect(new Set(colors).size).toBe(1);

  await page.locator('[data-studio-select]').click();
  await page.locator('#demoPanel .merd-mobile-page-copy > p').click();
  await page.locator('[data-studio-scope]').selectOption('pages');
  await page.locator('[data-studio-hide]').click();
  await expect(page.locator('.merd-mobile-page-head .merd-mobile-page-copy > p')).toHaveCount(2);
  expect(await page.locator('.merd-mobile-page-head .merd-mobile-page-copy > p').evaluateAll(nodes => nodes.every(node => getComputedStyle(node).display === 'none'))).toBe(true);
  const payload = JSON.parse(await page.locator('[data-studio-output]').inputValue());
  expect(payload.patches).toEqual(expect.arrayContaining([
    expect.objectContaining({ scope: 'pages', selector: '.merd-mobile-page-head span.merd-mobile-context', property: 'color', value: 'var(--color-brand-cyan)' }),
    expect.objectContaining({ scope: 'pages', selector: '.merd-mobile-page-head p', property: 'display', value: 'none' }),
  ]));
});


test('mobile UI Studio stays compact and exposes circular quick actions', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  await mount(page, true, 384);
  await page.locator('#openUiStudioBtn').click();
  await expect(page.locator('.merd-ui-studio')).toBeHidden();
  await expect(page.locator('.merd-ui-studio-mobile-hub')).toBeVisible();
  await expect(page.locator('#cardA')).toBeVisible();

  await page.locator('.merd-ui-studio-mobile-hub').click();
  await expect(page.locator('.merd-ui-studio-radial')).toHaveClass(/opened-nav/);
  await page.locator('.merd-ui-studio-radial a[aria-label="Select element"]').click();
  await page.locator('#cardA').click();
  await expect(page.locator('.merd-ui-studio')).toBeVisible();
  const box = await page.locator('.merd-ui-studio').boundingBox();
  expect(box.height).toBeLessThanOrEqual(370);
  await expect(page.locator('[data-studio-scope]')).toBeVisible();
  expect(pageErrors).toEqual([]);
});
