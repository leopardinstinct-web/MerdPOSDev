const {test,expect}=require('@playwright/test');
const fs=require('fs');
const path=require('path');
test.use({channel:'chrome'});
const repoRoot=process.env.GITHUB_WORKSPACE||path.resolve(__dirname,'..','..');
const portalRoot=path.join(repoRoot,'namecheap_beta_live','timesheet_portal');
const builderPath=path.join(portalRoot,'assets','dashboard-builder.js');
const apiPath=path.join(portalRoot,'api','dashboard_layout.php');
const betaApiPath=path.join(portalRoot,'includes','beta_api.php');

test('dashboard Studio edit mode is actual-DEV gated in source',async()=>{
  const builder=fs.readFileSync(builderPath,'utf8'),api=fs.readFileSync(apiPath,'utf8'),beta=fs.readFileSync(betaApiPath,'utf8');
  expect(builder).toContain('openStudioEdit');expect(builder).toContain("params.set('dev_studio','1')");
  expect(builder).toContain('data-describe-widget');expect(builder).toContain('addContextComment');
  expect(api).toContain('function dashboard_dev_studio_mode');expect(api).toContain('beta_user_is_dev($user)');
  expect(builder).toContain('canEdit&&editMode&&!studioEditMode');
  expect(beta).toContain("$studioDashboard = beta_user_is_dev($user)");
});

test('widget Describe writes DevStudio context while Studio edit opens the existing drawer',async({page})=>{
  const fixture='<!doctype html><html><body><section id="dashboardPanel"></section><script>window.__studioComment=null;window.MERDPOS_UI_STUDIO={getChanges:()=>[],addContextComment:x=>{window.__studioComment=x;return true;}};</script></body></html>';
  await page.route('https://merdpos-smoke.invalid/dashboard',r=>r.fulfill({status:200,contentType:'text/html',body:fixture}));
  let studioGet=false;
  await page.route('https://merdpos-smoke.invalid/api/dashboard_layout.php*',r=>{const u=new URL(r.request().url());studioGet=studioGet||u.searchParams.get('dev_studio')==='1';r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,csrf:'x',can_edit:u.searchParams.get('dev_studio')==='1',can_select_role:false,selected_role:{id:2,role_key:'USER',role_label:'User',authority_level:10},roles:[{id:2,role_key:'USER',role_label:'User',authority_level:10}],allowed_widgets:['my_shift'],layout:[],grid:{columns:12,max_rows:1000}})});});
  await page.route('https://merdpos-smoke.invalid/api/dashboard_data.php*',r=>r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,role:{id:2,role_key:'USER',role_label:'User',authority_level:10},allowed_widgets:['my_shift'],client_defaults:{currency_code:'AUD',timezone:'Australia/Sydney'},working:[],disputes:[],recent_shifts:[],stores:[],management:null})}));
  await page.goto('https://merdpos-smoke.invalid/dashboard');
  await page.addScriptTag({path:builderPath});
  await expect.poll(()=>page.evaluate(()=>!!window.MERDPOSDashboardBuilder)).toBe(true);
  await page.evaluate(()=>window.MERDPOSDashboardBuilder.openStudioEdit());
  await expect(page.locator('#dashboardWidgetDrawer')).toHaveClass(/open/);
  await expect(page.locator('#dashboardAddButton')).toBeHidden();
  await expect(page.locator('[data-describe-widget="my_shift"]')).toBeVisible();
  page.once('dialog',dialog=>dialog.accept('Show the employee current shift context.'));
  await page.locator('[data-describe-widget="my_shift"]').click();
  const comment=await page.evaluate(()=>window.__studioComment);
  expect(studioGet).toBe(true);
  expect(comment).toMatchObject({contextType:'dashboard-widget',widgetKey:'my_shift',contextKey:'dashboard-widget:my_shift',comment:'Show the employee current shift context.',selector:'.dashboard-widget[data-widget="my_shift"]'});
});
