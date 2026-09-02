const {test,expect}=require('@playwright/test');
const fs=require('fs');
const path=require('path');
test.use({channel:'chrome'});
const repoRoot=process.env.GITHUB_WORKSPACE||path.resolve(__dirname,'..','..');
const portalRoot=path.join(repoRoot,'namecheap_beta_live','timesheet_portal');
const analyticsPath=path.join(portalRoot,'assets','analytics-runtime.js');
const builderPath=path.join(portalRoot,'assets','dashboard-builder.js');
const managementPath=path.join(portalRoot,'assets','management.js');
const dataApiPath=path.join(portalRoot,'api','dashboard_data.php');

function layoutPayload(){return {
  success:true,csrf:'x',can_edit:false,can_select_role:false,
  selected_role:{id:1,role_key:'DEV',role_label:'Developer',authority_level:1000},
  roles:[],allowed_widgets:['workforce_by_store','sales_trend_7d'],
  layout:[
    {widget_key:'workforce_by_store',grid_x:0,grid_y:0,grid_w:6,grid_h:4},
    {widget_key:'sales_trend_7d',grid_x:6,grid_y:0,grid_w:6,grid_h:4}
  ],grid:{columns:12,max_rows:1000}
};}
function dataPayload(storeId=0,period='7'){
  const stores=[{id:1,store_name:'Alpha',week_start_day:1},{id:2,store_name:'Beta',week_start_day:2}];
  const shown=storeId?stores.filter(row=>row.id===storeId):stores;
  const working=(storeId===2?2:storeId===1?1:3);
  const days=period==='current_week'?(storeId===2?2:3):Number(period||7);
  return {
    success:true,role:{id:1,role_key:'DEV',role_label:'Developer',authority_level:1000},
    allowed_widgets:['workforce_by_store','sales_trend_7d'],
    client_defaults:{currency_code:'AUD',timezone:'Australia/Sydney'},
    filters:{store_id:storeId,days,period,period_label:period==='current_week'?'Current week':`${days} days`,week_start_day:period==='current_week'?(storeId===2?2:1):null},filter_options:{stores,periods:['current_week',7,14,30]},
    working_count:working,
    working:[
      ...(storeId&&storeId!==1?[]:[{full_name:'A',store_id:1,store_name:'Alpha',clock_in_at:'2026-08-31 01:00:00'}]),
      ...(storeId&&storeId!==2?[]:[{full_name:'B',store_id:2,store_name:'Beta',clock_in_at:'2026-08-31 01:00:00'},{full_name:'C',store_id:2,store_name:'Beta',clock_in_at:'2026-08-31 02:00:00'}])
    ],my_working:[],disputes:[],recent_shifts:[],stores:shown,
    management:{currency_code:'AUD',timezone:'Australia/Sydney',period_days:days,period,week_start_day:period==='current_week'?(storeId===2?2:1):null,financial_by_store:[],sales_by_store:[],analytics:{sales_period:Array.from({length:days},(_,i)=>({date:`2026-09-${String(i+1).padStart(2,'0')}`,value:i+1})),attendance_period:[],sync_statuses:[]}}
  };
}
test('analytics runtime provides typed views and accessible SVG selection events',async({page})=>{
  await page.setContent('<main id="root"></main>');
  await page.addScriptTag({path:analyticsPath});
  const result=await page.evaluate(()=>{
    const A=window.MERDPOSAnalytics;
    const table=A.dataset('sales',[{key:'label'},{key:'value',type:'number'}],[{label:'One',value:'12'},{label:'Two',value:4}]);
    const view=A.view(table,{filter:row=>row.value>=5,sort:(a,b)=>b.value-a.value});
    const root=document.getElementById('root');
    root.innerHTML=A.bar(view,{labelKey:'label',valueKey:'value',payload:row=>({storeId:row.label==='One'?1:2})});
    A.bind(root);window.__selection=null;
    root.addEventListener('merdpos-chart-select',event=>window.__selection=event.detail);
    return {rows:view.rows,svg:!!root.querySelector('svg'),focusable:root.querySelectorAll('[data-merd-chart-info][tabindex="0"]').length};
  });
  expect(result).toEqual({rows:[{label:'One',value:12}],svg:true,focusable:1});
  await page.locator('[data-merd-chart-info]').press('Enter');
  await expect.poll(()=>page.evaluate(()=>window.__selection?.payload?.storeId)).toBe(1);
  await expect(page.locator('.merd-chart-selection')).toHaveText('One: 12');
});
test('dashboard coordinates store drill-down and reporting-period filters',async({page})=>{
  await page.route('https://merdpos-smoke.invalid/dashboard',route=>route.fulfill({status:200,contentType:'text/html',body:'<!doctype html><html><body><section id="dashboardPanel"></section></body></html>'}));
  await page.route('https://merdpos-smoke.invalid/api/dashboard_layout.php*',route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(layoutPayload())}));
  const requests=[];
  await page.route('https://merdpos-smoke.invalid/api/dashboard_data.php*',route=>{
    const url=new URL(route.request().url());
    const storeId=Number(url.searchParams.get('store_id')||0),period=url.searchParams.get('period')||String(Number(url.searchParams.get('days')||7));
    const payload=dataPayload(storeId,period);requests.push({storeId,period,days:payload.filters.days});
    route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(payload)});
  });
  await page.goto('https://merdpos-smoke.invalid/dashboard');
  await page.addScriptTag({path:analyticsPath});
  await page.addScriptTag({path:builderPath});
  await expect(page.locator('#dashboardAnalyticsToolbar')).toBeVisible();
  await expect(page.locator('#dashboardStoreFilter')).toHaveValue('0');
  await page.locator('[data-dashboard-period="14"]').click();
  await expect.poll(()=>requests.at(-1)?.days).toBe(14);
  await expect(page.locator('[data-dashboard-period="14"]')).toHaveAttribute('aria-pressed','true');
  await page.locator('.dashboard-widget[data-widget="workforce_by_store"] .merd-chart-item').filter({hasText:'Beta'}).click();
  await expect.poll(()=>requests.at(-1)?.storeId).toBe(2);
  await expect(page.locator('#dashboardStoreFilter')).toHaveValue('2');
  await expect(page.locator('#dashboardFilterSummary')).toContainText('Beta');
  await page.locator('[data-dashboard-period="current_week"]').click();
  await expect.poll(()=>requests.at(-1)?.period).toBe('current_week');
  await expect.poll(()=>requests.at(-1)?.storeId).toBe(2);
  await expect(page.locator('[data-dashboard-period="current_week"]')).toHaveAttribute('aria-pressed','true');
  await expect(page.locator('#dashboardFilterSummary')).toContainText('Current week');
});
test('analytics wiring and data endpoint keep filtering inside dashboard authorization scope',async()=>{
  const management=fs.readFileSync(managementPath,'utf8');
  const api=fs.readFileSync(dataApiPath,'utf8');
  expect(management.indexOf("assets/analytics-runtime.js?v=20260831analytics2")).toBeGreaterThan(-1);
  expect(management.indexOf("assets/analytics-runtime.js?v=20260831analytics2")).toBeLessThan(management.indexOf("assets/dashboard-builder.js?v=20260902ds130"));
  expect(api).toContain('function dashboard_data_period_dates');
  expect(api).toContain('function dashboard_data_current_week_dates');
  expect(api).toContain("$isCurrentWeek = $period === 'current_week'");
  expect(api).toContain("'store_required_for_current_week'");
  expect(api).toContain("week_start_day,timezone FROM stores");
  expect(api).toContain("in_array($days, [7,14,30], true)");
  expect(api).toContain("AND (?=0 OR rs.store_id=?)");
  expect(api).toContain("AND (?=0 OR s.store_id=?)");
  expect(api).toContain("$allRecent ? 's.client_id=?' : 's.client_id=? AND s.employee_id=?'");
  expect(api).toContain("'period'=>$isCurrentWeek ? 'current_week' : (string)$days");
  expect(api).toContain("'period_label'=>$isCurrentWeek ? 'Current week'");
  expect(api).toContain("'filter_options'=>['stores'=>$filterStores,'periods'=>['current_week',7,14,30]]");
});
