const { test, expect } = require('@playwright/test');
const path = require('path');

test.use({ channel: 'chrome' });

const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const asset = name => path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', 'assets', name);

test('DEV store enrichment keeps toolbar actions together and dashboard edit action on one line', async ({ page }) => {
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
      <div class="dashboard-role-controls">
        <button class="secondary-btn compact-btn dashboard-edit-toggle" type="button">Edit dashboard</button>
      </div>
      <section id="storesPanel">
        <div class="directory-card">
          <div class="directory-toolbar">
            <div><h2>Stores</h2><p>Legacy description</p><label class="search-box"><input id="storeSearch" type="search"></label></div>
            <div class="directory-actions">
              <button id="addStoreBtn" type="button">Add store</button>
            </div>
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

  await page.addScriptTag({ path: asset('minimal-controls.js') });
  await page.addScriptTag({ path: asset('dev-stores-ui.js') });

  await expect(page.locator('.dev-store-identity')).toHaveCount(0);
  await expect(page.locator('.directory-actions .search-box')).toHaveCount(1);
  await expect(page.locator('.directory-actions #addStoreBtn')).toHaveCount(1);
  await expect(page.locator('.directory-toolbar > div:first-child .search-box')).toHaveCount(0);
  await expect(page.locator('.directory-actions')).toHaveAttribute('data-merd-action-cluster', 'search-add');
  await expect(page.locator('.dashboard-edit-toggle')).toHaveCSS('white-space', 'nowrap');
  expect(consoleErrors, consoleErrors.join(' | ')).toEqual([]);
});
