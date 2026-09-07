const { chromium, request } = require('@playwright/test');
const fs = require('fs');

const FIXTURE = process.env.MERDPOS_ADMIN_FIXTURE;
if (!FIXTURE || !fs.existsSync(FIXTURE)) throw new Error('MERDPOS_ADMIN_FIXTURE is required.');
const fx = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
if (String(fx.client?.code || '').toUpperCase() !== 'DUMMY') throw new Error('Refusing non-DUMMY fixture.');
if (!/^\d{14}-[a-f0-9]{6}$/.test(String(fx.run || ''))) throw new Error('Invalid DUMMY run marker.');

const DRUPAL = 'https://drupal-beta.merdpos.com';
const PORTAL = 'https://app.merdpos.com/beta/timesheet_portal/api/';
const results = [];
const pass = (name, detail='') => { results.push({name,detail}); console.log('PASS', name, detail); };
const fail = (name, detail='') => { throw new Error(`${name}: ${detail}`); };
const storeName = `${fx.prefix} Store`;
const employeeName = `${fx.prefix} Employee`;
const clientName = fx.temp_client.name;

async function jsonResponse(response,label,expectSuccess=true) {
  const text=await response.text(); let data;
  try { data=text?JSON.parse(text):{}; } catch { fail(label,`invalid JSON ${response.status()} ${text.slice(0,300)}`); }
  if (expectSuccess && (!response.ok() || !data.success)) fail(label,`${response.status()} ${JSON.stringify(data).slice(0,600)}`);
  return {response,data};
}

async function portalLogin(employee) {
  const api=await request.newContext({extraHTTPHeaders:{Accept:'application/json'}});
  const {data}=await jsonResponse(await api.post(PORTAL+'login.php',{form:{user_id:String(employee.user_id),password:String(employee.password)}}),'portal login');
  if (Number(data.user?.client_id)!==Number(fx.client.id) || Number(data.user?.id)!==Number(employee.id)) fail('Portal identity boundary',JSON.stringify(data.user));
  return api;
}
async function drupalLogin(browser,employee) {
  const context=await browser.newContext();
  const page=await context.newPage();
  await page.goto(DRUPAL+'/login',{waitUntil:'domcontentloaded',timeout:20000});
  await page.locator('input[name="user_id"]').fill(String(employee.user_id));
  await page.locator('input[name="password"]').fill(String(employee.password));
  await page.locator('input[type="submit"],button[type="submit"]').click();
  await page.waitForURL(url=>url.pathname.startsWith('/merdpos'),{timeout:20000});
  return {context,page};
}

async function gotoAdmin(page,tab,clientId=fx.client.id) {
  const response=await page.goto(`${DRUPAL}/merdpos/admin?tab=${tab}&client_id=${clientId}`,{waitUntil:'domcontentloaded',timeout:20000});
  return response;
}

async function submitForm(page,form,buttonName) {
  await Promise.all([
    page.waitForNavigation({waitUntil:'domcontentloaded',timeout:20000}),
    form.getByRole('button',{name:buttonName}).click(),
  ]);
}

async function pageHasNoMojibake(page,label) {
  const text=await page.locator('body').innerText();
  if (/[ÂÃ�]|â(?:€|†|—|–)/.test(text)) fail(label,text.slice(0,500));
  pass(label);
}

async function portalGet(api,name) {
  return (await jsonResponse(await api.get(PORTAL+name),name)).data;
}

function findByName(rows,name,key='name') {
  return (rows||[]).find(row=>String(row[key]||'')===name);
}
async function main() {
  const browser=await chromium.launch({headless:true});
  const devApi=await portalLogin(fx.employees.dev);
  const dev=await drupalLogin(browser,fx.employees.dev);
  const sup=await drupalLogin(browser,fx.employees.super);
  const usr=await drupalLogin(browser,fx.employees.user);
  pass('DUMMY DEV/SUPER/USER authoritative logins',`client=${fx.client.id}`);

  await gotoAdmin(dev.page,'clients');
  if (await dev.page.getByText('Working client',{exact:true}).count()) fail('Clients duplicate selector','Working client must be absent');
  const newClient=dev.page.locator('details.merdpos-admin-editor--new').filter({hasText:'New client'});
  if (await newClient.getByRole('button',{name:'Create client'}).isVisible().catch(()=>false)) fail('New client collapsed','Create client visible before expansion');
  await newClient.locator('summary').click();
  const cf=newClient.locator('form');
  await cf.locator('input[name="name"]').fill(clientName);
  await cf.locator('input[name="client_code"]').fill(fx.temp_client.code);
  await submitForm(dev.page,cf,'Create client');
  if (!dev.page.url().includes('tab=clients')) fail('Client create preserves child tab',dev.page.url());
  let clients=await portalGet(devApi,'clients.php');
  let created=findByName(clients.clients,clientName);
  if (!created || String(created.client_code)!==String(fx.temp_client.code)) fail('Client create authoritative',JSON.stringify(clients.clients));
  pass('Client create + child-tab persistence',`${created.id} ${created.client_code}`);

  const clientCard=dev.page.locator('details.merdpos-admin-editor').filter({hasText:clientName}).first();
  await clientCard.locator('summary').click();
  const editClient=clientCard.locator('form');
  await editClient.locator('input[name="name"]').fill(clientName+' Edited');
  await editClient.locator('select[name="status"]').selectOption('inactive');
  await submitForm(dev.page,editClient,'Save client');
  clients=await portalGet(devApi,'clients.php');
  created=findByName(clients.clients,clientName+' Edited');
  if (!created || created.status!=='inactive') fail('Client update authoritative',JSON.stringify(created));
  pass('Client update/inactivate authoritative',String(created.id));
  await gotoAdmin(dev.page,'stores');
  if ((await dev.page.locator('select[name="client_id"]').inputValue())!==String(fx.client.id)) fail('Store working client','wrong client');
  const newStore=dev.page.locator('details.merdpos-admin-editor--new').filter({hasText:'New store'});
  if (await newStore.getByRole('button',{name:'Create store'}).isVisible().catch(()=>false)) fail('New store collapsed','Create store visible before expansion');
  await newStore.locator('summary').click();
  const sf=newStore.locator('form');
  await sf.locator('input[name="store_name"]').fill(storeName);
  await sf.locator('select[name="week_start_day"]').selectOption('7');
  if (await sf.locator('[name="store_code"]').count()) await sf.locator('[name="store_code"]').fill('ADM'+fx.run.replace(/\D/g,'').slice(-10));
  if (await sf.locator('[name="address"]').count()) await sf.locator('[name="address"]').fill('1 Acceptance Lane');
  await sf.locator('select[name="timezone"]').selectOption('Australia/Perth');
  await sf.locator('select[name="currency_code"]').selectOption('USD');
  await sf.locator('input[name="days[1][start_time]"]').fill('08:15');
  await sf.locator('input[name="days[1][end_time]"]').fill('18:45');
  await sf.locator('input[name="days[7][is_closed]"][type="checkbox"]').check();
  await submitForm(dev.page,sf,'Create store');
  if (!dev.page.url().includes('tab=stores') || !dev.page.url().includes(`client_id=${fx.client.id}`)) fail('Store create preserves context',dev.page.url());
  let directory=await portalGet(devApi,'admin_directory.php');
  let store=(directory.stores||[]).find(row=>row.store_name===storeName);
  if (!store || String(store.timezone)!=='Australia/Perth' || String(store.currency_code)!=='USD' || Number(store.week_start_day)!==7) fail('Store create/profile authoritative',JSON.stringify(store));
  const storeId=Number(store.id);
  const timings=await portalGet(devApi,'store_timings.php');
  const rows=(timings.timings||[]).filter(row=>Number(row.store_id)===storeId);
  const mon=rows.find(row=>Number(row.day_of_week)===1), sun=rows.find(row=>Number(row.day_of_week)===7);
  if (rows.length!==7 || !mon || String(mon.start_time).slice(0,5)!=='08:15' || !sun || Number(sun.is_closed)!==1) fail('Store seven-day timings authoritative',JSON.stringify(rows));
  pass('Store create/profile/week/timezone/currency/hours authoritative',`store=${storeId}`);
  const storeCard=dev.page.locator('details.merdpos-admin-editor').filter({hasText:storeName}).first();
  await storeCard.locator('summary').click();
  const editStore=storeCard.locator('form');
  await editStore.locator('input[name="store_name"]').fill(storeName+' Edited');
  const png=await dev.page.screenshot({type:'png'});
  await editStore.locator('input[name="logo"]').setInputFiles({name:'acceptance.png',mimeType:'image/png',buffer:png});
  await submitForm(dev.page,editStore,'Save store');
  directory=await portalGet(devApi,'admin_directory.php');
  store=(directory.stores||[]).find(row=>Number(row.id)===storeId);
  if (!store || store.store_name!==storeName+' Edited') fail('Store edit authoritative',JSON.stringify(store));
  const identity=await portalGet(devApi,'store_identity.php');
  const identityRow=(identity.stores||[]).find(row=>Number(row.id)===storeId);
  if (!identityRow || !String(identityRow.logo_path||'').startsWith('uploads/store_logos/')) fail('Store logo authoritative',JSON.stringify(identityRow));
  pass('Store edit + logo authoritative',String(identityRow.logo_path));

  const userRole=(directory.actor?.roles||[]).find(role=>String(role.base_role).toUpperCase()==='USER');
  if (!userRole) fail('DUMMY USER role available',JSON.stringify(directory.actor?.roles));
  await gotoAdmin(dev.page,'workforce');
  const newEmployee=dev.page.locator('details.merdpos-admin-editor--new').filter({hasText:'New employee'});
  if (await newEmployee.getByRole('button',{name:'Create employee'}).isVisible().catch(()=>false)) fail('New employee collapsed','Create employee visible before expansion');
  await newEmployee.locator('summary').click();
  const ef=newEmployee.locator('form');
  const employeeUserId='86'+fx.run.replace(/\D/g,'').slice(-10);
  const employeePassword='65432109';
  await ef.locator('input[name="full_name"]').fill(employeeName);
  await ef.locator('input[name="user_id"]').fill(employeeUserId);
  await ef.locator('select[name="client_role_id"]').selectOption(String(userRole.id));
  await ef.locator('select[name="store_access_mode"]').selectOption('selected');
  await ef.locator('select[name="store_ids[]"]').selectOption([String(storeId)]);
  if (await ef.locator('input[name="hourly_rate"]').count()) await ef.locator('input[name="hourly_rate"]').fill('31.25');
  if (await ef.locator('input[name="rate_effective_date"]').count()) await ef.locator('input[name="rate_effective_date"]').fill(String(directory.today));
  await ef.locator('input[name="new_password"]').fill(employeePassword);
  await submitForm(dev.page,ef,'Create employee');
  if (!dev.page.url().includes('tab=workforce') || !dev.page.url().includes(`client_id=${fx.client.id}`)) fail('Employee create preserves context',dev.page.url());
  directory=await portalGet(devApi,'admin_directory.php');
  let employee=(directory.employees||[]).find(row=>row.full_name===employeeName);
  if (!employee || String(employee.user_id)!==employeeUserId || String(employee.store_access_mode)!=='selected' || !(employee.assigned_store_ids||[]).map(Number).includes(storeId)) fail('Employee create authoritative',JSON.stringify(employee));
  if (Math.abs(Number(employee.hourly_rate)-31.25)>0.001) fail('Employee pay rate authoritative',JSON.stringify(employee));
  const employeeId=Number(employee.id);
  pass('Workforce create/role/pay/store access authoritative',`employee=${employeeId}`);

  const createdLogin=await drupalLogin(browser,{id:employeeId,user_id:employeeUserId,password:employeePassword,name:employeeName});
  pass('Created DUMMY employee credentials authenticate through Drupal');
  await createdLogin.context.close();

  const employeeCard=dev.page.locator('details.merdpos-admin-editor').filter({hasText:employeeName}).first();
  await employeeCard.locator('summary').click();
  const editEmployee=employeeCard.locator('form');
  await editEmployee.locator('input[name="full_name"]').fill(employeeName+' Edited');
  if (await editEmployee.locator('input[name="hourly_rate"]').count()) await editEmployee.locator('input[name="hourly_rate"]').fill('32.50');
  if (await editEmployee.locator('input[name="rate_effective_date"]').count()) await editEmployee.locator('input[name="rate_effective_date"]').fill(String(directory.today));
  await editEmployee.locator('input[name="new_password"]').fill('76543210');
  await submitForm(dev.page,editEmployee,'Save employee');
  directory=await portalGet(devApi,'admin_directory.php');
  employee=(directory.employees||[]).find(row=>Number(row.id)===employeeId);
  if (!employee || employee.full_name!==employeeName+' Edited' || Math.abs(Number(employee.hourly_rate)-32.50)>0.001) fail('Employee update authoritative',JSON.stringify(employee));
  pass('Workforce edit authoritative',String(employeeId));

  const editedCard=dev.page.locator('details.merdpos-admin-editor').filter({hasText:employeeName+' Edited'}).first();
  await editedCard.locator('summary').click();
  const deactivate=editedCard.locator('form');
  await deactivate.locator('select[name="status"]').selectOption('inactive');
  await submitForm(dev.page,deactivate,'Save employee');
  directory=await portalGet(devApi,'admin_directory.php');
  employee=(directory.employees||[]).find(row=>Number(row.id)===employeeId);
  if (!employee || employee.status!=='inactive') fail('Employee inactive state authoritative',JSON.stringify(employee));
  pass('Workforce lifecycle state authoritative');
  await gotoAdmin(sup.page,'stores');
  const adminNav=(await sup.page.locator('nav[aria-label="Admin sections"]').innerText()).trim();
  if (/\bClients\b/.test(adminNav) || /\bOnboard\b/.test(adminNav)) fail('SUPER DEV-only tabs hidden',adminNav);
  const supNewStore=sup.page.locator('details.merdpos-admin-editor--new').filter({hasText:'New store'});
  await supNewStore.locator('summary').click();
  if (await supNewStore.locator('select[name="timezone"],select[name="currency_code"],input[name="logo"]').count()) fail('SUPER store profile/logo boundary','DEV-only profile controls visible');
  if (!(await supNewStore.locator('fieldset.merdpos-store-hours').count())) fail('SUPER store timings permission','Store hours missing');
  pass('SUPER store management vs DEV-only profile boundary');

  await gotoAdmin(sup.page,'workforce');
  const highCard=sup.page.locator('details.merdpos-admin-editor').filter({hasText:fx.employees.dev.name}).first();
  await highCard.locator('summary').click();
  if (!(await highCard.getByText('above your current authority level',{exact:false}).count())) fail('SUPER higher-authority guard','readonly guard missing');
  pass('SUPER cannot edit higher-authority DEV');

  const userResponse=await gotoAdmin(usr.page,'stores');
  const userText=await usr.page.locator('body').innerText();
  if (userResponse && userResponse.status()<400 && !/access denied|permission is required|not authorised/i.test(userText)) fail('USER administration denied',`${userResponse.status()} ${userText.slice(0,400)}`);
  pass('USER administration management denied',String(userResponse?.status()||''));
  for (const tab of ['onboarding','clients','stores','workforce']) {
    await gotoAdmin(dev.page,tab);
    await pageHasNoMojibake(dev.page,`No mojibake on Admin ${tab}`);
    const switchCount=await dev.page.locator('select[name="client_id"]').count();
    if (['stores','workforce'].includes(tab) ? switchCount!==1 : switchCount!==0) fail(`Admin selector placement ${tab}`,String(switchCount));
  }
  pass('Admin Working client selector placement across all children');

  await gotoAdmin(dev.page,'stores');
  const theme=dev.page.getByLabel('Color theme');
  await theme.selectOption('light'); await dev.page.waitForTimeout(250);
  let overflow=await dev.page.evaluate(()=>document.documentElement.scrollWidth>document.documentElement.clientWidth+2);
  if (overflow) fail('Admin light desktop overflow');
  await theme.selectOption('dark'); await dev.page.waitForTimeout(250);
  overflow=await dev.page.evaluate(()=>document.documentElement.scrollWidth>document.documentElement.clientWidth+2);
  if (overflow) fail('Admin dark desktop overflow');
  pass('Admin light/dark desktop regression');

  const mobileContext=await browser.newContext({viewport:{width:390,height:844}});
  const mobilePage=await mobileContext.newPage();
  await mobilePage.goto(DRUPAL+'/login',{waitUntil:'domcontentloaded'});
  await mobilePage.locator('input[name="user_id"]').fill(String(fx.employees.dev.user_id));
  await mobilePage.locator('input[name="password"]').fill(String(fx.employees.dev.password));
  await mobilePage.locator('input[type="submit"],button[type="submit"]').click();
  await mobilePage.waitForURL(url=>url.pathname.startsWith('/merdpos'),{timeout:20000});
  await gotoAdmin(mobilePage,'workforce');
  await mobilePage.getByLabel('Color theme').selectOption('dark'); await mobilePage.waitForTimeout(250);
  overflow=await mobilePage.evaluate(()=>document.documentElement.scrollWidth>document.documentElement.clientWidth+2);
  if (overflow) fail('Admin mobile dark overflow');
  pass('Admin mobile dark regression');
  await mobileContext.close();
  await gotoAdmin(dev.page,'stores');
  const finalStoreCard=dev.page.locator('details.merdpos-admin-editor').filter({hasText:storeName+' Edited'}).first();
  await finalStoreCard.locator('summary').click();
  const finalStoreForm=finalStoreCard.locator('form');
  await finalStoreForm.locator('select[name="status"]').selectOption('inactive');
  await submitForm(dev.page,finalStoreForm,'Save store');
  directory=await portalGet(devApi,'admin_directory.php');
  store=(directory.stores||[]).find(row=>Number(row.id)===storeId);
  if (!store || store.status!=='inactive') fail('Store inactive state authoritative',JSON.stringify(store));
  pass('Store lifecycle state authoritative');

  console.log(`ADMIN_E2E_OK run=${fx.run} total=${results.length} passed=${results.length}`);
  await devApi.dispose();
  await dev.context.close(); await sup.context.close(); await usr.context.close();
  await browser.close();
}

main().catch(error=>{
  console.error('ADMIN_E2E_FAIL',error?.stack||error);
  process.exitCode=1;
});
