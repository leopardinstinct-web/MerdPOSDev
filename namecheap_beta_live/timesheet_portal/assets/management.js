(function(){
  const $=id=>document.getElementById(id);
  const esc=value=>String(value??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const money=value=>Number(value||0).toLocaleString(undefined,{style:'currency',currency:'AUD'});

  function ensureShellAssets(){
    if(!document.querySelector('link[href$="assets/shell.css"]')){
      const link=document.createElement('link');link.rel='stylesheet';link.href='assets/shell.css';document.head.appendChild(link);
    }
    if(!document.querySelector('script[src$="assets/navigation.js"]')){
      const script=document.createElement('script');script.src='assets/navigation.js';script.defer=true;document.body.appendChild(script);
    }
  }
  ensureShellAssets();

  function activatePanel(id){
    document.querySelectorAll('.portal-tab').forEach(tab=>tab.classList.toggle('active',tab.dataset.panel===id));
    document.querySelectorAll('.portal-panel').forEach(panel=>panel.hidden=panel.id!==id);
  }

  async function json(url){
    const response=await fetch(url,{headers:{'Accept':'application/json'}});
    const text=await response.text();
    let data;
    try{data=text?JSON.parse(text):null;}catch(_){throw new Error(`MERDPOS API returned invalid data (${response.status}).`);}
    if(!data)throw new Error(`MERDPOS API returned an empty response (${response.status}).`);
    if(!data.success)throw new Error(data.error||'Request failed');
    return data;
  }

  function updateClock(){
    const root=$('liveClock');
    if(root)root.textContent=new Date().toLocaleString([],{weekday:'short',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
  }

  function kpi(icon,value,label,alert=false){return `<article class="mgmt-kpi${alert?' alert':''}"><span class="kpi-icon">${icon}</span><div class="kpi-value">${esc(value)}</div><div class="kpi-label">${esc(label)}</div></article>`;}

  function renderBars(root,rows,labelKey,valueFn,valueLabelFn){
    if(!root)return;
    if(!rows.length){root.innerHTML='<div class="muted">No data for today.</div>';return;}
    const values=rows.map(valueFn);const max=Math.max(1,...values);
    root.innerHTML=rows.map(row=>{const value=valueFn(row),pct=Math.max(value>0?3:0,(value/max)*100);return `<div class="chart-row"><div class="chart-label">${esc(row[labelKey])}</div><div class="chart-track"><div class="chart-fill" style="width:${pct.toFixed(1)}%"></div></div><div class="chart-value">${esc(valueLabelFn(row,value))}</div></div>`;}).join('');
  }

  function renderFinanceRing(rows){
    const root=$('financeRingRoot');if(!root)return;
    const register=rows.reduce((sum,row)=>sum+Number(row.register_balance||0),0);
    const petty=rows.reduce((sum,row)=>sum+Number(row.petty_balance||0),0);
    const total=register+petty;
    const stop=total>0?(register/total)*100:50;
    root.innerHTML=`<div class="finance-ring" style="--ring-stop:${stop.toFixed(2)}%"></div><div class="finance-legend"><div class="legend-item"><span class="legend-dot"></span><span>Register</span><strong>${esc(money(register))}</strong></div><div class="legend-item"><span class="legend-dot petty"></span><span>Petty Cash</span><strong>${esc(money(petty))}</strong></div><div class="legend-item"><span></span><span>Total</span><strong>${esc(money(total))}</strong></div></div>`;
  }

  function renderWorkingBars(working,stores){
    const counts=new Map((stores||[]).map(store=>[store.store_name,0]));
    for(const person of working)counts.set(person.store_name,(counts.get(person.store_name)||0)+1);
    const rows=[...counts].map(([store_name,count])=>({store_name,count}));
    renderBars($('workforceChart'),rows,'store_name',row=>row.count,(row,value)=>String(value));
  }

  function pendingDisputes(data){return (data.disputes||[]).filter(d=>d.status==='pending').length;}

  function renderManagement(data){
    const mgmt=data.management;if(!mgmt)return;
    const pending=pendingDisputes(data);
    const root=$('managementKpis');
    if(root)root.innerHTML=[
      kpi('◉',(data.working||[]).length,'Working now'),
      kpi('◇',pending,'Pending disputes',pending>0),
      kpi('◫',mgmt.active_employees,'Active employees'),
      kpi('↻',mgmt.sync_attention,'Sync attention',Number(mgmt.sync_attention)>0)
    ].join('');
    renderWorkingBars(data.working||[],data.stores||[]);
    const financial=mgmt.financial_by_store||[];
    renderBars($('storeFinanceChart'),financial,'store_name',row=>Number(row.register_balance||0)+Number(row.petty_balance||0),(row,value)=>money(value));
    renderFinanceRing(financial);
    const date=$('financeChartDate');if(date)date.textContent=mgmt.business_date||'Today';
  }

  function updateDisputeBadge(data){
    const bell=$('timesheetBell');if(!bell)return;
    const count=pendingDisputes(data);bell.textContent=String(count);bell.hidden=count===0;
  }

  async function loadDev(){
    const root=$('devStatus');if(!root)return;
    try{
      const data=await json('api/dev_status.php');
      const tableCount=Object.values(data.tables||{}).filter(v=>v!==null).length;
      const totalRows=Object.values(data.tables||{}).reduce((sum,v)=>sum+(Number(v)||0),0);
      root.innerHTML=`<article class="dev-tile"><strong>${esc(data.database)}</strong><span>Database</span></article><article class="dev-tile"><strong>${esc(data.server_version)}</strong><span>MySQL / MariaDB</span></article><article class="dev-tile"><strong>${esc(data.php_version)}</strong><span>PHP</span></article><article class="dev-tile"><strong>${tableCount}</strong><span>Tracked tables</span></article><article class="dev-tile"><strong>${totalRows.toLocaleString()}</strong><span>Rows across tracked tables</span></article><article class="dev-tile"><strong>${esc(data.branch)}</strong><span>Authoritative beta branch</span></article>`;
    }catch(error){root.innerHTML=`<div class="status-card error-card">${esc(error.message)}</div>`;}
  }

  async function load(){
    try{
      const data=await json('api/beta_state.php');
      updateDisputeBadge(data);
      if(data.is_management)renderManagement(data);
      if(data.is_dev)loadDev();
    }catch(_){/* beta.js already owns visible API errors */}
  }

  document.addEventListener('click',event=>{
    const shortcut=event.target.closest('[data-dispute-shortcut]');
    if(shortcut){event.preventDefault();event.stopPropagation();activatePanel('disputesPanel');}
  });

  const refresh=$('refreshBetaBtn');if(refresh)refresh.addEventListener('click',()=>setTimeout(load,250));
  updateClock();setInterval(updateClock,30000);
  load();setInterval(load,60000);
})();
