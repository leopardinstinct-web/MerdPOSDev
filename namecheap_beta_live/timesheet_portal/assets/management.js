(function(){
  const THEME_KEY='merdpos-theme';
  const validTheme=value=>value==='dark'||value==='light';
  const currentTheme=()=>document.documentElement.dataset.theme==='dark'?'dark':'light';
  const syncThemeMeta=theme=>{const meta=document.querySelector('meta[name="theme-color"]');if(meta)meta.content='#031B4B';};
  const setTheme=(theme,persist=true)=>{const next=validTheme(theme)?theme:'light';document.documentElement.dataset.theme=next;syncThemeMeta(next);if(persist){try{localStorage.setItem(THEME_KEY,next);}catch(_){}}window.dispatchEvent(new CustomEvent('merdpos-themechange',{detail:{theme:next}}));return next;};
  let storedTheme=null;try{storedTheme=localStorage.getItem(THEME_KEY);}catch(_){}
  if(validTheme(storedTheme)){document.documentElement.dataset.theme=storedTheme;syncThemeMeta(storedTheme);}
  window.MERDPOSTheme=Object.freeze({current:currentTheme,set:setTheme,toggle:()=>setTheme(currentTheme()==='dark'?'light':'dark')});
  const $=id=>document.getElementById(id);
  const permissions=window.MERDPOS_AUTH?.permissions||{};
  const can=key=>!!permissions[key];
  const isDev=window.MERDPOS_AUTH?.is_dev===true;
  const esc=value=>String(value??'').replace(/[&<>'\"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','\"':'&quot;'}[c]));
  const money=(value,currency='AUD')=>{try{return Number(value||0).toLocaleString(undefined,{style:'currency',currency:String(currency||'AUD').toUpperCase()});}catch(_){return `${String(currency||'AUD').toUpperCase()} ${Number(value||0).toFixed(2)}`;}};
  const sortStores=rows=>(Array.isArray(rows)?rows.slice():[]).sort((a,b)=>Number(a?.store_id??a?.id??Number.MAX_SAFE_INTEGER)-Number(b?.store_id??b?.id??Number.MAX_SAFE_INTEGER));
  let displayTimezone=null;

  function appendStyle(key,href){
    if(document.querySelector(`link[data-${key}]`))return;
    const link=document.createElement('link');
    link.rel='stylesheet';
    link.href=href;
    link.dataset[key.replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]='1';
    document.head.appendChild(link);
  }
  function appendScript(key,src,defer=false){
    if(document.querySelector(`script[data-${key}]`))return;
    const script=document.createElement('script');
    // Dynamically inserted classic scripts are async by default. Force ordered
    // execution so dependency-sensitive modules run in insertion order.
    script.async=false;
    script.src=src;
    if(defer)script.defer=true;
    script.dataset[key.replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]='1';
    document.body.appendChild(script);
  }

  function ensureShellAssets(){
    /* Tokens are inserted before every runtime visual layer. */
    appendStyle('merd-design-tokens','assets/design-tokens.css?v=20260828palette1');
    appendStyle('merd-shell','assets/shell.css?v=20260830bottom1');
    appendScript('merd-brand-assets','assets/brand/brand-assets.js?v=20260827brand4');

    if(can('dashboard.view')&&document.getElementById('dashboardPanel')){
      appendStyle('dashboard-builder-css','assets/dashboard-builder.css?v=20260830dashboardstudio1');
      appendScript('dashboard-builder','assets/dashboard-builder.js?v=20260830dashboardstudio1');
    }

    /* Roles mounts before navigation so Operations structure is deterministic. */
    if(can('roles.manage'))appendScript('roles-module','assets/roles.js?v=20260826ds1');

    appendScript('merd-navigation','assets/navigation.js?v=20260830bottom1',true);
    appendScript('store-order','assets/store-order.js?v=20260826ds1',true);
    appendScript('modal-lock','assets/modal-lock.js?v=20260826ds1',true);

    appendStyle('account-menu-css','assets/account-menu.css?v=20260830roleview3');
    appendScript('account-menu','assets/account-menu.js?v=20260830roleview3');

    if(can('stores.profile.manage'))appendScript('dev-stores-ui','assets/dev-stores-ui.js?v=20260826ds1');

    /* Functional identity patch remains; its old standalone styling layer is
       retired because design-system.css now owns the visual grammar. */
    if(can('dashboard.view'))appendScript('omnichannel-identity','assets/omnichannel-identity.js?v=20260828palette1');

    /* Behaviour only. Geometry comes from the canonical design system. */
    appendScript('merd-minimal-controls','assets/minimal-controls.js?v=20260826ds1');
    appendScript('merd-mobile-runtime','assets/mobile-runtime.js?v=20260828mobile1');

    /* Canonical component layer must be the final stylesheet in the beta. */
    appendStyle('merd-design-system','assets/design-system.css?v=20260827visual1');
    appendScript('merd-design-audit','assets/design-audit.js?v=20260826ds1');

    if(isDev){
      appendStyle('merd-ui-studio-css','assets/ui-studio.css?v=20260830studio17');
      appendScript('merd-ui-studio','assets/ui-studio.js?v=20260830studio17');
    }
  }
  ensureShellAssets();

  function activatePanel(id){document.querySelectorAll('.portal-tab').forEach(tab=>tab.classList.toggle('active',tab.dataset.panel===id));document.querySelectorAll('.portal-panel').forEach(panel=>panel.hidden=panel.id!==id);window.MERDPOSDesignAudit?.run?.();}
  async function json(url){const response=await fetch(url,{cache:'no-store',headers:{'Accept':'application/json'}});const text=await response.text();let data;try{data=text?JSON.parse(text):null;}catch(_){throw new Error(`MERDPOS API returned invalid data (${response.status}).`);}if(!data)throw new Error(`MERDPOS API returned an empty response (${response.status}).`);if(!data.success)throw new Error(data.error||'Request failed');return data;}
  function updateClock(){const root=$('liveClock');if(!root)return;const options={weekday:'short',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'};if(displayTimezone)options.timeZone=displayTimezone;try{root.textContent=new Date().toLocaleString([],options);}catch(_){delete options.timeZone;root.textContent=new Date().toLocaleString([],options);}}
  function kpi(icon,value,label,alert=false){return `<article class="mgmt-kpi${alert?' alert':''}"><span class="kpi-icon" aria-hidden="true">${icon}</span><div class="kpi-value">${esc(value)}</div><div class="kpi-label">${esc(label)}</div></article>`;}
  function renderBars(root,rows,labelKey,valueFn,valueLabelFn){if(!root)return;if(!rows.length){root.innerHTML='<div class="muted">No data for today.</div>';return;}const values=rows.map(valueFn);const max=Math.max(1,...values);root.innerHTML=rows.map(row=>{const value=valueFn(row),pct=Math.max(value>0?3:0,(value/max)*100);return `<div class="chart-row"><div class="chart-label">${esc(row[labelKey])}</div><div class="chart-track"><div class="chart-fill" style="width:${pct.toFixed(1)}%"></div></div><div class="chart-value">${esc(valueLabelFn(row,value))}</div></div>`;}).join('');}
  function renderFinanceRing(rows,defaultCurrency){const root=$('financeRingRoot');if(!root)return;const currencies=new Set(rows.map(row=>String(row.currency_code||defaultCurrency||'AUD').toUpperCase()));if(currencies.size>1){root.innerHTML='<div class="empty-card"><h3>Mixed store currencies</h3><p>Store balances are shown individually. A combined cash total is hidden because currencies cannot be summed safely.</p></div>';return;}const currency=[...currencies][0]||String(defaultCurrency||'AUD').toUpperCase();const register=rows.reduce((sum,row)=>sum+Number(row.register_balance||0),0);const petty=rows.reduce((sum,row)=>sum+Number(row.petty_balance||0),0);const total=register+petty;const stop=total>0?(register/total)*100:50;root.innerHTML=`<div class="finance-ring" style="--ring-stop:${stop.toFixed(2)}%" role="img" aria-label="Register ${esc(money(register,currency))}; Petty Cash ${esc(money(petty,currency))}"></div><div class="finance-legend"><div class="legend-item"><span class="legend-dot" aria-hidden="true"></span><span>Register</span><strong>${esc(money(register,currency))}</strong></div><div class="legend-item"><span class="legend-dot petty" aria-hidden="true"></span><span>Petty Cash</span><strong>${esc(money(petty,currency))}</strong></div><div class="legend-item"><span></span><span>Total</span><strong>${esc(money(total,currency))}</strong></div></div>`;}
  function renderWorkingBars(working,stores){const orderedStores=sortStores(stores||[]);const counts=new Map(orderedStores.map(store=>[store.store_name,0]));for(const person of working)counts.set(person.store_name,(counts.get(person.store_name)||0)+1);const rows=[...counts].map(([store_name,count])=>({store_name,count}));renderBars($('workforceChart'),rows,'store_name',row=>row.count,(row,value)=>String(value));}
  function pendingDisputes(data){return (data.disputes||[]).filter(d=>d.status==='pending').length;}

  function setCardVisible(id,visible){
    const node=$(id);
    const card=node?.closest('.mgmt-card,.controls-card');
    if(card)card.hidden=!visible;
  }

  function applyManagementPermissionVisibility(){
    const canWorkforceData=can('workforce.view');
    const canFinanceData=can('finance.management_summary');
    const canAttendanceData=can('timesheets.view_own')||can('timesheets.view_all');
    setCardVisible('workingNow',canWorkforceData);
    setCardVisible('workforceChart',canWorkforceData);
    setCardVisible('storeFinanceChart',canFinanceData);
    setCardVisible('financeRingRoot',canFinanceData);
    setCardVisible('recentShifts',canAttendanceData);
  }

  function renderManagement(data){
    applyManagementPermissionVisibility();
    const mgmt=data.management||{};
    displayTimezone=mgmt.timezone||data.client_defaults?.timezone||null;
    updateClock();
    const pending=pendingDisputes(data);
    const root=$('managementKpis');
    if(root){
      const cards=[];
      if(can('workforce.view')){
        cards.push(kpi('◉',(data.working||[]).length,'Working now'));
        cards.push(kpi('◫',mgmt.active_employees??0,'Active employees'));
      }
      if(can('disputes.review'))cards.push(kpi('◇',pending,'Pending disputes',pending>0));
      if(can('system.sync_status'))cards.push(kpi('↻',mgmt.sync_attention??0,'Sync attention',Number(mgmt.sync_attention)>0));
      root.innerHTML=cards.join('');
      root.hidden=cards.length===0;
    }
    if(can('workforce.view'))renderWorkingBars(data.working||[],sortStores(data.stores||[]));
    const financial=can('finance.management_summary')?sortStores(mgmt.financial_by_store||[]):[];
    if(can('finance.management_summary')){
      renderBars($('storeFinanceChart'),financial,'store_name',row=>Number(row.register_balance||0)+Number(row.petty_balance||0),(row,value)=>money(value,row.currency_code||mgmt.currency_code));
      renderFinanceRing(financial,mgmt.currency_code);
    }
    const date=$('financeChartDate');if(date&&can('finance.management_summary'))date.textContent=mgmt.business_date||'Today';
  }
  function updateDisputeBadge(data){const bell=$('timesheetBell');if(!bell)return;const count=pendingDisputes(data);bell.textContent=String(count);bell.hidden=count===0;bell.setAttribute('aria-label',`${count} pending dispute${count===1?'':'s'}`);}
  async function loadDev(){const root=$('devStatus');if(!root||!can('dev.status'))return;try{const data=await json('api/dev_status.php');const tableCount=Object.values(data.tables||{}).filter(v=>v!==null).length;const totalRows=Object.values(data.tables||{}).reduce((sum,v)=>sum+(Number(v)||0),0);root.innerHTML=`<article class="dev-tile"><strong>${esc(data.database)}</strong><span>Database</span></article><article class="dev-tile"><strong>${esc(data.server_version)}</strong><span>MySQL / MariaDB</span></article><article class="dev-tile"><strong>${esc(data.php_version)}</strong><span>PHP</span></article><article class="dev-tile"><strong>${tableCount}</strong><span>Tracked tables</span></article><article class="dev-tile"><strong>${totalRows.toLocaleString()}</strong><span>Rows across tracked tables</span></article><article class="dev-tile"><strong>LOA</strong><span>${esc(data.authorization_model||'central')}</span></article>`;}catch(error){root.innerHTML=`<div class="status-card error-card" role="alert">${esc(error.message)}</div>`;}}
  async function load(){if(!can('dashboard.view')){if(can('dev.status'))loadDev();return;}try{const data=await json('api/beta_state.php');data.stores=sortStores(data.stores||[]);if(data.management){data.management.financial_by_store=sortStores(data.management.financial_by_store||[]);data.management.sales_by_store=sortStores(data.management.sales_by_store||[]);}updateDisputeBadge(data);if(data.is_management)renderManagement(data);if(can('dev.status'))loadDev();window.MERDPOSStoreOrder?.run?.();window.MERDPOSOmnichannelIdentity?.patch?.();window.MERDPOSMinimalControls?.apply?.();window.MERDPOSMobileRuntime?.enhance?.();window.MERDPOSDesignAudit?.run?.();}catch(_){}}
  document.addEventListener('click',event=>{const shortcut=event.target.closest('[data-dispute-shortcut]');if(shortcut&&can('disputes.review')){event.preventDefault();event.stopPropagation();activatePanel('disputesPanel');}});
  const refresh=$('refreshBetaBtn');if(refresh)refresh.addEventListener('click',()=>setTimeout(load,250));
  updateClock();setInterval(updateClock,30000);load();setInterval(load,60000);
})();
