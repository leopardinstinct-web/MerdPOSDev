const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
test.use({ channel: 'chrome' });

const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const portal = rel => path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', rel);
const source = rel => fs.readFileSync(portal(rel), 'utf8');

test('DevStudio implementation requests are canonical in dashboard source', async () => {
  const dashboard = source('dashboard.php');
  expect(dashboard).toContain("'home' => 'M600-160v-280h280v280H600");
  expect(dashboard).toContain("'key' => 'M420-360h120l-23-129");
  expect(dashboard).toContain("'wallet' => 'M441-120v-86");
  expect(dashboard).not.toContain('id="reportsPanel"');
  expect(dashboard).not.toContain('report-center.js');
  expect(dashboard).toContain("$showDisputesNav = $canDisputes && strtoupper($role) === 'DEV';");
  expect(dashboard).toContain('id="storeWeekStartDay"');
});

test('Stores use canonical Search width and icon-only circular edit action', async () => {
  const devStores = source('assets/dev-stores-ui.js');
  const directory = source('assets/directory.js');
  expect(devStores).not.toContain('width:min(460px,38vw)');
  expect(devStores).not.toContain('min-width:220px');
  expect(directory).toContain('class="merd-icon-action directory-edit-icon-btn store-edit-icon-btn"');
  expect(directory).toContain('aria-label="Edit ${esc(store.store_name)}"');
  expect(directory).not.toContain("data-edit-store=\"${Number(store.id)}\">${icon('edit')}<span>Edit</span>");
});

test('Store Edit embeds weekly timings and persists week-start day without changing day keys', async ({ page }) => {
  let posted = null;
  const timings = Array.from({length:7},(_,i)=>({store_id:7,day_of_week:i+1,start_time:'09:00:00',end_time:'17:00:00',is_closed:0}));
  const payload = () => ({success:true,csrf:'timing-csrf',stores:[{id:7,store_name:'Demo Store',status:'active',week_start_day:7}],timings,message:'Store timings saved.'});
  await page.route('https://merdpos-smoke.invalid/api/store_timings.php*', async route => {
    if (route.request().method() === 'POST') posted = route.request().postDataJSON();
    await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(payload())});
  });
  await page.route('https://merdpos-smoke.invalid/assets/timings.css*', route => route.fulfill({status:200,contentType:'text/css',body:''}));
  await page.setContent(`<!doctype html><html><head><base href="https://merdpos-smoke.invalid/"></head><body>
    <dialog id="storeDialog" open><form id="storeAdminForm"><div class="admin-dialog-body">
      <div class="admin-form-grid"><input name="id" value="7"><select name="week_start_day" id="storeWeekStartDay">
        <option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option><option value="4">Thursday</option><option value="5">Friday</option><option value="6">Saturday</option><option value="7" selected>Sunday</option>
      </select></div><div class="admin-dialog-footer"></div></div></form></dialog>
  </body></html>`);
  await page.addScriptTag({path:portal('assets/timings.js')});
  await expect(page.locator('#storeDialog #storeTimingsSection')).toHaveCount(1);
  await expect(page.locator('#timingRows .timing-row').first()).toHaveAttribute('data-day','7');
  await page.selectOption('#storeWeekStartDay','2');
  await expect(page.locator('#timingRows .timing-row').first()).toHaveAttribute('data-day','2');
  await page.locator('#saveTimingsBtn').click();
  await expect.poll(()=>posted).not.toBeNull();
  expect(posted).toMatchObject({action:'save_timings',scope:'store',store_id:7,week_start_day:2});
  expect(posted.days.map(row=>row.day_of_week)).toEqual([1,2,3,4,5,6,7]);
});

test('directory edit actions share one icon-only contract and employee rows omit requested metadata clutter', () => {
  const directory=source('assets/directory.js');
  const clients=source('assets/client.js');
  expect(directory).toContain('class="merd-icon-action directory-edit-icon-btn" data-edit-employee');
  expect(directory).toContain('class="merd-icon-action directory-edit-icon-btn store-edit-icon-btn" data-edit-store');
  expect(clients).toContain('class="merd-icon-action directory-edit-icon-btn" data-edit-client');
  expect(directory).not.toContain('Allowed: ${esc(accessText)}');
  expect(directory).not.toContain('${rolePill(employee)}<span class="store-access-summary">LOA');
  expect(directory).not.toContain('${storeAccessPill(employee)}${payMeta}');
  expect(directory).not.toContain('class="icon-text-btn" data-edit-employee');
  expect(clients).not.toContain('data-edit-client="${Number(client.id)}">${editIcon()}<span>Edit</span>');
});


test('client directory edit action stays circular on phone while Legacy Sync remains a text action', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  await page.setContent('<body class="merd-shell"><div class="client-row-actions"><button class="icon-text-btn"><span>Legacy Sync</span></button><button class="merd-icon-action directory-edit-icon-btn" aria-label="Edit client"><svg viewBox="0 0 24 24"><path d="M12 20h9"/></svg></button></div></body>');
  await page.addStyleTag({path:portal('assets/design-tokens.css')});
  await page.addStyleTag({path:portal('assets/mobile-hardening.css')});
  await page.addStyleTag({path:portal('assets/design-system.css')});
  const geometry=await page.evaluate(()=>{const edit=document.querySelector('.directory-edit-icon-btn').getBoundingClientRect(),sync=document.querySelector('.icon-text-btn').getBoundingClientRect();return{editW:edit.width,editH:edit.height,syncW:sync.width};});
  expect(Math.abs(geometry.editW-geometry.editH)).toBeLessThan(1);
  expect(geometry.editW).toBeGreaterThanOrEqual(47);
  expect(geometry.editW).toBeLessThanOrEqual(49);
  expect(geometry.syncW).toBeGreaterThan(geometry.editW);
});
