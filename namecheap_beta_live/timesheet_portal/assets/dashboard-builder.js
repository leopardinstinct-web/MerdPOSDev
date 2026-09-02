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
    recent_attendance:{title:'Recent attendance',desc:'Latest attendance visible to this role.',w:8,h:5,minW:5,minH:4,maxW:12,maxH:9},
    my_shift:{title:'My current shift',desc:'Your current QR attendance status.',w:4,h:2,minW:3,minH:2,maxW:6,maxH:4},
    my_disputes:{title:'My open disputes',desc:'Your pending attendance corrections.',w:4,h:3,minW:3,minH:2,maxW:6,maxH:5},
    sales_change:{title:'Sales change',desc:"Today's completed sales compared with yesterday.",w:3,h:2,minW:3,minH:2,maxW:5,maxH:3},
    attendance_change:{title:'Attendance change',desc:"Today's clock-ins compared with yesterday.",w:3,h:2,minW:3,minH:2,maxW:5,maxH:3},
    sales_trend_7d:{title:'Sales — 7 day trend',desc:'Completed sales across the last seven business dates.',w:5,h:4,minW:4,minH:3,maxW:8,maxH:6},
    attendance_trend_7d:{title:'Attendance — 7 day trend',desc:'Clock-ins across the last seven business dates.',w:5,h:4,minW:4,minH:3,maxW:8,maxH:6},
    top_stores_sales:{title:'Top stores by sales',desc:"Stores ranked by today's completed sales.",w:5,h:4,minW:4,minH:3,maxW:8,maxH:7},
    sync_status_table:{title:'Sync status',desc:'Outbox exceptions grouped by current status.',w:5,h:4,minW:4,minH:3,maxW:7,maxH:6},
  };
  const presets = [
    {key:'store_operations',title:'Store operations',desc:'Live workforce, sales and exceptions.',widgets:['working_now_count','workforce_by_store','today_sales_by_store','pending_disputes','sync_attention','sync_status_table']},
    {key:'finance',title:'Finance',desc:'Sales movement, ranking and cash position.',widgets:['sales_change','sales_trend_7d','top_stores_sales','today_sales_by_store','store_cash_position','cash_mix']},
    {key:'workforce',title:'Workforce',desc:'Attendance movement and live workforce.',widgets:['working_now_count','attendance_change','attendance_trend_7d','workforce_by_store','working_now','recent_attendance']},
  ];

  const esc = value => String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const num = value => Number.isFinite(Number(value)) ? Number(value) : 0;
  const Analytics = window.MERDPOSAnalytics || null;
  const PERIODS = ['current_week',7,14,30];
  const filterState = { storeId:0, period:'7', days:7 };
  let layoutApi = null, data = null, layout = [], saveTimer = null, currentRoleId = null, editMode = false, studioEditMode = false;

  panel.classList.add('dashboard-builder-active');
  const builder = document.createElement('section');
  builder.className = 'merd-dashboard-builder';
  builder.innerHTML = `
    <header class="dashboard-page-head"><h2 class="ui-page-title">Dashboard</h2></header>
    <div class="dashboard-rolebar" id="dashboardRolebar">
      <div><span class="dashboard-rolebar-label">Dashboard role</span></div>
      <div class="dashboard-role-controls"><select id="dashboardRoleSelect" aria-label="Select dashboard role" hidden></select><button id="dashboardEditToggle" class="secondary-btn compact-btn dashboard-edit-toggle" type="button" aria-pressed="false" hidden>Edit dashboard</button><button id="dashboardAddButton" class="dashboard-add-button" type="button" aria-label="Add dashboard widget" aria-expanded="false" hidden>+</button></div>
    </div>
    <div id="dashboardAnalyticsToolbar" class="dashboard-analytics-toolbar" hidden>
      <label class="dashboard-filter-field"><span>Store</span><select id="dashboardStoreFilter" aria-label="Filter dashboard by store"><option value="0">All stores</option></select></label>
      <div class="dashboard-period-filter" role="group" aria-label="Reporting period"><span>Period</span><div>${PERIODS.map(period=>`<button type="button" data-dashboard-period="${period}" aria-pressed="${String(period)==='7'?'true':'false'}">${period==='current_week'?'Current week':`${period}D`}</button>`).join('')}</div></div>
      <span id="dashboardFilterSummary" class="dashboard-filter-summary" aria-live="polite"></span>
    </div>
    <div id="dashboardCanvas" class="dashboard-canvas" aria-label="Role dashboard"></div>
    <div id="dashboardSaveState" class="dashboard-save-state" aria-live="polite"></div>
    <aside id="dashboardWidgetDrawer" class="dashboard-widget-drawer" aria-label="Add widgets" aria-hidden="true">
      <div class="dashboard-drawer-head"><h2>Add widget</h2><p>Only widgets available to the selected role are shown.</p></div>
      <label class="dashboard-widget-search"><span aria-hidden="true">⌕</span><input id="dashboardWidgetSearch" type="search" placeholder="Search widgets"></label>
      <section id="dashboardWidgetTemplates" class="dashboard-widget-templates" aria-label="Quick dashboard templates" hidden>
        <div class="dashboard-template-label">Quick templates</div>
        <div id="dashboardTemplateList" class="dashboard-template-list"></div>
      </section>
      <div id="dashboardWidgetCatalog" class="dashboard-widget-catalog"></div>
      <div class="dashboard-drawer-foot"><button id="dashboardReset" class="dashboard-reset" type="button">Clear this role dashboard</button></div>
    </aside>`;
  panel.insertBefore(builder, panel.firstChild);

  const canvas = document.getElementById('dashboardCanvas');
  const roleSelect = document.getElementById('dashboardRoleSelect');
  const editToggle = document.getElementById('dashboardEditToggle');
  const addButton = document.getElementById('dashboardAddButton');
  const drawer = document.getElementById('dashboardWidgetDrawer');
  const catalog = document.getElementById('dashboardWidgetCatalog');
  const search = document.getElementById('dashboardWidgetSearch');
  const templateSection = document.getElementById('dashboardWidgetTemplates');
  const templateList = document.getElementById('dashboardTemplateList');
  const saveState = document.getElementById('dashboardSaveState');
  const resetButton = document.getElementById('dashboardReset');
  const analyticsToolbar = document.getElementById('dashboardAnalyticsToolbar');
  const storeFilter = document.getElementById('dashboardStoreFilter');
  const periodButtons = Array.from(document.querySelectorAll('[data-dashboard-period]'));
  const filterSummary = document.getElementById('dashboardFilterSummary');
  const periodFilter = analyticsToolbar?.querySelector('.dashboard-period-filter');
  const storeFilterField = storeFilter?.closest('.dashboard-filter-field');

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

  function shortDate(value){
    const date=new Date(`${String(value||'')}T00:00:00`);
    if(Number.isNaN(date.getTime()))return String(value||'');
    try{return date.toLocaleDateString(undefined,{weekday:'short',day:'numeric'});}catch(_){return String(value||'');}
  }

  function pendingDisputes(){return num(data?.pending_disputes_count);}
  function openMyDisputes(){return (data?.disputes||[]).filter(row=>['pending','awaiting_employee'].includes(String(row.status))).length;}

  function filterableWidgetKeys(){
    return new Set(['working_now_count','working_now','workforce_by_store','store_cash_position','cash_mix','today_sales_by_store','recent_attendance','sales_change','attendance_change','sales_trend_7d','attendance_trend_7d','top_stores_sales']);
  }

  function renderFilters(){
    if(!analyticsToolbar||!storeFilter||!filterSummary)return;
    const storeKeys=filterableWidgetKeys(),periodKeys=new Set(['sales_trend_7d','attendance_trend_7d']);
    const hasStoreFilter=layout.some(item=>storeKeys.has(item.widget_key)),hasPeriodFilter=layout.some(item=>periodKeys.has(item.widget_key));
    analyticsToolbar.hidden=!data||(!hasStoreFilter&&!hasPeriodFilter);
    if(analyticsToolbar.hidden)return;
    if(storeFilterField)storeFilterField.hidden=!hasStoreFilter;
    if(periodFilter)periodFilter.hidden=!hasPeriodFilter;
    const stores=Array.isArray(data?.filter_options?.stores)?data.filter_options.stores:[];
    const validIds=new Set(stores.map(row=>num(row.id)));
    if(filterState.storeId&&!validIds.has(filterState.storeId))filterState.storeId=0;
    storeFilter.innerHTML='<option value="0">All stores</option>'+stores.map(row=>`<option value="${num(row.id)}">${esc(row.store_name)}</option>`).join('');
    storeFilter.value=String(filterState.storeId||0);
    periodButtons.forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.dashboardPeriod)===filterState.period?'true':'false'));
    const selected=stores.find(row=>num(row.id)===filterState.storeId);
    const parts=[];if(hasStoreFilter)parts.push(selected?.store_name||'All stores');if(hasPeriodFilter)parts.push(data?.filters?.period_label||`${filterState.days} days`);
    filterSummary.textContent=parts.join(' | ');
  }

  function bars(rows,labelFn,valueFn,valueLabelFn,datasetId='dashboard-bars'){
    const source=Array.isArray(rows)?rows:[];
    if(!Analytics){if(!source.length)return '<div class="dashboard-empty-widget">No data yet.</div>';const max=Math.max(1,...source.map(valueFn));return `<div class="dashboard-bars">${source.map(row=>{const value=valueFn(row),pct=Math.max(value>0?3:0,(value/max)*100);return `<div class="dashboard-bar-row"><div class="dashboard-bar-label">${esc(labelFn(row))}</div><div class="dashboard-bar-track"><div class="dashboard-bar-fill" style="width:${pct.toFixed(1)}%"></div></div><div class="dashboard-bar-value">${esc(valueLabelFn(row,value))}</div></div>`;}).join('')}</div>`;}
    const rowsForChart=source.map(row=>{const value=num(valueFn(row));return{label:String(labelFn(row)),value,display:String(valueLabelFn(row,value)),store_id:num(row.store_id??row.id)};});
    const table=Analytics.dataset(datasetId,[{key:'label',label:'Category'},{key:'value',label:'Value',type:'number'},{key:'display',label:'Display'},{key:'store_id',label:'Store',type:'number'}],rowsForChart,{label:datasetId});
    return Analytics.bar(table,{labelKey:'label',valueKey:'value',formatValue:(value,row)=>row.display,payload:row=>row.store_id?{storeId:row.store_id}:{},ariaLabel:datasetId});
  }

  function changeKpi(rows,formatValue,label,neutral=false){
    const series=Array.isArray(rows)?rows:[],current=num(series.at(-1)?.value),previous=num(series.at(-2)?.value),delta=current-previous;
    const direction=delta>0?'up':delta<0?'down':'flat',arrow=delta>0?'↑':delta<0?'↓':'→';
    let relative='No change';
    if(previous!==0)relative=`${delta>0?'+':''}${((delta/Math.abs(previous))*100).toFixed(1)}%`;
    else if(current!==0)relative='New activity';
    const absolute=delta===0?'Same as yesterday':`${delta>0?'+':''}${formatValue(delta)} vs yesterday`;
    return `<div class="dashboard-change-kpi ${neutral?'is-neutral':`is-${direction}`}"><div class="dashboard-change-main"><strong>${esc(formatValue(current))}</strong><span>${esc(label)}</span></div><div class="dashboard-change-badge"><span aria-hidden="true">${arrow}</span><strong>${esc(relative)}</strong></div><small>${esc(absolute)}</small></div>`;
  }

  function trendChart(rows,formatValue,label,kind='sales'){
    const series=Array.isArray(rows)?rows:[];
    if(!Analytics){if(!series.length)return '<div class="dashboard-empty-widget">No trend data yet.</div>';return `<div class="dashboard-empty-widget">${esc(label)}</div>`;}
    const chartRows=series.map(row=>({label:shortDate(row.date),date:String(row.date||''),value:num(row.value)}));
    const table=Analytics.dataset(`dashboard-${kind}-trend`,[{key:'label',label:'Date'},{key:'date',label:'Date key'},{key:'value',label:'Value',type:'number'}],chartRows,{label});
    return Analytics.line(table,{labelKey:'label',valueKey:'value',formatValue:(value)=>formatValue(value),payload:row=>({date:row.date}),ariaLabel:label});
  }

  function topStores(rows,currencyCode){
    const ranked=(Array.isArray(rows)?rows:[]).slice().sort((a,b)=>num(b.today_sales)-num(a.today_sales)||num(a.store_id)-num(b.store_id)).slice(0,5);
    return bars(ranked,row=>row.store_name,row=>num(row.today_sales),(row,value)=>money(value,row.currency_code||currencyCode),'top-stores-sales');
  }

  function syncStatusTable(rows){
    const source=Array.isArray(rows)?rows:[],byStatus=new Map(source.map(row=>[String(row.status),num(row.count)])),ordered=['failed','processing','pending'].map(status=>({status,count:byStatus.get(status)||0})),total=ordered.reduce((sum,row)=>sum+row.count,0),labels={failed:'Failed',processing:'Processing',pending:'Pending'},tones={failed:'danger',processing:'warning',pending:'info'};
    return `<div class="dashboard-status-wrap">${total===0?'<div class="dashboard-status-clear">All sync queues clear</div>':''}<table class="dashboard-status-table"><thead><tr><th>Status</th><th>Items</th></tr></thead><tbody>${ordered.map(row=>`<tr class="${row.count>0?`is-${tones[row.status]}`:'is-clear'}"><td><span class="dashboard-status-dot" aria-hidden="true"></span>${esc(labels[row.status])}</td><td>${row.count}</td></tr>`).join('')}</tbody></table></div>`;
  }

  function renderBody(key){
    if(!data)return '<div class="dashboard-empty-widget">Loading…</div>';
    const management=data.management||{}, analytics=management.analytics||{}, timezone=management.timezone||data.client_defaults?.timezone||null;
    if(key==='working_now_count')return `<div class="dashboard-kpi"><strong>${num(data.working_count)}</strong><span>Working now</span><small>Live QR attendance</small></div>`;
    if(key==='pending_disputes')return `<div class="dashboard-kpi"><strong>${pendingDisputes()}</strong><span>Pending disputes</span><small>Waiting for review</small></div>`;
    if(key==='active_employees')return `<div class="dashboard-kpi"><strong>${num(management.active_employees)}</strong><span>Active employees</span><small>Working client</small></div>`;
    if(key==='sync_attention')return `<div class="dashboard-kpi"><strong>${num(management.sync_attention)}</strong><span>Sync attention</span><small>Pending / failed outbox</small></div>`;
    if(key==='sales_change')return changeKpi(analytics.sales_period||[],value=>money(value,management.currency_code),'Completed sales');
    if(key==='attendance_change')return changeKpi(analytics.attendance_period||[],value=>String(Math.round(num(value))),'Clock-ins',true);
    if(key==='sales_trend_7d')return trendChart(analytics.sales_period||[],value=>money(value,management.currency_code),`Completed sales over the selected ${filterState.days} days`,'sales');
    if(key==='attendance_trend_7d')return trendChart(analytics.attendance_period||[],value=>String(Math.round(num(value))),`Clock-ins over the selected ${filterState.days} days`,'attendance');
    if(key==='top_stores_sales')return topStores(management.sales_by_store||[],management.currency_code);
    if(key==='sync_status_table')return syncStatusTable(analytics.sync_statuses||[]);
    if(key==='my_shift'){const shift=(data.my_working||[])[0];return shift?`<div class="dashboard-kpi"><strong>Clocked in</strong><span>${esc(shift.store_name||'')}</span><small>Since ${esc(localTime(shift.clock_in_at,timezone))}</small></div>`:'<div class="dashboard-kpi"><strong>Off shift</strong><span>Not clocked in</span><small>Scan a store QR to start</small></div>';}
    if(key==='my_disputes')return `<div class="dashboard-kpi"><strong>${openMyDisputes()}</strong><span>Open disputes</span><small>Your attendance corrections</small></div>`;
    if(key==='working_now'){const rows=data.working||[];if(!rows.length)return '<div class="dashboard-empty-widget">Nobody is clocked in.</div>';return `<div class="dashboard-list">${rows.slice(0,30).map(row=>`<div class="dashboard-list-row"><div><strong>${esc(row.full_name)}</strong><span>${esc(row.store_name)}</span></div><small>${esc(localTime(row.clock_in_at,row.timezone||timezone))}</small></div>`).join('')}</div>`;}
    if(key==='workforce_by_store'){const stores=(data.stores||[]).slice().sort((a,b)=>num(a.id)-num(b.id));const counts=new Map(stores.map(s=>[String(s.store_name),0]));(data.working||[]).forEach(r=>counts.set(String(r.store_name),(counts.get(String(r.store_name))||0)+1));return bars(stores.map(s=>({store_id:s.id,store_name:s.store_name,count:counts.get(String(s.store_name))||0})),r=>r.store_name,r=>num(r.count),(r,v)=>String(v),'workforce-by-store');}
    if(key==='store_cash_position'){const rows=(management.financial_by_store||[]).slice().sort((a,b)=>num(a.store_id)-num(b.store_id));return bars(rows,r=>r.store_name,r=>num(r.register_balance)+num(r.petty_balance),(r,v)=>money(v,r.currency_code||management.currency_code),'store-cash-position');}
    if(key==='today_sales_by_store'){const rows=(management.sales_by_store||[]).slice().sort((a,b)=>num(a.store_id)-num(b.store_id));return bars(rows,r=>r.store_name,r=>num(r.today_sales),(r,v)=>money(v,r.currency_code||management.currency_code),'today-sales-by-store');}
    if(key==='cash_mix'){
      const rows=management.financial_by_store||[],currencies=new Set(rows.map(r=>String(r.currency_code||management.currency_code||'AUD').toUpperCase()));
      if(currencies.size>1)return '<div class="dashboard-empty-widget">Mixed store currencies cannot be combined.</div>';
      const code=[...currencies][0]||management.currency_code||'AUD',register=rows.reduce((sum,row)=>sum+num(row.register_balance),0),petty=rows.reduce((sum,row)=>sum+num(row.petty_balance),0);
      if(!Analytics){const total=register+petty,stop=total>0?register/total*100:50;return `<div class="dashboard-ring-wrap"><div class="dashboard-ring" style="--ring-stop:${stop.toFixed(2)}%"></div><div class="dashboard-ring-legend"><div><span class="dashboard-ring-dot"></span><span>Register</span><strong>${esc(money(register,code))}</strong></div><div><span class="dashboard-ring-dot petty"></span><span>Petty Cash</span><strong>${esc(money(petty,code))}</strong></div></div></div>`;}
      const table=Analytics.dataset('cash-mix',[{key:'label',label:'Account'},{key:'value',label:'Balance',type:'number'}],[{label:'Register',value:register},{label:'Petty Cash',value:petty}],{label:'Register versus Petty Cash'});
      return Analytics.donut(table,{labelKey:'label',valueKey:'value',formatValue:value=>money(value,code),ariaLabel:'Register versus Petty Cash'});
    }
    if(key==='recent_attendance'){const rows=(data.recent_shifts||[]).slice(0,30);if(!rows.length)return '<div class="dashboard-empty-widget">No recent attendance.</div>';return `<table class="dashboard-mini-table"><thead><tr><th>Employee</th><th>Store</th><th>In</th><th>Out</th></tr></thead><tbody>${rows.map(r=>`<tr><td>${esc(r.full_name)}</td><td>${esc(r.store_name)}</td><td>${esc(localTime(r.clock_in_at,r.timezone||timezone))}</td><td>${esc(localTime(r.clock_out_at,r.timezone||timezone))}</td></tr>`).join('')}</tbody></table>`;}
    return '<div class="dashboard-empty-widget">Widget unavailable.</div>';
  }

  function normalized(item){const def=defs[item.widget_key]||{w:4,h:3,minW:2,minH:2,maxW:12,maxH:20};const w=Math.max(def.minW,Math.min(def.maxW,num(item.grid_w)||def.w)),h=Math.max(def.minH,Math.min(def.maxH,num(item.grid_h)||def.h));return{widget_key:item.widget_key,grid_x:Math.max(0,Math.min(COLS-w,num(item.grid_x))),grid_y:Math.max(0,num(item.grid_y)),grid_w:w,grid_h:h};}
  function overlaps(items,key,x,y,w,h){return items.some(item=>item.widget_key!==key&&x<item.grid_x+item.grid_w&&x+w>item.grid_x&&y<item.grid_y+item.grid_h&&y+h>item.grid_y);}
  function collision(key,x,y,w,h){return overlaps(layout,key,x,y,w,h);}
  function compactLayout(items){
    const ordered=(items||[]).map((item,index)=>({...normalized(item),_order:index})).sort((a,b)=>a.grid_y-b.grid_y||a.grid_x-b.grid_x||a._order-b._order),placed=[];
    ordered.forEach(item=>{let found=null;for(let y=0;y<1000&&!found;y++)for(let x=0;x<=COLS-item.grid_w;x++)if(!overlaps(placed,item.widget_key,x,y,item.grid_w,item.grid_h)){found={x,y};break;}if(found){item.grid_x=found.x;item.grid_y=found.y;}delete item._order;placed.push(item);});
    return placed;
  }
  function firstOpenPosition(w,h,key=''){for(let y=0;y<1000;y++)for(let x=0;x<=COLS-w;x++)if(!collision(key,x,y,w,h))return{x,y};return{x:0,y:0};}
  function setPos(tile,item){tile.style.gridColumn=`${item.grid_x+1} / span ${item.grid_w}`;tile.style.gridRow=`${item.grid_y+1} / span ${item.grid_h}`;}
  function metrics(){const rect=canvas.getBoundingClientRect(),col=(rect.width-GAP*(COLS-1))/COLS,stepX=col+GAP;canvas.style.setProperty('--db-col-step',`${stepX}px`);canvas.style.setProperty('--db-row-step',`${ROW_STEP}px`);return{rect,stepX,stepY:ROW_STEP};}

  function saveSoon(){if(!layoutApi?.can_edit)return;window.clearTimeout(saveTimer);saveTimer=window.setTimeout(saveLayout,280);}
  async function saveLayout(){
    try{layout=compactLayout(layout);layoutApi=await json('api/dashboard_layout.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_layout',role_id:currentRoleId,csrf:layoutApi.csrf,layout,dev_studio:studioEditMode?1:0})});layout=compactLayout(layoutApi.layout||[]);renderCanvas();showSave('Saved');}
    catch(error){showSave(error.message,true);}
  }

  function bindMove(tile,item){
    if(!layoutApi?.can_edit||!editMode)return;
    const head=tile.querySelector('.dashboard-widget-head');
    head.addEventListener('pointerdown',event=>{
      if(!desktop.matches||event.button!==0||event.target.closest('button'))return;
      event.preventDefault();const m=metrics(),sx=event.clientX,sy=event.clientY,ox=item.grid_x,oy=item.grid_y;canvas.classList.add('is-editing');tile.classList.add('is-dragging');head.setPointerCapture?.(event.pointerId);
      const move=e=>{const x=Math.max(0,Math.min(COLS-item.grid_w,Math.round(ox+(e.clientX-sx)/m.stepX))),y=Math.max(0,Math.round(oy+(e.clientY-sy)/m.stepY));if(!collision(item.widget_key,x,y,item.grid_w,item.grid_h)){item.grid_x=x;item.grid_y=y;setPos(tile,item);}};
      const up=()=>{head.removeEventListener('pointermove',move);head.removeEventListener('pointerup',up);head.removeEventListener('pointercancel',up);canvas.classList.remove('is-editing');tile.classList.remove('is-dragging');layout=compactLayout(layout);renderCanvas();saveSoon();};
      head.addEventListener('pointermove',move);head.addEventListener('pointerup',up);head.addEventListener('pointercancel',up);
    });
  }

  function bindResize(tile,item){
    if(!layoutApi?.can_edit||!editMode)return;
    const handle=tile.querySelector('.dashboard-widget-resize'); if(!handle)return;
    handle.addEventListener('pointerdown',event=>{
      if(!desktop.matches||event.button!==0)return;event.preventDefault();event.stopPropagation();const def=defs[item.widget_key],m=metrics(),sx=event.clientX,sy=event.clientY,ow=item.grid_w,oh=item.grid_h;canvas.classList.add('is-editing');tile.classList.add('is-resizing');handle.setPointerCapture?.(event.pointerId);
      const move=e=>{let w=Math.max(def.minW,Math.min(def.maxW,Math.round(ow+(e.clientX-sx)/m.stepX))),h=Math.max(def.minH,Math.min(def.maxH,Math.round(oh+(e.clientY-sy)/m.stepY)));w=Math.min(w,COLS-item.grid_x);if(!collision(item.widget_key,item.grid_x,item.grid_y,w,h)){item.grid_w=w;item.grid_h=h;setPos(tile,item);}};
      const up=()=>{handle.removeEventListener('pointermove',move);handle.removeEventListener('pointerup',up);handle.removeEventListener('pointercancel',up);canvas.classList.remove('is-editing');tile.classList.remove('is-resizing');layout=compactLayout(layout);renderCanvas();saveSoon();};
      handle.addEventListener('pointermove',move);handle.addEventListener('pointerup',up);handle.addEventListener('pointercancel',up);
    });
  }

  function renderCanvas(){
    canvas.innerHTML='';
    if(!layout.length){canvas.innerHTML='<div class="dashboard-empty-widget dashboard-empty-canvas">No widgets on this role dashboard.</div>';renderCatalog();renderFilters();return;}
    layout.forEach(item=>{const def=defs[item.widget_key];if(!def)return;const tile=document.createElement('article');tile.className='dashboard-widget';tile.dataset.widget=item.widget_key;tile.innerHTML=`<div class="dashboard-widget-head"><span class="dashboard-widget-title">${esc(def.title)}</span><div class="dashboard-widget-actions">${(layoutApi?.can_edit&&editMode)?'<span data-mobile-order hidden></span><button type="button" class="dashboard-widget-action" data-remove aria-label="Remove widget">×</button>':''}</div></div><div class="dashboard-widget-body">${renderBody(item.widget_key)}</div>${(layoutApi?.can_edit&&editMode)?'<div class="dashboard-widget-resize" aria-hidden="true"></div>':''}`;setPos(tile,item);canvas.appendChild(tile);Analytics?.bind?.(tile);if(layoutApi?.can_edit&&editMode){tile.querySelector('[data-remove]')?.addEventListener('click',()=>{layout=compactLayout(layout.filter(row=>row.widget_key!==item.widget_key));renderCanvas();saveSoon();});bindMove(tile,item);bindResize(tile,item);}});
    renderCatalog();renderFilters();
  }

  function renderTemplates(){
    if(!templateSection||!templateList)return;
    const q=String(search?.value||'').trim();
    if(!layoutApi?.can_edit||!editMode||q){templateSection.hidden=true;return;}
    const allowed=new Set(layoutApi.allowed_widgets||[]),added=new Set(layout.map(item=>item.widget_key));
    const available=presets.map(preset=>({...preset,available:preset.widgets.filter(key=>allowed.has(key))})).filter(preset=>preset.available.length);
    templateSection.hidden=!available.length;
    templateList.innerHTML=available.map(preset=>{const missing=preset.available.filter(key=>!added.has(key));return `<button type="button" class="dashboard-template-button" data-template="${esc(preset.key)}" ${missing.length?'':'disabled'}><strong>${esc(preset.title)}</strong><span>${esc(preset.desc)}</span><small>${missing.length?`Add ${missing.length} available widget${missing.length===1?'':'s'}`:'Already applied'}</small></button>`;}).join('');
    templateList.querySelectorAll('[data-template]').forEach(button=>button.addEventListener('click',()=>applyTemplate(button.dataset.template)));
  }

  function describeWidget(key){
    const def=defs[key];if(!def)return;const studio=window.MERDPOS_UI_STUDIO;if(!studio?.addContextComment){showSave('DevStudio is not ready',true);return;}
    const selector=`.dashboard-widget[data-widget="${key}"]`,existing=(studio.getChanges?.()||[]).slice().reverse().find(row=>row.kind==='comment'&&row.contextKey===`dashboard-widget:${key}`),comment=window.prompt(`Describe ${def.title} for DevStudio:`,existing?.comment||'');
    if(comment===null)return;const value=String(comment).trim();if(!value){showSave('Widget description not changed');return;}
    const element=canvas.querySelector(`.dashboard-widget[data-widget="${key}"]`);studio.addContextComment({selector,comment:value,label:`Dashboard widget: ${def.title}`,contextKey:`dashboard-widget:${key}`,contextType:'dashboard-widget',widgetKey:key,element});showSave('Widget context added to DevStudio');
  }

  function renderCatalog(){
    if(!catalog||!layoutApi)return;const q=String(search?.value||'').trim().toLowerCase(),allowed=new Set(layoutApi.allowed_widgets||[]),added=new Set(layout.map(i=>i.widget_key));
    const rows=Object.entries(defs).filter(([key,def])=>allowed.has(key)&&(!q||`${def.title} ${def.desc}`.toLowerCase().includes(q)));
    catalog.innerHTML=rows.length?rows.map(([key,def])=>`<div class="dashboard-catalog-item ${added.has(key)?'is-added':''}"><div class="dashboard-catalog-copy"><strong>${esc(def.title)}</strong><span>${esc(def.desc)}</span></div><div class="dashboard-catalog-actions"><button type="button" class="dashboard-catalog-describe" data-describe-widget="${esc(key)}">Describe</button><button type="button" class="dashboard-catalog-add" data-add-widget="${esc(key)}" ${added.has(key)?'disabled':''}>${added.has(key)?'✓':'+'}</button></div></div>`).join(''):'<div class="dashboard-empty-widget">No widgets available for this role.</div>';
    catalog.querySelectorAll('[data-describe-widget]').forEach(button=>button.addEventListener('click',()=>describeWidget(button.dataset.describeWidget)));
    catalog.querySelectorAll('[data-add-widget]').forEach(button=>button.addEventListener('click',()=>addWidget(button.dataset.addWidget)));renderTemplates();
  }

  function addWidget(key){if(!layoutApi?.can_edit||!editMode||!defs[key]||!(layoutApi.allowed_widgets||[]).includes(key)||layout.some(i=>i.widget_key===key))return;const def=defs[key],pos=firstOpenPosition(def.w,def.h);layout.push({widget_key:key,grid_x:pos.x,grid_y:pos.y,grid_w:def.w,grid_h:def.h});layout=compactLayout(layout);renderCanvas();saveSoon();}

  function applyTemplate(templateKey){
    if(!layoutApi?.can_edit||!editMode)return;
    const preset=presets.find(row=>row.key===templateKey);if(!preset)return;
    const allowed=new Set(layoutApi.allowed_widgets||[]),added=new Set(layout.map(item=>item.widget_key));let count=0;
    preset.widgets.forEach(key=>{if(!allowed.has(key)||added.has(key)||!defs[key])return;const def=defs[key],pos=firstOpenPosition(def.w,def.h);layout.push({widget_key:key,grid_x:pos.x,grid_y:pos.y,grid_w:def.w,grid_h:def.h});added.add(key);count++;});
    if(!count){showSave('Template already applied');renderTemplates();return;}
    layout=compactLayout(layout);renderCanvas();saveSoon();showSave(`${preset.title}: ${count} widget${count===1?'':'s'} added`);
  }

  function renderRolebar(){
    const role=layoutApi?.selected_role||{};currentRoleId=Number(role.id)||null;
    const canEdit=!!layoutApi?.can_edit;if(!canEdit)editMode=false;const selectable=!!layoutApi?.can_select_role;roleSelect.hidden=!selectable;editToggle.hidden=!canEdit||studioEditMode;editToggle.setAttribute('aria-pressed',editMode?'true':'false');editToggle.textContent=editMode?'Done editing':'Edit dashboard';addButton.hidden=!(canEdit&&editMode&&!studioEditMode);resetButton.hidden=!(canEdit&&editMode);builder.classList.toggle('is-edit-mode',canEdit&&editMode);builder.classList.toggle('is-studio-edit-mode',studioEditMode&&editMode);
    if(selectable){const roles=(layoutApi.roles||[]).slice().sort((a,b)=>Number(a.authority_level)-Number(b.authority_level)||Number(a.id)-Number(b.id));roleSelect.innerHTML=roles.map(r=>`<option value="${Number(r.id)}" ${Number(r.id)===currentRoleId?'selected':''}>${esc(r.role_label)}</option>`).join('');roleSelect.value=String(currentRoleId);}
  }

  async function loadData(roleId=currentRoleId){
    try{
      const params=new URLSearchParams();
      if(roleId)params.set('role_id',String(Number(roleId)));
      if(filterState.storeId)params.set('store_id',String(filterState.storeId));
      if(filterState.period==='current_week')params.set('period','current_week');else params.set('days',String(filterState.days));params.set('_',String(Date.now()));
      data=await json('api/dashboard_data.php?'+params.toString(),{headers:{'Accept':'application/json'}});
      if(data?.filters){const days=num(data.filters.days),period=String(data.filters.period||days||7);filterState.period=period==='current_week'?'current_week':String([7,14,30].includes(days)?days:7);filterState.days=days>0?days:7;filterState.storeId=num(data.filters.store_id);}
      renderCanvas();
    }catch(error){showSave(error.message,true);data=null;renderCanvas();}
  }

  async function loadRole(roleId=null,animate=true,studioMode=studioEditMode){
    const previous=layoutApi?.selected_role||{},previousLoa=Number(previous.authority_level||0),params=new URLSearchParams();if(roleId)params.set('role_id',String(Number(roleId)));if(studioMode)params.set('dev_studio','1');const url='api/dashboard_layout.php'+(params.toString()?`?${params}`:'');
    try{
      if(animate&&layoutApi){const target=(layoutApi.roles||[]).find(r=>Number(r.id)===Number(roleId)),dir=Number(target?.authority_level||0)>=previousLoa?'left':'right';canvas.classList.add(`role-slide-out-${dir}`);await new Promise(r=>setTimeout(r,150));canvas.className='dashboard-canvas';}
      layoutApi=await json(url,{headers:{'Accept':'application/json'}});layout=compactLayout(layoutApi.layout||[]);renderRolebar();data=null;renderCanvas();await loadData(currentRoleId);
      if(animate&&previous.id){const dir=Number(layoutApi.selected_role?.authority_level||0)>=previousLoa?'right':'left';canvas.classList.add(`role-slide-in-${dir}`);requestAnimationFrame(()=>requestAnimationFrame(()=>canvas.classList.remove(`role-slide-in-${dir}`)));}
    }catch(error){canvas.innerHTML=`<div class="dashboard-empty-widget">${esc(error.message)}</div>`;showSave(error.message,true);}
  }

  function toggleDrawer(force=null){if(!layoutApi?.can_edit||(!editMode&&force!==false))return;const open=force===null?!drawer.classList.contains('open'):!!force;drawer.classList.toggle('open',open);drawer.setAttribute('aria-hidden',open?'false':'true');addButton.setAttribute('aria-expanded',open?'true':'false');if(open)renderCatalog();}

  async function openStudioEdit(){
    studioEditMode=true;await loadRole(null,false,true);if(!layoutApi?.can_edit){studioEditMode=false;showSave('Dashboard editing is unavailable',true);return false;}editMode=true;renderRolebar();renderCanvas();toggleDrawer(true);showSave('DevStudio dashboard editing');return true;
  }
  async function closeStudioEdit(){toggleDrawer(false);editMode=false;studioEditMode=false;await loadRole(null,false,false);renderRolebar();renderCanvas();showSave('Dashboard editing closed');return false;}
  async function toggleStudioEdit(){return studioEditMode&&editMode?closeStudioEdit():openStudioEdit();}

  roleSelect.addEventListener('change',()=>loadRole(Number(roleSelect.value),true));
  editToggle.addEventListener('click',()=>{if(!layoutApi?.can_edit)return;const next=!editMode;if(!next)toggleDrawer(false);editMode=next;renderRolebar();renderCanvas();});
  addButton.addEventListener('click',()=>toggleDrawer());
  search.addEventListener('input',renderCatalog);
  storeFilter?.addEventListener('change',()=>{filterState.storeId=num(storeFilter.value);loadData(currentRoleId);});
  periodButtons.forEach(button=>button.addEventListener('click',()=>{const period=String(button.dataset.dashboardPeriod||'');if(!PERIODS.map(String).includes(period)||period===filterState.period)return;filterState.period=period;if(period!=='current_week')filterState.days=num(period);loadData(currentRoleId);}));
  builder.addEventListener('merdpos-chart-select',event=>{const storeId=num(event.detail?.payload?.storeId);if(!storeId||storeId===filterState.storeId)return;filterState.storeId=storeId;if(storeFilter)storeFilter.value=String(storeId);loadData(currentRoleId);});
  resetButton.addEventListener('click',async()=>{if(!layoutApi?.can_edit||!editMode)return;if(!window.confirm(`Clear the ${layoutApi.selected_role?.role_label||'selected role'} dashboard?`))return;try{layoutApi=await json('api/dashboard_layout.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'reset_layout',role_id:currentRoleId,csrf:layoutApi.csrf,dev_studio:studioEditMode?1:0})});layout=[];renderRolebar();renderCanvas();showSave('Dashboard cleared');}catch(error){showSave(error.message,true);}});
  document.addEventListener('pointerdown',event=>{if(drawer.classList.contains('open')&&!drawer.contains(event.target)&&!addButton.contains(event.target))toggleDrawer(false);});

  window.MERDPOSDashboardBuilder={reloadRoles:async()=>{const selected=currentRoleId;await loadRole(selected,false,studioEditMode);},refreshData:()=>loadData(currentRoleId),openStudioEdit,closeStudioEdit,toggleStudioEdit,isEditing:()=>!!(studioEditMode&&editMode),openWidgetDrawer:()=>{if(editMode)toggleDrawer(true);}};

  loadRole(null,false);
  window.setInterval(()=>loadData(currentRoleId),60000);
})();