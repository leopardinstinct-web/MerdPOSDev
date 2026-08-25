(function () {
  'use strict';

  const panel = document.getElementById('dashboardPanel');
  if (!panel || document.querySelector('.merd-dashboard-builder')) return;

  const COLS = 12;
  const GAP = 12;
  const ROW = 72;
  const ROW_STEP = ROW + GAP;
  const desktop = window.matchMedia('(min-width: 821px)');

  const defs = {
    working_now_count: {title:'Working now', desc:'Live number of employees clocked in.', w:3,h:2,minW:2,minH:2,maxW:4,maxH:3},
    pending_disputes: {title:'Pending disputes', desc:'Attendance disputes waiting for action.', w:3,h:2,minW:2,minH:2,maxW:4,maxH:3},
    active_employees: {title:'Active employees', desc:'Active workforce in the working client.', w:3,h:2,minW:2,minH:2,maxW:4,maxH:3},
    sync_attention: {title:'Sync attention', desc:'Pending or failed sync items needing attention.', w:3,h:2,minW:2,minH:2,maxW:4,maxH:3},
    working_now: {title:'Who is working now', desc:'Live employee and store attendance.', w:5,h:4,minW:4,minH:3,maxW:8,maxH:7},
    workforce_by_store: {title:'Workforce by store', desc:'Open shifts grouped by Store ID.', w:5,h:4,minW:4,minH:3,maxW:8,maxH:7},
    store_cash_position: {title:'Store cash position', desc:'Register plus Petty Cash by store.', w:5,h:4,minW:4,minH:3,maxW:8,maxH:7},
    cash_mix: {title:'Register vs Petty Cash', desc:'Current cash mix for the working client.', w:4,h:4,minW:3,minH:3,maxW:6,maxH:6},
    today_sales_by_store: {title:"Today's sales by store", desc:'Completed retail sales grouped by store.', w:5,h:4,minW:4,minH:3,maxW:8,maxH:7},
    recent_attendance: {title:'Recent attendance', desc:'Latest verified attendance activity.', w:8,h:5,minW:5,minH:4,maxW:12,maxH:9},
    my_shift: {title:'My current shift', desc:'Your current QR attendance status.', w:4,h:2,minW:3,minH:2,maxW:6,maxH:4},
    my_disputes: {title:'My open disputes', desc:'Your pending attendance corrections.', w:4,h:3,minW:3,minH:2,maxW:6,maxH:5},
  };

  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const num = value => Number.isFinite(Number(value)) ? Number(value) : 0;

  panel.classList.add('dashboard-builder-active');
  const builder = document.createElement('section');
  builder.className = 'merd-dashboard-builder';
  builder.innerHTML = `
    <div id="dashboardCanvas" class="dashboard-canvas" aria-label="Personal dashboard"></div>
    <div id="dashboardSaveState" class="dashboard-save-state" aria-live="polite"></div>
    <button id="dashboardAddButton" class="dashboard-add-button" type="button" aria-label="Add dashboard widget" aria-expanded="false">+</button>
    <aside id="dashboardWidgetDrawer" class="dashboard-widget-drawer" aria-label="Add widgets" aria-hidden="true">
      <div class="dashboard-drawer-head"><h2>Add widget</h2><p>Choose a tile, then drag and resize it directly on your dashboard.</p></div>
      <label class="dashboard-widget-search"><span aria-hidden="true">⌕</span><input id="dashboardWidgetSearch" type="search" placeholder="Search widgets"></label>
      <div id="dashboardWidgetCatalog" class="dashboard-widget-catalog"></div>
      <div class="dashboard-drawer-foot"><button id="dashboardReset" class="dashboard-reset" type="button">Clear dashboard</button></div>
    </aside>`;
  panel.insertBefore(builder, panel.firstChild);

  const canvas = document.getElementById('dashboardCanvas');
  const addButton = document.getElementById('dashboardAddButton');
  const drawer = document.getElementById('dashboardWidgetDrawer');
  const catalog = document.getElementById('dashboardWidgetCatalog');
  const search = document.getElementById('dashboardWidgetSearch');
  const saveState = document.getElementById('dashboardSaveState');
  const resetButton = document.getElementById('dashboardReset');

  let layoutApi = null;
  let data = null;
  let layout = [];
  let saveTimer = null;
  let saveMessageTimer = null;

  async function json(url, options = {}) {
    const response = await fetch(url, {cache:'no-store', ...options});
    const text = await response.text();
    let payload = null;
    try { payload = text ? JSON.parse(text) : null; }
    catch (_) { throw new Error(`Dashboard API returned invalid data (${response.status}).`); }
    if (!payload) throw new Error(`Dashboard API returned an empty response (${response.status}).`);
    if (!payload.success) throw new Error(payload.error || 'Dashboard request failed.');
    return payload;
  }

  function showSave(message, error = false) {
    window.clearTimeout(saveMessageTimer);
    saveState.textContent = message;
    saveState.style.color = error ? '#B42318' : '#63758C';
    saveState.classList.add('show');
    saveMessageTimer = window.setTimeout(() => saveState.classList.remove('show'), 1400);
  }

  function currency(value, code) {
    const currencyCode = String(code || data?.client_defaults?.currency_code || 'AUD').toUpperCase();
    try { return Number(value || 0).toLocaleString(undefined,{style:'currency',currency:currencyCode,maximumFractionDigits:0}); }
    catch (_) { return `${currencyCode} ${Number(value || 0).toFixed(0)}`; }
  }

  function localTime(value, timezone) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ','T') + 'Z');
    if (Number.isNaN(date.getTime())) return String(value);
    const options = {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'};
    if (timezone) options.timeZone = timezone;
    try { return date.toLocaleString([], options); }
    catch (_) { delete options.timeZone; return date.toLocaleString([], options); }
  }

  function pendingDisputes() {
    return (data?.disputes || []).filter(row => String(row.status) === 'pending').length;
  }

  function openMyDisputes() {
    return (data?.disputes || []).filter(row => ['pending','awaiting_employee'].includes(String(row.status))).length;
  }

  function bars(rows, labelFn, valueFn, valueLabelFn) {
    if (!rows.length) return '<div class="dashboard-empty-widget">No data yet.</div>';
    const values = rows.map(valueFn);
    const max = Math.max(1, ...values);
    return `<div class="dashboard-bars">${rows.map(row => {
      const value = valueFn(row);
      const pct = Math.max(value > 0 ? 3 : 0, (value / max) * 100);
      return `<div class="dashboard-bar-row"><div class="dashboard-bar-label">${esc(labelFn(row))}</div><div class="dashboard-bar-track"><div class="dashboard-bar-fill" style="width:${pct.toFixed(1)}%"></div></div><div class="dashboard-bar-value">${esc(valueLabelFn(row,value))}</div></div>`;
    }).join('')}</div>`;
  }

  function renderBody(key) {
    if (!data) return '<div class="dashboard-empty-widget">Loading…</div>';
    const management = data.management || {};
    const timezone = management.timezone || data.client_defaults?.timezone || null;

    if (key === 'working_now_count') return `<div class="dashboard-kpi"><strong>${num(data.working?.length)}</strong><span>Working now</span><small>Live QR attendance</small></div>`;
    if (key === 'pending_disputes') return `<div class="dashboard-kpi"><strong>${pendingDisputes()}</strong><span>Pending disputes</span><small>Waiting for review</small></div>`;
    if (key === 'active_employees') return `<div class="dashboard-kpi"><strong>${num(management.active_employees)}</strong><span>Active employees</span><small>Working client</small></div>`;
    if (key === 'sync_attention') return `<div class="dashboard-kpi"><strong>${num(management.sync_attention)}</strong><span>Sync attention</span><small>Pending / failed outbox</small></div>`;
    if (key === 'my_shift') {
      const shift = (data.working || [])[0];
      return shift
        ? `<div class="dashboard-kpi"><strong>Clocked in</strong><span>${esc(shift.store_name || '')}</span><small>Since ${esc(localTime(shift.clock_in_at, timezone))}</small></div>`
        : '<div class="dashboard-kpi"><strong>Off shift</strong><span>Not clocked in</span><small>Scan a store QR to start</small></div>';
    }
    if (key === 'my_disputes') return `<div class="dashboard-kpi"><strong>${openMyDisputes()}</strong><span>Open disputes</span><small>Your attendance corrections</small></div>`;

    if (key === 'working_now') {
      const rows = data.working || [];
      if (!rows.length) return '<div class="dashboard-empty-widget">Nobody is clocked in.</div>';
      return `<div class="dashboard-list">${rows.slice(0,30).map(row => `<div class="dashboard-list-row"><div><strong>${esc(row.full_name)}</strong><span>${esc(row.store_name)}</span></div><small>${esc(localTime(row.clock_in_at, row.timezone || timezone))}</small></div>`).join('')}</div>`;
    }

    if (key === 'workforce_by_store') {
      const stores = (data.stores || []).slice().sort((a,b) => num(a.id)-num(b.id));
      const counts = new Map(stores.map(store => [String(store.store_name),0]));
      (data.working || []).forEach(row => counts.set(String(row.store_name),(counts.get(String(row.store_name)) || 0) + 1));
      const rows = stores.map(store => ({store_name:store.store_name,count:counts.get(String(store.store_name)) || 0}));
      return bars(rows,row=>row.store_name,row=>num(row.count),(row,value)=>String(value));
    }

    if (key === 'store_cash_position') {
      const rows = (management.financial_by_store || []).slice().sort((a,b)=>num(a.store_id)-num(b.store_id));
      return bars(rows,row=>row.store_name,row=>num(row.register_balance)+num(row.petty_balance),(row,value)=>currency(value,row.currency_code || management.currency_code));
    }

    if (key === 'today_sales_by_store') {
      const rows = (management.sales_by_store || []).slice().sort((a,b)=>num(a.store_id)-num(b.store_id));
      return bars(rows,row=>row.store_name,row=>num(row.today_sales),(row,value)=>currency(value,row.currency_code || management.currency_code));
    }

    if (key === 'cash_mix') {
      const rows = management.financial_by_store || [];
      const currencies = new Set(rows.map(row => String(row.currency_code || management.currency_code || 'AUD').toUpperCase()));
      if (currencies.size > 1) return '<div class="dashboard-empty-widget">Mixed store currencies cannot be combined into one cash-mix tile.</div>';
      const code = [...currencies][0] || management.currency_code || 'AUD';
      const register = rows.reduce((sum,row)=>sum+num(row.register_balance),0);
      const petty = rows.reduce((sum,row)=>sum+num(row.petty_balance),0);
      const total = register + petty;
      const stop = total > 0 ? register / total * 100 : 50;
      return `<div class="dashboard-ring-wrap"><div class="dashboard-ring" style="--ring-stop:${stop.toFixed(2)}%"></div><div class="dashboard-ring-legend"><div><span class="dashboard-ring-dot"></span><span>Register</span><strong>${esc(currency(register,code))}</strong></div><div><span class="dashboard-ring-dot petty"></span><span>Petty Cash</span><strong>${esc(currency(petty,code))}</strong></div><div><span></span><span>Total</span><strong>${esc(currency(total,code))}</strong></div></div></div>`;
    }

    if (key === 'recent_attendance') {
      const rows = (data.recent_shifts || []).slice(0,30);
      if (!rows.length) return '<div class="dashboard-empty-widget">No recent attendance.</div>';
      return `<table class="dashboard-mini-table"><thead><tr><th>Employee</th><th>Store</th><th>In</th><th>Out</th></tr></thead><tbody>${rows.map(row => `<tr><td>${esc(row.full_name)}</td><td>${esc(row.store_name)}</td><td>${esc(localTime(row.clock_in_at,row.timezone || timezone))}</td><td>${esc(localTime(row.clock_out_at,row.timezone || timezone))}</td></tr>`).join('')}</tbody></table>`;
    }

    return '<div class="dashboard-empty-widget">Widget unavailable.</div>';
  }

  function normalized(item) {
    const def = defs[item.widget_key] || {w:4,h:3,minW:2,minH:2,maxW:12,maxH:20};
    const w = Math.max(def.minW, Math.min(def.maxW, num(item.grid_w) || def.w));
    const h = Math.max(def.minH, Math.min(def.maxH, num(item.grid_h) || def.h));
    return {
      widget_key:item.widget_key,
      grid_x:Math.max(0,Math.min(COLS-w,num(item.grid_x))),
      grid_y:Math.max(0,num(item.grid_y)),
      grid_w:w,
      grid_h:h,
    };
  }

  function collision(key,x,y,w,h) {
    return layout.some(item => item.widget_key !== key && x < item.grid_x + item.grid_w && x + w > item.grid_x && y < item.grid_y + item.grid_h && y + h > item.grid_y);
  }

  function firstOpenPosition(w,h,key='',startX=0,startY=0) {
    const x0 = Math.max(0,Math.min(COLS-w,startX));
    for (let y = Math.max(0,startY); y < 1000; y++) {
      const xs = [x0, ...Array.from({length:COLS-w+1},(_,i)=>i).filter(x=>x!==x0)];
      for (const x of xs) if (!collision(key,x,y,w,h)) return {x,y};
    }
    return {x:0,y:0};
  }

  function setTilePosition(tile,item) {
    tile.style.gridColumn = `${item.grid_x + 1} / span ${item.grid_w}`;
    tile.style.gridRow = `${item.grid_y + 1} / span ${item.grid_h}`;
  }

  function gridMetrics() {
    const rect = canvas.getBoundingClientRect();
    const colWidth = Math.max(1,(rect.width - GAP * (COLS - 1)) / COLS);
    const stepX = colWidth + GAP;
    canvas.style.setProperty('--db-col-step', `${stepX}px`);
    canvas.style.setProperty('--db-row-step', `${ROW_STEP}px`);
    return {rect,colWidth,stepX,stepY:ROW_STEP};
  }

  function beginGridEdit() {
    gridMetrics();
    canvas.classList.add('is-editing');
  }
  function endGridEdit() { canvas.classList.remove('is-editing'); }

  function bindDrag(tile,item) {
    const head = tile.querySelector('.dashboard-widget-head');
    if (!head) return;
    head.addEventListener('pointerdown', event => {
      if (!desktop.matches || event.button !== 0 || event.target.closest('button')) return;
      event.preventDefault();
      const metrics = gridMetrics();
      const startX = event.clientX;
      const startY = event.clientY;
      const originalX = item.grid_x;
      const originalY = item.grid_y;
      let moved = false;
      head.setPointerCapture?.(event.pointerId);
      tile.classList.add('is-dragging');
      beginGridEdit();

      const move = e => {
        const dx = Math.round((e.clientX - startX) / metrics.stepX);
        const dy = Math.round((e.clientY - startY) / metrics.stepY);
        const x = Math.max(0,Math.min(COLS-item.grid_w,originalX + dx));
        const y = Math.max(0,originalY + dy);
        if (!collision(item.widget_key,x,y,item.grid_w,item.grid_h)) {
          item.grid_x = x; item.grid_y = y; setTilePosition(tile,item); moved = moved || x !== originalX || y !== originalY;
        }
      };
      const up = () => {
        head.removeEventListener('pointermove',move);
        head.removeEventListener('pointerup',up);
        head.removeEventListener('pointercancel',up);
        tile.classList.remove('is-dragging');
        endGridEdit();
        if (moved) scheduleSave();
      };
      head.addEventListener('pointermove',move);
      head.addEventListener('pointerup',up);
      head.addEventListener('pointercancel',up);
    });
  }

  function bindResize(tile,item) {
    const handle = tile.querySelector('.dashboard-widget-resize');
    const def = defs[item.widget_key];
    if (!handle || !def) return;
    handle.addEventListener('pointerdown', event => {
      if (!desktop.matches || event.button !== 0) return;
      event.preventDefault(); event.stopPropagation();
      const metrics = gridMetrics();
      const startX = event.clientX;
      const startY = event.clientY;
      const originalW = item.grid_w;
      const originalH = item.grid_h;
      let changed = false;
      handle.setPointerCapture?.(event.pointerId);
      tile.classList.add('is-resizing');
      beginGridEdit();

      const move = e => {
        const dw = Math.round((e.clientX - startX) / metrics.stepX);
        const dh = Math.round((e.clientY - startY) / metrics.stepY);
        const maxW = Math.min(def.maxW,COLS-item.grid_x);
        const w = Math.max(def.minW,Math.min(maxW,originalW + dw));
        const h = Math.max(def.minH,Math.min(def.maxH,originalH + dh));
        if (!collision(item.widget_key,item.grid_x,item.grid_y,w,h)) {
          item.grid_w = w; item.grid_h = h; setTilePosition(tile,item); changed = changed || w !== originalW || h !== originalH;
        }
      };
      const up = () => {
        handle.removeEventListener('pointermove',move);
        handle.removeEventListener('pointerup',up);
        handle.removeEventListener('pointercancel',up);
        tile.classList.remove('is-resizing');
        endGridEdit();
        if (changed) scheduleSave();
      };
      handle.addEventListener('pointermove',move);
      handle.addEventListener('pointerup',up);
      handle.addEventListener('pointercancel',up);
    });
  }

  function removeWidget(key) {
    layout = layout.filter(item => item.widget_key !== key);
    renderWidgets();
    renderCatalog();
    scheduleSave();
  }

  function renderWidgets() {
    canvas.innerHTML = '';
    layout = layout.map(normalized).sort((a,b)=>a.grid_y-b.grid_y || a.grid_x-b.grid_x);
    layout.forEach(item => {
      const def = defs[item.widget_key];
      if (!def) return;
      const tile = document.createElement('article');
      tile.className = 'dashboard-widget';
      tile.dataset.widgetKey = item.widget_key;
      setTilePosition(tile,item);
      tile.innerHTML = `<header class="dashboard-widget-head"><strong class="dashboard-widget-title">${esc(def.title)}</strong><div class="dashboard-widget-actions"><button class="dashboard-widget-action" type="button" data-remove-widget aria-label="Remove ${esc(def.title)}">×</button></div></header><div class="dashboard-widget-body">${renderBody(item.widget_key)}</div><span class="dashboard-widget-resize" aria-hidden="true"></span>`;
      tile.querySelector('[data-remove-widget]')?.addEventListener('click',()=>removeWidget(item.widget_key));
      bindDrag(tile,item);
      bindResize(tile,item);
      canvas.appendChild(tile);
    });
    gridMetrics();
  }

  function renderCatalog() {
    const allowed = new Set(layoutApi?.allowed_widgets || []);
    const added = new Set(layout.map(item=>item.widget_key));
    const query = String(search.value || '').trim().toLowerCase();
    const rows = Object.entries(defs).filter(([key,def]) => allowed.has(key) && (!query || `${def.title} ${def.desc}`.toLowerCase().includes(query)));
    catalog.innerHTML = rows.length ? rows.map(([key,def]) => {
      const exists = added.has(key);
      return `<article class="dashboard-catalog-item${exists?' is-added':''}"><div class="dashboard-catalog-copy"><strong>${esc(def.title)}</strong><span>${esc(def.desc)}</span></div><button class="dashboard-catalog-add" type="button" data-add-widget="${esc(key)}" ${exists?'disabled':''} aria-label="${exists?'Already added':'Add '+esc(def.title)}">${exists?'✓':'+'}</button></article>`;
    }).join('') : '<div class="dashboard-empty-widget" style="color:#AFC0D9">No matching widgets.</div>';
    catalog.querySelectorAll('[data-add-widget]').forEach(button => button.addEventListener('click',()=>addWidget(button.dataset.addWidget)));
  }

  function addWidget(key) {
    if (!defs[key] || layout.some(item=>item.widget_key===key)) return;
    const def = defs[key];
    const pos = firstOpenPosition(def.w,def.h,key,0,0);
    layout.push({widget_key:key,grid_x:pos.x,grid_y:pos.y,grid_w:def.w,grid_h:def.h});
    renderWidgets();
    renderCatalog();
    scheduleSave();
  }

  function payload() {
    return layout.map(item => ({widget_key:item.widget_key,grid_x:item.grid_x,grid_y:item.grid_y,grid_w:item.grid_w,grid_h:item.grid_h}));
  }

  async function saveLayout() {
    if (!layoutApi) return;
    try {
      showSave('Saving…');
      const next = await json('api/dashboard_layout.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'save_layout',csrf:layoutApi.csrf,layout:payload()}),
      });
      layoutApi.csrf = next.csrf || layoutApi.csrf;
      showSave('Saved');
    } catch (error) {
      console.error('MERDPOS dashboard save:',error);
      showSave('Not saved',true);
    }
  }

  function scheduleSave() {
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(saveLayout,220);
  }

  function setDrawer(open) {
    drawer.classList.toggle('open',open);
    drawer.setAttribute('aria-hidden',open?'false':'true');
    addButton.setAttribute('aria-expanded',open?'true':'false');
    if (open) window.setTimeout(()=>search.focus(),100);
  }

  addButton.addEventListener('click',event=>{event.stopPropagation();setDrawer(!drawer.classList.contains('open'));});
  drawer.addEventListener('pointerdown',event=>event.stopPropagation());
  document.addEventListener('pointerdown',event=>{if(drawer.classList.contains('open')&&!drawer.contains(event.target)&&event.target!==addButton)setDrawer(false);});
  search.addEventListener('input',renderCatalog);
  resetButton.addEventListener('click',async()=>{
    if (!layout.length) { setDrawer(false); return; }
    if (!confirm('Clear all widgets from this dashboard?')) return;
    try {
      const next = await json('api/dashboard_layout.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'reset_layout',csrf:layoutApi.csrf})});
      layoutApi = next; layout = []; renderWidgets(); renderCatalog(); setDrawer(false); showSave('Dashboard cleared');
    } catch(error){alert(error.message);}
  });

  async function loadData() {
    try {
      data = await json('api/beta_state.php');
      renderWidgets();
    } catch (error) {
      console.error('MERDPOS dashboard data:',error);
    }
  }

  async function init() {
    try {
      [layoutApi,data] = await Promise.all([json('api/dashboard_layout.php'),json('api/beta_state.php')]);
      layout = (layoutApi.layout || []).filter(item => defs[item.widget_key]).map(normalized);
      renderWidgets();
      renderCatalog();
      window.setInterval(loadData,60000);
    } catch (error) {
      console.error('MERDPOS dashboard builder:',error);
      canvas.innerHTML = `<div class="dashboard-empty-widget">${esc(error.message)}</div>`;
    }
  }

  window.addEventListener('resize',()=>window.setTimeout(gridMetrics,50));
  window.MERDPOSDashboardBuilder = {add:addWidget,clear:()=>resetButton.click(),layout:()=>payload()};
  init();
})();
