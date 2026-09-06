const { chromium } = require('@playwright/test');
const fs = require('fs');
const crypto = require('crypto');

const FIXTURE = process.env.MERDPOS_ATTENDANCE_FIXTURE;
if (!FIXTURE || !fs.existsSync(FIXTURE)) throw new Error('MERDPOS_ATTENDANCE_FIXTURE is required.');
const fx = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
if (String(fx.client?.code || '').toUpperCase() !== 'DUMMY') throw new Error('Refusing non-DUMMY fixture.');
if (!String(fx.run || '').match(/^\d{14}-[a-f0-9]{6}$/)) throw new Error('Invalid DUMMY run marker.');

const DRUPAL = 'https://drupal-beta.merdpos.com';
const BACKEND = 'https://app.merdpos.com/beta/backend/api/';
const PORTAL_LOGIN = 'https://app.merdpos.com/beta/timesheet_portal/api/login.php';
const results = [];
const pass = (name, detail='') => { results.push({name,ok:true,detail}); console.log('PASS', name, detail); };
const fail = (name, detail='') => { throw new Error(`${name}: ${detail}`); };
const b64url = (value) => Buffer.from(value).toString('base64url');

function keyPair() {
  const pair = crypto.generateKeyPairSync('ed25519');
  const jwk = pair.publicKey.export({ format: 'jwk' });
  return { privateKey: pair.privateKey, publicKeyB64: Buffer.from(jwk.x, 'base64url').toString('base64') };
}
function qrToken(deviceUuid, privateKey, offset=0, ttl=120) {
  const now = Math.floor(Date.now()/1000) + offset;
  const claims = { v:1, did:deviceUuid, iat:now, exp:now+ttl, n:crypto.randomBytes(12).toString('hex') };
  const encoded = b64url(JSON.stringify(claims));
  const sig = crypto.sign(null, Buffer.from(encoded), privateKey);
  return `${encoded}.${b64url(sig)}`;
}

async function jsonResponse(response, label, expectSuccess=true) {
  const text = await response.text();
  let data; try { data = text ? JSON.parse(text) : {}; } catch { fail(label, `invalid JSON ${response.status()} ${text.slice(0,180)}`); }
  if (expectSuccess && (!response.ok() || !data.success)) fail(label, `${response.status()} ${JSON.stringify(data).slice(0,400)}`);
  return { response, data };
}
async function preflightLogin(request, employee, label) {
  const { data } = await jsonResponse(await request.post(PORTAL_LOGIN, {
    headers: { 'Accept':'application/json' }, form: { user_id:String(employee.user_id), password:String(employee.password) }
  }), label);
  if (Number(data.user?.client_id || 0) !== Number(fx.client.id)) fail(label, 'authoritative login resolved wrong client');
  if (Number(data.user?.id || 0) !== Number(employee.id)) fail(label, 'authoritative login resolved wrong employee');
  pass(label, `client=${data.user.client_id} employee=${data.user.id}`);
}

async function registerKey(request, device, kp) {
  const { data } = await jsonResponse(await request.post(BACKEND+'register_attendance_key.php', {
    headers: { 'Accept':'application/json', 'Content-Type':'application/json', 'Authorization':`Bearer ${device.token}` },
    data: { client_id:fx.client.id, store_id:device.store_id, device_uuid:device.uuid, public_key_b64:kp.publicKeyB64 }
  }), 'register attendance key');
  if (!data.key_registered) fail('Register attendance key', device.uuid);
}
async function backendPost(request, api, device, body) {
  return jsonResponse(await request.post(BACKEND+api, {
    headers: { 'Accept':'application/json', 'Content-Type':'application/json', 'Authorization':`Bearer ${device.token}` }, data:body
  }), api);
}
async function login(context, employee) {
  const page = await context.newPage();
  await page.goto(DRUPAL+'/login', {waitUntil:'domcontentloaded', timeout:20000});
  await page.locator('input[name="user_id"]').fill(String(employee.user_id));
  await page.locator('input[name="password"]').fill(String(employee.password));
  await page.locator('input[type="submit"],button[type="submit"]').click();
  try { await page.waitForURL((url) => url.pathname === '/merdpos' || url.pathname.startsWith('/merdpos/'),{timeout:20000}); } catch (error) { console.error('LOGIN_DIAG', employee.name, page.url(), (await page.locator('body').innerText()).replace(/\s+/g,' ').slice(0,2500)); throw error; }
  return page;
}

async function openScanner(page) {
  if (new URL(page.url()).pathname !== '/merdpos') await page.goto(DRUPAL+'/merdpos',{waitUntil:'domcontentloaded'});
  const root=page.locator('[data-attendance-scan]');
  await root.waitFor({state:'visible',timeout:15000});
  if (await root.locator('[data-attendance-panel]').evaluate(el=>el.hidden)) await root.locator('[data-attendance-open]').click();
  return root;
}
async function scan(page, token, expectation) {
  const root=await openScanner(page);
  const status=root.locator('[data-attendance-status]');
  await root.locator('[data-attendance-input]').fill(token);
  await root.locator('[data-attendance-manual] button[type="submit"]').click();
  await page.waitForFunction(() => {
    const el=document.querySelector('[data-attendance-status]');
    return el && !/Validating/i.test(el.textContent||'');
  }, null, {timeout:15000});
  const text=(await status.innerText()).trim();
  if (expectation.action) {
    const action=(await root.locator('[data-attendance-result-action]').innerText()).trim();
    if (action !== expectation.action) fail(`QR ${expectation.action}`, `${action} / ${text}`);
  } else if (!text.toLowerCase().includes(expectation.error.toLowerCase())) {
    fail(`QR error ${expectation.error}`, text);
  }
  pass(expectation.name, text);
  return text;
}
async function pageContains(page, text, label) {
  const body=(await page.locator('body').innerText()).replace(/\s+/g,' ');
  if (!body.includes(text)) fail(label, `missing ${text}`);
  pass(label, text);
}
async function workingNowContains(page, text, expected, label) {
  const panel=page.locator('.merdpos-dashboard-panel--working_now');
  await panel.waitFor({state:'visible',timeout:15000});
  const body=(await panel.innerText()).replace(/\s+/g,' ');
  if (body.includes(text) !== expected) fail(label, expected ? `missing ${text}` : `still contains ${text}`);
  pass(label, expected ? text : 'cleared');
}

async function main() {
  const browser=await chromium.launch({channel:'chrome',headless:true});
  const apiContext=await browser.newContext(); const request=apiContext.request;
  const keyA=keyPair(), keyB=keyPair();
  await preflightLogin(request,fx.employees.user,'DUMMY USER authoritative login preflight');
  await preflightLogin(request,fx.employees.super,'DUMMY SUPER authoritative login preflight');
  await registerKey(request,fx.devices.a,keyA); await registerKey(request,fx.devices.b,keyB);
  pass('Register DUMMY POS attendance keys', `devices=${fx.devices.a.id},${fx.devices.b.id}`);

  const userContext=await browser.newContext({viewport:{width:1280,height:900}});
  const superContext=await browser.newContext({viewport:{width:1280,height:900}});
  const userPage=await login(userContext,fx.employees.user);
  const superPage=await login(superContext,fx.employees.super);
  await pageContains(userPage,fx.employees.user.name,'DUMMY employee Drupal login');
  await pageContains(superPage,fx.employees.super.name,'DUMMY SUPER Drupal login');

  await scan(userPage,qrToken(fx.devices.b.uuid,keyB.privateKey),{name:'Reject unassigned-store QR',error:'not assigned'});
  await scan(userPage,'x.x',{name:'Reject invalid QR',error:'valid'});
  await scan(userPage,qrToken(fx.devices.a.uuid,keyA.privateKey,-300,60),{name:'Reject expired QR',error:'expired'});
  await scan(userPage,qrToken(fx.devices.a.uuid,keyB.privateKey),{name:'Reject wrong-signature QR',error:'authorised'});

  const inToken=qrToken(fx.devices.a.uuid,keyA.privateKey);
  await scan(userPage,inToken,{name:'Clock IN through My current shift',action:'CLOCKED IN'});
  await scan(userPage,inToken,{name:'Duplicate QR is idempotent',action:'CLOCKED IN'});
  await scan(userPage,qrToken(fx.devices.a.uuid,keyA.privateKey),{name:'Enforce 60-second cooldown',error:'just clocked in'});

  await userPage.reload({waitUntil:'domcontentloaded'});
  await pageContains(userPage,'Clocked in','My current shift reflects IN');
  await pageContains(userPage,fx.stores.a.name,'My current shift shows correct store');
  await superPage.goto(DRUPAL+'/merdpos',{waitUntil:'domcontentloaded'});
  await workingNowContains(superPage,fx.employees.user.name,true,'Working Now reflects DUMMY IN');

  console.log('WAIT cooldown window 61s');
  await userPage.waitForTimeout(61000);
  await scan(userPage,qrToken(fx.devices.a.uuid,keyA.privateKey),{name:'Clock OUT through My current shift',action:'CLOCKED OUT'});
  await userPage.reload({waitUntil:'domcontentloaded'});
  await pageContains(userPage,'Off shift','My current shift reflects OUT');
  await superPage.goto(DRUPAL+'/merdpos',{waitUntil:'domcontentloaded'});
  await workingNowContains(superPage,fx.employees.user.name,false,'Working Now clears after OUT');

  await superPage.goto(DRUPAL+'/merdpos/reports',{waitUntil:'domcontentloaded'});
  await pageContains(superPage,fx.employees.user.name,'Reports include completed DUMMY shift');

  await userPage.goto(DRUPAL+'/merdpos',{waitUntil:'domcontentloaded'});
  await scan(userPage,qrToken(fx.devices.a.uuid,keyA.privateKey),{name:'Clock IN for POS handover',action:'CLOCKED IN'});
  const handover=await backendPost(request,'report_pos_handover.php',fx.devices.a,{
    previous_employee_id:fx.employees.user.id,replacement_employee_id:fx.employees.replacement.id
  });
  if (handover.data.handover?.status !== 'awaiting_employee') fail('POS handover dispute created',JSON.stringify(handover.data.handover));
  pass('POS handover creates awaiting-employee dispute');

  await userPage.goto(DRUPAL+'/merdpos/disputes',{waitUntil:'domcontentloaded'});
  await pageContains(userPage,'Confirm & send','DUMMY employee sees handover confirmation');
  await userPage.getByRole('button',{name:'Confirm & send'}).click();
  await userPage.waitForLoadState('domcontentloaded');
  await pageContains(userPage,'pending','Confirmed handover moves to pending review');

  await superPage.goto(DRUPAL+'/merdpos/disputes',{waitUntil:'domcontentloaded'});
  const card=superPage.locator('.merdpos-dispute-card,.merdpos-disputes-item,article').filter({hasText:fx.employees.user.name}).first();
  await card.waitFor({state:'visible',timeout:15000});
  await card.getByRole('button',{name:'Approve'}).click();
  await superPage.waitForLoadState('domcontentloaded');
  await pageContains(superPage,'approved','SUPER approves handover correction');

  await userPage.goto(DRUPAL+'/merdpos',{waitUntil:'domcontentloaded'});
  await pageContains(userPage,'Off shift','Approved handover closes current shift');
  await superPage.goto(DRUPAL+'/merdpos/reports',{waitUntil:'domcontentloaded'});
  await pageContains(superPage,fx.employees.user.name,'Reports reflect handover-resolved shift');

  console.log(`ATTENDANCE_E2E_OK run=${fx.run} total=${results.length} passed=${results.length}`);
  await userContext.close(); await superContext.close(); await apiContext.close(); await browser.close();
}

main().catch(async error => {
  console.error('ATTENDANCE_E2E_FAIL', error?.stack || error);
  process.exitCode=1;
});
