const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
test.use({ channel: 'chrome' });
const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const portal = rel => path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', rel);
const source = rel => fs.readFileSync(portal(rel), 'utf8');

test('revision 117 palette exposes canonical semantic status colors without changing five brand masters', () => {
  const studio = source('assets/ui-studio.js');
  const tokens = source('assets/design-tokens.css');
  const server = source('includes/ui_studio_history.php');
  for (const [token, hex] of [['--color-warning','#8A5300'],['--color-danger','#B42318'],['--color-success','#18794E']]) {
    expect(tokens).toContain(`${token}: ${hex}`);
    expect(studio).toContain(`token:'${token}'`);
  }
  expect(studio).toContain('brand-[a-z0-9_-]{1,48}|success|warning|danger');
  expect(server).toContain('brand-[a-z0-9_-]{1,48}|success|warning|danger');
});

test('revision 117 fixes Workforce naming, close glyphs and DevStudio selector clobbering', () => {
  const dashboard = source('dashboard.php');
  const studio = source('assets/ui-studio.js');
  expect(dashboard).toContain('<h2 class="ui-page-title">Workforce</h2>');
  expect(dashboard).not.toContain('>Ã—</button>');
  expect(studio).toContain("getAttribute?.('id')");
  expect(studio).toContain('clearHover();clearSelected();');
});

test('revision 117 account shell keeps only copy metric, syncs storage and uses exact About icon', () => {
  const account = source('assets/account-menu.js');
  expect(account).not.toContain('data-studio-metric="requests"');
  expect(account).not.toContain('data-studio-metric="patches"');
  expect(account).toContain('data-studio-metric="copy"');
  expect(account).toContain("event.key===STUDIO_SETTINGS_KEY");
  expect(account).toContain('copyMetric.hidden=!enabled');
  expect(account).toContain('assets/brand/M_Icon_v2.svg?v=20260902ds117');
  const icon = fs.readFileSync(portal('assets/brand/M_Icon_v2.svg'));
  expect(crypto.createHash('sha256').update(icon).digest('hex')).toBe('fd98914e2dcb700477e04b0ed4ddece75ecebf304a632e775149b57b3f1d9177');
});

test('revision 117 desktop bottom navigation is icon-left label-right', async ({ page }) => {
  await page.setViewportSize({width:1280,height:800});
  await page.setContent('<div class="app-frame nav-bottom"><aside class="app-rail"><button class="rail-group-btn"><span class="ui-icon"></span><span class="rail-label">Home</span></button></aside></div>');
  await page.addStyleTag({path:portal('assets/design-tokens.css')});
  await page.addStyleTag({path:portal('assets/shell.css')});
  await expect(page.locator('.rail-group-btn')).toHaveCSS('flex-direction','row');
});

test('revision 117 shared directory toolbar and copy/toggle ordering are canonical', () => {
  const design = source('assets/design-system.css');
  const accountCss = source('assets/account-menu.css');
  const accountJs = source('assets/account-menu.js');
  expect(design).toContain('DS117 shared directory/page-toolbar alignment');
  expect(accountCss).toContain('grid-template-columns: var(--size-icon-lg) minmax(0, 1fr) auto auto');
  const summary = accountJs.match(/<div class="rail-user-summary">[^`]+/s)?.[0] || '';
  expect(summary.indexOf('${studioMetrics}')).toBeGreaterThan(summary.indexOf('rail-user-copy'));
  expect(summary.indexOf('rail-devstudio-toggle')).toBeGreaterThan(summary.indexOf('${studioMetrics}'));
});
