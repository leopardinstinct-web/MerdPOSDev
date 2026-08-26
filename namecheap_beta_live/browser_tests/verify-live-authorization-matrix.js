const { chromium } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = (process.env.MERDPOS_BASE_URL || 'https://app.merdpos.com/beta/timesheet_portal/').replace(/\/?$/, '/');
const AUTH_STATE = process.env.MERDPOS_AUTH_STATE;
if (!AUTH_STATE || !fs.existsSync(AUTH_STATE)) throw new Error('MERDPOS_AUTH_STATE must point to an external Playwright storage-state JSON file.');

const SYSTEM_KEYS = ['USER', 'ADMIN', 'SUPER', 'DEV'];

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ storageState: AUTH_STATE });
  const page = await context.newPage();
  await page.goto(BASE + 'dashboard.php', { waitUntil: 'domcontentloaded', timeout: 15000 });
  if (!page.url().includes('dashboard.php')) throw new Error('AUTH_REQUIRED');

  const live = await page.evaluate(async () => {
    const response = await fetch('api/role_authority.php?_=' + Date.now(), { headers: { Accept: 'application/json' }, cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || !data?.success) throw new Error(data?.error || `role_authority failed (${response.status})`);
    return data;
  });

  const roles = (live.roles || []).filter(role => Number(role.is_system) === 1);
  const permissions = live.permissions || [];
  const errors = [];
  for (const key of SYSTEM_KEYS) if (!roles.some(role => String(role.role_key).toUpperCase() === key)) errors.push(`Missing system role ${key}`);
  const dev = roles.find(role => String(role.role_key).toUpperCase() === 'DEV');
  if (!dev || Number(dev.authority_level) !== 1000) errors.push('DEV role must exist at LOA 1000');

  const matrix = roles.map(role => {
    const key = String(role.role_key || '').toUpperCase();
    const loa = Number(role.authority_level || 0);
    const granted = permissions.filter(permission => permission.dev_only ? key === 'DEV' : loa >= Number(permission.min_authority_level || 1000));
    return { role_key: key, role_label: role.role_label, authority_level: loa, granted: granted.map(permission => permission.permission_key) };
  });

  for (const permission of permissions.filter(permission => permission.dev_only)) {
    for (const row of matrix.filter(row => row.role_key !== 'DEV')) {
      if (row.granted.includes(permission.permission_key)) errors.push(`${permission.permission_key} leaked to ${row.role_key}`);
    }
  }

  const developer = matrix.find(row => row.role_key === 'DEV');
  const state = await page.evaluate(async () => {
    const response = await fetch('api/beta_state.php?_=' + Date.now(), { headers: { Accept: 'application/json' }, cache: 'no-store' });
    const data = await response.json();
    return { status: response.status, success: data?.success, permissions: data?.permissions || {} };
  });
  if (!state.success || state.status !== 200) errors.push(`Developer beta_state failed (${state.status})`);
  if (developer) {
    for (const key of developer.granted) if (state.permissions[key] === false) errors.push(`Developer beta_state denies derived permission ${key}`);
  }

  for (const row of matrix) console.log(`ROLE ${row.role_key} loa=${row.authority_level} granted=${row.granted.length}`);
  console.log(`MATRIX permissions=${permissions.length} dev_only=${permissions.filter(permission => permission.dev_only).length}`);
  if (errors.length) throw new Error(errors.join(' | '));
  console.log('AUTH_MATRIX_OK');
  await browser.close();
})().catch(error => {
  console.error('AUTH_MATRIX_FATAL', error?.stack || error);
  process.exitCode = 1;
});
