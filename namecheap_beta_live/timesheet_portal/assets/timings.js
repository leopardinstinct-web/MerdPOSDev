(function(){
  'use strict';
  const storeDialog=document.getElementById('storeDialog');
  const storeForm=document.getElementById('storeAdminForm');
  if(!storeDialog||!storeForm)return;

  const days=[[1,'Monday'],[2,'Tuesday'],[3,'Wednesday'],[4,'Thursday'],[5,'Friday'],[6,'Saturday'],[7,'Sunday']];
  let state=null,currentStoreId=null;
  const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function ensureStyles(){
    if(document.querySelector('link[data-timings-css]'))return;
    const link=document.createElement('link');
    link.rel='stylesheet';
    link.href='assets/timings.css?v=20260831storeedit1';
    link.dataset.timingsCss='1';
    document.head.appendChild(link);
  }

  async function api(url,options={}){
    const response=await fetch(url,{cache:'no-store',...options});
    const text=await response.text();let data=null;
    try{data=text?JSON.parse(text):null;}catch(_){throw new Error(`Store timings API returned invalid data (${response.status}).`);}
    if(!data)throw new Error(`Store timings API returned an empty response (${response.status}).`);
    if(!data.success)throw new Error(data.error||`Request failed (${response.status})`);
    return data;
  }

  function createSection(){
    if(document.getElementById('storeTimingsSection'))return;
    const body=storeDialog.querySelector('.admin-dialog-body');
    const footer=body?.querySelector('.admin-dialog-footer');
    if(!body)return;
    const section=document.createElement('section');
    section.id='storeTimingsSection';
    section.className='store-timings-edit';
    section.innerHTML=`
      <div class="timing-page-head"><h3>Weekly timings</h3><p>Opening and closing hours for this store. End time may be after midnight.</p></div>
      <div class="timing-controls"><button type="button" id="copyFirstDayBtn" class="secondary-btn compact-btn">Copy first day to all days</button></div>
      <div id="timingScopeNote" class="timing-scope-note"></div>
      <div class="timing-grid timing-grid-head"><span>Day</span><span>Closed</span><span>Start</span><span>End</span></div>
      <div id="timingRows"></div>
      <div class="timing-footer"><span id="timingStatus" class="muted"></span><button type="button" id="saveTimingsBtn" class="primary-btn compact-btn">Save timings</button></div>`;
    if(footer)body.insertBefore(section,footer);else body.appendChild(section);
    document.getElementById('copyFirstDayBtn')?.addEventListener('click',copyFirstDayToAll);
    document.getElementById('saveTimingsBtn')?.addEventListener('click',saveTimings);
    document.getElementById('storeWeekStartDay')?.addEventListener('change',()=>renderSelectedSchedule());
  }

  function scheduleMap(){
    const map=new Map();
    for(const row of state?.timings||[]){
      if(!map.has(Number(row.store_id)))map.set(Number(row.store_id),new Map());
      map.get(Number(row.store_id)).set(Number(row.day_of_week),row);
    }
    return map;
  }

  function timeValue(value){const text=String(value||'');return /^\d{2}:\d{2}/.test(text)?text.slice(0,5):'';}
  function blankDay(day){return {day_of_week:day,start_time:'',end_time:'',is_closed:0};}
  function normalizedSchedule(storeId){
    const map=scheduleMap().get(Number(storeId))||new Map();
    return days.map(([day])=>{
      const row=map.get(day)||blankDay(day);
      return {day_of_week:day,start_time:timeValue(row.start_time),end_time:timeValue(row.end_time),is_closed:Number(row.is_closed)===1?1:0};
    });
  }
  function weekStartDay(){const value=Number(document.getElementById('storeWeekStartDay')?.value||1);return value>=1&&value<=7?value:1;}
  function orderedDays(){const start=weekStartDay(),index=days.findIndex(([day])=>day===start);return index<0?days:[...days.slice(index),...days.slice(0,index)];}

  function renderRows(schedule){
    const root=document.getElementById('timingRows');if(!root)return;
    const byDay=new Map(schedule.map(row=>[Number(row.day_of_week),row]));
    root.innerHTML=orderedDays().map(([day,label])=>{
      const row=byDay.get(day)||blankDay(day),closed=Number(row.is_closed)===1;
      return `<div class="timing-grid timing-row" data-day="${day}"><strong>${label}</strong>
        <label class="timing-closed"><input type="checkbox" class="timing-closed-input" ${closed?'checked':''}><span>Closed</span></label>
        <label><span class="mobile-field-label">Start</span><input type="time" class="timing-start" value="${esc(row.start_time)}" ${closed?'disabled':''}></label>
        <label><span class="mobile-field-label">End</span><input type="time" class="timing-end" value="${esc(row.end_time)}" ${closed?'disabled':''}></label></div>`;
    }).join('');
    root.querySelectorAll('.timing-closed-input').forEach(input=>input.addEventListener('change',event=>{
      event.target.closest('.timing-row')?.querySelectorAll('.timing-start,.timing-end').forEach(field=>{field.disabled=event.target.checked;});
    }));
  }

  function currentStore(){return (state?.stores||[]).find(store=>Number(store.id)===Number(currentStoreId))||null;}
  function renderSelectedSchedule(){
    if(!state||!currentStoreId)return;
    renderRows(normalizedSchedule(currentStoreId));
    const first=orderedDays()[0]?.[1]||'first day';
    const copy=document.getElementById('copyFirstDayBtn');
    if(copy)copy.textContent=`Copy ${first} to all days`;
  }

  function copyFirstDayToAll(){
    const first=document.querySelector('#storeTimingsSection .timing-row');if(!first)return;
    const closed=first.querySelector('.timing-closed-input').checked;
    const start=first.querySelector('.timing-start').value,end=first.querySelector('.timing-end').value;
    document.querySelectorAll('#storeTimingsSection .timing-row').forEach(row=>{
      const closedInput=row.querySelector('.timing-closed-input'),startInput=row.querySelector('.timing-start'),endInput=row.querySelector('.timing-end');
      closedInput.checked=closed;startInput.disabled=closed;endInput.disabled=closed;
      startInput.value=closed?'':start;endInput.value=closed?'':end;
    });
  }

  function collectDays(){
    return Array.from(document.querySelectorAll('#storeTimingsSection .timing-row')).map(row=>{
      const closed=row.querySelector('.timing-closed-input').checked;
      return {day_of_week:Number(row.dataset.day),is_closed:closed?1:0,start_time:closed?'':row.querySelector('.timing-start').value,end_time:closed?'':row.querySelector('.timing-end').value};
    }).sort((a,b)=>a.day_of_week-b.day_of_week);
  }

  function setStatus(message,error=false){
    const root=document.getElementById('timingStatus');if(!root)return;
    root.textContent=message||'';root.classList.toggle('is-error',error);
  }

  async function saveTimings(){
    if(!state||!currentStoreId)return;
    const daysPayload=collectDays();
    for(const row of daysPayload){
      if(!row.is_closed&&(!row.start_time||!row.end_time)){setStatus('Every open day needs both a start time and an end time.',true);return;}
    }
    const button=document.getElementById('saveTimingsBtn');if(button)button.disabled=true;
    try{
      state=await api('api/store_timings.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:'save_timings',csrf:state.csrf,scope:'store',store_id:Number(currentStoreId),week_start_day:weekStartDay(),days:daysPayload})});
      renderSelectedSchedule();setStatus(state.message||'Store timings saved.');
    }catch(error){setStatus(error.message,true);}finally{if(button)button.disabled=false;}
  }

  function openStore(storeId){
    createSection();currentStoreId=storeId?Number(storeId):null;
    const section=document.getElementById('storeTimingsSection'),save=document.getElementById('saveTimingsBtn'),copy=document.getElementById('copyFirstDayBtn');
    if(!section)return;
    const note=document.getElementById('timingScopeNote');
    if(!currentStoreId){if(note)note.textContent='Save the store first, then reopen it to configure weekly timings.';document.getElementById('timingRows').innerHTML='';if(save)save.disabled=true;if(copy)copy.disabled=true;return;}
    if(save)save.disabled=false;if(copy)copy.disabled=false;
    const store=currentStore();if(store&&storeForm.elements.week_start_day)storeForm.elements.week_start_day.value=String(store.week_start_day||1);
    if(note)note.textContent='Changes apply to this store only. Week start day changes the editing order, not payroll calculations.';
    if(state)renderSelectedSchedule();else setStatus('Loading timings…');
  }

  async function load(){
    createSection();setStatus('Loading timings…');
    try{
      state=await api('api/store_timings.php');
      setStatus('');
      if(storeDialog.open)openStore(storeForm.elements.id?.value||null);
    }catch(error){setStatus(error.message,true);}
  }

  ensureStyles();
  createSection();
  window.MERDPOSStoreTimings=Object.freeze({openStore,refresh:load});
  load();
})();
