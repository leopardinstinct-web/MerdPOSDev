const { test, expect } = require('@playwright/test');
const path = require('path');

test.use({ channel: 'chrome' });

const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const asset = name => path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', 'assets', name);

test('DEV store enrichment accepts Developer role label from store identity API', async ({ page }) => {
  const consoleErrors = [];
  page.on('console', message => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });

  await page.route('**/assets/store-identity.js*', route => route.fulfill({
    status: 200,
    contentType: 'application/javascript',
    body: '',
  }));
  await page.route('**/assets/roles.js*', route => route.fulfill({
    status: 200,
    contentType: 'application/javascript',
    body: '',
  }));
  await page.route('**/api/store_identity.php*', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      success: true,
      actor_role: 'Developer',
      actor_loa: 1000,
      active_client_id: 1,
      stores: [{
        id: 1,
        store_name: 'Marrickville Xpress',
        store_code: 'MX',
        status: 'active',
        address: null,
        google_maps_url: null,
      }],
    }),
  }));

  await page.setContent(`<!doctype html>
    <html><head><base href="https://merdpos-smoke.invalid/"></head><body>
      <section id="storesPanel">
        <div class="directory-card">
          <div class="directory-toolbar">
            <div><p>Legacy description</p><label class="search-box"><input id="storeSearch"></label></div>
          </div>
          <div id="storeDirectory">
            <div class="entity-row">
              <div class="entity-copy"><strong>Marrickville Xpress</strong></div>
              <button data-edit-store="1" type="button">Edit</button>
            </div>
          </div>
        </div>
      </section>
    </body></html>`);

  await page.addScriptTag({ path: asset('dev-stores-ui.js') });

  await expect(page.locator('.dev-store-identity')).toHaveText('Code MX · ID 1');
  expect(consoleErrors, consoleErrors.join(' | ')).toEqual([]);
});
