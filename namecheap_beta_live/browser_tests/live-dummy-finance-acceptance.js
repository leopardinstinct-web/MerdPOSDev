const { chromium } = require('@playwright/test');
const fs = require('fs');
const crypto = require('crypto');
const BASE = (process.env.MERDPOS_BASE_URL || 'https://app.merdpos.com/beta/timesheet_portal/').replace(/\/?$/, '/');
const FIXTURE = process.env.MERDPOS_FINANCE_FIXTURE;
const WRITE_WAIT_MS = Number(process.env.MERDPOS_E2E_WRITE_WAIT_MS || 13000);
if (!FIXTURE || !fs.existsSync(FIXTURE)) throw new Error('MERDPOS_FINANCE_FIXTURE must point to the private fixture JSON.');
const fx = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
if (fx?.client?.code !== 'DUMMY' || !String(fx?.prefix || '').startsWith('AUTOTEST Finance E2E ')) throw new Error('Refusing non-DUMMY or unbounded fixture.');
const results=[]; const uuid=()=>crypto.randomUUID(); const sleep=(ms)=>new Promise(r=>setTimeout(r,ms));
function record(name, ok, detail=''){ results.push({name,ok:!!ok,detail}); console.log(`${ok?'PASS':'FAIL'} ${name}${detail?' :: '+detail:''}`); }
async function body(response){ const text=await response.text(); let data=null; try{data=text?JSON.parse(text):null;}catch{} return {status:response.status(),ok:response.ok(),data,text}; }
async function expectOk(response,label){ const r=await body(response); if(!r.ok||!r.data?.success) throw new Error(`${label}: ${r.status} ${r.text.slice(0,500)}`); return r.data; }
async function expectError(response,label,code){ const r=await body(response); const got=r.data?.code||r.data?.error_code||r.data?.error||''; const ok=r.data?.success===false && String(r.text).includes(code); record(label,ok,`${r.status} ${got}`); if(!ok) throw new Error(`${label}: expected ${code}, got ${r.status} ${r.text.slice(0,500)}`); return r; }
async function post(ctx,api,data,label){ const out=await expectOk(await ctx.request.post(BASE+api,{data,headers:{Accept:'application/json'}}),label); await sleep(WRITE_WAIT_MS); return out; }
async function login(browser,key){ const e=fx.employees[key]; const ctx=await browser.newContext({userAgent:`MERDPOS-FIN-E2E-${key}`}); const data=await expectOk(await ctx.request.post(BASE+'api/login.php',{data:{user_id:e.user_id,password:e.password},headers:{Accept:'application/json'}}),`${key} login`); record(`${key.toUpperCase()} login`,String(data.user?.role_key||data.user?.role).toUpperCase()===key.toUpperCase(),`LOA ${data.user?.authority_level}`); const state=await expectOk(await ctx.request.get(BASE+'api/beta_state.php'),`${key} state`); return {ctx,csrf:state.csrf,state}; }
async function finPost(session,type,date,payload,id=uuid()){ return post(session.ctx,'api/financials.php',{csrf:session.csrf,submission_id:id,submission_type:type,business_date:date,store_id:Number(fx.store.id),payload},`${type} ${date}`); }
async function finGet(session,date){ return expectOk(await session.ctx.request.get(BASE+`api/financials.php?store_id=${fx.store.id}&business_date=${date}&_=${Date.now()}`),`statement ${date}`); }
async function main(){ const browser=await chromium.launch({channel:'chrome',headless:true}); const s={}; try{
  for(const key of ['dev','super','admin','user']) s[key]=await login(browser,key);
  record('USER finance.open_day denied by policy',!s.user.state.permissions?.['finance.open_day']);
  record('ADMIN finance.open_day granted',!!s.admin.state.permissions?.['finance.open_day']);
  record('SUPER finance.open_day granted',!!s.super.state.permissions?.['finance.open_day']);
  record('DEV finance.open_day granted',!!s.dev.state.permissions?.['finance.open_day']);
  const denied=await s.user.ctx.request.post(BASE+'api/financials.php',{data:{csrf:s.user.csrf,submission_id:uuid(),submission_type:'open_day',business_date:fx.business_dates.user_denied,store_id:Number(fx.store.id),payload:{register_opening:'1.00',petty_cash_opening:'1.00'}},headers:{Accept:'application/json'}}); await expectError(denied,'USER cannot open financial day','forbidden'); await sleep(WRITE_WAIT_MS);
  await finPost(s.admin,'open_day',fx.business_dates.admin,{register_opening:'10.00',petty_cash_opening:'5.00'}); record('ADMIN opens financial day',true);
  await finPost(s.dev,'open_day',fx.business_dates.dev,{register_opening:'10.00',petty_cash_opening:'5.00'}); record('DEV opens financial day',true);
  const openId=uuid(); await finPost(s.super,'open_day',fx.business_dates.main,{register_opening:'100.00',petty_cash_opening:'25.00'},openId); record('SUPER opens main day',true);
  const dup=await finPost(s.super,'open_day',fx.business_dates.main,{register_opening:'100.00',petty_cash_opening:'25.00'},openId); record('Identical submission id is idempotent',dup.result?.duplicate===true);
  const conflict=await s.super.ctx.request.post(BASE+'api/financials.php',{data:{csrf:s.super.csrf,submission_id:openId,submission_type:'open_day',business_date:fx.business_dates.main,store_id:Number(fx.store.id),payload:{register_opening:'101.00',petty_cash_opening:'25.00'}},headers:{Accept:'application/json'}}); await expectError(conflict,'Submission id conflict rejected','idempotency_conflict'); await sleep(WRITE_WAIT_MS);
  const userView=await finGet(s.user,fx.business_dates.main); record('Clocked-in USER can view own-store financials',userView.statement?.can_cross_store===false && userView.statement?.day_status==='open');
  const pettyDenied=await s.user.ctx.request.post(BASE+'api/financials.php',{data:{csrf:s.user.csrf,submission_id:uuid(),submission_type:'cash_out',business_date:fx.business_dates.main,store_id:Number(fx.store.id),payload:{transactions:[{account:'Petty Cash',head:'AUTOTEST overdraw',amount:'30.00'}]}},headers:{Accept:'application/json'}}); await expectError(pettyDenied,'Petty Cash overdraw rejected','insufficient_balance'); await sleep(WRITE_WAIT_MS);
  await finPost(s.user,'cash_in',fx.business_dates.main,{transactions:[{account:'Petty Cash',head:'AUTOTEST petty topup',amount:'10.00'}]});
  await finPost(s.user,'cash_out',fx.business_dates.main,{transactions:[{account:'Petty Cash',head:'AUTOTEST petty expense',amount:'30.00'}]});
  const regDenied=await s.user.ctx.request.post(BASE+'api/financials.php',{data:{csrf:s.user.csrf,submission_id:uuid(),submission_type:'cash_out',business_date:fx.business_dates.main,store_id:Number(fx.store.id),payload:{transactions:[{account:'Register',head:'AUTOTEST register overdraw',amount:'101.00'}]}},headers:{Accept:'application/json'}}); await expectError(regDenied,'Register overdraw rejected','insufficient_balance'); await sleep(WRITE_WAIT_MS);
  await finPost(s.user,'cash_in',fx.business_dates.main,{transactions:[{account:'Register',head:'AUTOTEST cash in',amount:'30.00'}]});
  await finPost(s.user,'cash_out',fx.business_dates.main,{transactions:[{account:'Register',head:'AUTOTEST cash out',amount:'10.00'}]});
  let statement=(await finGet(s.user,fx.business_dates.main)).statement; const reg=statement.accounts.find(a=>a.account==='Register'), petty=statement.accounts.find(a=>a.account==='Petty Cash'); record('Available balances reconcile',Number(reg?.available)===120 && Number(petty?.available)===5,JSON.stringify({register:reg?.available,petty:petty?.available}));
  await finPost(s.user,'z_report',fx.business_dates.main,{register_total:'150.00',petty_cash_addin:'0.00'}); statement=(await finGet(s.user,fx.business_dates.main)).statement;
  const regClosed=statement.accounts.find(a=>a.account==='Register'), pettyClosed=statement.accounts.find(a=>a.account==='Petty Cash'); record('Z-report closes and reconciles day',statement.day_status==='closed'&&Number(regClosed?.closing)===150&&Number(pettyClosed?.closing)===5&&statement.entries.length>=8);
  const closedWrite=await s.user.ctx.request.post(BASE+'api/financials.php',{data:{csrf:s.user.csrf,submission_id:uuid(),submission_type:'cash_in',business_date:fx.business_dates.main,store_id:Number(fx.store.id),payload:{transactions:[{account:'Register',head:'AUTOTEST after close',amount:'1.00'}]}},headers:{Accept:'application/json'}}); await expectError(closedWrite,'Closed day rejects movements','financial_day_closed'); await sleep(WRITE_WAIT_MS);
  const next=(await finGet(s.admin,'2099-11-12')).statement; record('Closing rolls balances into next day',next.day_status==='open'&&Number(next.accounts.find(a=>a.account==='Register')?.opening)===150&&Number(next.accounts.find(a=>a.account==='Petty Cash')?.opening)===5);
  const adminView=await finGet(s.admin,fx.business_dates.main); record('ADMIN cross-store finance works without attendance',adminView.statement?.can_cross_store===true);
  const devView=await finGet(s.dev,fx.business_dates.main); record('DEV cross-store finance works',devView.statement?.can_cross_store===true);
  const passed=results.filter(r=>r.ok).length, failed=results.length-passed; console.log(`FINANCE_E2E_DONE run=${fx.run} total=${results.length} passed=${passed} failed=${failed}`); if(failed)process.exitCode=1;
 } finally { for(const v of Object.values(s)) await v.ctx.close().catch(()=>{}); await browser.close(); }
}
main().catch(e=>{console.error('FINANCE_E2E_FAIL',e?.stack||e);process.exitCode=1;});
