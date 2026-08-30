const { test, expect } = require('@playwright/test');
const path = require('path');
test.use({ channel: 'chrome' });
const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const portalRoot = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal');
const tokensPath = path.join(portalRoot, 'assets', 'design-tokens.css');
const designSystemPath = path.join(portalRoot, 'assets', 'design-system.css');
const omniPath = path.join(portalRoot, 'assets', 'omnichannel-identity.js');
const fixture = '<!doctype html><html><head></head><body class="merd-shell"><div id="dashboardRolebar" class="dashboard-rolebar"><div><span>Dashboard role</span></div><div></div></div></body></html>';

async function mount(page,{isDev=true,shop=true,width=1280}={}){
  await page.setViewportSize({width,height:width<600?780:900});
  await page.route('https://pill-smoke.invalid/dashboard',r=>r.fulfill({status:200,contentType:'text/html',body:fixture}));
  await page.route('**/api/beta_state.php*',r=>r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,generated_at:new Date().toISOString(),current_user_id:'1',current_user:{name:'Imran',user_id:'1',portal_active:true,portal_login_at:new Date(Date.now()-5*60000).toISOString(),shop_active:shop,shop_name:shop?'Marrickville Xpress':null,shop_clock_in_at:shop?new Date(Date.now()-2*60000).toISOString():null},client:{id:1,name:'Merd Retail Group',client_code:'MRG'},stores:[],store_identity:[]})}));
  await page.goto('https://pill-smoke.invalid/dashboard');
  await page.evaluate(({isDev})=>{localStorage.setItem('merdpos-preview-role-action-v1',JSON.stringify({key:'USER',label:'User',at:Date.now()-2*60000}));window.MERDPOS_AUTH={is_dev:isDev,is_role_preview:isDev,view_role_key:isDev?'USER':null,role_key:isDev?'USER':'USER',role_label:'User',actual_role_label:isDev?'Developer':'User',permissions:{'dashboard.view':true}};},{isDev});
  await page.addStyleTag({path:tokensPath});await page.addStyleTag({path:designSystemPath});await page.addScriptTag({path:omniPath});
}

test('DEV dashboard shows current user, preview role and client status pills',async({page})=>{
  await mount(page,{isDev:true,shop:true});
  await expect(page.locator('#dashboardStatusPills')).toBeVisible();
  await expect(page.locator('.omni-status-pill')).toHaveCount(3);
  await expect(page.locator('#omniCurrentUser')).toContainText('Imran');
  await expect(page.locator('#omniCurrentUser')).toContainText('Portal + Shop');
  await expect(page.locator('#omniCurrentUser')).toContainText('Clocked in');
  await expect(page.locator('#omniPreviewRole')).toContainText('User');
  await expect(page.locator('#omniPreviewRole')).toContainText('Preview');
  await expect(page.locator('#omniPreviewRole')).toContainText('Switched');
  await expect(page.locator('#omniFreshness')).toContainText('MRG');
  await expect(page.locator('#omniFreshness')).toContainText('Updated now');
  await expect(page.locator('#omniCurrentUser .omni-status-pill-dot')).toBeVisible();
});

test('non-DEV mobile keeps self/client pills visible without preview-role pill',async({page})=>{
  await mount(page,{isDev:false,shop:false,width:390});
  await expect(page.locator('.omni-status-pill')).toHaveCount(2);
  await expect(page.locator('#omniPreviewRole')).toHaveCount(0);
  await expect(page.locator('#omniCurrentUser')).toBeVisible();
  await expect(page.locator('#omniCurrentUser')).toContainText('Imran');
  await expect(page.locator('#omniCurrentUser')).toContainText('Portal');
  await expect(page.locator('#omniCurrentUser')).toContainText('Logged in');
  await expect(page.locator('#omniFreshness')).toBeVisible();
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>document.documentElement.clientWidth+1);
  expect(overflow).toBe(false);
});
