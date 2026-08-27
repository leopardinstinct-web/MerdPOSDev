const { chromium } = require('@playwright/test');
const fs = require('fs');
const crypto = require('crypto');

const BASE = (process.env.MERDPOS_BASE_URL || 'https://app.merdpos.com/beta/timesheet_portal/').replace(/\/?$/, '/');
const AUTH_STATE = process.env.MERDPOS_AUTH_STATE;
if (!AUTH_STATE || !fs.existsSync(AUTH_STATE)) throw new Error('Set MERDPOS_AUTH_STATE to an external DEV Playwright storage-state file.');

const stamp = new Date().toISOString().replace(/[-:.TZ]/g, '').slice(0, 14);
const today = new Date().toISOString().slice(0, 10);
const storeName = `AUTOTEST Dispute ${stamp}`;
const userName = `AUTOTEST Dispute User ${stamp}`;
const superName = `AUTOTEST Dispute Super ${stamp}`;
const userId = `71${stamp.slice(-9)}`;
const superId = `72${stamp.slice(-9)}`;
const userPassword = String(100000 + crypto.randomInt(899999));
const superPassword = String(100000 + crypto.randomInt(899999));

function formatSydney(date) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Australia/Sydney', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
  }).formatToParts(date).reduce((out, part) => (out[part.type] = part.value, out), {});
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

async function json(response, label) {
  const text = await response.text();
  let data;
  try { data = text ? JSON.parse(text) : null; } catch (_) { throw new Error(`${label}: invalid JSON ${response.status()} ${text.slice(0, 240)}`); }
  if (!response.ok() || !data?.success) throw new Error(`${label}: ${response.status()} ${JSON.stringify(data).slice(0, 700)}`);
  return data;
}
async function post(request, api, data, label) {
  return json(await request.post(BASE + api, { data, headers: { Accept: 'application/json' } }), label);
}
async function loginTenant(context, user_id, password, expectedRole) {
  const login = await post(context.request, 'api/login.php', { user_id, password, client_code: 'DUMMY' }, `DUMMY ${expectedRole} login`);
  if (String(login.user?.client_id) !== '2' && String(login.user?.role || '').toUpperCase() !== expectedRole) {
    throw new Error(`DUMMY ${expectedRole} login identity mismatch: ${JSON.stringify(login.user)}`);
  }
  const me = await json(await context.request.get(BASE + 'api/me.php'), `DUMMY ${expectedRole} me`);
  if (Number(me.user?.client_id) !== Number(me.user?.auth_client_id) || String(me.user?.role_key).toUpperCase() !== expectedRole) {
    throw new Error(`DUMMY ${expectedRole} session not tenant-native: ${JSON.stringify(me.user)}`);
  }
  return me.user;
}
async function state(context, label) {
  const data = await json(await context.request.get(BASE + 'api/beta_state.php'), label);
  if (String(data.client?.client_code).toUpperCase() !== 'DUMMY') throw new Error(`${label}: not DUMMY ${JSON.stringify(data.client)}`);
  return data;
}

async function main() {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const dev = await browser.newContext({ storageState: AUTH_STATE });
  const devRequest = dev.request;

  let clientState = await json(await devRequest.get(BASE + 'api/client_context.php'), 'DEV client context');
  const dummy = (clientState.clients || []).find(c => String(c.client_code).toUpperCase() === 'DUMMY');
  if (!dummy) throw new Error('Active DUMMY client unavailable');
  await post(devRequest, 'api/client_context.php', { action: 'select_client', csrf: clientState.csrf, client_id: dummy.id }, 'select DUMMY');

  let directory = await json(await devRequest.get(BASE + 'api/admin_directory.php'), 'DUMMY directory');
  const userRole = (directory.actor?.roles || []).find(r => String(r.role_key).toUpperCase() === 'USER');
  const superRole = (directory.actor?.roles || []).find(r => String(r.role_key).toUpperCase() === 'SUPER');
  if (!userRole || !superRole) throw new Error('DUMMY USER/SUPER system roles unavailable');

  directory = await post(devRequest, 'api/admin_directory.php', {
    action: 'save_store', csrf: directory.csrf, store_name: storeName, status: 'active'
  }, 'create DUMMY dispute store');
  const store = (directory.stores || []).find(s => s.store_name === storeName);
  if (!store) throw new Error('DUMMY dispute store missing after create');

  directory = await post(devRequest, 'api/admin_directory.php', {
    action: 'save_employee', csrf: directory.csrf, full_name: userName, user_id: userId,
    client_role_id: userRole.id, employee_type: 'USER', status: 'active', hourly_rate: '12.50',
    rate_effective_date: today, new_password: userPassword, store_access_mode: 'selected', store_ids: [Number(store.id)]
  }, 'create DUMMY dispute USER');
  const userEmployee = (directory.employees || []).find(e => String(e.user_id) === userId);
  if (!userEmployee) throw new Error('DUMMY USER missing after create');

  directory = await post(devRequest, 'api/admin_directory.php', {
    action: 'save_employee', csrf: directory.csrf, full_name: superName, user_id: superId,
    client_role_id: superRole.id, employee_type: 'SUPER', status: 'active', hourly_rate: '0',
    rate_effective_date: today, new_password: superPassword, store_access_mode: 'all', store_ids: []
  }, 'create DUMMY dispute SUPER');
  const superEmployee = (directory.employees || []).find(e => String(e.user_id) === superId);
  if (!superEmployee) throw new Error('DUMMY SUPER missing after create');

  const userContext = await browser.newContext();
  const superContext = await browser.newContext();
  await loginTenant(userContext, userId, userPassword, 'USER');
  await loginTenant(superContext, superId, superPassword, 'SUPER');
  console.log('PASS DUMMY native USER/SUPER login');

  let userState = await state(userContext, 'USER beta state');
  let superState = await state(superContext, 'SUPER beta state');
  if (!userState.permissions?.['disputes.submit_own'] || !superState.permissions?.['disputes.review']) {
    throw new Error('DUMMY dispute permissions unavailable');
  }

  const requestedIn = formatSydney(new Date(Date.now() - 2 * 60 * 60 * 1000));
  const requestedOut = formatSydney(new Date(Date.now() - 60 * 60 * 1000));
  const created = await post(userContext.request, 'api/disputes.php', {
    action: 'create', csrf: userState.csrf, shift_id: '', dispute_type: 'new_shift',
    requested_clock_in: requestedIn, requested_clock_out: requestedOut, proposed_store_id: Number(store.id),
    reason: `AUTOTEST missing shift ${stamp}`
  }, 'USER create new-shift dispute');
  const createDisputeId = created.result?.dispute_id;
  if (!createDisputeId || created.result?.status !== 'pending') throw new Error(`Unexpected create result: ${JSON.stringify(created.result)}`);
  console.log('PASS USER created dispute', createDisputeId);

  superState = await state(superContext, 'SUPER pending state');
  const pending = (superState.disputes || []).find(d => d.dispute_id === createDisputeId && d.status === 'pending');
  if (!pending) throw new Error('SUPER cannot see pending DUMMY dispute');
  await post(superContext.request, 'api/disputes.php', {
    action: 'decide', csrf: superState.csrf, dispute_id: createDisputeId, decision: 'approved', note: 'AUTOTEST approve missing shift'
  }, 'SUPER approve new-shift dispute');
  console.log('PASS SUPER approved new-shift dispute');

  userState = await state(userContext, 'USER approved state');
  const createdShift = (userState.recent_shifts || []).find(s => String(s.store_name) === storeName && String(s.status) === 'closed');
  if (!createdShift?.shift_id) throw new Error(`Approved shift not visible to USER: ${JSON.stringify(userState.recent_shifts)}`);
  console.log('PASS approved dispute created shift', createdShift.shift_id);

  const deleteCreated = await post(userContext.request, 'api/disputes.php', {
    action: 'create', csrf: userState.csrf, shift_id: createdShift.shift_id, dispute_type: 'delete_shift',
    reason: `AUTOTEST cleanup shift ${stamp}`
  }, 'USER create delete-shift dispute');
  const deleteDisputeId = deleteCreated.result?.dispute_id;
  if (!deleteDisputeId || deleteCreated.result?.status !== 'pending') throw new Error(`Unexpected delete result: ${JSON.stringify(deleteCreated.result)}`);

  superState = await state(superContext, 'SUPER delete pending state');
  await post(superContext.request, 'api/disputes.php', {
    action: 'decide', csrf: superState.csrf, dispute_id: deleteDisputeId, decision: 'approved', note: 'AUTOTEST cleanup approved shift'
  }, 'SUPER approve delete-shift dispute');

  userState = await state(userContext, 'USER cleanup state');
  const voidedShift = (userState.recent_shifts || []).find(s => s.shift_id === createdShift.shift_id);
  if (!voidedShift || String(voidedShift.status) !== 'void') throw new Error(`Shift cleanup not applied: ${JSON.stringify(voidedShift)}`);
  console.log('PASS USER -> SUPER dispute lifecycle and cleanup');

  directory = await json(await devRequest.get(BASE + 'api/admin_directory.php'), 'DUMMY cleanup directory');
  for (const employee of [userEmployee, superEmployee]) {
    directory = await post(devRequest, 'api/admin_directory.php', {
      action: 'save_employee', csrf: directory.csrf, id: employee.id,
      full_name: employee.id === userEmployee.id ? userName : superName,
      user_id: employee.id === userEmployee.id ? userId : superId,
      client_role_id: employee.id === userEmployee.id ? userRole.id : superRole.id,
      employee_type: employee.id === userEmployee.id ? 'USER' : 'SUPER', status: 'inactive', hourly_rate: employee.id === userEmployee.id ? '12.50' : '0',
      rate_effective_date: today, new_password: '', store_access_mode: employee.id === userEmployee.id ? 'selected' : 'all',
      store_ids: employee.id === userEmployee.id ? [Number(store.id)] : []
    }, `deactivate DUMMY employee ${employee.id}`);
  }
  await post(devRequest, 'api/admin_directory.php', {
    action: 'save_store', csrf: directory.csrf, id: store.id, store_name: storeName, status: 'inactive'
  }, 'deactivate DUMMY dispute store');

  console.log(`DUMMY_DISPUTES_OK create=${createDisputeId} delete=${deleteDisputeId} shift=${createdShift.shift_id}`);
  await userContext.close();
  await superContext.close();
  await dev.close();
  await browser.close();
}

main().catch(error => {
  console.error('DUMMY_DISPUTES_FAIL', error?.stack || error);
  process.exitCode = 1;
});
