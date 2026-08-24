(function () {
  let state = null;
  let financialStatement = null;
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const localTime = value => value ? new Date(String(value).replace(' ','T')+'Z').toLocaleString([], {dateStyle:'medium',timeStyle:'short'}) : 'Missing';
  const uuid = () => crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c=>{const r=crypto.getRandomValues(new Uint8Array(1))[0]&15,v=c==='x'?r:(r&3|8);return v.toString(16);});
  const queueKey = 'merdpos_financial_queue_v1';

  document.querySelectorAll('.portal-tab').forEach(button => button.addEventListener('click', () => {
    document.querySelectorAll('.portal-tab').forEach(tab => tab.classList.toggle('active', tab === button));
    document.querySelectorAll('.portal-panel').forEach(panel => panel.hidden = panel.id !== button.dataset.panel);
  }));

  async function api(url, options = {}) {
    const response = await fetch(url, options); const data = await response.json();
    if (!data.success) { const error=new Error(data.error || 'Request failed'); error.status=response.status; error.code=data.code||''; throw error; } return data;
  }

  async function loadState() {
    try { state = await api('api/beta_state.php'); render(); await flushQueue(); }
    catch (error) { $('workingNow').innerHTML = `<div class="status-card error-card">${esc(error.message)}</div>`; }
  }

  function render() {
    const working = state.working || [];
    renderDashboardSummary(working);
    $('workingNow').innerHTML = working.length ? working.map(person => `<article class="working-card"><span class="live-dot"></span><h2>${esc(person.full_name)}</h2><p>${esc(person.store_name)}</p><small>Since ${esc(person.clock_in_at)} UTC · ${Number(person.working_minutes || 0)} min</small></article>`).join('') : '<div class="empty-card"><h2>Nobody is clocked in</h2><p>Only verified QR attendance appears here.</p></div>';
    const shifts = state.recent_shifts || [];
    $('recentShifts').innerHTML = table(['Employee','Store','In','Out','Status'], shifts.map(s => [s.full_name,s.store_name,localTime(s.clock_in_at),localTime(s.clock_out_at),s.status]));
    if ($('disputeShift')) $('disputeShift').innerHTML = shifts.filter(s=>String(s.user_id)===String(state.current_user_id)).map(s => `<option value="${esc(s.shift_id)}">${esc(s.store_name)} · ${esc(localTime(s.clock_in_at))}</option>`).join('');
    const allStores=state.stores||[],storeOptions=allStores.map(s => `<option value="${Number(s.id)}">${esc(s.store_name)}</option>`).join('');
    const myWorkingNames=new Set(working.filter(person=>String(person.user_id)===String(state.current_user_id)).map(person=>person.store_name));
    const userStores=allStores.filter(s=>myWorkingNames.has(s.store_name));
    $('financialStore').innerHTML = state.is_super?storeOptions:userStores.map(s=>`<option value="${Number(s.id)}">${esc(s.store_name)}</option>`).join('');
    const accessNote=$('financialAccessNote');
    if(accessNote) accessNote.textContent = state.is_super
      ? 'You can select any active store.'
      : (userStores.length ? `Financials are unlocked for your active QR store: ${userStores.map(s=>s.store_name).join(', ')}.` : 'Clock in with the store QR to unlock that store’s financials.');
    if ($('proposedStore')) $('proposedStore').innerHTML=storeOptions;
    renderDisputes(); renderFlags(); updateQueueBadge();
    if ($('financialStore').value) loadFinancialStatement();
    else if(!state.is_super) $('financialSummary').innerHTML='<div class="empty-card"><h2>No active store access</h2><p>Clock in with the store QR before viewing or submitting financials.</p></div>';
  }

  function renderDashboardSummary(working){
    const root=$('dashboardSummary'); if(!root) return;
    const disputes=state.disputes||[],pending=disputes.filter(d=>d.status==='pending').length;
    const openForMe=working.find(person=>String(person.user_id)===String(state.current_user_id));
    if(state.is_super){
      const stores=new Set(working.map(person=>person.store_name));
      const flags=(state.attendance_flags||[]).filter(f=>f.status==='open').length;
      root.innerHTML=[
        summaryCard('Working now', working.length, `${stores.size} active store${stores.size===1?'':'s'}`),
        summaryCard('Pending disputes', pending, 'USER requests needing decision'),
        summaryCard('Open flags', flags, 'Suspended attendance accounts'),
      ].join('');
    }else{
      const myDisputes=disputes.filter(d=>['awaiting_employee','pending'].includes(d.status)).length;
      root.innerHTML=[
        summaryCard('Current shift', openForMe?'Clocked in':'Not clocked in', openForMe?openForMe.store_name:'Scan store QR to start'),
        summaryCard('Financial access', openForMe?'Unlocked':'Locked', openForMe?openForMe.store_name:'Only available while clocked in'),
        summaryCard('Open disputes', myDisputes, 'Your active correction requests'),
      ].join('');
    }
  }

  function summaryCard(label,value,detail){return `<article class="working-card"><h2>${esc(value)}</h2><p>${esc(label)}</p><small>${esc(detail)}</small></article>`;}

  function table(headers, rows) {
    return `<table><thead><tr>${headers.map(h => `<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.map(row => `<tr>${row.map(v => `<td>${esc(v)}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
  }

  function renderDisputes() {
    const rows = (state.disputes || []).map(d => {
      const actions = state.is_super && d.status === 'pending' ? `<button class="mini-btn approve" data-decision="approved" data-id="${esc(d.dispute_id)}">Approve</button><button class="mini-btn reject" data-decision="rejected" data-id="${esc(d.dispute_id)}">Reject</button>` : (!state.is_super&&d.status==='awaiting_employee'&&d.origin==='pos_handover'?`<button class="mini-btn approve" data-handover="confirm_handover" data-id="${esc(d.dispute_id)}">Confirm & send</button><button class="mini-btn reject" data-handover="reject_handover" data-id="${esc(d.dispute_id)}">Not correct</button>`:(!state.is_super && d.status==='pending'?`<button class="mini-btn reject" data-cancel="1" data-id="${esc(d.dispute_id)}">Cancel</button>`:''));
      const requested=`${d.requested_clock_in_at?'IN '+localTime(d.requested_clock_in_at):''}${d.requested_clock_in_at&&d.requested_clock_out_at?' · ':''}${d.requested_clock_out_at?'OUT '+localTime(d.requested_clock_out_at):''}`||'No time change';
      return `<tr><td>${esc(d.full_name)}</td><td>${esc(d.store_name)}</td><td>${esc(d.dispute_type)}</td><td>${esc(requested)}</td><td>${esc(d.reason)}</td><td><span class="status-pill ${esc(d.status)}">${esc(d.status)}</span></td><td>${actions}</td></tr>`;
    }).join('');
    $('disputeList').innerHTML = `<table><thead><tr><th>Employee</th><th>Store</th><th>Issue</th><th>Requested change</th><th>Reason</th><th>Status</th><th>Action</th></tr></thead><tbody>${rows || '<tr><td colspan="7">No disputes.</td></tr>'}</tbody></table>`;
    $('disputeList').querySelectorAll('[data-decision]').forEach(button => button.addEventListener('click', () => decide(button)));
    $('disputeList').querySelectorAll('[data-cancel]').forEach(button=>button.addEventListener('click',()=>cancelDispute(button.dataset.id)));
    $('disputeList').querySelectorAll('[data-handover]').forEach(button=>button.addEventListener('click',()=>confirmHandover(button.dataset.id,button.dataset.handover)));
  }

  function renderFlags(){const root=$('attendanceFlags');if(!root)return;const flags=state.attendance_flags||[];root.innerHTML=`<table><thead><tr><th>Employee</th><th>Attempted store</th><th>Reason</th><th>When</th><th>Status</th><th>Action</th></tr></thead><tbody>${flags.map(f=>`<tr><td>${esc(f.full_name)}</td><td>${esc(f.attempted_store)}</td><td>${esc(f.reason)}</td><td>${esc(f.created_at)}</td><td>${esc(f.status)}</td><td>${f.status==='open'?`<button class="mini-btn approve" data-flag="${esc(f.flag_id)}">Reactivate</button>`:''}</td></tr>`).join('')||'<tr><td colspan="6">No attendance suspensions.</td></tr>'}</tbody></table>`;root.querySelectorAll('[data-flag]').forEach(b=>b.addEventListener('click',()=>resolveFlag(b.dataset.flag)));}
  async function resolveFlag(id){const note=prompt('Reactivation note:')??null;if(note===null)return;try{await api('api/disputes.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'resolve_flag',flag_id:id,note,csrf:state.csrf})});await loadState();}catch(e){alert(e.message);}}
  async function cancelDispute(id){if(!confirm('Cancel this pending dispute?'))return;try{await api('api/disputes.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'cancel',dispute_id:id,csrf:state.csrf})});await loadState();}catch(e){alert(e.message);}}
  async function confirmHandover(id,action){const message=action==='confirm_handover'?'Confirm this proposed clock-out and send it to SUPER?':'Mark this handover report as incorrect?';if(!confirm(message))return;try{await api('api/disputes.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,dispute_id:id,csrf:state.csrf})});await loadState();}catch(e){alert(e.message);}}

  const disputeForm=$('disputeForm');
  if(disputeForm) disputeForm.addEventListener('submit', async event => {
    event.preventDefault(); const values = Object.fromEntries(new FormData(event.target)),type=values.dispute_type;
    if((type==='new_shift'||type==='wrong_in')&&!values.requested_clock_in){alert('Enter the correct clock-in time.');return;}
    if((type==='new_shift'||type==='missing_out'||type==='wrong_out')&&!values.requested_clock_out){alert('Enter the correct clock-out time.');return;}
    values.action = 'create'; values.csrf = state.csrf;
    try { await api('api/disputes.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(values)}); event.target.reset(); updateDisputeForm(); await loadState(); alert('Sent for SUPER approval.'); }
    catch (error) { alert(error.message); }
  });
  function updateDisputeForm(){if(!$('disputeType'))return;const type=$('disputeType').value,isNew=type==='new_shift';$('proposedStoreField').hidden=!isNew;$('disputeShiftField').hidden=isNew;$('disputeShift').disabled=isNew;
    $('requestedInField').hidden=!['wrong_in','new_shift','other'].includes(type);$('requestedOutField').hidden=!['missing_out','wrong_out','new_shift','other'].includes(type);}
  if($('disputeType')){$('disputeType').addEventListener('change',updateDisputeForm);updateDisputeForm();}

  async function decide(button) {
    const note = prompt(`${button.dataset.decision === 'approved' ? 'Approval' : 'Rejection'} note (optional):`) ?? null;
    if (note === null) return;
    try { await api('api/disputes.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'decide',dispute_id:button.dataset.id,decision:button.dataset.decision,note,csrf:state.csrf})}); await loadState(); }
    catch (error) { alert(error.message); }
  }

  document.querySelectorAll('.financial-tab').forEach(button=>button.addEventListener('click',()=>{document.querySelectorAll('.financial-tab').forEach(tab=>tab.classList.toggle('active',tab===button));document.querySelectorAll('.financial-section').forEach(panel=>panel.hidden=panel.id!==button.dataset.financePanel);}));
  $('financialDate').value = new Date().toISOString().slice(0,10);
  $('financialStore').addEventListener('change',loadFinancialStatement); $('financialDate').addEventListener('change',loadFinancialStatement); $('refreshFinancial').addEventListener('click',loadFinancialStatement);

  function accountState(name){return financialStatement?.accounts?.find(account=>account.account===name)||null;}
  function effectiveAvailable(name){
    const account=accountState(name),storeId=Number($('financialStore').value),date=$('financialDate').value;let value=account?Number(account.available):null;
    for(const item of getQueue()){
      if(Number(item.store_id)!==storeId||item.business_date!==date)continue;
      if(item.submission_type==='open_day'&&value===null)value=Number(name==='Register'?item.payload.register_opening:item.payload.petty_cash_opening);
      if((item.submission_type==='cash_in'||item.submission_type==='cash_out')&&value!==null)for(const tx of item.payload.transactions||[])if(tx.account===name)value+=(item.submission_type==='cash_in'?1:-1)*Number(tx.amount);
    }
    return value;
  }
  function updateAvailable(){const available=effectiveAvailable($('cashAccount').value);$('cashAvailable').textContent=available===null?'Not opened':`$${available.toFixed(2)}`;}
  $('cashAccount').addEventListener('change',updateAvailable);
  async function loadFinancialStatement(){
    const storeId=$('financialStore').value,date=$('financialDate').value;if(!storeId||!date)return;
    try{const result=await api(`api/financials.php?store_id=${encodeURIComponent(storeId)}&business_date=${encodeURIComponent(date)}`);financialStatement=result.statement;renderFinancialStatement();}
    catch(error){if(financialStatement){$('financialStatus').textContent='Offline — showing the last confirmed balance plus this device’s pending entries.';}else $('financialSummary').innerHTML=`<div class="status-card error-card">${esc(error.message)}</div>`;updateAvailable();}
  }
  function renderFinancialStatement(){
    const summary=$('financialSummary'),accounts=financialStatement.accounts||[],openForm=$('openDayForm');
    if(financialStatement.day_status==='not_open'){
      summary.innerHTML='<div class="empty-card"><h2>Financial day not opened</h2><p>A SUPER user must confirm the opening balances.</p></div>';
      if(openForm)openForm.hidden=false;$('financialEntries').hidden=true;updateAvailable();return;
    }
    if(openForm)openForm.hidden=true;
    summary.innerHTML=`<div class="financial-account-grid">${accounts.map(a=>`<article class="financial-account-card"><div class="financial-account-title">${esc(financialStatement.store_name)} — ${esc(a.account)} (${esc(financialStatement.business_date)})</div><table><thead><tr><th>Head</th><th class="num">Amount</th></tr></thead><tbody><tr><td>OPENING</td><td class="num">$${Number(a.opening).toFixed(2)}</td></tr><tr><td>Cash IN</td><td class="num">$${Number(a.cash_in).toFixed(2)}</td></tr><tr><td>Cash OUT</td><td class="num">$${Number(a.cash_out).toFixed(2)}</td></tr><tr class="balance-row"><td>${a.status==='closed'?'CLOSING':'AVAILABLE'}</td><td class="num">$${Number(a.status==='closed'?a.closing:a.available).toFixed(2)}</td></tr></tbody></table></article>`).join('')}</div>`;
    const entries=financialStatement.entries||[],root=$('financialEntries');root.hidden=!entries.length;root.innerHTML=entries.length?`<table><thead><tr><th>Account</th><th>Type</th><th>Head</th><th>Amount</th><th>By</th></tr></thead><tbody>${entries.map(e=>`<tr><td>${esc(e.account)}</td><td>${esc(e.entry_type)}</td><td>${esc(e.head)}</td><td>$${Number(e.amount).toFixed(2)}</td><td>${esc(e.full_name)}</td></tr>`).join('')}</tbody></table>`:'';
    updateAvailable();
  }
  function queueFinancial(submissionType,payload){
    const storeId=Number($('financialStore').value),businessDate=$('financialDate').value;if(!storeId){alert('Clock in before submitting store financials.');return false;}
    const sameDay=getQueue().filter(item=>Number(item.store_id)===storeId&&item.business_date===businessDate);
    if(sameDay.some(item=>item.submission_type==='z_report')){alert('This day already has a closing waiting to sync.');return false;}
    if(submissionType==='open_day'&&sameDay.some(item=>item.submission_type==='open_day')){alert('This day already has an opening waiting to sync.');return false;}
    const item={submission_id:uuid(),store_id:storeId,business_date:businessDate,submission_type:submissionType,payload};const queue=getQueue();queue.push(item);localStorage.setItem(queueKey,JSON.stringify(queue));updateQueueBadge();$('financialStatus').classList.remove('error-message');$('financialStatus').textContent='Saved on this phone. Sending…';flushQueue();return true;
  }
  const openDayForm=$('openDayForm');if(openDayForm)openDayForm.addEventListener('submit',event=>{event.preventDefault();const f=Object.fromEntries(new FormData(event.target)),register=Number(f.register_opening),petty=Number(f.petty_cash_opening);if(!Number.isFinite(register)||register<0||!Number.isFinite(petty)||petty<0){alert('Enter valid opening balances.');return;}if(queueFinancial('open_day',{register_opening:register,petty_cash_opening:petty}))event.target.reset();});
  $('cashMovementForm').addEventListener('submit',event=>{event.preventDefault();const f=Object.fromEntries(new FormData(event.target)),amount=Number(f.amount);if(String(f.head||'').trim().length<2||!Number.isFinite(amount)||amount<=0){alert('Enter a reason and an amount greater than zero.');return;}const available=effectiveAvailable(f.account);if(available===null){alert('Open this financial day and confirm its balance before entering Cash IN / OUT.');return;}if(f.submission_type==='cash_out'&&amount>available){alert(`${f.account} has $${available.toFixed(2)} available. Cash OUT cannot exceed this amount.`);return;}if(queueFinancial(f.submission_type,{transactions:[{account:f.account,head:String(f.head).trim(),amount}]}))event.target.reset();updateAvailable();});
  $('closingForm').addEventListener('submit',event=>{event.preventDefault();const f=Object.fromEntries(new FormData(event.target)),total=Number(f.register_total),petty=Number(f.petty_cash_addin||0),registerAvailable=effectiveAvailable('Register');if(registerAvailable===null){alert('Open this financial day before closing it.');return;}if(!Number.isFinite(total)||total<0||!Number.isFinite(petty)||petty<0||petty>total){alert('Enter valid closing totals. Petty Cash transfer cannot exceed the Register total.');return;}if(total-registerAvailable+petty<0){alert('Register total is below the recorded balance. Review Cash IN / OUT first.');return;}if(!confirm('Close this financial day? This can only be done once.'))return;if(queueFinancial('z_report',{register_total:total,petty_cash_addin:petty,denominations:String(f.denominations||'').split(',').map(v=>v.trim()).filter(Boolean)}))event.target.reset();});

  function getQueue() { try { const v=JSON.parse(localStorage.getItem(queueKey)||'[]'); return Array.isArray(v)?v:[]; } catch (_) { return []; } }
  function updateQueueBadge() { const count=getQueue().length; $('financialQueue').textContent=`${count} pending`; updateAvailable(); }
  async function flushQueue() {
    if (!state || !navigator.onLine) { updateQueueBadge(); return; }
    const queue = getQueue(), remaining = [];
    for (const item of queue) {
      try { await api('api/financials.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...item,csrf:state.csrf})}); $('financialStatus').classList.remove('error-message');$('financialStatus').textContent='Saved successfully.'; }
      catch (error) { if(!error.status)remaining.push(item);else{$('financialStatus').classList.add('error-message');$('financialStatus').textContent=`Not accepted: ${error.message}`;} }
    }
    localStorage.setItem(queueKey,JSON.stringify(remaining)); updateQueueBadge();if(!remaining.length&&$('financialStore').value)await loadFinancialStatement();
  }
  window.addEventListener('online', flushQueue); $('refreshBetaBtn').addEventListener('click', loadState);
  const passwordDialog=$('passwordDialog'),passwordForm=$('passwordForm'),passwordStatus=$('passwordStatus');
  $('passwordBtn').addEventListener('click',()=>passwordDialog.showModal());
  $('passwordClose').addEventListener('click',()=>passwordDialog.close());
  passwordForm.addEventListener('submit',async event=>{event.preventDefault();passwordStatus.hidden=true;const values=Object.fromEntries(new FormData(passwordForm));values.csrf=state.csrf;
    try{const result=await api('api/change_password.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(values)});state.csrf=result.csrf;passwordForm.reset();passwordDialog.close();alert('Password changed successfully.');}
    catch(error){passwordStatus.textContent=error.message;passwordStatus.hidden=false;}});
  loadState(); setInterval(loadState, 60000);
})();
