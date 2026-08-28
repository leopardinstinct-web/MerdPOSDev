const { test, expect } = require('@playwright/test');
const path = require('path');

test.use({ channel: 'chrome' });

const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const reportCenterPath = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', 'assets', 'report-center.js');

test('Reports overview launches permitted report through canonical portal tab', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));

  await page.setContent(`<!doctype html><html><body>
    <button class="portal-tab" data-panel="timesheetPanel" type="button">Timesheets tab</button>
    <button class="portal-tab" data-panel="disputesPanel" type="button">Disputes tab</button>
    <section id="reportsPanel">
      <button class="report-launch-card" data-report-target="timesheetPanel" type="button">Timesheets</button>
      <button class="report-launch-card" data-report-target="disputesPanel" type="button">Disputes</button>
    </section>
    <section id="timesheetPanel" hidden>Timesheet content</section>
    <section id="disputesPanel" hidden>Disputes content</section>
    <script>
      document.querySelectorAll('.portal-tab').forEach(tab => tab.addEventListener('click', () => {
        document.querySelectorAll('#timesheetPanel,#disputesPanel').forEach(panel => { panel.hidden = panel.id !== tab.dataset.panel; });
      }));
    </script>
  </body></html>`);
  await page.addScriptTag({ path: reportCenterPath });

  await page.getByRole('button', { name: 'Timesheets', exact: true }).click();
  await expect(page.locator('#timesheetPanel')).toBeVisible();
  await expect(page.locator('#disputesPanel')).toBeHidden();

  await page.getByRole('button', { name: 'Disputes', exact: true }).click();
  await expect(page.locator('#timesheetPanel')).toBeHidden();
  await expect(page.locator('#disputesPanel')).toBeVisible();
  expect(pageErrors).toEqual([]);
});
