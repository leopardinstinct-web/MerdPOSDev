const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.use({ channel: 'chrome' });

const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const tokensPath = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', 'assets', 'design-tokens.css');
const brandPath = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', 'assets', 'brand', 'brand.css');
const accountPath = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal', 'assets', 'account-menu.css');

const master = {
  white: '#FFFFFF',
  background: '#F5F7FC',
  navy: '#031B4B',
  cyan: '#12BDF3',
  violet: '#8B2EFF',
};
test('canonical brand master palette is exactly the approved five colors', async ({ page }) => {
  const tokens = fs.readFileSync(tokensPath, 'utf8');
  const brand = fs.readFileSync(brandPath, 'utf8');
  const account = fs.readFileSync(accountPath, 'utf8');

  for (const [name, hex] of Object.entries(master)) {
    expect(tokens).toContain(`--color-brand-${name}: ${hex}`);
  }

  for (const retired of ['#1D6CFF','#2B90FF','#586CFF','#9638FF','#B184FF','#6A748B','#55617C']) {
    expect(tokens + brand + account).not.toContain(retired);
  }

  await page.setContent(`<style>${tokens}</style>
    <div id="bg" style="background:var(--color-bg-main)"></div>
    <div id="primary" style="background:var(--color-brand-primary)"></div>
    <div id="cyan" style="background:var(--color-brand-cyan)"></div>`);

  await expect(page.locator('#bg')).toHaveCSS('background-color', 'rgb(245, 247, 252)');
  await expect(page.locator('#primary')).toHaveCSS('background-color', 'rgb(139, 46, 255)');
  await expect(page.locator('#cyan')).toHaveCSS('background-color', 'rgb(18, 189, 243)');

  await page.evaluate(() => { document.documentElement.dataset.theme = 'dark'; });
  await expect(page.locator('#bg')).toHaveCSS('background-color', 'rgb(3, 27, 75)');
});
