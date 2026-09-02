const { test, expect } = require('@playwright/test');
const fs=require('fs'),path=require('path');
test.use({channel:'chrome'});
const repoRoot=process.env.GITHUB_WORKSPACE||path.resolve(__dirname,'..','..');
const portal=rel=>path.join(repoRoot,'namecheap_beta_live','timesheet_portal',rel);
const source=rel=>fs.readFileSync(portal(rel),'utf8');

test('revision 130 restores cyan as the semantic MERDPOS product accent without changing five masters',()=>{
  const tokens=source('assets/design-tokens.css');
  for(const literal of ['#FFFFFF','#F5F7FC','#031B4B','#12BDF3','#8B2EFF'])expect(tokens).toContain(literal);
  expect(tokens).toContain('--color-brand-primary: var(--color-brand-cyan);');
  expect(tokens).toContain('--color-focus: var(--color-brand-cyan);');
  expect(tokens).toContain('--color-nav-active: var(--color-brand-cyan);');
});

test('revision 130 dashboard and shared heading alignment are canonical',()=>{
  const builder=source('assets/dashboard-builder.js'),design=source('assets/design-system.css');
  expect(builder).toContain('<header class="dashboard-page-head"><h2 class="ui-page-title">Dashboard</h2></header>');
  expect(design).toContain('DS130 recurrent header-alignment guard');
  expect(design).toContain('.merd-shell :where(.dialog-heading, .dialog-head, .admin-dialog-header) {\n  align-items: center;');
  expect(design).toContain('min-height: var(--size-icon-action);');
});

test('revision 130 store dialog has schema-aware profile fields and one Save control',()=>{
  const dashboard=source('dashboard.php'),directory=source('assets/directory.js'),api=source('api/admin_directory.php');
  expect(dashboard).toContain('id="storeProfileFields"');
  expect(dashboard).not.toContain('Stores are inactivated rather than deleted');
  expect(dashboard).toContain('<div class="admin-dialog-footer"><button type="submit" class="primary-btn compact-btn">Save</button></div>');
  expect(directory).toContain('renderStoreProfileFields(store)');
  expect(directory).toContain('values.days=schedule.days');
  expect(api).toContain('function directory_store_edit_fields');
  expect(api).toContain("[['store_code','code'], 'Code'");
  expect(api).toContain("[['address','address_line1'], 'Address'");
  expect(api).toContain('directory_save_store_schedule($pdo');
});

test('revision 130 timing Closed control is checkbox-only and separate save is retired',()=>{
  const timings=source('assets/timings.js');
  expect(timings).toContain('aria-label="Closed ${esc(label)}"');
  expect(timings).not.toContain('<span>Closed</span></label>');
  expect(timings).not.toContain('saveTimingsBtn');
  expect(timings).toContain('collectForSave');
});

test('revision 130 desktop bottom dock is shorter while retaining icon-left label-right layout',()=>{
  const shell=source('assets/shell.css');
  expect(shell).toContain('--shell-desktop-nav-h:4.5rem');
  expect(shell).toContain('min-height:3.45rem');
  expect(shell).toContain('.app-frame.nav-bottom .rail-group-btn {');
  expect(shell).toContain('flex-direction: row;');
});

test('revision 130 account tools trigger has durable and cross-window UI state',()=>{
  const account=source('assets/account-menu.js');
  expect(account).toContain("ACCOUNT_UI_STATE_KEY='merdpos-account-tools-ui-v1'");
  expect(account).toContain("accountTrigger.dataset.accountToolsTrigger = '1'");
  expect(account).toContain('writeAccountUiState(true)');
  expect(account).toContain('writeAccountUiState(false)');
  expect(account).toContain('if(event.key!==ACCOUNT_UI_STATE_KEY)return;');
});
