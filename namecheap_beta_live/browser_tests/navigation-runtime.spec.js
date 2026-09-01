const { test, expect } = require('@playwright/test');
const path = require('path');

test.use({ channel: 'chrome' });

const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const navigationPath = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', 'assets', 'navigation.js');

test('mobile parent navigation activates first submenu and clears on direct section', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  await page.setViewportSize({ width: 390, height: 844 });

  const fixtureHtml = `<!doctype html>
    <html><head>
      <script data-id-order></script>
      <script data-dashboard-builder></script>
      <link rel="stylesheet" data-dashboard-builder-css href="data:text/css,">
    </head><body>
      <main class="merd-page-shell">
        <nav class="merd-nav" aria-label="MERDPOS sections">
          <div class="nav-group">
            <span class="nav-group-label">Overview</span>
            <button class="portal-tab active" data-panel="dashboardPanel"><span>Dashboard</span></button>
          </div>
          <div class="nav-group">
            <span class="nav-group-label">Operations</span>
            <button class="portal-tab" data-panel="storesPanel"><span>Stores</span></button>
            <button class="portal-tab" data-panel="employeesPanel"><span>Workforce</span></button>
          </div>
          <div class="nav-group">
            <span class="nav-group-label">Reports</span>
            <button class="portal-tab" data-panel="reportsPanel"><span>Overview</span></button>
            <button class="portal-tab" data-panel="timesheetPanel"><span>Timesheets</span></button>
            <button class="portal-tab" data-panel="disputesPanel"><span>Disputes</span></button>
          </div>
          <div class="nav-group">
            <span class="nav-group-label">Finance</span>
            <button class="portal-tab" data-panel="financialPanel"><span>Financial</span></button>
          </div>
        </nav>
        <section id="dashboardPanel" class="portal-panel">Dashboard content</section>
        <section id="storesPanel" class="portal-panel" hidden>Stores content</section>
        <section id="employeesPanel" class="portal-panel" hidden>Workforce content</section>
        <section id="reportsPanel" class="portal-panel" hidden>Reports overview</section>
        <section id="timesheetPanel" class="portal-panel" hidden>Timesheet content</section>
        <section id="disputesPanel" class="portal-panel" hidden>Disputes content</section>
        <section id="financialPanel" class="portal-panel" hidden>Finance content</section>
      </main>
    </body></html>`;

  // sessionStorage is intentionally used by navigation.js. Serving the fixture
  // from a normal HTTPS origin exercises the real browser contract instead of
  // Chromium's opaque about:blank storage restriction from page.setContent().
  await page.route('https://merdpos-smoke.invalid/navigation-fixture', async route => {
    await route.fulfill({ status: 200, contentType: 'text/html', body: fixtureHtml });
  });
  await page.goto('https://merdpos-smoke.invalid/navigation-fixture');
  await page.addScriptTag({ path: navigationPath });

  await expect(page.locator('.app-frame')).toHaveCount(1);
  await expect(page.locator('.app-rail')).toHaveCount(1);
  await expect(page.locator('body')).not.toHaveClass(/merd-mobile-subnav-open/);
  await expect(page.locator('#dashboardPanel')).toBeVisible();

  const operations = page.locator('[data-nav-group="operations"]');
  const stores = page.locator('[data-sidebar-group="operations"] [data-panel="storesPanel"]');
  const workforce = page.locator('[data-sidebar-group="operations"] [data-panel="employeesPanel"]');
  await operations.click();

  // Parent group click opens the submenu and activates its first available item.
  await expect(page.locator('body')).toHaveClass(/merd-mobile-subnav-open/);
  await expect(page.locator('#dashboardPanel')).toBeHidden();
  await expect(page.locator('#storesPanel')).toBeVisible();
  await expect(page.locator('#employeesPanel')).toBeHidden();
  await expect(stores).toHaveClass(/active/);
  await expect(operations).toHaveAttribute('aria-expanded', 'true');

  // Explicit submenu selection still works after the parent's default selection.
  await workforce.click();
  await expect(page.locator('#storesPanel')).toBeHidden();
  await expect(page.locator('#employeesPanel')).toBeVisible();
  await expect(workforce).toHaveClass(/active/);
  await expect(page.locator('body')).toHaveClass(/merd-mobile-subnav-open/);

  // Reports is a first-class multi-item section. Its parent opens Overview first.
  const reports = page.locator('[data-nav-group="reports"]');
  await expect(reports.locator('.ui-icon path')).toHaveAttribute('d', 'M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h168q13-36 43.5-58t68.5-22q38 0 68.5 22t43.5 58h168q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H200Zm0-80h560v-560H200v560Zm80-80h280v-80H280v80Zm0-160h400v-80H280v80Zm0-160h400v-80H280v80Zm221.5-198.5Q510-807 510-820t-8.5-21.5Q493-850 480-850t-21.5 8.5Q450-833 450-820t8.5 21.5Q467-790 480-790t21.5-8.5ZM200-200v-560 560Z');
  const reportsOverview = page.locator('[data-sidebar-group="reports"] [data-panel="reportsPanel"]');
  await reports.click();
  await expect(page.locator('#employeesPanel')).toBeHidden();
  await expect(page.locator('#reportsPanel')).toBeVisible();
  await expect(reportsOverview).toHaveClass(/active/);
  await expect(reports).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('body')).toHaveClass(/merd-mobile-subnav-open/);

  // Finance is a direct single-item section. Selecting it must clear the
  // contextual-mobile state so the workspace does not retain a phantom gap.
  await page.locator('[data-nav-group="finance"]').click();
  await expect(page.locator('#employeesPanel')).toBeHidden();
  await expect(page.locator('#financialPanel')).toBeVisible();
  await expect(page.locator('body')).not.toHaveClass(/merd-mobile-subnav-open/);

  expect(pageErrors, `Unexpected browser errors: ${pageErrors.join(' | ')}`).toEqual([]);
});

test('single USER Timesheets destination keeps its Timesheet icon instead of the Reports group icon', async ({ page }) => {
  await page.setViewportSize({ width:390, height:844 });
  const html=`<!doctype html><html><body><main class="merd-page-shell"><nav class="merd-nav"><div class="nav-group"><span class="nav-group-label">Reports</span><button class="portal-tab active" data-panel="timesheetPanel"><svg class="ui-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span>Timesheets</span></button></div></nav><section id="timesheetPanel" class="portal-panel">Timesheets</section></main></body></html>`;
  await page.route('https://merdpos-smoke.invalid/user-timesheet',r=>r.fulfill({status:200,contentType:'text/html',body:html}));
  await page.goto('https://merdpos-smoke.invalid/user-timesheet');
  await page.addScriptTag({path:navigationPath});
  const button=page.locator('[data-nav-group="reports"]');
  await expect(button).toHaveAttribute('aria-label','Timesheets');
  await expect(button.locator('.ui-icon path')).toHaveAttribute('d','M12 7v5l3 2');
  await expect(button.locator('.ui-icon path[d^="M200-120"]')).toHaveCount(0);
});
