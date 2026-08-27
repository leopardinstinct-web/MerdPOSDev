const { chromium } = require('@playwright/test');
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');

const BASE = (process.env.MERDPOS_BASE_URL || 'https://app.merdpos.com/beta/timesheet_portal/').replace(/\/?$/, '/');
const AUTH_STATE = process.env.MERDPOS_AUTH_STATE;
const OUT = process.env.MERDPOS_DUMMY_OUTPUT || path.join(os.tmpdir(), 'merdpos-dummy-financial');
const TARGET_CLIENT_CODE = (process.env.MERDPOS_TEST_CLIENT_CODE || 'DUMMY').toUpperCase();

if (!AUTH_STATE) throw new Error('MERDPOS_AUTH_STATE must point to a Playwright storage-state JSON file outside the repository.');
if (!fs.existsSync(AUTH_STATE)) throw new Error(`MERDPOS_AUTH_STATE not found: ${AUTH_STATE}`);

fs.mkdirSync(OUT, { recursive: true });

const stamp = new Date().toISOString().replace(/[-:.TZ]/g, '').slice(0, 14);
const storeName = `AUTOTEST Financial ${stamp}`;
const businessDate = `2099-12-${String((Number(stamp.slice(-2)) % 20) + 1).padStart(2, '0')}`;
const uuid = () => crypto.randomUUID();
const results = [];

function record(name, ok, detail = '') {
  results.push({ name, ok: !!ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ' :: ' + detail : ''}`);
}

async function readJson(response, label) {
  const text = await response.text();
  let data;
  try {
    data = text ? JSON.parse(text) : null;
  } catch (_) {
    throw new Error(`${label}: invalid JSON ${response.status()} ${text.slice(0, 300)}`);
  }
  if (!response.ok() || !data?.success) {
    throw new Error(`${label}: ${response.status()} ${JSON.stringify(data).slice(0, 700)}`);
  }
  return data;
}

async function post(request, api, body, label) {
  return readJson(await request.post(BASE + api, { data: body, headers: { Accept: 'application/json' } }), label);
}

async function ensureActiveTestClient(request) {
  let contextState = await readJson(await request.get(BASE + 'api/client_context.php'), 'client_context GET');
  let target = (contextState.clients || []).find(client => String(client.client_code).toUpperCase() === TARGET_CLIENT_CODE);
  if (!target) {
    const clients = await readJson(await request.get(BASE + 'api/clients.php'), 'clients GET');
    const inactiveTarget = (clients.clients || []).find(client => String(client.client_code).toUpperCase() === TARGET_CLIENT_CODE);
    if (!inactiveTarget) throw new Error(`${TARGET_CLIENT_CODE} client does not exist`);
    await post(request, 'api/clients.php', {
      action: 'save_client',
      csrf: clients.csrf,
      id: inactiveTarget.id,
      name: inactiveTarget.name || TARGET_CLIENT_CODE,
      client_code: TARGET_CLIENT_CODE,
      status: 'active',
    }, `activate ${TARGET_CLIENT_CODE}`);
    contextState = await readJson(await request.get(BASE + 'api/client_context.php'), 'client_context refresh');
    target = (contextState.clients || []).find(client => String(client.client_code).toUpperCase() === TARGET_CLIENT_CODE);
  }
  if (!target) throw new Error(`${TARGET_CLIENT_CODE} is not selectable after activation`);
  const selected = await post(request, 'api/client_context.php', {
    action: 'select_client',
    csrf: contextState.csrf,
    client_id: target.id,
  }, `select ${TARGET_CLIENT_CODE}`);
  if (String(selected.client?.client_code).toUpperCase() !== TARGET_CLIENT_CODE) {
    throw new Error(`Client switch did not land on ${TARGET_CLIENT_CODE}`);
  }
  record(`Selected ${TARGET_CLIENT_CODE}`, true, `client_id=${selected.client.id}`);
}

async function main() {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ storageState: AUTH_STATE });
  const request = context.request;

  try {
    await ensureActiveTestClient(request);

    let directory = await readJson(await request.get(BASE + 'api/admin_directory.php'), 'directory GET');
    directory = await post(request, 'api/admin_directory.php', {
      action: 'save_store',
      csrf: directory.csrf,
      store_name: storeName,
      status: 'active',
    }, 'create AUTOTEST store');
    const store = (directory.stores || []).find(row => row.store_name === storeName);
    if (!store) throw new Error('Created AUTOTEST store not returned by directory API');
    record('Created DUMMY AUTOTEST store', true, `store_id=${store.id}; name=${storeName}`);

    await post(request, 'api/financials.php', {
      csrf: directory.csrf,
      submission_id: uuid(),
      submission_type: 'open_day',
      business_date: businessDate,
      store_id: Number(store.id),
      payload: { register_opening: '100.00', petty_cash_opening: '25.00' },
    }, 'financial open_day');
    record('Financial Open Day', true, businessDate);

    await post(request, 'api/financials.php', {
      csrf: directory.csrf,
      submission_id: uuid(),
      submission_type: 'cash_in',
      business_date: businessDate,
      store_id: Number(store.id),
      payload: { transactions: [{ account: 'Register', head: 'AUTOTEST cash sale', amount: '30.00' }] },
    }, 'financial cash_in');
    record('Financial Cash In', true, 'Register +30.00');

    await post(request, 'api/financials.php', {
      csrf: directory.csrf,
      submission_id: uuid(),
      submission_type: 'cash_out',
      business_date: businessDate,
      store_id: Number(store.id),
      payload: { transactions: [{ account: 'Petty Cash', head: 'AUTOTEST petty expense', amount: '5.00' }] },
    }, 'financial cash_out');
    record('Financial Cash Out', true, 'Petty Cash -5.00');

    await post(request, 'api/financials.php', {
      csrf: directory.csrf,
      submission_id: uuid(),
      submission_type: 'z_report',
      business_date: businessDate,
      store_id: Number(store.id),
      payload: { register_total: '150.00', petty_cash_addin: '0.00' },
    }, 'financial z_report');
    record('Financial Z Report', true, 'Register closing 150.00');

    const statement = await readJson(await request.get(BASE + `api/financials.php?store_id=${store.id}&business_date=${businessDate}`), 'financial statement');
    const accounts = Array.isArray(statement.statement?.accounts) ? statement.statement.accounts : [];
    const register = accounts.find(row => row.account === 'Register');
    const petty = accounts.find(row => row.account === 'Petty Cash');
    const balancesOk = register?.status === 'closed' && Number(register?.closing) === 150
      && petty?.status === 'closed' && Number(petty?.closing) === 20;
    record('Financial statement balances', balancesOk, JSON.stringify({ register, petty }));

    const passed = results.filter(result => result.ok).length;
    const failed = results.length - passed;
    const report = { generatedAt: new Date().toISOString(), baseUrl: BASE, clientCode: TARGET_CLIENT_CODE, storeName, businessDate, summary: { total: results.length, passed, failed }, results };
    fs.writeFileSync(path.join(OUT, 'dummy-financial-report.json'), JSON.stringify(report, null, 2));
    console.log(`DUMMY_FINANCIAL_DONE total=${results.length} passed=${passed} failed=${failed}`);
    if (failed) process.exitCode = 1;
  } finally {
    await browser.close();
  }
}

main().catch(error => {
  console.error('DUMMY_FINANCIAL_FAIL', error?.stack || error);
  process.exitCode = 1;
});
