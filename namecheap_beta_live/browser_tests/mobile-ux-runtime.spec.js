const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.use({ channel: 'chrome' });

const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const portalRoot = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal');
const asset = name => path.join(portalRoot, 'assets', name);

const fixtureHtml = `<!doctype html><html><head>
  <script data-id-order></script><script data-dashboard-builder></script>
  <link rel="stylesheet" data-dashboard-builder-css href="data:text/css,">
</head><body class="merd-shell">
  <div id="shellAccountSources" hidden data-user-name="Imran" data-role-label="Developer" data-role-key="DEV">
    <button id="passwordBtn" type="button"><span>Change password</span></button>
    <button id="logoutBtn" type="button"><span>Log out</span></button>
  </div>
  <main class="merd-page-shell">
    <nav class="merd-nav">
      <div class="nav-group"><span class="nav-group-label">Overview</span><button class="portal-tab active" data-panel="dashboardPanel"><span>Dashboard</span></button></div>
      <div class="nav-group"><span class="nav-group-label">Operations</span><button class="portal-tab" data-panel="employeesPanel"><span>Workforce</span></button><button class="portal-tab" data-panel="storesPanel"><span>Stores</span></button></div>
      <div class="nav-group"><span class="nav-group-label">Reports</span><button class="portal-tab" data-panel="reportsPanel"><span>Overview</span></button><button class="portal-tab" data-panel="timesheetPanel"><span>Timesheets</span></button></div>
      <div class="nav-group"><span class="nav-group-label">Finance</span><button class="portal-tab" data-panel="financialPanel"><span>Financial</span></button></div>
      <div class="nav-group"><span class="nav-group-label">System</span><button class="portal-tab" data-panel="devPanel"><span>DEV</span></button></div>
    </nav>
    <section id="dashboardPanel" class="portal-panel">
      <div class="status-card">Loading dashboard...</div>
      <div class="table-scroll"><table><thead><tr><th>Store</th><th>Sales</th></tr></thead><tbody><tr><td>Rosebay Tobacco</td><td>A$123</td></tr></tbody></table></div>
      <div style="height:1200px"></div>
    </section>
    <section id="employeesPanel" class="portal-panel" hidden><section class="directory-card"><div class="directory-toolbar"><div><h2>Employees</h2><p>People</p></div><div class="directory-actions"><label class="search-box"><input type="search" placeholder="Search employees"></label><button id="addEmployeeBtn">Add employee</button></div></div></section></section>
    <section id="storesPanel" class="portal-panel" hidden><section class="directory-card"><div class="directory-toolbar"><div><h2>Stores</h2><p>Stores</p></div><div class="directory-actions"><label class="search-box"><input type="search" placeholder="Search stores"></label><button id="addStoreBtn">Add store</button></div></div></section></section>
    <section id="reportsPanel" class="portal-panel" hidden><header class="app-panel-head"><h2>Reports</h2></header></section>
    <section id="timesheetPanel" class="portal-panel" hidden><header class="app-panel-head"><h2>Timesheets</h2></header></section>
    <section id="financialPanel" class="portal-panel" hidden><div>Financial content</div></section>
    <section id="devPanel" class="portal-panel" hidden><div>DEV content</div></section>
  </main>
  <dialog id="merdposAboutDialog"><button id="merdposAboutClose">Close</button></dialog>
</body></html>`;

async function mount(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error?.message || error)));
  await page.setViewportSize({ width: 390, height: 844 });
  await page.route('https://merdpos-mobile.invalid/fixture', route => route.fulfill({ status:200, contentType:'text/html', body:fixtureHtml }));
  await page.route('**/api/client_context.php*', route => route.fulfill({
    status:200, contentType:'application/json', body:JSON.stringify({
      success:true, can_select_client:true, active_client_id:1, csrf:'test',
      client:{id:1,name:'Merd Retail Group',client_code:'MRG'},
      clients:[{id:1,name:'Merd Retail Group',client_code:'MRG',status:'active'}]
    })
  }));
  await page.goto('https://merdpos-mobile.invalid/fixture');
  for (const css of ['design-tokens.css','shell.css','app-ui.css','table-ui.css','account-menu.css']) await page.addStyleTag({ path: asset(css) });
  await page.addScriptTag({ content:'window.MERDPOSTheme={current:()=>"light",toggle:()=>{}};window.MERDPOS_AUTH={role_key:"ADMIN",role_label:"Admin",actual_role_key:"DEV",actual_role_label:"Developer",view_role_key:"ADMIN",is_dev:true};' });
  await page.addScriptTag({ path: asset('navigation.js') });
  await page.addScriptTag({ path: asset('account-menu.js') });
  await page.addScriptTag({ path: asset('mobile-runtime.js') });
  await expect(page.locator('#dashboardPanel > .merd-mobile-page-head')).toBeVisible();
  return errors;
}

test('dashboard builder preserves mobile page header and shared phone gutter', async () => {
  const source = fs.readFileSync(asset('dashboard-builder.css'), 'utf8');
  expect(source).toContain(':not(.merd-dashboard-builder):not(.merd-mobile-page-head)');
  expect(source).toContain('var(--merd-mobile-gutter, var(--space-3))');
});

test('phone shell uses four primary destinations and a bottom utility sheet', async ({ page }) => {
  const errors = await mount(page);
  const visiblePrimary = page.locator('.app-rail > .rail-section:visible');
  await expect(visiblePrimary).toHaveCount(4);
  await expect(page.locator('.app-rail')).not.toContainText('More');
  await expect(page.locator('.app-rail > .rail-section[data-nav-section="system"]')).toBeHidden();
  await page.locator('#dashboardPanel .merd-mobile-account-trigger').click();
  await expect(page.locator('body')).toHaveClass(/merd-mobile-tools-open/);
  await expect(page.locator('.rail-shell-utilities')).toBeVisible();
  await expect(page.locator('.rail-mobile-system-links')).toHaveCount(0);
  await expect(page.locator('.rail-dev-role-select')).toHaveValue('ADMIN');
  await page.waitForTimeout(260);
  const sheetBox = await page.locator('.rail-shell-utilities').boundingBox();
  expect(sheetBox.y + sheetBox.height).toBeLessThanOrEqual(846);
  expect(sheetBox.width).toBeLessThanOrEqual(390);
  expect(errors).toEqual([]);
});

test('phone page header, subtabs and responsive table preserve context without overflow', async ({ page }) => {
  const errors = await mount(page);
  await expect(page.locator('#dashboardPanel .merd-mobile-page-head h1')).toHaveText('Dashboard');
  await expect(page.locator('#dashboardPanel .merd-mobile-page-head p')).toHaveCount(0);
  await expect(page.locator('#dashboardPanel .merd-mobile-context')).toContainText('Merd Retail Group');
  await expect(page.locator('.status-card')).toHaveClass(/merd-inline-loading/);
  await expect(page.locator('.status-card')).toHaveAttribute('role', 'status');
  await expect(page.locator('.merd-mobile-card-table')).toHaveCount(1);
  await expect(page.locator('.merd-mobile-card-table td').first()).toHaveAttribute('data-label', 'Store');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(2);
  const gutter = await page.locator('.app-workspace').evaluate(el => parseFloat(getComputedStyle(el).paddingLeft));
  expect(gutter).toBeGreaterThanOrEqual(20);

  await page.locator('.app-rail > .rail-section[data-nav-section="reports"] .rail-group-btn').click();
  await expect(page.locator('#reportsPanel')).toBeVisible();
  await expect(page.locator('#reportsPanel .merd-mobile-page-head h1')).toHaveText('Reports');
  await expect(page.locator('#reportsPanel .merd-mobile-page-head p')).toHaveCount(0);
  await expect(page.locator('#reportsPanel .merd-mobile-subtab')).toHaveCount(2);
  await page.locator('#reportsPanel .merd-mobile-subtab', { hasText:'Timesheets' }).click();
  await expect(page.locator('#timesheetPanel')).toBeVisible();
  await expect(page.locator('#timesheetPanel .merd-mobile-page-head h1')).toHaveText('Timesheets');
  await expect(page.locator('#timesheetPanel .merd-mobile-page-head p')).toHaveCount(0);
  expect(errors).toEqual([]);
});
