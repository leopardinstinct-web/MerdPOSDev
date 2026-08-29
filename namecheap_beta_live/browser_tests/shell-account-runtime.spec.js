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
    <nav class="merd-nav">
      <div class="nav-group"><span class="nav-group-label">Overview</span><button class="portal-tab active" data-panel="dashboardPanel"><span>Dashboard</span></button></div>
      <div class="nav-group"><span class="nav-group-label">Operations</span><button class="portal-tab" data-panel="storesPanel"><span>Stores</span></button><button class="portal-tab" data-panel="employeesPanel"><span>Workforce</span></button></div>
      <div class="nav-group"><span class="nav-group-label">Reports</span><button class="portal-tab" data-panel="reportsPanel"><span>Overview</span></button><button class="portal-tab" data-panel="timesheetPanel"><span>Timesheets</span></button><button class="portal-tab" data-panel="disputesPanel"><span>Disputes</span></button></div>
      <div class="nav-group"><span class="nav-group-label">Finance</span><button class="portal-tab" data-panel="financialPanel"><span>Financial</span></button></div>
      <div class="nav-group"><span class="nav-group-label">System</span><button class="portal-tab dev-tab" data-panel="devPanel"><span>DEV</span></button></div>
    </nav>
    <section id="dashboardPanel" class="portal-panel">Dashboard</section><section id="storesPanel" class="portal-panel" hidden>Stores</section><section id="employeesPanel" class="portal-panel" hidden>Workforce</section><section id="reportsPanel" class="portal-panel" hidden>Reports</section><section id="timesheetPanel" class="portal-panel" hidden>Timesheets</section><section id="disputesPanel" class="portal-panel" hidden>Disputes</section><section id="financialPanel" class="portal-panel" hidden>Financial</section><section id="devPanel" class="portal-panel" hidden>DEV</section>
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
  expect(source).toContain('assets/management.js?v=20260830bottomstudio15');
  expect(source).toContain('Smarter &middot; Faster &middot; Together');
  expect(source).toContain('&times;</button>');
});

test('desktop uses the mobile-style bottom dock plus one account/client circle', async ({ page }) => {
  const pageErrors = await mountShell(page, 1280);const rail=page.locator('.app-rail');await expect(page.locator('.app-frame')).toHaveClass(/nav-bottom/);const primary=rail.locator(':scope > .rail-section:not([data-nav-section="system"])');await expect(primary).toHaveCount(4);await expect(rail.locator(':scope > .rail-section[data-nav-section="system"]')).toBeHidden();await expect(rail.locator('.rail-client-section')).toBeHidden();await expect(rail.locator('.merd-shell-account-trigger')).toBeVisible();
  const geom=await rail.evaluate(el=>{const r=el.getBoundingClientRect();return {bottom:innerHeight-r.bottom,height:r.height,position:getComputedStyle(el).position}});expect(Math.abs(geom.bottom)).toBeLessThan(2);expect(geom.position).toBe('fixed');expect(geom.height).toBeGreaterThan(60);
  await expect(rail.locator('.rail-shell-utilities')).toBeHidden();await rail.locator('.merd-shell-account-trigger').click();await expect(page.locator('body')).toHaveClass(/merd-mobile-tools-open/);await expect(rail.locator('.rail-shell-utilities')).toBeVisible();await expect(rail.locator('.rail-mobile-client-select')).toHaveValue('1');await expect(rail.locator('.rail-user-summary')).toContainText('Imran');await expect(rail.locator('.rail-mobile-system-links')).toContainText('DEV');
  const utilityText=await rail.locator('.rail-shell-utilities').innerText();expect(utilityText.indexOf('Change password')).toBeLessThan(utilityText.indexOf('Dark mode'));expect(utilityText.indexOf('Log out')).toBeLessThan(utilityText.indexOf('Dark mode'));expect(utilityText.indexOf('Dark mode')).toBeLessThan(utilityText.indexOf('About MERDPOS'));await rail.locator('.rail-about-toggle').click();await expect(page.locator('#merdposAboutDialog')).toHaveJSProperty('open',true);await page.locator('#merdposAboutClose').click();await expect(page.locator('#merdposAboutDialog')).toHaveJSProperty('open',false);
  await page.locator('[data-nav-group="operations"]').click();await expect(page.locator('#storesPanel')).toBeVisible();await expect(page.locator('[data-sidebar-group="operations"]')).toBeVisible();expect(pageErrors).toEqual([]);
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
