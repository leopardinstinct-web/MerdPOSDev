const { test, expect } = require('@playwright/test');
const path = require('path');

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
