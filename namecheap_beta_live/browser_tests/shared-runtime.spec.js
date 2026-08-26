const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

// GitHub's Ubuntu runner already provides stable Google Chrome. Using the
// system channel keeps this recovery smoke job small and avoids downloading a
// separate production-sized browser bundle on every beta push.
test.use({ channel: 'chrome' });

const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const asset = name => path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', 'assets', name);

test('shared Add control remains node-stable under MutationObserver activity', async ({ page }) => {
  await page.setContent(`<!doctype html>
    <html><body>
      <div class="directory-actions">
        <label class="search-box" aria-label="Search employees"><input id="employeeSearch" type="search"></label>
        <button id="addEmployeeBtn" type="button">+ Add employee</button>
      </div>
      <div id="mutationTarget"></div>
    </body></html>`);

  await page.evaluate(() => {
    window.__addClicks = 0;
    document.getElementById('addEmployeeBtn').addEventListener('click', () => { window.__addClicks += 1; });
  });

  await page.addScriptTag({ path: asset('minimal-controls.js') });
  await page.waitForTimeout(50);

  const initial = await page.evaluate(() => {
    const button = document.getElementById('addEmployeeBtn');
    const icon = button.querySelector(':scope > svg[data-merd-add-icon="1"]');
    window.__canonicalAddIcon = icon;
    return {
      normalized: button.dataset.minimalAdd === '1',
      iconCount: button.querySelectorAll(':scope > svg[data-merd-add-icon="1"]').length,
      cluster: button.parentElement?.dataset.merdActionCluster || '',
    };
  });

  expect(initial.normalized).toBe(true);
  expect(initial.iconCount).toBe(1);
  expect(initial.cluster).toBe('search-add');

  await page.evaluate(() => {
    const target = document.getElementById('mutationTarget');
    for (let i = 0; i < 20; i += 1) {
      const node = document.createElement('span');
      node.textContent = String(i);
      target.appendChild(node);
    }
  });
  await page.waitForTimeout(100);

  const stable = await page.evaluate(() => {
    const button = document.getElementById('addEmployeeBtn');
    const icon = button.querySelector(':scope > svg[data-merd-add-icon="1"]');
    return {
      sameIconNode: icon === window.__canonicalAddIcon,
      iconCount: button.querySelectorAll(':scope > svg[data-merd-add-icon="1"]').length,
    };
  });

  expect(stable.sameIconNode).toBe(true);
  expect(stable.iconCount).toBe(1);

  await page.getByRole('button', { name: 'Add employee' }).click();
  await expect.poll(() => page.evaluate(() => window.__addClicks)).toBe(1);
});

test('permission-minimal portal DOM does not crash legacy shared beta runtime', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));

  await page.setContent(`<!doctype html>
    <html><head><base href="https://merdpos-smoke.invalid/"></head><body>
      <button id="logoutBtn" type="button">Log out</button>
      <main class="merd-page-shell">
        <section id="financialPanel" class="portal-panel"></section>
      </main>
    </body></html>`);

  await page.evaluate(() => {
    window.MERDPOS_AUTH = {
      permissions: {
        'dashboard.view': false,
        'finance.view': true,
        'finance.submit': false,
        'password.change_own': false,
      },
    };
  });

  await page.route('**/api/beta_state.php', async route => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        csrf: 'browser-smoke-only',
        current_user_id: '1',
        is_super: false,
        is_management: false,
        permissions: { 'finance.view': true },
        working: [],
        recent_shifts: [],
        disputes: [],
        attendance_flags: [],
        stores: [],
        management: null,
      }),
    });
  });
  await page.route('**/api/logout.php', route => route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true}' }));

  // app.js must run first: it supplies inert compatibility nodes for controls
  // intentionally omitted by permission-aware PHP rendering.
  await page.addScriptTag({ path: asset('app.js') });
  await page.addScriptTag({ path: asset('beta.js') });
  await page.waitForTimeout(150);

  expect(pageErrors, `Unexpected browser errors: ${pageErrors.join(' | ')}`).toEqual([]);

  const shims = await page.evaluate(() => ({
    financialDate: document.getElementById('financialDate')?.dataset.permissionRuntimeShim || '',
    passwordDialog: document.getElementById('passwordDialog')?.dataset.permissionRuntimeShim || '',
    disputeList: document.getElementById('disputeList')?.dataset.permissionRuntimeShim || '',
  }));
  expect(shims.financialDate).toBe('1');
  expect(shims.passwordDialog).toBe('1');
  expect(shims.disputeList).toBe('1');
});

test('Timesheet runtime injects once and switches weeks once per selection', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));

  let timesheetScriptRequests = 0;
  let weeksRequests = 0;
  let reportRequests = 0;

  await page.route('**/assets/timesheet-app.js*', async route => {
    timesheetScriptRequests += 1;
    await route.fulfill({
      status: 200,
      contentType: 'application/javascript',
      body: fs.readFileSync(asset('timesheet-app.js'), 'utf8'),
    });
  });
  await page.route('**/api/weeks.php', async route => {
    weeksRequests += 1;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        current_week: '2026-08-24',
        weeks: [
          { value: '2026-08-24', label: '24 Aug - 30 Aug 2026' },
          { value: '2026-08-17', label: '17 Aug - 23 Aug 2026' },
        ],
      }),
    });
  });
  await page.route('**/api/timesheet.php?*', async route => {
    reportRequests += 1;
    const url = new URL(route.request().url());
    const week = url.searchParams.get('week_start') || '2026-08-24';
    const label = week === '2026-08-17' ? '17 Aug - 23 Aug 2026' : '24 Aug - 30 Aug 2026';
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        report: {
          is_super: false,
          week_label: label,
          week_start: week,
          week_end: week === '2026-08-17' ? '2026-08-23' : '2026-08-30',
          employees: [],
          show_wages: false,
        },
      }),
    });
  });
  await page.route('**/api/logout.php', route => route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true}' }));

  await page.setContent(`<!doctype html>
    <html><head><base href="https://merdpos-smoke.invalid/"></head><body>
      <button id="logoutBtn" type="button">Log out</button>
      <select id="weekSelect"></select>
      <button id="downloadPdfBtn" type="button">Download</button>
      <div id="statusBox"></div>
      <h2 id="reportTitle"></h2>
      <p id="reportSubtitle"></p>
      <div id="reportContainer"></div>
    </body></html>`);

  // Re-evaluating app.js must not inject a second Timesheet script while the
  // first injected script is pending or after it has initialized.
  await page.addScriptTag({ path: asset('app.js') });
  await page.addScriptTag({ path: asset('app.js') });

  await expect.poll(() => weeksRequests).toBe(1);
  await expect.poll(() => reportRequests).toBe(1);
  await page.waitForTimeout(50);

  expect(timesheetScriptRequests).toBe(1);
  expect(pageErrors, `Unexpected browser errors: ${pageErrors.join(' | ')}`).toEqual([]);
  await expect(page.locator('#weekSelect')).toHaveValue('2026-08-24');

  await page.selectOption('#weekSelect', '2026-08-17');
  await expect.poll(() => reportRequests).toBe(2);
  await expect(page.locator('#weekSelect')).toHaveValue('2026-08-17');
  await expect(page.locator('#reportTitle')).toContainText('17 Aug - 23 Aug 2026');
  expect(reportRequests).toBe(2);
});
