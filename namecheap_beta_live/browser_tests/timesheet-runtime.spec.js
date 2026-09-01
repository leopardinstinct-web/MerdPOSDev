const { test, expect } = require('@playwright/test');
const path = require('path');

test.use({ channel: 'chrome' });

const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const timesheetPath = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', 'assets', 'timesheet-app.js');
const tokensPath = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', 'assets', 'design-tokens.css');
const tableUiPath = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', 'assets', 'table-ui.css');
const designSystemPath = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', 'assets', 'design-system.css');

const report = {
  week_start: '2026-08-03',
  week_end: '2026-08-09',
  week_label: '03 Aug - 09 Aug 2026',
  is_super: true,
  show_wages: true,
  payroll_visible: true,
  grand_total_hours: 27.5,
  grand_total_wage: 495,
  store_summary: [{ store_name: 'Enmore Tobacco', total_employees_worked: 2, total_hours_worked: 27.5, total_amount: 495 }],
  employee_summary: [
    { employee_name: 'Abid', user_id: '0426592362', total_hours: 18.5, total_wage: 333, missing_pay_rate: false },
    { employee_name: 'Amena', user_id: '0406376993', total_hours: 9, total_wage: 162, missing_pay_rate: false },
  ],  employees: [
    {
      employee_name: 'Abid', user_id: '0426592362', pay_rate: 18, pay_rate_varies: false,
      total_hours: 18.5, total_wage: 333,
      rows: [
        {
          store_name: 'Enmore Tobacco', in_date: '2026-08-04', actual_in_time: '18:00:00',
          rounded_in_time: '18:00:00', out_date: '2026-08-04', actual_out_time: '20:29:00',
          rounded_out_time: '20:30:00', total_hours: 2.5, wage: 45, is_late: false,
        },
      ],
    },
    {
      employee_name: 'Amena', user_id: '0406376993', pay_rate: 18, pay_rate_varies: false,
      total_hours: 9, total_wage: 162,
      rows: [
        {
          store_name: 'Enmore Tobacco', in_date: '2026-08-05', actual_in_time: '09:01:00',
          rounded_in_time: '09:00:00', out_date: '2026-08-05', actual_out_time: '18:02:00',
          rounded_out_time: '18:00:00', total_hours: 9, wage: 162, is_late: false,
        },
      ],
    },
  ],
};
test('timesheets use standard hierarchy and expandable employee details', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));

  const fixtureHtml = `<!doctype html><html><body class="merd-shell">
    <main>
      <section id="timesheetPanel">
        <header class="timesheet-page-head app-panel-head"><div><h2>Timesheets</h2></div></header>
        <section class="controls-card timesheet-toolbar-card"><div class="timesheet-toolbar">
          <label class="timesheet-week-field" for="weekSelect"><span>Select Week</span><select id="weekSelect"></select></label>
          <button id="downloadPdfBtn" class="secondary-btn timesheet-download-btn" type="button" aria-label="Download PDF"><svg class="ui-icon" viewBox="0 -960 960 960"><path d="M480-320 280-520l56-58 104 104v-326h80v326l104-104 56 58-200 200ZM240-160q-33 0-56.5-23.5T160-240v-120h80v120h480v-120h80v120q0 33-23.5 56.5T720-160H240Z"/></svg></button>
        </div><p id="reportSubtitle" hidden></p><p id="reportTitle"></p></section>
        <section id="statusBox">Loading</section>
        <section id="reportContainer" class="timesheet-report"></section>
      </section>
    </main>
    <button id="logoutBtn" type="button">Logout</button>
  </body></html>`;

  await page.route('https://merdpos-smoke.invalid/timesheet-fixture', route => route.fulfill({ status: 200, contentType: 'text/html', body: fixtureHtml }));
  await page.route('https://merdpos-smoke.invalid/api/weeks.php', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, weeks: [{ value: '2026-08-03', label: '03 Aug - 09 Aug 2026' }], current_week: '2026-08-03' }) }));
  await page.route(/https:\/\/merdpos-smoke\.invalid\/api\/timesheet\.php.*/, route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, report }) }));

  await page.goto('https://merdpos-smoke.invalid/timesheet-fixture');
  await page.addScriptTag({ path: timesheetPath });

  await expect(page.getByRole('heading', { name: 'Timesheets', exact: true })).toBeVisible();
  await expect(page.locator('.timesheet-week-field > span')).toHaveText('Select Week');
  await expect(page.locator('#weekSelect')).toHaveValue('2026-08-03');
  await expect(page.locator('#reportSubtitle')).toBeHidden();
  await expect(page.locator('#reportSubtitle')).toContainText('03 Aug - 09 Aug 2026');
  await expect(page.locator('#downloadPdfBtn')).toHaveAttribute('aria-label','Download PDF');
  await expect(page.locator('#downloadPdfBtn .ui-icon path')).toHaveAttribute('d','M480-320 280-520l56-58 104 104v-326h80v326l104-104 56 58-200 200ZM240-160q-33 0-56.5-23.5T160-240v-120h80v120h480v-120h80v120q0 33-23.5 56.5T720-160H240Z');
  await expect(page.locator('.timesheet-metric')).toHaveCount(4);
  await expect(page.getByRole('heading', { name: 'Store summary' })).toBeVisible();
  await expect(page.locator('.timesheet-section-card').filter({ hasText: 'Store summary' })).toContainText('$495.00');
  await expect(page.getByRole('heading', { name: 'Employee summary' })).toBeVisible();
  const abidSummary = page.locator('.employee-summary-table tbody tr').filter({ hasText: 'Abid' });
  await expect(abidSummary).toContainText('1');
  await expect(abidSummary).toContainText('18.50');
  await expect(abidSummary).toContainText('$333.00');
  await expect(abidSummary).toContainText('$18.00/hr');

  await expect(page.getByRole('heading', { name: 'Shift details' })).toHaveCount(0);
  await expect(page.locator('.timesheet-store-table th').nth(1)).toHaveClass(/count/);
  await expect(page.locator('.timesheet-store-table th').nth(2)).toHaveClass(/num/);

  const employeeRows = page.locator('.employee-summary-row');
  await expect(employeeRows).toHaveCount(2);
  const abidRow = employeeRows.filter({ hasText: 'Abid' });
  const otherRow = employeeRows.filter({ hasNotText: 'Abid' });
  const abidDetail = page.locator('#employee-shifts-0');
  const otherDetail = page.locator('#employee-shifts-1');
  await expect(page.getByText('View shifts')).toHaveCount(0);
  await expect(page.getByText('Hide shifts')).toHaveCount(0);
  await expect(abidRow).toHaveAttribute('role', 'button');
  await expect(abidRow).toHaveAttribute('tabindex', '0');
  await expect(abidRow).toHaveAttribute('aria-expanded', 'false');
  await expect(abidDetail).toBeHidden();
  await abidRow.click();
  await expect(abidRow).toHaveAttribute('aria-expanded', 'true');
  await expect(abidRow).toHaveClass(/is-expanded/);
  await expect(otherRow).toBeHidden();
  await expect(abidDetail).toBeVisible();
  await expect(abidDetail).toContainText('Rounded 20:30');
  await expect(abidDetail).toContainText('$45.00');
  await expect(abidDetail.locator('tbody tr')).toHaveClass(/compact-shift-row/);
  await expect(abidDetail.locator('.shift-hours')).not.toContainText('/hr');
  await abidRow.click();
  await expect(abidRow).toHaveAttribute('aria-expanded', 'false');
  await expect(abidDetail).toBeHidden();
  await expect(otherRow).toBeVisible();
  await otherRow.focus();
  await page.keyboard.press('Enter');
  await expect(otherRow).toHaveAttribute('aria-expanded', 'true');
  await expect(abidRow).toBeHidden();
  await expect(otherDetail).toBeVisible();

  // Reproduce the real-device phone layout with the canonical final cascade.
  await otherRow.click();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.evaluate(() => { document.documentElement.dataset.theme = 'dark'; });
  await page.addStyleTag({ path: tokensPath });
  await page.addStyleTag({ path: tableUiPath });
  await page.addStyleTag({ path: designSystemPath });

  const [weekLabelBox, weekSelectBox, downloadBox] = await Promise.all([
    page.locator('.timesheet-week-field > span').boundingBox(),
    page.locator('#weekSelect').boundingBox(),
    page.locator('#downloadPdfBtn').boundingBox(),
  ]);
  expect(weekSelectBox.x).toBeGreaterThan(weekLabelBox.x + weekLabelBox.width - 1);
  expect(Math.abs(downloadBox.width - downloadBox.height)).toBeLessThanOrEqual(1);
  await expect(page.locator('#downloadPdfBtn')).toHaveCSS('border-radius', '50%');

  await expect(abidRow).toBeVisible();
  await expect(otherRow).toBeVisible();
  await expect(page.locator('.timesheet-store-table thead')).toBeHidden();
  await expect(page.locator('.employee-summary-table > thead')).toBeHidden();
  const storeFitsPhone = await page.locator('.timesheet-store-table').evaluate(el => el.scrollWidth <= el.clientWidth + 1);
  const employeeFitsPhone = await page.locator('.employee-summary-table').evaluate(el => el.scrollWidth <= el.clientWidth + 1);
  const pageFitsPhone = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
  expect(storeFitsPhone).toBeTruthy();
  expect(employeeFitsPhone).toBeTruthy();
  expect(pageFitsPhone).toBeTruthy();
  await expect(page.locator('.timesheet-store-table tbody tr').first()).toHaveCSS('display', 'grid');
  await expect(abidRow).toHaveCSS('display', 'grid');
  const expectedSurface = await page.evaluate(() => {
    const probe = document.createElement('div');
    probe.style.background = 'var(--color-surface-main)';
    document.body.appendChild(probe);
    const value = getComputedStyle(probe).backgroundColor;
    probe.remove();
    return value;
  });
  expect(await page.locator('.timesheet-section-head').first().evaluate(el => getComputedStyle(el).backgroundColor)).toBe(expectedSurface);

  await abidRow.click();
  await expect(otherRow).toBeHidden();
  await expect(abidDetail).toBeVisible();
  const detailFitsPhone = await abidDetail.evaluate(el => el.scrollWidth <= el.clientWidth + 1);
  expect(detailFitsPhone).toBeTruthy();

  expect(pageErrors, `Unexpected browser errors: ${pageErrors.join(' | ')}`).toEqual([]);
});
