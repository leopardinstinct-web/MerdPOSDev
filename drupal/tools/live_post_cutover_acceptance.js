'use strict';
const fs = require('fs');
const { chromium } = require('@playwright/test');
const BASE = process.env.MERDPOS_DRUPAL_BASE || 'https://drupal-beta.merdpos.com';
const fixturePath = process.env.MERDPOS_E2E_FIXTURE;
if (!fixturePath || !fs.existsSync(fixturePath)) throw new Error('Set MERDPOS_E2E_FIXTURE to the private role fixture JSON.');
const fx = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
const roles = (process.env.MERDPOS_ACCEPTANCE_ROLES || 'dev,admin,super,user').split(',').map(v => v.trim().toLowerCase()).filter(Boolean);
const access = {
  dev:   { admin: 200, dev: 200 },
  admin: { admin: 200, dev: 403 },
  super: { admin: 200, dev: 403 },
  user:  { admin: 403, dev: 403 },
};
const surfaces = ['/merdpos','/merdpos/reports','/merdpos/finance'];
const retiredSurfaces = ['/merdpos/operations','/merdpos/disputes'];
const postOnly = ['/merdpos/dashboard/layout','/merdpos/attendance/scan','/merdpos/finance/submit','/merdpos/account/change-password','/merdpos/account/working-client','/merdpos/account/working-role','/merdpos/account/timesheet-google-sync'];
const adminTabs = ['clients','stores','defaults','workforce','roles'];
const badText = /website encountered an unexpected error|internal server error|fatal error/i;
const executablePath = process.env.MERDPOS_BROWSER || 'C:/Users/Imran/AppData/Local/Programs/Opera/opera.exe';
function assert(condition, message) { if (!condition) throw new Error(message); }
function expectedClientError(entry, role) {
  const m = /^(\d{3}) (.+)$/.exec(entry); if (!m) return false;
  const status = Number(m[1]), path = new URL(m[2]).pathname;
  if (postOnly.includes(path) && status === 405) return true;
  if (path === '/merdpos/admin/legacy-migration' && status === (role === 'user' ? 403 : 422)) return true;
  if (path === '/merdpos/dev' && role !== 'dev' && status === 403) return true;
  if (path === '/merdpos/admin' && role === 'user' && status === 403) return true;
  return false;
}
function expectedConsoleError(entry, role) {
  const path = (() => { try { return new URL(entry.location?.url || '').pathname; } catch { return ''; } })();
  if (!/^Failed to load resource: the server responded with a status of (403|405|422)/.test(entry.text || '')) return false;
  if (postOnly.includes(path)) return true;
  if (path === '/merdpos/admin/legacy-migration') return true;
  if (path === '/merdpos/dev' && role !== 'dev') return true;
  if (path === '/merdpos/admin' && role === 'user') return true;
  return false;
}
async function login(page, cred) {
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  await page.locator('#edit-user-id').fill(String(cred.user_id));
  await page.locator('#edit-password').fill(String(cred.password));
  await page.locator('#edit-submit').click();
  await page.waitForTimeout(500);
  const r = await page.goto(BASE + '/merdpos', { waitUntil: 'domcontentloaded' });
  assert(r.status() === 200 && await page.locator('#edit-user-id').count() === 0, 'login failed');
}
async function routeStatus(page, path) {
  const r = await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
  const text = await page.locator('body').innerText().catch(() => '');
  assert(!badText.test(text), `${path} rendered fatal runtime text`);
  return r.status();
}
async function consolidatedTimesheet(page, role) {
  for (const legacy of retiredSurfaces) {
    const status = await routeStatus(page, legacy);
    const url = new URL(page.url());
    assert(status === 200 && url.pathname === '/merdpos/reports' && url.hash === '#merdpos-shift-detail', `${role}: ${legacy} did not retire into Shift Detail`);
  }
  await page.goto(BASE + '/merdpos/reports', { waitUntil: 'domcontentloaded' });
  assert((await page.locator('h1').first().innerText()).trim() === 'Timesheets Report', `${role}: consolidated Timesheets Report title missing`);
  const navLabels = (await page.locator('.merdpos-bottom-nav-label').allTextContents()).map(v => v.trim());
  assert(navLabels.includes('Timesheets') && !navLabels.includes('Reports'), `${role}: primary navigation must expose Timesheets and retire Reports`);
  assert(await page.locator('#merdpos-shift-detail').count() === 1, `${role}: Shift Detail table missing`);
  const shiftRows = await page.locator('#merdpos-shift-detail tbody tr').count();
  if (shiftRows > 0) {
    const lastShiftHeader = ((await page.locator('#merdpos-shift-detail thead th').last().textContent()) || '').trim();
    assert(lastShiftHeader === 'Action', `${role}: Action is not the final Shift Detail column`);
  } else {
    assert(await page.locator('#merdpos-shift-detail .merdpos-report-empty').count() === 1, `${role}: Shift Detail is neither populated nor a valid empty state`);
  }
  assert(await page.locator('#merdpos-disputes-chart').count() === 0, `${role}: standalone dispute chart returned`);
  return { legacyRedirects: retiredSurfaces.length, actionColumn: shiftRows > 0 ? true : 'empty-state' };
}
async function roleControls(page, role) {
  const status = await routeStatus(page, '/merdpos/admin?tab=roles');
  if (status !== 200) return { status };
  const actions = page.locator('input[name="entity_action"]');
  const roleKeys = page.locator('input[name="role_key"]');
  const clientRoleOptions = await page.locator('select[name="client_role_id"] option').allTextContents();
  const result = {
    status,
    createRole: await page.locator('input[name="entity_action"][value="create_role"]').count() > 0,
    permissionThresholds: await page.locator('input[name="entity_action"][value="save_role_permissions"]').count() > 0,
    usabilityForms: await page.locator('input[name="entity_action"][value="save_role_usability"]').count(),
    superTarget: await page.locator('input[name="role_key"][value="SUPER"]').count() > 0,
    userTarget: await page.locator('input[name="role_key"][value="USER"]').count() > 0,
    devAssignable: clientRoleOptions.some(v => /^\s*(DEV|Developer)\b/i.test(v)),
  };
  if (role === 'dev') assert(result.createRole && result.permissionThresholds && result.usabilityForms === 0 && !result.devAssignable, 'DEV role controls mismatch');
  if (role === 'admin') assert(!result.createRole && !result.permissionThresholds && result.usabilityForms === 2 && result.superTarget && result.userTarget && !result.devAssignable, 'ADMIN role controls mismatch');
  if (role === 'super') assert(!result.createRole && !result.permissionThresholds && result.usabilityForms === 0, 'SUPER role controls mismatch');
  return result;
}
async function globalUiSweep(page, role) {
  const paths = ['/merdpos','/merdpos/reports','/merdpos/finance'];
  if (access[role].admin === 200) paths.push('/merdpos/admin');
  if (access[role].dev === 200) paths.push('/merdpos/dev');
  const report = {};
  for (const path of paths) {
    const status = await routeStatus(page, path);
    assert(status === 200, `${role}: UI sweep route failed ${path}`);
    const snapshot = await page.evaluate(() => {
      const visible = el => { const r=el.getBoundingClientRect(); const c=getComputedStyle(el); return c.visibility !== 'hidden' && c.display !== 'none' && r.width > 0 && r.height > 0; };
      const controls=[...document.querySelectorAll('.merdpos-app button,.merdpos-app input,.merdpos-app select,.merdpos-app textarea')].filter(visible);
      const problems=[];
      for (const el of controls) {
        const c=getComputedStyle(el), type=(el.getAttribute('type')||'').toLowerCase();
        if (c.boxSizing !== 'border-box') problems.push(`${el.tagName}.${el.className}:box-sizing=${c.boxSizing}`);
        if (!c.fontFamily) problems.push(`${el.tagName}.${el.className}:font-family-empty`);
        if (!['checkbox','radio','hidden'].includes(type) && ['INPUT','SELECT','TEXTAREA'].includes(el.tagName) && parseFloat(c.height) < 38) problems.push(`${el.tagName}.${el.className}:height=${c.height}`);
      }
      const cards=[...document.querySelectorAll('.merdpos-dashboard-kpi,.merdpos-dashboard-panel,.merdpos-ops-kpi,.merdpos-ops-panel,.merdpos-reports-kpi,.merdpos-report-chart-card,.merdpos-report-table-card,.merdpos-finance-kpi,.merdpos-finance-chart-card,.merdpos-finance-panel,.merdpos-finance-table-card,.merdpos-dev-kpi,.merdpos-dev-card,.merdpos-dev-source-card,.merdpos-dev-chart-card,.merdpos-dev-table-card,.merdpos-admin-editor,.merdpos-defaults-section,.merdpos-dispute-card')].filter(visible);
      for (const el of cards) { const c=getComputedStyle(el); if (c.backgroundColor === 'rgba(0, 0, 0, 0)' || c.borderRadius === '0px') problems.push(`${el.className}:surface-contract`); }
      const tables=[...document.querySelectorAll('.merdpos-app table')].filter(visible);
      for (const el of tables) if (getComputedStyle(el).borderCollapse !== 'collapse') problems.push(`${el.className}:table-collapse`);
      const header=document.querySelector('.merdpos-page-header');
      if (header && header.querySelector('[class*="roleline"],[class*="role-line"]')) problems.push('header-roleline-returned');
      return {controls:controls.length,cards:cards.length,tables:tables.length,problems};
    });
    assert(snapshot.problems.length === 0, `${role}: global UI primitive drift on ${path}: ${snapshot.problems.join('; ')}`);
    report[path]=snapshot;
  }
  return report;
}
async function previewRoleRedirect(page) {
  await page.goto(BASE + '/merdpos/dev', {waitUntil:'domcontentloaded'});
  assert(new URL(page.url()).pathname === '/merdpos/dev', 'DEV surface unavailable before preview switch');
  await page.locator('[data-merdpos-account-toggle]').click();
  const details=page.locator('.merdpos-account-role-context');
  if (!(await details.getAttribute('open'))) await details.locator('summary').click();
  const select=details.locator('[data-merdpos-working-role]');
  await select.selectOption('ADMIN');
  await page.waitForURL(url => new URL(url).pathname === '/merdpos', {timeout:10000});
  assert((await page.locator('[data-merdpos-role-pill]').innerText()).trim() === 'Admin', 'ADMIN preview did not become active');
  await page.goto(BASE + '/merdpos/dev', {waitUntil:'domcontentloaded'});
  assert(new URL(page.url()).pathname === '/merdpos', 'DEV route did not fail closed to Dashboard while previewing ADMIN');
  await page.locator('[data-merdpos-account-toggle]').click();
  const restore=page.locator('.merdpos-account-role-context');
  if (!(await restore.getAttribute('open'))) await restore.locator('summary').click();
  await restore.locator('[data-merdpos-working-role]').selectOption('DEV');
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(200);
  assert((await page.locator('[data-merdpos-role-pill]').innerText()).trim() === 'Developer', 'DEV preview role was not restored');
  return {adminRedirectedToDashboard:true,directDevGuarded:true,restoredDeveloper:true};
}
async function themeAndMobile(page, role) {
  await page.goto(BASE + '/merdpos', { waitUntil: 'domcontentloaded' });
  const toggle = page.locator('[data-merdpos-account-toggle]').first();
  if (await toggle.count()) await toggle.click();
  const themeToggle = page.locator('[data-merdpos-theme-toggle]').first();
  assert(await themeToggle.count() === 1, `${role}: theme toggle missing`);
  await page.evaluate(() => localStorage.setItem('merdpos-theme', 'dark'));
  await page.reload({ waitUntil: 'domcontentloaded' });
  assert(await page.locator('html').getAttribute('data-theme') === 'dark', `${role}: dark theme not persisted`);
  await page.evaluate(() => localStorage.setItem('merdpos-theme', 'system'));
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.setViewportSize({ width: 390, height: 844 });
  const mobile = {};
  for (const path of surfaces) {
    const status = await routeStatus(page, path);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 2);
    mobile[path] = { status, overflow };
    assert(status === 200 && !overflow, `${role}: mobile regression on ${path}`);
  }
  await page.setViewportSize({ width: 1440, height: 1000 });
  return mobile;
}
async function sessionChecks(page, cred) {
  await page.goto(BASE + '/merdpos', { waitUntil: 'domcontentloaded' });
  const toggle = page.locator('[data-merdpos-account-toggle]').first();
  if (await toggle.count()) await toggle.click();
  const logout = page.getByText('Log out', { exact: true }).first();
  assert(await logout.count() === 1, 'logout action missing');
  await logout.click();
  await page.waitForLoadState('domcontentloaded');
  assert(await page.locator('#edit-user-id').count() === 1, 'logout did not return to login');
  await login(page, cred);
  await page.context().clearCookies();
  await page.goto(BASE + '/merdpos', { waitUntil: 'domcontentloaded' });
  assert(await page.locator('#edit-user-id').count() === 1, 'cleared session did not require login');
}
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath });
  const report = { base: BASE, roles: {}, session: {}, verified: false };
  try {
    for (const role of roles) {
      const cred = fx.employees?.[role];
      assert(cred?.user_id && cred?.password, `fixture missing ${role}`);
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
      const page = await context.newPage();
      const runtime = { pageErrors: [], consoleErrors: [], failedRequests: [], clientErrors: [], serverErrors: [] };
      page.on('pageerror', e => runtime.pageErrors.push(String(e.message || e)));
      page.on('console', m => { if (m.type() === 'error') runtime.consoleErrors.push({text:m.text(), location:m.location()}); });
      page.on('requestfailed', r => runtime.failedRequests.push(`${r.method()} ${r.url()} ${r.failure()?.errorText || ''}`));
      page.on('response', r => { if (r.status() >= 500) runtime.serverErrors.push(`${r.status()} ${r.url()}`); else if (r.status() >= 400) runtime.clientErrors.push(`${r.status()} ${r.url()}`); });
      await login(page, cred);
      const routes = {};
      for (const path of surfaces) routes[path] = await routeStatus(page, path);
      routes['/merdpos/admin'] = await routeStatus(page, '/merdpos/admin');
      routes['/merdpos/dev'] = await routeStatus(page, '/merdpos/dev');
      routes['/merdpos/admin/legacy-migration'] = await routeStatus(page, '/merdpos/admin/legacy-migration');
      routes['/merdpos/reports/export.csv'] = await page.evaluate(async (u) => (await fetch(u, {credentials:'same-origin'})).status, BASE + '/merdpos/reports/export.csv');
      assert(surfaces.every(p => routes[p] === 200), `${role}: core surface route failure`);
      assert(routes['/merdpos/admin'] === access[role].admin && routes['/merdpos/dev'] === access[role].dev, `${role}: route boundary mismatch`);
      const legacyExpected = role === 'user' ? 403 : 422;
      assert(routes['/merdpos/admin/legacy-migration'] === legacyExpected, `${role}: legacy route boundary mismatch`);
      assert(routes['/merdpos/reports/export.csv'] === 200, `${role}: report export unavailable`);
      const timesheet = await consolidatedTimesheet(page, role);
      const methodSafety = {};
      for (const path of postOnly) methodSafety[path] = await routeStatus(page, path);
      assert(Object.values(methodSafety).every(v => v === 405), `${role}: POST-only route accepted GET`);
      const tabs = {};
      for (const tab of adminTabs) tabs[tab] = await routeStatus(page, `/merdpos/admin?tab=${tab}`);
      assert(Object.values(tabs).every(v => v === access[role].admin), `${role}: admin tab access mismatch`);
      const controls = await roleControls(page, role);
      if (role === 'user') assert(controls.status === 403, 'USER unexpectedly reached role controls');
      await page.goto(BASE + '/merdpos', { waitUntil: 'domcontentloaded' });
      const account = {
        passwordAction: await page.locator('[data-merdpos-password-open]').count(),
        workingClientSelect: await page.locator('[data-merdpos-working-client]').count(),
        workingClientOptions: await page.locator('[data-merdpos-working-client] option').count(),
      };
      assert(account.passwordAction === 1, `${role}: password action missing`);
      if (role === 'dev') assert(account.workingClientSelect === 1 && account.workingClientOptions >= 2, 'DEV Working Client control missing');
      const uiSweep = await globalUiSweep(page, role);
      const previewRedirect = role === 'dev' ? await previewRoleRedirect(page) : null;
      const mobile = await themeAndMobile(page, role);
      const unexpectedClientErrors = runtime.clientErrors.filter(v => !expectedClientError(v, role));
      const unexpectedConsoleErrors = runtime.consoleErrors.filter(v => !expectedConsoleError(v, role));
      assert(runtime.pageErrors.length === 0 && unexpectedConsoleErrors.length === 0 && runtime.failedRequests.length === 0 && unexpectedClientErrors.length === 0 && runtime.serverErrors.length === 0, `${role}: browser/runtime errors detected ${JSON.stringify({runtime,unexpectedClientErrors,unexpectedConsoleErrors})}`);
      report.roles[role] = { routes, methodSafety, tabs, controls, account, timesheet, uiSweep, previewRedirect, mobile, runtime };
      if (role === 'user') { await sessionChecks(page, cred); report.session = { logout: true, clearedCookieRequiresLogin: true }; }
      await context.close();
    }
    report.verified = true;
    console.log(JSON.stringify(report));
  } finally {
    await browser.close();
  }
})().catch(e => { console.error(`POST_CUTOVER_ACCEPTANCE_FAIL ${e.stack || e}`); process.exit(1); });
