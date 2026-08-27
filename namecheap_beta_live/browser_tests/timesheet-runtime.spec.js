const { test, expect } = require('@playwright/test');
const path = require('path');

test.use({ channel: 'chrome' });

const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const timesheetPath = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', 'assets', 'timesheet-app.js');

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
        <header class="timesheet-page-head app-panel-head"><div><h2>Timesheets</h2><p>Review weekly timesheet activity for the selected week.</p></div></header>
        <section class="controls-card timesheet-toolbar-card"><div class="timesheet-toolbar">
          <label class="timesheet-week-field" for="weekSelect"><span>Week</span><select id="weekSelect"></select></label>
          <button id="downloadPdfBtn" type="button">Download PDF</button>
        </div><p id="reportSubtitle"></p><p id="reportTitle"></p></section>
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
  await expect(page.getByText('Review weekly timesheet activity for the selected week.')).toBeVisible();
  await expect(page.locator('#weekSelect')).toHaveValue('2026-08-03');
  await expect(page.locator('#reportSubtitle')).toContainText('03 Aug - 09 Aug 2026');
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
  const abidToggle = abidRow.locator('.employee-summary-toggle');
  const abidDetail = page.locator('#employee-shifts-0');
  await expect(abidToggle).toHaveAttribute('aria-expanded', 'false');
  await expect(abidDetail).toBeHidden();
  await abidToggle.click();
  await expect(abidToggle).toHaveAttribute('aria-expanded', 'true');
  await expect(abidRow).toHaveClass(/is-expanded/);
  await expect(abidToggle).toContainText('Hide shifts');
  await expect(abidDetail).toBeVisible();
  await expect(abidDetail).toContainText('Rounded 20:30');
  await expect(abidDetail).toContainText('$45.00');
  await expect(abidDetail.locator('tbody tr')).toHaveClass(/compact-shift-row/);
  await expect(abidDetail.locator('.shift-hours')).not.toContainText('/hr');

  expect(pageErrors, `Unexpected browser errors: ${pageErrors.join(' | ')}`).toEqual([]);
});
