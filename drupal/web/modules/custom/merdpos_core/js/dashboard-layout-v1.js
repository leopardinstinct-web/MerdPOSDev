(function (Drupal, once) {
  'use strict';

  const COLS = 12;
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
    sync_status_table:{title:'Sync status',desc:'Outbox exceptions grouped by current status.',w:5,h:4,minW:4,minH:3,maxW:7,maxH:6}
  };  const presets = [
    {key:'store_operations',title:'Store operations',desc:'Live workforce, sales and exceptions.',widgets:['working_now_count','workforce_by_store','today_sales_by_store','pending_disputes','sync_attention','sync_status_table']},
    {key:'finance',title:'Finance',desc:'Sales movement, ranking and cash position.',widgets:['sales_change','sales_trend_7d','top_stores_sales','today_sales_by_store','store_cash_position','cash_mix']},
    {key:'workforce',title:'Workforce',desc:'Attendance movement and live workforce.',widgets:['working_now_count','attendance_change','attendance_trend_7d','workforce_by_store','working_now','recent_attendance']}
  ];

  function int(value, fallback = 0) {
    const parsed = Number.parseInt(String(value), 10);
    return Number.isFinite(parsed) ? parsed : fallback;
  }

  function overlaps(items, key, x, y, w, h) {
    return items.some((item) => item.widget_key !== key && x < item.grid_x + item.grid_w && x + w > item.grid_x && y < item.grid_y + item.grid_h && y + h > item.grid_y);
  }

  function packInOrder(items) {
    const placed = [];
    items.forEach((source) => {
      const item = {...source};
      const def = defs[item.widget_key] || {w:4,h:3,minW:1,minH:1,maxW:12,maxH:20};
      item.grid_w = Math.max(def.minW, Math.min(def.maxW, int(item.grid_w, def.w)));
      item.grid_h = Math.max(def.minH, Math.min(def.maxH, int(item.grid_h, def.h)));
      let found = false;
      for (let y = 0; y < 1000 && !found; y += 1) {
        for (let x = 0; x <= COLS - item.grid_w; x += 1) {
          if (!overlaps(placed, item.widget_key, x, y, item.grid_w, item.grid_h)) { item.grid_x = x; item.grid_y = y; placed.push(item); found = true; break; }
        }
      }
      if (!found) placed.push(item);
    });
    return placed;
  }  function compact(items) {
    return packInOrder(items.slice().sort((a,b) => a.grid_y - b.grid_y || a.grid_x - b.grid_x));
  }

  function firstOpen(items, w, h) {
    for (let y = 0; y < 1000; y += 1) {
      for (let x = 0; x <= COLS - w; x += 1) {
        if (!overlaps(items, '', x, y, w, h)) return {x,y};
      }
    }
    return {x:0,y:0};
  }

  function init(root) {
    const grid = root.querySelector('[data-dashboard-grid]');
    if (!grid) return;
    const endpoint = root.dataset.endpoint || '';
    const csrf = root.dataset.csrf || '';
    const roleId = int(root.dataset.roleId);
    const allowed = new Set(String(root.dataset.allowedWidgets || '').split(',').map((value) => value.trim()).filter(Boolean));
    const editButton = root.querySelector('[data-dashboard-edit]');
    const addButton = root.querySelector('[data-dashboard-add]');
    const saveState = root.querySelector('[data-dashboard-save-state]');
    const drawer = root.querySelector('[data-dashboard-drawer]');
    const backdrop = root.querySelector('[data-dashboard-backdrop]');
    const catalog = root.querySelector('[data-dashboard-catalog]');
    const search = root.querySelector('[data-dashboard-search]');
    const templateList = root.querySelector('[data-dashboard-template-list]');
    const roleSelect = root.querySelector('[data-dashboard-role]');
    let editing = false;
    let busy = false;
    let layout = Array.from(root.querySelectorAll('[data-dashboard-item]')).map((node) => ({widget_key:node.dataset.widget,grid_x:int(node.dataset.gridX),grid_y:int(node.dataset.gridY),grid_w:int(node.dataset.gridW,1),grid_h:int(node.dataset.gridH,1)}));

    const show = (message, error = false) => {
      if (!saveState) return;
      saveState.textContent = message;
      saveState.dataset.error = error ? '1' : '0';
      window.clearTimeout(show.timer);
      show.timer = window.setTimeout(() => { if (!busy) saveState.textContent = ''; }, 1800);
    };    async function request(action, body = {}) {
      if (busy) throw new Error('A dashboard update is already in progress.');
      busy = true; show('Saving…');
      try {
        const response = await fetch(endpoint, {method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-MERDPOS-CSRF':csrf},body:JSON.stringify({action,role_id:roleId,...body})});
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.success !== true) throw new Error(payload?.error || `Dashboard update failed (${response.status}).`);
        show('Saved');
        return payload;
      } finally {
        busy = false;
      }
    }

    async function save(next, reload = false) {
      layout = compact(next);
      applyLayout();
      await request('save_layout', {layout});
      if (reload) window.location.reload();
    }

    function applyLayout() {
      const nodes = new Map(Array.from(root.querySelectorAll('[data-dashboard-item]')).map((node) => [node.dataset.widget, node]));
      layout.forEach((item) => {
        const node = nodes.get(item.widget_key); if (!node) return;
        node.dataset.gridX = String(item.grid_x); node.dataset.gridY = String(item.grid_y);
        node.dataset.gridW = String(item.grid_w); node.dataset.gridH = String(item.grid_h);
        node.style.gridColumn = `${item.grid_x + 1} / span ${item.grid_w}`;
        node.style.gridRow = `${item.grid_y + 1} / span ${item.grid_h}`;
      });
    }

    function openDrawer() {
      if (!drawer || !editing) return;
      renderCatalog();
      drawer.classList.add('open'); drawer.setAttribute('aria-hidden','false');
      addButton?.setAttribute('aria-expanded','true'); if (backdrop) backdrop.hidden = false;
      window.setTimeout(() => search?.focus(), 0);
    }

    function closeDrawer() {
      if (!drawer) return;
      drawer.classList.remove('open'); drawer.setAttribute('aria-hidden','true');
      addButton?.setAttribute('aria-expanded','false'); if (backdrop) backdrop.hidden = true;
    }

    function toggleEditing(force = null) {
      if (!editButton) return;
      editing = force === null ? !editing : Boolean(force);
      root.classList.toggle('is-editing', editing);
      editButton.setAttribute('aria-pressed', editing ? 'true' : 'false');
      editButton.textContent = editing ? 'Done editing' : 'Edit dashboard';
      if (addButton) addButton.hidden = !editing;
      if (!editing) closeDrawer();
    }
    function renderCatalog() {
      if (!catalog) return;
      const query = String(search?.value || '').trim().toLowerCase();
      const added = new Set(layout.map((item) => item.widget_key));
      catalog.replaceChildren();
      Object.entries(defs).filter(([key, def]) => allowed.has(key) && (!query || `${def.title} ${def.desc}`.toLowerCase().includes(query))).forEach(([key, def]) => {
        const row = document.createElement('div'); row.className = 'merdpos-dashboard-catalog-item';
        const copy = document.createElement('div'); const title = document.createElement('strong'); const desc = document.createElement('span');
        title.textContent = def.title; desc.textContent = def.desc; copy.append(title, desc);
        const button = document.createElement('button'); button.type = 'button'; button.dataset.addWidget = key;
        button.disabled = added.has(key); button.textContent = added.has(key) ? '✓' : '+'; button.setAttribute('aria-label', added.has(key) ? `${def.title} already added` : `Add ${def.title}`);
        button.addEventListener('click', () => addWidget(key).catch((error) => show(error.message, true)));
        row.append(copy, button); catalog.append(row);
      });
      if (!catalog.children.length) { const empty = document.createElement('div'); empty.className = 'merdpos-dashboard-empty'; empty.textContent = 'No widgets available for this role.'; catalog.append(empty); }
      renderTemplates();
    }

    function renderTemplates() {
      if (!templateList) return;
      const added = new Set(layout.map((item) => item.widget_key));
      templateList.replaceChildren();
      presets.forEach((preset) => {
        const available = preset.widgets.filter((key) => allowed.has(key)); if (!available.length) return;
        const missing = available.filter((key) => !added.has(key));
        const button = document.createElement('button'); button.type = 'button'; button.className = 'merdpos-dashboard-template'; button.disabled = !missing.length;
        const strong = document.createElement('strong'); strong.textContent = preset.title; const span = document.createElement('span'); span.textContent = preset.desc; const small = document.createElement('small'); small.textContent = missing.length ? `Add ${missing.length} available widget${missing.length === 1 ? '' : 's'}` : 'Already applied';
        button.append(strong, span, small); button.addEventListener('click', () => applyTemplate(preset).catch((error) => show(error.message, true))); templateList.append(button);
      });
    }
    async function addWidget(key) {
      if (!editing || !allowed.has(key) || layout.some((item) => item.widget_key === key) || !defs[key]) return;
      const def = defs[key]; const pos = firstOpen(layout, def.w, def.h);
      const next = layout.concat([{widget_key:key,grid_x:pos.x,grid_y:pos.y,grid_w:def.w,grid_h:def.h}]);
      await save(next, true);
    }

    async function applyTemplate(preset) {
      const added = new Set(layout.map((item) => item.widget_key)); let next = layout.slice(); let count = 0;
      preset.widgets.forEach((key) => {
        if (!allowed.has(key) || added.has(key) || !defs[key]) return;
        const def = defs[key]; const pos = firstOpen(next, def.w, def.h);
        next.push({widget_key:key,grid_x:pos.x,grid_y:pos.y,grid_w:def.w,grid_h:def.h}); added.add(key); count += 1;
      });
      if (!count) { show('Template already applied'); return; }
      await save(next, true);
    }

    async function removeWidget(key) {
      if (!editing) return;
      await save(layout.filter((item) => item.widget_key !== key), true);
    }

    async function moveMobile(key, direction) {
      if (!editing || desktop.matches) return;
      const ordered = layout.slice().sort((a,b) => a.grid_y - b.grid_y || a.grid_x - b.grid_x);
      const index = ordered.findIndex((item) => item.widget_key === key); const target = index + direction;
      if (index < 0 || target < 0 || target >= ordered.length) return;
      [ordered[index], ordered[target]] = [ordered[target], ordered[index]];
      await save(packInOrder(ordered), true);
    }
    function gridMetrics() {
      const rect = grid.getBoundingClientRect(); const style = window.getComputedStyle(grid);
      const gapX = Number.parseFloat(style.columnGap) || 12; const gapY = Number.parseFloat(style.rowGap) || 12;
      const row = Number.parseFloat(style.gridAutoRows) || 72; const col = (rect.width - gapX * (COLS - 1)) / COLS;
      return {stepX:col + gapX, stepY:row + gapY};
    }

    function bindItem(node) {
      const key = node.dataset.widget;
      node.querySelector('[data-dashboard-remove]')?.addEventListener('click', () => removeWidget(key).catch((error) => show(error.message, true)));
      node.querySelector('[data-dashboard-up]')?.addEventListener('click', () => moveMobile(key, -1).catch((error) => show(error.message, true)));
      node.querySelector('[data-dashboard-down]')?.addEventListener('click', () => moveMobile(key, 1).catch((error) => show(error.message, true)));
      const drag = node.querySelector('[data-dashboard-drag]');
      drag?.addEventListener('pointerdown', (event) => {
        if (!editing || !desktop.matches || event.button !== 0) return;
        event.preventDefault(); const item = layout.find((row) => row.widget_key === key); if (!item) return;
        const m = gridMetrics(); const sx = event.clientX; const sy = event.clientY; const ox = item.grid_x; const oy = item.grid_y;
        node.classList.add('is-dragging'); drag.setPointerCapture?.(event.pointerId);
        const move = (e) => {
          const x = Math.max(0, Math.min(COLS - item.grid_w, Math.round(ox + (e.clientX - sx) / m.stepX)));
          const y = Math.max(0, Math.round(oy + (e.clientY - sy) / m.stepY));
          if (!overlaps(layout, key, x, y, item.grid_w, item.grid_h)) { item.grid_x = x; item.grid_y = y; applyLayout(); }
        };
        const up = async () => {
          drag.removeEventListener('pointermove', move); drag.removeEventListener('pointerup', up); drag.removeEventListener('pointercancel', up); node.classList.remove('is-dragging');
          layout = compact(layout); applyLayout(); window.dispatchEvent(new Event('resize'));
          try { await request('save_layout', {layout}); } catch (error) { show(error.message, true); }
        };
        drag.addEventListener('pointermove', move); drag.addEventListener('pointerup', up); drag.addEventListener('pointercancel', up);
      });
    }
    function bindResize(node) {
      const key = node.dataset.widget; const handle = node.querySelector('[data-dashboard-resize]');
      handle?.addEventListener('pointerdown', (event) => {
        if (!editing || !desktop.matches || event.button !== 0) return;
        event.preventDefault(); event.stopPropagation(); const item = layout.find((row) => row.widget_key === key); if (!item) return;
        const def = defs[key] || {minW:1,minH:1,maxW:12,maxH:20}; const m = gridMetrics();
        const sx = event.clientX; const sy = event.clientY; const ow = item.grid_w; const oh = item.grid_h;
        node.classList.add('is-resizing'); handle.setPointerCapture?.(event.pointerId);
        const move = (e) => {
          let w = Math.max(def.minW, Math.min(def.maxW, Math.round(ow + (e.clientX - sx) / m.stepX)));
          const h = Math.max(def.minH, Math.min(def.maxH, Math.round(oh + (e.clientY - sy) / m.stepY)));
          w = Math.min(w, COLS - item.grid_x);
          if (!overlaps(layout, key, item.grid_x, item.grid_y, w, h)) { item.grid_w = w; item.grid_h = h; applyLayout(); }
        };
        const up = async () => {
          handle.removeEventListener('pointermove', move); handle.removeEventListener('pointerup', up); handle.removeEventListener('pointercancel', up); node.classList.remove('is-resizing');
          layout = compact(layout); applyLayout(); window.dispatchEvent(new Event('resize'));
          try { await request('save_layout', {layout}); } catch (error) { show(error.message, true); }
        };
        handle.addEventListener('pointermove', move); handle.addEventListener('pointerup', up); handle.addEventListener('pointercancel', up);
      });
    }

    Array.from(root.querySelectorAll('[data-dashboard-item]')).forEach((node) => { bindItem(node); bindResize(node); });
    editButton?.addEventListener('click', () => toggleEditing());
    addButton?.addEventListener('click', openDrawer);
    root.querySelector('[data-dashboard-close]')?.addEventListener('click', closeDrawer);
    backdrop?.addEventListener('click', closeDrawer);
    search?.addEventListener('input', renderCatalog);    root.querySelector('[data-dashboard-reset]')?.addEventListener('click', async () => {
      if (!editing) return;
      const label = roleSelect?.selectedOptions?.[0]?.textContent?.trim() || 'selected role';
      if (!window.confirm(`Clear the ${label} dashboard?`)) return;
      try { await request('reset_layout'); window.location.reload(); } catch (error) { show(error.message, true); }
    });

    roleSelect?.addEventListener('change', () => {
      const nextRole = int(roleSelect.value); if (!nextRole || nextRole === roleId) return;
      const url = new URL(window.location.href); url.searchParams.set('role_id', String(nextRole)); window.location.assign(url.toString());
    });

    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && drawer?.classList.contains('open')) closeDrawer(); });
    renderCatalog();
  }

  Drupal.behaviors.merdposDashboardLayoutV1 = {
    attach(context) {
      once('merdpos-dashboard-layout-v1', '[data-dashboard-layout]', context).forEach(init);
    }
  };
})(Drupal, once);
