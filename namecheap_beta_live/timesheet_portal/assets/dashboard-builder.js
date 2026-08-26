(function () {
  'use strict';

  const panel = document.getElementById('dashboardPanel');
  if (!panel || document.querySelector('.merd-dashboard-builder')) return;

  const COLS = 12, GAP = 12, ROW = 72, ROW_STEP = ROW + GAP;
  const desktop = window.matchMedia('(min-width: 821px)');
  const defs = {
    working_now_count:{title:'Working now',desc:'Live number of employees clocked in.',w:3,h:2,minW:2,minH:2,maxW:4,maxH:3},
    pending_disputes:{title:'Pending disputes',desc:'Attendance disputes waiting for action.',w:3,h:2,minW:2,minH:2,maxW:4,maxH:3},
    active_employees:{title:'Active employees',desc:'Active workforce in the working client.',w:3,h:2,minW:2,minH:2,maxW:4,maxH:3},
    sync_attention:{title:'Sync attention',desc:'Pending or failed sync items needing attention.',w:3,h:2,minW:2,minH:2,maxW:4,maxH:3},
    working_now:{title:'Who is working now',desc:'Live employee and store attendance.',w:5,h:4,minW:4,minH:3,maxW:8,maxH:7},
    workforce_by_store:{title:'Workforce by store',desc:'Open shifts grouped by Store ID.',w:5,h:4,minW:4,minH:3,maxW:8,maxH:7},
    store_cash_position:{title:'Store cash position',desc:'Register plus Petty Cash by store.',w:5,h:4,minW:4,minH:3,maxW:8,maxH:7},
    cash_mix:{title:'Register vs Petty Cash',desc:'Current cash mix for the working client.',w:4,h:4,minW:3,minH:3,maxW:6,maxH:6},
    today_sales_by_store:{title:"Today's sales by store",desc:'Completed retail sales grouped by store.',w:5,h:4,minW:4,minH:3,maxW:8,maxH:7},
    recent_attendance:{title:'Recent attendance',desc:'Latest attendance visible at this role LOA.',w:8,h:5,minW:5,minH:4,maxW:12,maxH:9},
    my_shift:{title:'My current shift',desc:'Your current QR attendance status.',w:4,h:2,minW:3,minH:2,maxW:6,maxH:4},
    my_disputes:{title:'My open disputes',desc:'Your pending attendance corrections.',w:4,h:3,minW:3,minH:2,maxW:6,maxH:5},
  };

  const esc = value => String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot',"'":'&#39;'}[c]));
  const num = value => Number.isFinite(Number(value)) ? Number(value) : 0;
  let layoutApi = null, data = null, layout = [], saveTimer = null, currentRoleId = null;

  panel.classList.add('dashboard-builder-active');
  const builder = document.createElement('section');
  builder.className = 'merd-dashboard-builder';
  builder.innerHTML = `
    <div class="dashboard-rolebar" id="dashboardRolebar">
      <div><span class="dashboard-rolebar-label">Dashboard role</span><strong id="dashboardRoleTitle">Loading…</strong></div>
      <div class="dashboard-role-controls"><select id="dashboardRoleSelect" aria-label="Select dashboard role" hidden></select><span id="dashboardRoleLoa" class="dashboard-role-loa"></span></div>
    </div>
    <div id="dashboardCanvas" class="dashboard-canvas" aria-label="Role dashboard"></div>
    <div id="dashboardSaveState" class="dashboard-save-state" aria-live="polite"></div>
    <button id="dashboardAddButton" class="dashboard-add-button" type="button" aria-label="Add dashboard widget" aria-expanded="false" hidden>+</button>
    <aside id="dashboardWidgetDrawer" class="dashboard-widget-drawer" aria-label="Add widgets" aria-hidden="true">
      <div class="dashboard-drawer-head"><h2>Add widget</h2><p>Only widgets inside the selected role LOA are available.</p></div>
      <label class="dashboard-widget-search"><span aria-hidden="true">⌕</span><input id="dashboardWidgetSearch" type="search" placeholder="Search widgets"></label>
      <div id="dashboardWidgetCatalog" class="dashboard-widget-catalog"></div>
      <div class="dashboard-drawer-foot"><button id="dashboardReset" class="dashboard-reset" type="button">Clear this role dashboard</button></div>
    </aside>`;
  panel.insertBefore(builder, panel.firstChild);

  const canvas = document.getElementById('dashboardCanvas');
  const roleSelect = document.getElementById('dashboardRoleSelect');
  const roleTitle = document.getElementById('dashboardRoleTitle');
  const roleLoa = document.getElementById('dashboardRoleLoa');
  const addButton = document.getElementById('dashboardAddButton');
  const drawer = document.getElementById('dashboardWidgetDrawer');
  const catalog = document.getElementById('dashboardWidgetCatalog');
  const search = document.getElementById('dashboardWidgetSearch');
  const saveState = document.getElementById('dashboardSaveState');
  const resetButton = document.getElementById('dashboardReset');

  async function json(url, options={}) {
    const response = await fetch(url,{cache:'no-store',...options});
    const text = await response.text();
    let payload = null;
    try { payload = text ? JSON.parse(text) : null; } catch (_) { throw new Error(`Dashboard API returned invalid data (${response.status}).`); }
    if (!payload) throw new Error(`Dashboard API returned an empty response (${response.status}).`);
    if (!payload.success) throw new Error(payload.error || 'Dashboard request failed.');
    return payload;
  }

  function showSave(message,error=false){
    saveState.textContent=message; saveState.style.color=error?'#B42318':'#63758C'; saveState.classList.add('show');
    window.clearTimeout(showSave.timer); showSave.timer=window.setTimeout(()=>saveState.classList.remove('show'),1500);
  }

  function money(value,code){
    const currency=String(code||data?.client_defaults?.currency_code||'AUD').toUpperCase();
    try{return Number(value||0).toLocaleString(undefined,{style:'currency',currency,maximumFractionDigits:0});}
    catch(_){return `${currency} ${Number(value||0).toFixed(0)}`;}
  }

  function localTime(value,timezone){
    if(!value)return '—';
    const date=new Date(String(value).replace(' ','T')+'Z'); if(Number.isNaN(date.getTime()))return String(value);
    const options={day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}; if(timezone)options.timeZone=timezone;
    try{return date.toLocaleString([],options);}catch(_){delete options.timeZone;return date.toLocaleString([],options);}
  }

  function pendingDisputes(){return (data?.disputes||[]).filter(row=>String(row.status)==='pending').length;}
  function openMyDisputes(){return (data?.disputes||[]).filter(row=>['pending','awaiting_employee'].includes(String(row.status))).length;}

  function bars(rows,labelFn,valueFn,valueLabelFn){
    if(!rows.length)return '<div class="dashboard-empty-widget">No data yet.</div>';
    const max=Math.max(1,...rows.map(valueFn));
    return `<div class="dashboard-bars">${rows.map(row=>{const value=valueFn(row),pct=Math.max(value>0?3:0,(value/max)*100);return `<div class="dashboard-bar-row"><div class="dashboard-bar-label">${esc(labelFn(row))}</div><div class="dashboard-bar-track"><div class="dashboard-bar-fill" style="width:${pct.toFixed(1)}%"></div></div><div class="dashboard-bar-value">${esc(valueLabelFn(row,value))}</div></div>`;}).join('')}</div>`;
  }

  function renderBody(key){
    if(!data)return '<div class="dashboard-empty-widget">Loading…</div>';
    const management=data.management||{}, timezone=management.timezone||data.client_defaults?.timezone||null;
    if(key==='working_now_count')return `<div class="dashboard-kpi"><strong>${num(data.working?.length)}</strong><span>Working now</span><small>Live QR attendance</small></div>`;
    if(key==='pending_disputes')return `<div class="dashboard-kpi"><strong>${pendingDisputes()}</strong><span>Pending disputes</span><small>Waiting for review</small></div>`;
    if(key==='active_employees')return `<div class="dashboard-kpi"><strong>${num(management.active_employees)}</strong><span>Active employees</span><small>Working client</small></div>`;
    if(key==='sync_attention')return `<div class="dashboard-kpi"><strong>${num(management.sync_attention)}</strong><span>Sync attention</span><small>Pending / failed outbox</small></div>`;
    if(key==='my_shift'){const shift=(data.working||[])[0];return shift?`<div class="dashboard-kpi"><strong>Clocked in</strong><span>${esc(shift.store_name||'')}</span><small>Since ${esc(localTime(shift.clock_in_at,timezone))}</small></div>`:'<div class="dashboard-kpi"><strong>Off shift</strong><span>Not clocked in</span><small>Scan a store QR to start</small></div>';}
    if(key==='my_disputes')return `<div class="dashboard-kpi"><strong>${openMyDisputes()}</strong><span>Open disputes</span><small>Your attendance corrections</small></div>`;
    if(key==='working_now'){const rows=data.working||[];if(!rows.length)return '<div class="dashboard-empty-widget">Nobody is clocked in.</div>';return `<div class="dashboard-list">${rows.slice(0,30).map(row=>`<div class="dashboard-list-row"><div><strong>${esc(row.full_name)}</strong><span>${esc(row.store_name)}</span></div><small>${esc(localTime(row.clock_in_at,row.timezone||timezone))}</small></div>`).join('')}</div>`;}
    if(key==='workforce_by_store'){const stores=(data.stores||[]).slice().sort((a,b)=>num(a.id)-num(b.id));const counts=new Map(stores.map(s=>[String(s.store_name),0]));(data.working||[]).forEach(r=>counts.set(String(r.store_name),(counts.get(String(r.store_name))||0)+1));return bars(stores.map(s=>({store_name:s.store_name,count:counts.get(String(s.store_name))||0})),r=>r.store_name,r=>num(r.count),(r,v)=>String(v));}
    if(key==='store_cash_position'){const rows=(management.financial_by_store||[]).slice().sort((a,b)=>num(a.store_id)-num(b.store_id));return bars(rows,r=>r.store_name,r=>num(r.register_balance)+num(r.petty_balance),(r,v)=>money(v,r.currency_code||management.currency_code));}
    if(key==='today_sales_by_store'){const rows=(management.sales_by_store||[]).slice().sort((a,b)=>num(a.store_id)-num(b.store_id));return bars(rows,r=>r.store_name,r=>num(r.today_sales),(r,v)=>money(v,r.currency_code||management.currency_code));}
    if(key==='cash_mix'){const rows=management.financial_by_store||[],currencies=new Set(rows.map(r=>String(r.currency_code||management.currency_code||'AUD').toUpperCase()));if(currencies.size>1)return '<div class="dashboard-empty-widget">Mixed store currencies cannot be combined.</div>';const code=[...currencies][0]||management.currency_code||'AUD',register=rows.reduce((s,r)=>s+num(r.register_balance),0),petty=rows.reduce((s,r)=>s+num(r.petty_balance),0),total=register+petty,stop=total>0?register/total*100:50;return `<div class="dashboard-ring-wrap"><div class="dashboard-ring" style="--ring-stop:${stop.toFixed(2)}%"></div><div class="dashboard-ring-legend"><div><span class="dashboard-ring-dot"></span><span>Register</span><strong>${esc(money(register,code))}</strong></div><div><span class="dashboard-ring-dot petty"></span><span>Petty Cash</span><strong>${esc(money(petty,code))}</strong></div><div><span></span><span>Total</span><strong>${esc(money(total,code))}</strong></div></div></div>`;}
    if(key==='recent_attendance'){const rows=(data.recent_shifts||[]).slice(0,30);if(!rows.length)return '<div class="dashboard-empty-widget">No recent attendance.</div>';return `<table class="dashboard-mini-table"><thead><tr><th>Employee</th><th>Store</th><th>In</th><th>Out</th></tr></thead><tbody>${rows.map(r=>`<tr><td>${esc(r.full_name)}</td><td>${esc(r.store_name)}</td><td>${esc(localTime(r.clock_in_at,r.timezone||timezone))}</td><td>${esc(localTime(r.clock_out_at,r.timezone||timezone))}</td></tr>`).join('')}</tbody></table>`;}
    return '<div class="dashboard-empty-widget">Widget unavailable.</div>';
  }

  function normalized(item){const def=defs[item.widget_key]||{w:4,h:3,minW:2,minH:2,maxW:12,maxH:20};const w=Math.max(def.minW,Math.min(def.maxW,num(item.grid_w)||def.w)),h=Math.max(def.minH,Math.min(def.maxH,num(item.grid_h)||def.h));return{widget_key:item.widget_key,grid_x:Math.max(0,Math.min(COLS-w,num(item.grid_x))),grid_y:Math.max(0,num(item.grid_y)),grid_w:w,grid_h:h};}
  function collision(key,x,y,w,h){return layout.some(item=>item.widget_key!==key&&x<item.grid_x+item.grid_w&&x+w>item.grid_x&&y<item.grid_y+item.grid_h&&y+h>item.grid_y);}
  function firstOpenPosition(w,h,key=''){for(let y=0;y<1000;y++)for(let x=0;x<=COLS-w;x++)if(!collision(key,x,y,w,h))return{x,y};return{x:0,y:0};}
  function setPos(tile,item){tile.style.gridColumn=`${item.grid_x+1} / span ${item.grid_w}`;tile.style.gridRow=`${item.grid_y+1} / span ${item.grid_h}`;}
  function metrics(){const rect=canvas.getBoundingClientRect(),col=(rect.width-GAP*(COLS-1))/COLS,stepX=col+GAP;canvas.style.setProperty('--db-col-step',`${stepX}px`);canvas.style.setProperty('--db-row-step',`${ROW_STEP}px`);return{rect,stepX,stepY:ROW_STEP};}

  function saveSoon(){if(!layoutApi?.can_edit)return;window.clearTimeout(saveTimer);saveTimer=window.setTimeout(saveLayout,280);}
  async function saveLayout(){
    try{layoutApi=await json('api/dashboard_layout.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_layout',role_id:currentRoleId,csrf:layoutApi.csrf,layout})});layout=(layoutApi.layout||[]).map(normalized);showSave('Saved');}
    catch(error){showSave(error.message,true);}
  }

  function bindMove(tile,item){
    if(!layoutApi?.can_edit)return;
    const head=tile.querySelector('.dashboard-widget-head');
    head.addEventListener('pointerdown',event=>{
      if(!desktop.matches||event.button!==0||event.target.closest('button'))return;
      event.preventDefault();const m=metrics(),sx=event.clientX,sy=event.clientY,ox=item.grid_x,oy=item.grid_y;canvas.classList.add('is-editing');tile.classList.add('is-dragging');head.setPointerCapture?.(event.pointerId);
      const move=e=>{const x=Math.max(0,Math.min(COLS-item.grid_w,Math.round(ox+(e.clientX-sx)/m.stepX))),y=Math.max(0,Math.round(oy+(e.clientY-sy)/m.stepY));if(!collision(item.widget_key,x,y,item.grid_w,item.grid_h)){item.grid_x=x;item.grid_y=y;setPos(tile,item);}};
      const up=()=>{head.removeEventListener('pointermove',move);head.removeEventListener('pointerup',up);head.removeEventListener('pointercancel',up);canvas.classList.remove('is-editing');tile.classList.remove('is-dragging');saveSoon();};
      head.addEventListener('pointermove',move);head.addEventListener('pointerup',up);head.addEventListener('pointercancel',up);
    });
  }

  function bindResize(tile,item){
    if(!layoutApi?.can_edit)return;
    const handle=tile.querySelector('.dashboard-widget-resize'); if(!handle)return;
    handle.addEventListener('pointerdown',event=>{
      if(!desktop.matches||event.button!==0)return;event.preventDefault();event.stopPropagation();const def=defs[item.widget_key],m=metrics(),sx=event.clientX,sy=event.clientY,ow=item.grid_w,oh=item.grid_h;canvas.classList.add('is-editing');tile.classList.add('is-resizing');handle.setPointerCapture?.(event.pointerId);
      const move=e=>{let w=Math.max(def.minW,Math.min(def.maxW,Math.round(ow+(e.clientX-sx)/m.stepX))),h=Math.max(def.minH,Math.min(def.maxH,Math.round(oh+(e.clientY-sy)/m.stepY)));w=Math.min(w,COLS-item.grid_x);if(!collision(item.widget_key,item.grid_x,item.grid_y,w,h)){item.grid_w=w;item.grid_h=h;setPos(tile,item);}};
      const up=()=>{handle.removeEventListener('pointermove',move);handle.removeEventListener('pointerup',up);handle.removeEventListener('pointercancel',up);canvas.classList.remove('is-editing');tile.classList.remove('is-resizing');saveSoon();};
      handle.addEventListener('pointermove',move);handle.addEventListener('pointerup',up);handle.addEventListener('pointercancel',up);
    });
  }

  function renderCanvas(){
    canvas.innerHTML='';
    if(!layout.length){canvas.innerHTML='<div class="dashboard-empty-widget dashboard-empty-canvas">No widgets on this role dashboard.</div>';renderCatalog();return;}
    layout.forEach(item=>{const def=defs[item.widget_key];if(!def)return;const tile=document.createElement('article');tile.className='dashboard-widget';tile.dataset.widget=item.widget_key;tile.innerHTML=`<div class="dashboard-widget-head"><span class="dashboard-widget-title">${esc(def.title)}</span><div class="dashboard-widget-actions">${layoutApi?.can_edit?'<button type="button" class="dashboard-widget-action" data-remove aria-label="Remove widget">×</button>':''}</div></div><div class="dashboard-widget-body">${renderBody(item.widget_key)}</div>${layoutApi?.can_edit?'<div class="dashboard-widget-resize" aria-hidden="true"></div>':''}`;setPos(tile,item);canvas.appendChild(tile);if(layoutApi?.can_edit){tile.querySelector('[data-remove]')?.addEventListener('click',()=>{layout=layout.filter(row=>row.widget_key!==item.widget_key);renderCanvas();saveSoon();});bindMove(tile,item);bindResize(tile,item);}});
    renderCatalog();
  }

  function renderCatalog(){
    if(!catalog||!layoutApi)return;const q=String(search?.value||'').trim().toLowerCase(),allowed=new Set(layoutApi.allowed_widgets||[]),added=new Set(layout.map(i=>i.widget_key));
    const rows=Object.entries(defs).filter(([key,def])=>allowed.has(key)&&(!q||`${def.title} ${def.desc}`.toLowerCase().includes(q)));
    catalog.innerHTML=rows.length?rows.map(([key,def])=>`<div class="dashboard-catalog-item ${added.has(key)?'is-added':''}"><div class="dashboard-catalog-copy"><strong>${esc(def.title)}</strong><span>${esc(def.desc)}</span></div><button type="button" class="dashboard-catalog-add" data-add-widget="${esc(key)}" ${added.has(key)?'disabled':''}>${added.has(key)?'✓':'+'}</button></div>`).join(''):'<div class="dashboard-empty-widget">No widgets available at this LOA.</div>';
    catalog.querySelectorAll('[data-add-widget]').forEach(button=>button.addEventListener('click',()=>addWidget(button.dataset.addWidget)));
  }

  function addWidget(key){if(!layoutApi?.can_edit||!defs[key]||!(layoutApi.allowed_widgets||[]).includes(key)||layout.some(i=>i.widget_key===key))return;const def=defs[key],pos=firstOpenPosition(def.w,def.h);layout.push({widget_key:key,grid_x:pos.x,grid_y:pos.y,grid_w:def.w,grid_h:def.h});renderCanvas();saveSoon();}

  function renderRolebar(){
    const role=layoutApi?.selected_role||{};currentRoleId=Number(role.id)||null;roleTitle.textContent=role.role_label||role.role_key||'Role';roleLoa.textContent=`LOA ${Number(role.authority_level||0)}`;
    const selectable=!!layoutApi?.can_select_role;roleSelect.hidden=!selectable;addButton.hidden=!layoutApi?.can_edit;resetButton.hidden=!layoutApi?.can_edit;
    if(selectable){const roles=(layoutApi.roles||[]).slice().sort((a,b)=>Number(a.authority_level)-Number(b.authority_level)||Number(a.id)-Number(b.id));roleSelect.innerHTML=roles.map(r=>`<option value="${Number(r.id)}" ${Number(r.id)===currentRoleId?'selected':''}>${esc(r.role_label)} · LOA ${Number(r.authority_level)}</option>`).join('');roleSelect.value=String(currentRoleId);}
  }

  async function loadRole(roleId=null,animate=true){
    const previous=layoutApi?.selected_role||{},previousLoa=Number(previous.authority_level||0);const url='api/dashboard_layout.php'+(roleId?`?role_id=${Number(roleId)}`:'');
    try{
      if(animate&&layoutApi){const target=(layoutApi.roles||[]).find(r=>Number(r.id)===Number(roleId)),dir=Number(target?.authority_level||0)>=previousLoa?'left':'right';canvas.classList.add(`role-slide-out-${dir}`);await new Promise(r=>setTimeout(r,150));canvas.className='dashboard-canvas';}
      layoutApi=await json(url,{headers:{'Accept':'application/json'}});layout=(layoutApi.layout||[]).map(normalized);renderRolebar();renderCanvas();
      if(animate&&previous.id){const dir=Number(layoutApi.selected_role?.authority_level||0)>=previousLoa?'right':'left';canvas.classList.add(`role-slide-in-${dir}`);requestAnimationFrame(()=>requestAnimationFrame(()=>canvas.classList.remove(`role-slide-in-${dir}`)));}
    }catch(error){canvas.innerHTML=`<div class="dashboard-empty-widget">${esc(error.message)}</div>`;showSave(error.message,true);}
  }

  async function loadData(){try{data=await json('api/beta_state.php?_='+Date.now(),{headers:{'Accept':'application/json'}});renderCanvas();}catch(error){showSave(error.message,true);}}

  function toggleDrawer(force=null){if(!layoutApi?.can_edit)return;const open=force===null?!drawer.classList.contains('open'):!!force;drawer.classList.toggle('open',open);drawer.setAttribute('aria-hidden',open?'false':'true');addButton.setAttribute('aria-expanded',open?'true':'false');if(open)renderCatalog();}

  roleSelect.addEventListener('change',()=>loadRole(Number(roleSelect.value),true));
  addButton.addEventListener('click',()=>toggleDrawer());
  search.addEventListener('input',renderCatalog);
  resetButton.addEventListener('click',async()=>{if(!layoutApi?.can_edit)return;if(!window.confirm(`Clear the ${layoutApi.selected_role?.role_label||'selected role'} dashboard?`))return;try{layoutApi=await json('api/dashboard_layout.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'reset_layout',role_id:currentRoleId,csrf:layoutApi.csrf})});layout=[];renderRolebar();renderCanvas();showSave('Dashboard cleared');}catch(error){showSave(error.message,true);}});
  document.addEventListener('pointerdown',event=>{if(drawer.classList.contains('open')&&!drawer.contains(event.target)&&!addButton.contains(event.target))toggleDrawer(false);});

  window.MERDPOSDashboardBuilder={reloadRoles:async()=>{const selected=currentRoleId;await loadRole(selected,false);},refreshData:loadData};

  Promise.all([loadRole(null,false),loadData()]);
  window.setInterval(loadData,60000);
})();
