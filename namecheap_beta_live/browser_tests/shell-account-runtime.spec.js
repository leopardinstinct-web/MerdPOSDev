const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.use({ channel: 'chrome' });

const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const portalRoot = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal');
const dashboardPath = path.join(portalRoot, 'dashboard.php');
const navigationPath = path.join(portalRoot, 'assets', 'navigation.js');
const accountPath = path.join(portalRoot, 'assets', 'account-menu.js');
const tokensPath = path.join(portalRoot, 'assets', 'design-tokens.css');
const shellCssPath = path.join(portalRoot, 'assets', 'shell.css');
const accountCssPath = path.join(portalRoot, 'assets', 'account-menu.css');
const aboutTaglinePath = path.join(portalRoot, 'assets', 'brand', 'smarter-faster-together.png');
const betaApiPath = path.join(portalRoot, 'includes', 'beta_api.php');
const dashboardDataPath = path.join(portalRoot, 'api', 'dashboard_data.php');
const dashboardAccessPath = path.join(portalRoot, 'includes', 'dashboard_access.php');
const clientContextPath = path.join(portalRoot, 'api', 'client_context.php');
const timesheetRefreshPath = path.join(portalRoot, 'api', 'timesheet_google_refresh.php');

const fixtureHtml = `<!doctype html><html><head>
  <script data-id-order></script><script data-dashboard-builder></script>
  <link rel="stylesheet" data-dashboard-builder-css href="data:text/css,">
</head><body>
  <div id="shellAccountSources" hidden data-user-name="Imran" data-role-label="Developer" data-role-key="DEV" data-view-role-key="ADMIN">
    <button id="passwordBtn" type="button"><span>Change password</span></button>
    <button id="logoutBtn" type="button"><span>Log out</span></button>
  </div>
  <main class="merd-page-shell">
    <nav class="merd-nav">
      <div class="nav-group"><span class="nav-group-label">Overview</span><button class="portal-tab active" data-panel="dashboardPanel"><span>Dashboard</span></button></div>
      <div class="nav-group"><span class="nav-group-label">Operations</span><button class="portal-tab" data-panel="storesPanel"><span>Stores</span></button><button class="portal-tab" data-panel="employeesPanel"><span>Workforce</span></button></div>
      <div class="nav-group"><span class="nav-group-label">Reports</span><button class="portal-tab" data-panel="reportsPanel"><span>Overview</span></button><button class="portal-tab" data-panel="timesheetPanel"><span>Timesheets</span></button><button class="portal-tab" data-panel="disputesPanel"><span>Disputes</span></button></div>
      <div class="nav-group"><span class="nav-group-label">Finance</span><button class="portal-tab" data-panel="financialPanel"><span>Financial</span></button></div>
      <div class="nav-group"><span class="nav-group-label">System</span><button class="portal-tab dev-tab" data-panel="devPanel"><span>DEV</span></button></div>
    </nav>
    <section id="dashboardPanel" class="portal-panel">Dashboard</section><section id="storesPanel" class="portal-panel" hidden>Stores</section><section id="employeesPanel" class="portal-panel" hidden>Workforce</section><section id="reportsPanel" class="portal-panel" hidden>Reports</section><section id="timesheetPanel" class="portal-panel" hidden>Timesheets</section><section id="disputesPanel" class="portal-panel" hidden>Disputes</section><section id="financialPanel" class="portal-panel" hidden>Financial</section><section id="devPanel" class="portal-panel" hidden>DEV</section>
  </main>
  <dialog id="merdposAboutDialog" class="merd-about-dialog" aria-labelledby="merdposAboutTitle"><div class="merd-about-card"><section class="merd-about-copy"><img class="merd-about-logo" alt="MERDPOS"><h2 id="merdposAboutTitle" class="merd-about-title">Release information</h2><div class="merd-about-release-grid"><div class="merd-about-release-row"><span>MERDPOS</span><strong>abc1234</strong><small>30 Aug 2026</small></div></div><div class="merd-about-tagline"><img src="assets/brand/smarter-faster-together.png?v=20260902about3" alt="Smarter Faster Together"></div><footer class="merd-about-foot"><span>Copyright Â© 2026 All rights reserved</span></footer></section><section class="merd-about-art"><span class="merd-about-shape shape-a"></span><span class="merd-about-shape shape-b"></span><span class="merd-about-shape shape-c"></span><img alt=""></section><button id="merdposAboutClose" class="merd-about-close">×</button></div></dialog>
</body></html>`;
async function mountShell(page, width = 1280) {
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  await page.setViewportSize({ width, height: 844 });
  await page.route('https://merdpos-smoke.invalid/shell-fixture', route => route.fulfill({
    status: 200, contentType: 'text/html', body: fixtureHtml,
  }));
  await page.route('**/assets/brand/smarter-faster-together.png*', route => route.fulfill({ status:200, contentType:'image/png', body:fs.readFileSync(aboutTaglinePath) }));
  await page.route('**/api/client_context.php*', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      success: true, can_select_client: true, active_client_id: 1,
      csrf: 'test-csrf', client: { id: 1, name: 'Merd Retail Group', client_code: 'MRG' },
      clients: [
        { id: 1, name: 'Merd Retail Group', client_code: 'MRG', status: 'active' },
        { id: 2, name: 'DUMMY', client_code: 'DUMMY', status: 'active' },
      ],
    }),
  }));
  await page.route('**/api/timesheet_google_refresh.php', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({success:true,client_id:1,sheet_name:'Time Sheet',source_rows:4080,deleted_rows:3972,inserted_rows:4080}),
  }));
  await page.goto('https://merdpos-smoke.invalid/shell-fixture');
  await page.addStyleTag({ path: tokensPath });
  await page.addStyleTag({ path: shellCssPath });
  await page.addStyleTag({ path: accountCssPath });
  await page.addScriptTag({ content: 'window.MERDPOSTheme={current:()=>"light",toggle:()=>{}};window.MERDPOS_AUTH={role_key:"ADMIN",role_label:"Admin",actual_role_key:"DEV",actual_role_label:"Developer",view_role_key:"ADMIN",is_dev:true};' });
  await page.addScriptTag({ path: navigationPath });
  await page.addScriptTag({ path: accountPath });
  await expect(page.locator('.rail-client-section')).toHaveCount(1);
  return pageErrors;
}
test('DEV role preview is universal across shell and API permission context', async () => {
  const source = fs.readFileSync(dashboardPath, 'utf8');
  const betaApi = fs.readFileSync(betaApiPath, 'utf8');
  const dashboardData = fs.readFileSync(dashboardDataPath, 'utf8');
  const dashboardAccess = fs.readFileSync(dashboardAccessPath, 'utf8');
  const clientContext = fs.readFileSync(clientContextPath, 'utf8');
  const timesheetRefresh = fs.readFileSync(timesheetRefreshPath, 'utf8');
  expect(source).not.toContain('<header class="topbar merd-topbar">');
  expect(source).toContain('id="shellAccountSources"');
  expect(source).toContain('assets/management.js?v=20260902ds130');
  expect(source).toContain("$permissions = (array)($user['permissions'] ?? []);");
  expect(source).not.toContain("$previewUser['actual_employee_type']");
  expect(betaApi).toContain('function beta_apply_dev_role_preview');
  expect(betaApi).toContain("$_COOKIE['merdpos_dev_view_role']");
  expect(betaApi).toContain("['DEV','ADMIN','SUPER','USER']");
  expect(betaApi).toContain("if ($viewRoleKey === 'DEV')");
  expect(betaApi).toContain("$user['employee_type'] = $viewRoleKey");
  expect(betaApi).toContain("$user['permissions'] = $permissions");
  expect(betaApi).toContain("!empty($user['is_role_preview'])");
  expect(betaApi).toContain('$user=beta_apply_dev_role_preview($pdo,$user);');
  expect(dashboardData).toContain('$effectiveRole = merd_dashboard_user_role($pdo, $user);');
  expect(dashboardData).toContain("beta_require_permission($user, 'dashboard.configure', $pdo);");
  expect(dashboardAccess).toContain('function merd_dashboard_dependency_enabled');
  expect(dashboardAccess).toContain("visibility_permission" );
  expect(dashboardData).toContain("merd_dashboard_dependency_enabled($allowed, 'workforce.view')");
  expect(dashboardData).toContain("'working_count'=>$workingCount");
  expect(dashboardData).toContain("'pending_disputes_count'=>$pendingDisputesCount");
  expect(clientContext).toContain('$canSelect = beta_user_is_dev($user);');
  expect(betaApi).toContain("case 'timesheet_google_refresh.php':");
  expect(timesheetRefresh).toContain('beta_actual_user_is_dev($user)');
  expect(timesheetRefresh).toContain("legacy_parse_known_csv_rows($csv, 'timesheet', $sheetName)");
  expect(timesheetRefresh).toContain('DELETE FROM employee_logs WHERE client_id=?');
  expect(timesheetRefresh).toContain('beginTransaction()');
  expect(timesheetRefresh).toContain('attendance_authority FROM client_migration_state');
  expect(timesheetRefresh).toContain("source_type='attendance_log'");
  expect(timesheetRefresh).not.toContain('UPDATE client_migration_state');
  expect(source).toContain('id="merdposAboutDialog"');
  expect(source).toContain('assets/brand/M_Icon_v2.svg');
  expect(source).toContain("dirname(__DIR__) . '/.beta_release.json'");
  expect(source).not.toContain('$devStudioVersion');
  expect(source).not.toContain('Release Highlights');
  expect(source).toContain('assets/brand/smarter-faster-together.png?v=20260902about3');
  expect(source).toContain('merd-about-tagline');
  expect(source).toContain('Copyright &copy;');
  expect(source).toContain('&times;</button>');
});

test('desktop uses the mobile-style bottom dock plus one account/client circle', async ({ page }) => {
  const pageErrors = await mountShell(page, 1280);const rail=page.locator('.app-rail');await expect(page.locator('.app-frame')).toHaveClass(/nav-bottom/);const primary=rail.locator(':scope > .rail-section:not([data-nav-section="system"])');await expect(primary).toHaveCount(4);await expect(rail.locator(':scope > .rail-section[data-nav-section="system"]')).toBeHidden();await expect(rail.locator('.rail-client-section')).toBeHidden();await expect(rail.locator('.merd-shell-account-trigger')).toBeVisible();
  const geom=await rail.evaluate(el=>{const r=el.getBoundingClientRect();return {bottom:innerHeight-r.bottom,height:r.height,position:getComputedStyle(el).position}});expect(Math.abs(geom.bottom)).toBeLessThan(2);expect(geom.position).toBe('fixed');expect(geom.height).toBeGreaterThan(60);
  await expect(rail.locator('.rail-shell-utilities')).toBeHidden();await rail.locator('.merd-shell-account-trigger').click();await expect(page.locator('body')).toHaveClass(/merd-mobile-tools-open/);await expect(rail.locator('.rail-shell-utilities')).toBeVisible();await expect(rail.locator('.rail-mobile-client-select')).toHaveValue('1');await expect(rail.locator('.rail-user-summary')).toContainText('Imran');await expect(rail.locator('.rail-user-summary')).toContainText('Developer');await expect(rail.locator('.rail-mobile-system-links')).toHaveCount(0);await expect(rail.locator('.rail-dev-role-select')).toHaveValue('ADMIN');await expect(rail.locator('.rail-dev-role-select option')).toHaveText(['Developer','Admin','Super','User']);await expect(rail.locator('.rail-devstudio-toggle')).toBeVisible();await expect(rail.locator('.rail-devstudio-toggle')).toHaveAttribute('aria-pressed','false');await rail.locator('.rail-devstudio-toggle').click();await expect(rail.locator('.rail-devstudio-toggle')).toHaveAttribute('aria-pressed','true');await expect(page.locator('body')).toHaveClass(/merd-ui-studio-enabled/);await page.evaluate(()=>window.dispatchEvent(new CustomEvent('merdpos-uistudio-state',{detail:{enabled:true,accent:'#8B2EFF',ink:'#FFFFFF'}})));await expect.poll(()=>rail.locator('.merd-shell-account-trigger .rail-user-avatar').evaluate(el=>getComputedStyle(el).backgroundColor)).toBe('rgb(139, 46, 255)');
  const clientContext=rail.locator('.rail-mobile-client-context'),roleContext=rail.locator('.rail-dev-role-context');await expect(clientContext.locator('.rail-context-toggle')).toHaveAttribute('aria-expanded','true');await clientContext.locator('.rail-context-toggle').click();await expect(clientContext.locator('.rail-context-body')).toBeHidden();await expect(clientContext.locator('.rail-context-toggle')).toHaveAttribute('aria-expanded','false');await clientContext.locator('.rail-context-toggle').click();await expect(clientContext.locator('.rail-context-body')).toBeVisible();await expect(roleContext.locator('small')).toHaveCount(0);await roleContext.locator('.rail-context-toggle').click();await expect(roleContext.locator('.rail-context-body')).toBeHidden();await roleContext.locator('.rail-context-toggle').click();await expect(roleContext.locator('.rail-context-body')).toBeVisible();
  const utilityText=await rail.locator('.rail-shell-utilities').innerText();expect(utilityText.indexOf('Imran')).toBeLessThan(utilityText.indexOf('Working client'));expect(utilityText.indexOf('Working client')).toBeLessThan(utilityText.indexOf('Current role'));expect(utilityText).not.toContain('Clients');expect(utilityText).not.toContain('DEV\n');expect(utilityText.indexOf('Change password')).toBeLessThan(utilityText.indexOf('Dark mode'));expect(utilityText.indexOf('Log out')).toBeLessThan(utilityText.indexOf('Dark mode'));expect(utilityText.indexOf('Dark mode')).toBeLessThan(utilityText.indexOf('About MERDPOS'));await rail.locator('.rail-about-toggle').click();await expect(page.locator('#merdposAboutDialog')).toHaveJSProperty('open',true);const aboutGeom=await page.locator('.merd-about-card').evaluate(el=>{const card=el.getBoundingClientRect(),copy=el.querySelector('.merd-about-copy'),art=el.querySelector('.merd-about-art');return {width:card.width,columns:getComputedStyle(el).gridTemplateColumns,copyBg:getComputedStyle(copy).backgroundColor,artBg:getComputedStyle(art).backgroundImage}});expect(aboutGeom.width).toBeGreaterThan(700);expect(aboutGeom.columns.split(' ').length).toBe(2);expect(aboutGeom.copyBg).toBe('rgb(255, 255, 255)');expect(aboutGeom.artBg).toContain('gradient');await expect(page.locator('.merd-about-release-row')).toHaveCount(1);await expect(page.locator('.merd-about-release-row')).toContainText('MERDPOS');const tagline=page.locator('.merd-about-tagline img');await expect(tagline).toHaveCount(1);await expect(tagline).toHaveAttribute('src',/smarter-faster-together\.png/);await expect(tagline).toHaveAttribute('alt','Smarter Faster Together');const splashLayout=await page.locator('.merd-about-copy').evaluate(el=>{const logo=el.querySelector('.merd-about-logo').getBoundingClientRect(),release=el.querySelector('.merd-about-release-row').getBoundingClientRect(),tag=el.querySelector('.merd-about-tagline').getBoundingClientRect(),img=el.querySelector('.merd-about-tagline img').getBoundingClientRect();return {releaseCenter:release.top+release.height/2,availableMid:(logo.bottom+tag.top)/2,imageWidth:img.width,taglineAlign:getComputedStyle(el.querySelector('.merd-about-tagline')).textAlign};});expect(Math.abs(splashLayout.releaseCenter-splashLayout.availableMid)).toBeLessThan(5);expect(splashLayout.imageWidth).toBeGreaterThan(200);expect(splashLayout.taglineAlign).toBe('center');await expect(page.locator('.merd-about-highlights')).toHaveCount(0);await page.locator('#merdposAboutClose').click();await expect(page.locator('#merdposAboutDialog')).toHaveJSProperty('open',false);
  await page.locator('[data-nav-group="operations"]').click();await expect(page.locator('#storesPanel')).toBeVisible();await expect(page.locator('[data-sidebar-group="operations"]')).toBeVisible();expect(pageErrors).toEqual([]);
});
test('Working client Time Sheet sync posts the selected client from an actual DEV account', async ({ page }) => {
  const pageErrors = await mountShell(page, 1280);
  await page.locator('.merd-shell-account-trigger').click();
  const context = page.locator('.rail-mobile-client-context');
  await expect(context.locator('.rail-timesheet-sync-row')).toContainText('Sync');
  const button = context.locator('.rail-timesheet-sync-btn');
  await expect(button).toBeEnabled();
  page.once('dialog', dialog => dialog.accept());
  const requestPromise = page.waitForRequest(request => request.url().includes('/api/timesheet_google_refresh.php') && request.method() === 'POST');
  await button.click();
  const request = await requestPromise;
  expect(request.postDataJSON()).toEqual({action:'refresh_timesheet',client_id:1,csrf:'test-csrf'});
  expect(pageErrors).toEqual([]);
});

test('mobile utility sheet opens without a More navigation tab', async ({ page }) => {
  const pageErrors = await mountShell(page, 390);
  await expect(page.locator('.rail-client-section')).toBeHidden();
  await expect(page.locator('.rail-mobile-tools-section')).toHaveCount(0);
  await expect(page.locator('.rail-shell-utilities')).toBeHidden();
  await page.evaluate(() => window.MERDPOSShellUtilities.open());
  await expect(page.locator('body')).toHaveClass(/merd-mobile-tools-open/);
  await expect(page.locator('.rail-shell-utilities')).toBeVisible();
  await expect(page.locator('.rail-mobile-client-select')).toHaveValue('1');
  await expect(page.locator('.rail-about-toggle')).toBeVisible();
  await page.locator('.rail-about-toggle').click();
  await expect(page.locator('body')).not.toHaveClass(/merd-mobile-tools-open/);
  await expect(page.locator('#merdposAboutDialog')).toHaveJSProperty('open', true);
  expect(pageErrors).toEqual([]);
});

test('dashboard authorization failure cannot redirect-loop through index', async () => {
  const source=fs.readFileSync(path.join(portalRoot,'dashboard.php'),'utf8');
  expect(source).toContain("$e->errorCode === 'account_inactive'");
  expect(source).toContain('logout_user();');
  expect(source).toContain("header('Location: index.php?session=expired')");
  expect(source).toContain("$status = ($e instanceof MerdWorkforceException && $e->errorCode === 'forbidden') ? 403 : 503;");
  const catchBlock=source.slice(source.indexOf("} catch (Throwable $e) {"), source.indexOf("$actualPermissions"));
  expect(catchBlock).not.toContain("header('Location: index.php');");
});