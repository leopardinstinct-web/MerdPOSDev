const { chromium } = require('@playwright/test');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = (process.env.MERDPOS_BASE_URL || 'https://app.merdpos.com/beta/timesheet_portal/').replace(/\/?$/, '/');
const AUTH_STATE = process.env.MERDPOS_AUTH_STATE;
const OUT = process.env.MERDPOS_POLICY_OUTPUT || path.join(os.tmpdir(), 'merdpos-live-policy.json');

if (!AUTH_STATE) throw new Error('MERDPOS_AUTH_STATE must point to an external Playwright storage-state file.');
if (!fs.existsSync(AUTH_STATE)) throw new Error(`MERDPOS_AUTH_STATE not found: ${AUTH_STATE}`);

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ storageState: AUTH_STATE });
  const page = await context.newPage();
  await page.goto(BASE + 'dashboard.php', { waitUntil: 'domcontentloaded', timeout: 15000 });
  if (!page.url().includes('dashboard.php')) throw new Error('AUTH_REQUIRED');

  const policy = await page.evaluate(async () => {
    const response = await fetch('api/role_authority.php?_=' + Date.now(), {
      headers: { Accept: 'application/json' }, cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || !data?.success) throw new Error(data?.error || `role_authority failed (${response.status})`);
    return {
      captured_at: new Date().toISOString(),
      client: data.client,
      roles: (data.roles || []).map(role => ({
        id: role.id, role_key: role.role_key, role_label: role.role_label,
        authority_level: Number(role.authority_level), is_system: Number(role.is_system),
        employee_count: Number(role.employee_count || 0)
      })),
      permissions: (data.permissions || []).map(permission => ({
        permission_key: permission.permission_key, label: permission.label,
        category: permission.category, min_authority_level: Number(permission.min_authority_level),
        dev_only: Boolean(permission.dev_only)
      }))
    };
  });

  fs.writeFileSync(OUT, JSON.stringify(policy, null, 2));
  console.log(`POLICY_CAPTURED roles=${policy.roles.length} permissions=${policy.permissions.length}`);
  console.log(`POLICY ${OUT}`);
  await browser.close();
})().catch(error => {
  console.error('POLICY_FATAL', error?.stack || error);
  process.exitCode = 1;
});
