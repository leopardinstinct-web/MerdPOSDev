const { chromium } = require('@playwright/test');
const fs = require('fs');
const crypto = require('crypto');

const BASE = (process.env.MERDPOS_BASE_URL || 'https://app.merdpos.com/beta/timesheet_portal/').replace(/\/?$/, '/');
const AUTH_STATE = process.env.MERDPOS_AUTH_STATE;
if (!AUTH_STATE || !fs.existsSync(AUTH_STATE)) throw new Error('Set MERDPOS_AUTH_STATE to an external Playwright storage-state file.');

const stamp = new Date().toISOString().replace(/[-:.TZ]/g, '').slice(0, 14);
const today = new Date().toISOString().slice(0, 10);
const storeName = `AUTOTEST Core ${stamp}`;
const storeRenamed = `${storeName} Edited`;
const employeeName = `AUTOTEST Employee ${stamp}`;
const employeeEdited = `${employeeName} Edited`;
const roleLabel = `AUTOTEST Role ${stamp}`;
const roleEdited = `${roleLabel} Edited`;
const userId = `8${stamp.slice(-10)}`;
const password = String(100000 + crypto.randomInt(899999));
const resetPassword = String(100000 + crypto.randomInt(899999));
const results = [];

function pass(name, detail = '') { results.push({ name, ok: true, detail }); console.log('PASS', name, detail); }
function fail(name, detail = '') { results.push({ name, ok: false, detail }); throw new Error(`${name}: ${detail}`); }
async function readJson(response, label) {
  const text = await response.text();
  let data;
  try { data = text ? JSON.parse(text) : null; } catch (_) { throw new Error(`${label}: invalid JSON ${response.status()} ${text.slice(0, 200)}`); }
  if (!response.ok() || !data?.success) throw new Error(`${label}: ${response.status()} ${JSON.stringify(data).slice(0, 600)}`);
  return data;
}
async function post(request, api, body, label) {
  return readJson(await request.post(BASE + api, { data: body, headers: { Accept: 'application/json' } }), label);
}
async function switchDummy(request) {
  let state = await readJson(await request.get(BASE + 'api/client_context.php'), 'client_context GET');
  let dummy = (state.clients || []).find(c => String(c.client_code).toUpperCase() === 'DUMMY');
  if (!dummy) {
    const clients = await readJson(await request.get(BASE + 'api/clients.php'), 'clients GET');
    const inactive = (clients.clients || []).find(c => String(c.client_code).toUpperCase() === 'DUMMY');
    if (!inactive) throw new Error('DUMMY client does not exist');
    await post(request, 'api/clients.php', {
      action: 'save_client', csrf: clients.csrf, id: inactive.id,
      name: inactive.name || 'DUMMY', client_code: 'DUMMY', status: 'active'
    }, 'activate DUMMY');
    state = await readJson(await request.get(BASE + 'api/client_context.php'), 'client_context refresh');
    dummy = (state.clients || []).find(c => String(c.client_code).toUpperCase() === 'DUMMY');
  }
  if (!dummy) throw new Error('DUMMY active client unavailable');
  const selected = await post(request, 'api/client_context.php', {
    action: 'select_client', csrf: state.csrf, client_id: dummy.id
  }, 'select DUMMY');
  if (String(selected.client?.client_code).toUpperCase() !== 'DUMMY') throw new Error('DUMMY switch failed');
  pass('DUMMY client selected', `client_id=${dummy.id}`);
}

async function main() {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ storageState: AUTH_STATE, viewport: { width: 1440, height: 900 } });
  const request = context.request;
  await switchDummy(request);

  let directory = await readJson(await request.get(BASE + 'api/admin_directory.php'), 'directory GET');
  const userRole = (directory.actor?.roles || []).find(r => String(r.role_key).toUpperCase() === 'USER');
  if (!userRole) throw new Error('DUMMY USER role missing');

  directory = await post(request, 'api/admin_directory.php', {
    action: 'save_store', csrf: directory.csrf, store_name: storeName, status: 'active'
  }, 'create DUMMY store');
  let store = (directory.stores || []).find(s => s.store_name === storeName);
  if (!store) fail('Create DUMMY store', 'created store missing from API response');
  pass('Create DUMMY store', `store_id=${store.id}`);

  directory = await post(request, 'api/admin_directory.php', {
    action: 'save_store', csrf: directory.csrf, id: store.id, store_name: storeRenamed, status: 'active'
  }, 'edit DUMMY store');
  store = (directory.stores || []).find(s => Number(s.id) === Number(store.id));
  if (!store || store.store_name !== storeRenamed) fail('Edit DUMMY store', 'rename did not persist');
  pass('Edit DUMMY store', store.store_name);

  directory = await post(request, 'api/admin_directory.php', {
    action: 'save_employee', csrf: directory.csrf, full_name: employeeName, user_id: userId,
    client_role_id: userRole.id, employee_type: 'USER', status: 'active', hourly_rate: '12.50',
    rate_effective_date: today, new_password: password, store_access_mode: 'selected', store_ids: [Number(store.id)]
  }, 'create DUMMY employee');
  let employee = (directory.employees || []).find(e => String(e.user_id) === userId);
  if (!employee) fail('Create DUMMY employee', 'employee missing from API response');
  pass('Create DUMMY employee', `employee_id=${employee.id}`);

  directory = await post(request, 'api/admin_directory.php', {
    action: 'save_employee', csrf: directory.csrf, id: employee.id, full_name: employeeEdited, user_id: userId,
    client_role_id: userRole.id, employee_type: 'USER', status: 'active', hourly_rate: '14.75',
    rate_effective_date: today, new_password: resetPassword, store_access_mode: 'selected', store_ids: [Number(store.id)]
  }, 'edit DUMMY employee');
  employee = (directory.employees || []).find(e => Number(e.id) === Number(employee.id));
  if (!employee || employee.full_name !== employeeEdited || Number(employee.hourly_rate) !== 14.75) fail('Edit DUMMY employee', JSON.stringify(employee));
  pass('Edit DUMMY employee + pay rate + credential reset', `rate=${employee.hourly_rate}`);

  directory = await post(request, 'api/admin_directory.php', {
    action: 'save_employee', csrf: directory.csrf, id: employee.id, full_name: employeeEdited, user_id: userId,
    client_role_id: userRole.id, employee_type: 'USER', status: 'inactive', hourly_rate: '14.75',
    rate_effective_date: today, new_password: '', store_access_mode: 'selected', store_ids: [Number(store.id)]
  }, 'deactivate DUMMY employee');
  employee = (directory.employees || []).find(e => Number(e.id) === Number(employee.id));
  if (!employee || String(employee.status).toLowerCase() !== 'inactive') fail('Deactivate DUMMY employee', JSON.stringify(employee));
  pass('Deactivate DUMMY employee', `employee_id=${employee.id}`);

  let roles = await readJson(await request.get(BASE + 'api/role_authority.php'), 'roles GET');
  roles = await post(request, 'api/role_authority.php', {
    action: 'create_role', csrf: roles.csrf, role_label: roleLabel, authority_level: 40
  }, 'create DUMMY role');
  let role = (roles.roles || []).find(r => r.role_label === roleLabel);
  if (!role) fail('Create DUMMY role', 'role missing after create');
  pass('Create DUMMY role', `role_id=${role.id}`);

  roles = await post(request, 'api/role_authority.php', {
    action: 'save_role', csrf: roles.csrf, role_id: role.id, role_label: roleEdited, authority_level: 45
  }, 'edit DUMMY role');
  role = (roles.roles || []).find(r => Number(r.id) === Number(role.id));
  if (!role || role.role_label !== roleEdited || Number(role.authority_level) !== 45) fail('Edit DUMMY role', JSON.stringify(role));
  pass('Edit DUMMY role', `loa=${role.authority_level}`);

  const permissionKey = 'dashboard.widget.working_now_count';
  const permission = (roles.permissions || []).find(p => p.permission_key === permissionKey);
  if (!permission || permission.dev_only) throw new Error(`Permission unavailable for test: ${permissionKey}`);
  const originalLevel = Number(permission.min_authority_level);
  const changedLevel = originalLevel === 51 ? 52 : 51;
  roles = await post(request, 'api/role_authority.php', {
    action: 'save_permissions', csrf: roles.csrf, levels: { [permissionKey]: changedLevel }
  }, 'change DUMMY permission');
  let changedPermission = (roles.permissions || []).find(p => p.permission_key === permissionKey);
  if (!changedPermission || Number(changedPermission.min_authority_level) !== changedLevel) fail('Change DUMMY permission', JSON.stringify(changedPermission));
  pass('Change DUMMY permission LOA', `${originalLevel}->${changedLevel}`);

  roles = await post(request, 'api/role_authority.php', {
    action: 'save_permissions', csrf: roles.csrf, levels: { [permissionKey]: originalLevel }
  }, 'restore DUMMY permission');
  changedPermission = (roles.permissions || []).find(p => p.permission_key === permissionKey);
  if (!changedPermission || Number(changedPermission.min_authority_level) !== originalLevel) fail('Restore DUMMY permission', JSON.stringify(changedPermission));
  pass('Restore DUMMY permission LOA', String(originalLevel));

  roles = await post(request, 'api/role_authority.php', {
    action: 'delete_role', csrf: roles.csrf, role_id: role.id
  }, 'delete DUMMY role');
  if ((roles.roles || []).some(r => Number(r.id) === Number(role.id))) fail('Delete DUMMY role', 'role still present');
  pass('Delete DUMMY role', `role_id=${role.id}`);

  directory = await readJson(await request.get(BASE + 'api/admin_directory.php'), 'directory refresh');
  directory = await post(request, 'api/admin_directory.php', {
    action: 'save_store', csrf: directory.csrf, id: store.id, store_name: storeRenamed, status: 'inactive'
  }, 'deactivate DUMMY store');
  store = (directory.stores || []).find(s => Number(s.id) === Number(store.id));
  if (!store || String(store.status).toLowerCase() !== 'inactive') fail('Deactivate DUMMY store', JSON.stringify(store));
  pass('Deactivate DUMMY store', `store_id=${store.id}`);

  const disputes = await readJson(await request.get(BASE + 'api/disputes.php'), 'DUMMY disputes GET');
  pass('DUMMY disputes read path', `${(disputes.disputes || []).length} rows`);

  const page = await context.newPage();
  const runtimeErrors = [];
  const failedResponses = [];
  page.on('pageerror', e => runtimeErrors.push(e.message));
  page.on('console', m => {
    if (m.type() === 'error' && !/^Failed to load resource: the server responded with a status of 404/i.test(m.text())) runtimeErrors.push(m.text());
  });
  page.on('response', r => {
    if (r.status() >= 400 && r.url().includes('/beta/timesheet_portal/')) failedResponses.push(`${r.status()} ${r.request().method()} ${r.url()}`);
  });
  await page.goto(BASE + 'dashboard.php', { waitUntil: 'domcontentloaded', timeout: 15000 });
  await page.waitForTimeout(1000);

  async function openPanel(target, marker) {
    const clicked = await page.evaluate((target) => {
      const el = document.querySelector(`[data-panel="${target}"]`);
      if (!el) return false;
      el.click();
      return true;
    }, target);
    await page.waitForTimeout(650);
    const visible = await page.evaluate(() => {
      const p = [...document.querySelectorAll('.portal-panel')].find(x => !x.hidden && getComputedStyle(x).display !== 'none');
      return p ? { id: p.id, text: (p.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 1200) } : { id: '', text: '' };
    });
    if (!clicked || visible.id !== target || (marker && !visible.text.toLowerCase().includes(marker.toLowerCase()))) fail(`Open ${target}`, JSON.stringify(visible));
    pass(`Open ${target}`, visible.text.slice(0, 120));
  }

  for (const [panel, marker] of [
    ['employeesPanel', 'Employees'], ['storesPanel', 'Stores'], ['rolesPanel', 'Roles'],
    ['timesheetPanel', 'Timesheets'], ['disputesPanel', 'Disputes'], ['financialPanel', 'Financial']
  ]) await openPanel(panel, marker);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(400);
  const mobile = await page.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth, media: matchMedia('(max-width: 720px)').matches }));
  if (!mobile.media || mobile.width !== 390) fail('Mobile viewport active', JSON.stringify(mobile));
  pass('Mobile viewport active', JSON.stringify(mobile));
  if (mobile.scrollWidth > mobile.width + 4) fail('No mobile horizontal overflow', JSON.stringify(mobile));
  pass('No mobile horizontal overflow', JSON.stringify(mobile));

  for (const [panel, marker] of [
    ['employeesPanel', 'Employees'], ['timesheetPanel', 'Timesheets'], ['storesPanel', 'Stores'],
    ['rolesPanel', 'Roles'], ['disputesPanel', 'Disputes'], ['financialPanel', 'Financial']
  ]) await openPanel(panel, marker);

  const uniqueRuntime = [...new Set(runtimeErrors)];
  const uniqueHttp = [...new Set(failedResponses)];
  if (uniqueRuntime.length) fail('No DUMMY browser runtime errors', uniqueRuntime.join(' | '));
  pass('No DUMMY browser runtime errors');
  if (uniqueHttp.length) fail('No DUMMY failed app HTTP responses', uniqueHttp.join(' | '));
  pass('No DUMMY failed app HTTP responses');

  const finalContext = await readJson(await request.get(BASE + 'api/client_context.php'), 'final client_context');
  if (String(finalContext.client?.client_code).toUpperCase() !== 'DUMMY') fail('DUMMY context preserved', JSON.stringify(finalContext.client));
  pass('DUMMY context preserved', `client_id=${finalContext.client?.id}`);

  console.log(`DUMMY_CORE_OK total=${results.length} passed=${results.filter(r => r.ok).length}`);
  await context.close();
  await browser.close();
}

main().catch(error => {
  console.error('DUMMY_CORE_FAIL', error?.stack || error);
  process.exitCode = 1;
});
