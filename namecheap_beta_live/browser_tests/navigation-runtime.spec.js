const { test, expect } = require('@playwright/test');
const path = require('path');

test.use({ channel: 'chrome' });

const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const navigationPath = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', 'assets', 'navigation.js');

test('mobile contextual navigation opens without navigating and clears on direct section', async ({ page }) => {
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
            <span class="nav-group-label">Finance</span>
            <button class="portal-tab" data-panel="financialPanel"><span>Financial</span></button>
          </div>
        </nav>
        <section id="dashboardPanel" class="portal-panel">Dashboard content</section>
        <section id="storesPanel" class="portal-panel" hidden>Stores content</section>
        <section id="employeesPanel" class="portal-panel" hidden>Workforce content</section>
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
  await operations.click();

  // Parent group click is contextual only: it opens the submenu without
  // silently navigating away from the current Dashboard panel.
  await expect(page.locator('body')).toHaveClass(/merd-mobile-subnav-open/);
  await expect(page.locator('#dashboardPanel')).toBeVisible();
  await expect(page.locator('#storesPanel')).toBeHidden();
  await expect(operations).toHaveAttribute('aria-expanded', 'true');

  await page.locator('[data-sidebar-group="operations"] [data-panel="storesPanel"]').click();
  await expect(page.locator('#dashboardPanel')).toBeHidden();
  await expect(page.locator('#storesPanel')).toBeVisible();
  await expect(page.locator('body')).toHaveClass(/merd-mobile-subnav-open/);

  // Finance is a direct single-item section. Selecting it must clear the
  // contextual-mobile state so the workspace does not retain a phantom gap.
  await page.locator('[data-nav-group="finance"]').click();
  await expect(page.locator('#storesPanel')).toBeHidden();
  await expect(page.locator('#financialPanel')).toBeVisible();
  await expect(page.locator('body')).not.toHaveClass(/merd-mobile-subnav-open/);

  expect(pageErrors, `Unexpected browser errors: ${pageErrors.join(' | ')}`).toEqual([]);
});
