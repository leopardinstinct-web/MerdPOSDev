const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.use({ channel: 'chrome' });

const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const portalRoot = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal');
const dashboardPath = path.join(portalRoot, 'dashboard.php');
const navigationPath = path.join(portalRoot, 'assets', 'navigation.js');
const accountPath = path.join(portalRoot, 'assets', 'account-menu.js');
const tokensPath = path.join(portalRoot, 'assets', 'design-tokens.css');
const shellCssPath = path.join(portalRoot, 'assets', 'shell.css');
const accountCssPath = path.join(portalRoot, 'assets', 'account-menu.css');

const fixtureHtml = `<!doctype html><html><head>
  <script data-id-order></script><script data-dashboard-builder></script>
  <link rel="stylesheet" data-dashboard-builder-css href="data:text/css,">
</head><body>
  <div id="shellAccountSources" hidden data-user-name="Imran" data-role-label="Developer" data-role-key="DEV">
    <button id="passwordBtn" type="button"><span>Change password</span></button>
    <button id="logoutBtn" type="button"><span>Log out</span></button>
  </div>
  <main class="merd-page-shell">
    <nav class="merd-nav"><div class="nav-group"><span class="nav-group-label">Overview</span>
      <button class="portal-tab active" data-panel="dashboardPanel"><span>Dashboard</span></button>
    </div></nav>
    <section id="dashboardPanel" class="portal-panel">Dashboard</section>
  </main>
  <dialog id="merdposAboutDialog" aria-labelledby="merdposAboutTitle"><h2 id="merdposAboutTitle">About</h2><button id="merdposAboutClose">Close</button></dialog>
</body></html>`;
async function mountShell(page, width = 1280) {
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  await page.setViewportSize({ width, height: 844 });
  await page.route('https://merdpos-smoke.invalid/shell-fixture', route => route.fulfill({
    status: 200, contentType: 'text/html', body: fixtureHtml,
  }));
  await page.route('**/api/client_context.php*', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      success: true, can_select_client: true, active_client_id: 1,
      csrf: 'test-csrf', client: { id: 1, name: 'Merd Retail Group', client_code: 'MRG' },
      clients: [
        { id: 1, name: 'Merd Retail Group', client_code: 'MRG', status: 'active' },
        { id: 2, name: 'DUMMY', client_code: 'DUMMY', status: 'active' },
      ],
    }),
  }));
  await page.goto('https://merdpos-smoke.invalid/shell-fixture');
  await page.addStyleTag({ path: tokensPath });
  await page.addStyleTag({ path: shellCssPath });
  await page.addStyleTag({ path: accountCssPath });
  await page.addScriptTag({ content: 'window.MERDPOSTheme={current:()=>"light",toggle:()=>{}};window.MERDPOS_AUTH={role_key:"DEV",role_label:"Developer"};' });
  await page.addScriptTag({ path: navigationPath });
  await page.addScriptTag({ path: accountPath });
  await expect(page.locator('.rail-client-section')).toHaveCount(1);
  return pageErrors;
}
test('dashboard source removes topbar and exposes sidebar account/About sources', async () => {
  const source = fs.readFileSync(dashboardPath, 'utf8');
  expect(source).not.toContain('<header class="topbar merd-topbar">');
  expect(source).toContain('id="shellAccountSources"');
  expect(source).toContain('id="merdposAboutDialog"');
  expect(source).toContain('assets/brand/M_Icon.svg');
  expect(source).toContain('assets/management.js?v=20260829studio3');
  expect(source).toContain('Smarter &middot; Faster &middot; Together');
  expect(source).toContain('&times;</button>');
});

test('desktop rail mounts client/account/theme/About in requested order', async ({ page }) => {
  const pageErrors = await mountShell(page, 1280);
  const rail = page.locator('.app-rail');
  const children = await rail.locator(':scope > *').evaluateAll(nodes => nodes.map(node => node.className));
  expect(children[0]).toContain('rail-client-section');
  await expect(rail.locator('#accountClientSelect')).toHaveValue('1');
  await expect(rail.locator('#passwordBtn')).toBeVisible();
  await expect(rail.locator('#logoutBtn')).toBeVisible();
  const utilityText = await rail.locator('.rail-shell-utilities').innerText();
  expect(utilityText.indexOf('Change password')).toBeLessThan(utilityText.indexOf('Dark mode'));
  expect(utilityText.indexOf('Log out')).toBeLessThan(utilityText.indexOf('Dark mode'));
  expect(utilityText.indexOf('Dark mode')).toBeLessThan(utilityText.indexOf('About MERDPOS'));
  await expect(rail.locator('.rail-about-toggle img')).toHaveAttribute('src', /assets\/brand\/M_Icon\.svg/);
  await rail.locator('.rail-about-toggle').click();
  await expect(page.locator('#merdposAboutDialog')).toHaveJSProperty('open', true);
  await page.locator('#merdposAboutClose').click();
  await expect(page.locator('#merdposAboutDialog')).toHaveJSProperty('open', false);
  expect(pageErrors).toEqual([]);
});
test('mobile utility sheet opens without a More navigation tab', async ({ page }) => {
  const pageErrors = await mountShell(page, 390);
  await expect(page.locator('.rail-client-section')).toBeHidden();
  await expect(page.locator('.rail-mobile-tools-section')).toHaveCount(0);
  await expect(page.locator('.rail-shell-utilities')).toBeHidden();
  await page.evaluate(() => window.MERDPOSShellUtilities.open());
  await expect(page.locator('body')).toHaveClass(/merd-mobile-tools-open/);
  await expect(page.locator('.rail-shell-utilities')).toBeVisible();
  await expect(page.locator('.rail-mobile-client-select')).toHaveValue('1');
  await expect(page.locator('.rail-about-toggle')).toBeVisible();
  await page.locator('.rail-about-toggle').click();
  await expect(page.locator('body')).not.toHaveClass(/merd-mobile-tools-open/);
  await expect(page.locator('#merdposAboutDialog')).toHaveJSProperty('open', true);
  expect(pageErrors).toEqual([]);
});
