const { chromium } = require('@playwright/test');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = (process.env.MERDPOS_BASE_URL || 'https://app.merdpos.com/beta/timesheet_portal/').replace(/\/?$/, '/');
const AUTH_STATE = process.env.MERDPOS_AUTH_STATE;
const OUT = process.env.MERDPOS_AUDIT_OUTPUT || path.join(os.tmpdir(), 'merdpos-live-audit');
const EXPECT_PROFILE = (process.env.MERDPOS_AUDIT_PROFILE || 'developer').toLowerCase();

if (!AUTH_STATE) throw new Error('MERDPOS_AUTH_STATE must point to a Playwright storage-state JSON file outside the repository.');
if (!fs.existsSync(AUTH_STATE)) throw new Error(`MERDPOS_AUTH_STATE not found: ${AUTH_STATE}`);
if (EXPECT_PROFILE !== 'developer') throw new Error('Only the developer profile is defined today. Add an explicit permission profile before testing another identity.');

fs.mkdirSync(OUT, { recursive: true });
const results = [];
const runtimeErrors = [];
const failedResponses = [];
const stamp = new Date().toISOString();

function clean(value) {
  return String(value || '').replace(/\s+/g, ' ').trim().slice(0, 700);
}
function record(name, ok, detail = '') {
  results.push({ name, ok: !!ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ' :: ' + detail : ''}`);
}
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

async function main() {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ storageState: AUTH_STATE, viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(4000);

  page.on('pageerror', error => runtimeErrors.push(`PAGE ${error.message}`));
  page.on('console', message => {
    if (message.type() !== 'error') return;
    const text = message.text();
    if (/^Failed to load resource: the server responded with a status of 404/i.test(text)) return;
    runtimeErrors.push(`CONSOLE ${text}`);
  });
  page.on('response', response => {
    if (response.status() >= 400 && response.url().includes('/beta/timesheet_portal/')) {
      failedResponses.push(`${response.status()} ${response.request().method()} ${response.url()}`);
    }
  });

  await page.goto(BASE + 'dashboard.php', { waitUntil: 'domcontentloaded', timeout: 15000 });
  await sleep(1200);
  const authenticated = page.url().includes('dashboard.php') && !(await page.locator('#loginForm').count());
  record('Authenticated dashboard session', authenticated, page.url());
  if (!authenticated) throw new Error('AUTH_REQUIRED: refresh MERDPOS_AUTH_STATE with a one-time browser login');

  async function visiblePanelId() {
    return page.evaluate(() => {
      const panel = [...document.querySelectorAll('.portal-panel')].find(node => !node.hidden && getComputedStyle(node).display !== 'none');
      return panel?.id || '';
    });
  }
  async function visiblePanelText(limit = 900) {
    return page.evaluate(limitValue => {
      const panel = [...document.querySelectorAll('.portal-panel')].find(node => !node.hidden && getComputedStyle(node).display !== 'none');
      return panel ? (panel.innerText || '').replace(/\s+/g, ' ').trim().slice(0, limitValue) : '';
    }, limit);
  }
  async function clickText(text) {
    return page.evaluate(label => {
      const normalize = value => String(value || '').replace(/\s+/g, ' ').trim();
      const nodes = [...document.querySelectorAll('button,[role="button"],a')];
      const node = nodes.find(item => normalize(item.innerText || item.textContent).replace(/›$/, '').trim() === label);
      if (!node) return false;
      node.click();
      return true;
    }, text);
  }
  async function clickPanel(target) {
    return page.evaluate(panelId => {
      const node = document.querySelector(`[data-panel="${panelId}"]`);
      if (!node) return false;
      node.click();
      return true;
    }, target);
  }
  async function checkPanel(label, target, marker) {
    const clicked = await clickPanel(target);
    await sleep(700);
    const panelId = await visiblePanelId();
    const text = await visiblePanelText();
    const ok = clicked && panelId === target && (!marker || text.toLowerCase().includes(marker.toLowerCase()));
    record(label, ok, `${panelId} :: ${clean(text)}`);
  }

  await checkPanel('Dashboard', 'dashboardPanel', 'Dashboard');
  await clickText('Operations'); await sleep(250);
  await checkPanel('Operations / Stores', 'storesPanel', 'Stores');
  await checkPanel('Operations / Roles', 'rolesPanel', 'Roles');
  await sleep(700);
  record('Roles data resolved', (await visiblePanelText()).includes('Developer'), clean(await visiblePanelText()));
  await checkPanel('Operations / Workforce', 'employeesPanel', 'Employees');
  await checkPanel('Operations / Defaults', 'defaultsPanel', 'Defaults');
  await sleep(700);
  record('Defaults data resolved', !(await visiblePanelText()).includes('Loading defaults'), clean(await visiblePanelText()));

  await clickText('Reports'); await sleep(250);
  await checkPanel('Reports / Timesheets', 'timesheetPanel', 'Timesheets');
  const weekInfo = await page.evaluate(() => {
    const select = document.querySelector('#weekSelect');
    return select ? { value: select.value, values: [...select.options].map(option => option.value) } : null;
  });
  record('Timesheet week selector', !!weekInfo && weekInfo.values.length > 1, weekInfo ? `${weekInfo.values.length} weeks; current ${weekInfo.value}` : 'missing');
  if (weekInfo && weekInfo.values.length > 1) {
    const other = weekInfo.values.find(value => value !== weekInfo.value);
    await page.evaluate(value => {
      const select = document.querySelector('#weekSelect');
      select.value = value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }, other);
    await sleep(900);
    const changed = await page.locator('#timesheetPanel').innerText().catch(() => '');
    record('Timesheet week refresh', !changed.includes('Loading'), clean(changed));
    await page.evaluate(value => {
      const select = document.querySelector('#weekSelect');
      select.value = value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }, weekInfo.value);
    await sleep(700);
  }

  await checkPanel('Reports / Disputes', 'disputesPanel', 'Disputes');
  await checkPanel('Financial', 'financialPanel', 'Financial');
  const financial = await visiblePanelText();
  record('Financial read-only state', financial.includes('Daily Cash') && financial.includes('Closing'), clean(financial));
  await clickText('DEV'); await sleep(250);
  await checkPanel('DEV inspector', 'devPanel', 'DEV system inspector');
  await checkPanel('DEV / Clients', 'clientsPanel', 'Clients');
  await page.screenshot({ path: path.join(OUT, 'desktop.png'), fullPage: true }).catch(() => {});

  await page.setViewportSize({ width: 390, height: 844 });
  await sleep(500);
  const mobile = await page.evaluate(() => ({ media: matchMedia('(max-width: 720px)').matches, width: innerWidth, scrollWidth: document.documentElement.scrollWidth }));
  record('Mobile viewport active', mobile.media && mobile.width === 390, JSON.stringify(mobile));
  record('No mobile horizontal overflow', mobile.scrollWidth <= mobile.width + 4, JSON.stringify(mobile));
  await checkPanel('Mobile Dashboard navigation', 'dashboardPanel', 'Dashboard');
  await clickText('Reports'); await sleep(150);
  await checkPanel('Mobile Reports / Timesheets', 'timesheetPanel', 'Timesheets');
  await clickText('Operations'); await sleep(150);
  await checkPanel('Mobile Operations / Workforce', 'employeesPanel', 'Employees');
  await clickText('DEV'); await sleep(150);
  await checkPanel('Mobile DEV / Clients', 'clientsPanel', 'Clients');
  await page.screenshot({ path: path.join(OUT, 'mobile.png'), fullPage: true }).catch(() => {});

  const uniqueRuntime = [...new Set(runtimeErrors)];
  const uniqueHttp = [...new Set(failedResponses)];
  record('No browser runtime errors', uniqueRuntime.length === 0, uniqueRuntime.join(' | '));
  record('No failed app HTTP responses', uniqueHttp.length === 0, uniqueHttp.join(' | '));

  const passed = results.filter(result => result.ok).length;
  const failed = results.length - passed;
  const report = { generatedAt: stamp, baseUrl: BASE, profile: EXPECT_PROFILE, summary: { total: results.length, passed, failed }, results, runtimeErrors: uniqueRuntime, failedResponses: uniqueHttp };
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
  console.log(`AUDIT_DONE total=${results.length} passed=${passed} failed=${failed}`);
  console.log(`REPORT ${path.join(OUT, 'report.json')}`);

  await browser.close();
  if (failed) process.exitCode = 1;
}

main().catch(error => {
  console.error('AUDIT_FATAL', error?.stack || error);
  process.exitCode = 2;
});
