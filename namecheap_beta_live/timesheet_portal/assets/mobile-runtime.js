(function () {
  'use strict';

  const layoutQuery = window.matchMedia ? window.matchMedia('(max-width: 820px)') : {matches:false};
  const coarseQuery = window.matchMedia ? window.matchMedia('(pointer: coarse)') : {matches:false};
  const root = document.documentElement;
  const body = document.body;
  let auditTimer = null;
  let dashboardEnhanceTimer = null;
  let mobileEnhanceTimer = null;

  function isMobileLayout() {
    return !!layoutQuery.matches;
  }

  function setViewportState() {
    const vv = window.visualViewport;
    const height = vv && Number(vv.height) > 0 ? Number(vv.height) : window.innerHeight;
    root.style.setProperty('--merd-mobile-visual-height', Math.round(height) + 'px');
    body.classList.toggle('merd-mobile-ui', isMobileLayout());
    body.classList.toggle('merd-coarse-pointer', !!coarseQuery.matches);

    const baseline = Math.max(window.innerHeight || height, 1);
    const keyboardOpen = isMobileLayout() && height < baseline * 0.76;
    body.classList.toggle('merd-keyboard-open', keyboardOpen);
  }

  function safeScrollTop() {
    if (!isMobileLayout()) return;
    try { window.scrollTo({top:0,left:0,behavior:'auto'}); }
    catch (_) { window.scrollTo(0, 0); }
  }

  function keepActiveSubnavVisible(tab) {
    if (!isMobileLayout() || !tab) return;
    window.setTimeout(function () {
      try { tab.scrollIntoView({block:'nearest', inline:'center', behavior:'auto'}); }
      catch (_) { try { tab.scrollIntoView(false); } catch (__){ } }
    }, 0);
  }

  function syncNavigationAccessibility() {
    const visiblePanel = Array.from(document.querySelectorAll('.portal-panel')).find(function (panel) { return !panel.hidden; });
    const panelId = visiblePanel ? visiblePanel.id : '';
    document.querySelectorAll('.portal-tab').forEach(function (tab) {
      if (tab.dataset.panel === panelId) tab.setAttribute('aria-current', 'page');
      else tab.removeAttribute('aria-current');
    });
  }

  document.addEventListener('click', function (event) {
    const tab = event.target.closest && event.target.closest('.app-rail .portal-tab');
    if (tab) {
      keepActiveSubnavVisible(tab);
      window.setTimeout(function () {
        syncNavigationAccessibility();
        safeScrollTop();
      }, 0);
    }

    const group = event.target.closest && event.target.closest('.app-rail .rail-group-btn');
    if (group && isMobileLayout()) {
      window.setTimeout(function () {
        const section = group.closest('.rail-section');
        const active = section && section.querySelector('.sidebar-group .portal-tab.active');
        keepActiveSubnavVisible(active);
      }, 0);
    }
  }, true);

  /* ----------------------------------------------------------------------
     Dialog compatibility and mobile keyboard reliability.
     Native dialog remains authoritative where supported. Older browsers get
     a very small standards-compatible fallback instead of dead buttons.
     ---------------------------------------------------------------------- */
  function installDialogFallback(dialog) {
    if (!dialog || dialog.dataset.merdDialogCompat === '1') return;
    dialog.dataset.merdDialogCompat = '1';
    if (typeof dialog.showModal === 'function' && typeof dialog.close === 'function') return;

    let backdrop = null;
    const closeFallback = function (returnValue) {
      if (!dialog.hasAttribute('open')) return;
      dialog.returnValue = returnValue == null ? '' : String(returnValue);
      dialog.removeAttribute('open');
      dialog.classList.remove('merd-dialog-fallback');
      if (backdrop) backdrop.remove();
      backdrop = null;
      dialog.dispatchEvent(new Event('close'));
    };

    dialog.showModal = function () {
      if (dialog.hasAttribute('open')) return;
      backdrop = document.createElement('div');
      backdrop.className = 'merd-dialog-fallback-backdrop';
      backdrop.setAttribute('aria-hidden', 'true');
      backdrop.addEventListener('click', function () { closeFallback(''); });
      document.body.appendChild(backdrop);
      dialog.classList.add('merd-dialog-fallback');
      dialog.setAttribute('open', '');
      window.setTimeout(function () {
        const focusTarget = dialog.querySelector('[autofocus],input:not([type="hidden"]),select,textarea,button');
        if (focusTarget && typeof focusTarget.focus === 'function') focusTarget.focus();
      }, 0);
    };
    dialog.close = closeFallback;
  }

  function enhanceDialogs(scope) {
    const host = scope || document;
    if (host.matches && host.matches('dialog')) installDialogFallback(host);
    if (host.querySelectorAll) host.querySelectorAll('dialog').forEach(installDialogFallback);
  }

  /* ----------------------------------------------------------------------
     Dashboard mobile editing.
     Desktop keeps drag/resize. Mobile adds deterministic up/down ordering,
     explicit drawer close and a backdrop so every editing action remains usable.
     ---------------------------------------------------------------------- */
  function dashboardRoleId() {
    const select = document.getElementById('dashboardRoleSelect');
    if (select && select.value) return Number(select.value) || null;
    return null;
  }

  async function dashboardJson(url, options) {
    const response = await fetch(url, Object.assign({cache:'no-store', headers:{'Accept':'application/json'}}, options || {}));
    const text = await response.text();
    let payload = null;
    try { payload = text ? JSON.parse(text) : null; }
    catch (_) { throw new Error('Dashboard returned invalid data (' + response.status + ').'); }
    if (!payload || !payload.success) throw new Error((payload && payload.error) || 'Dashboard request failed.');
    return payload;
  }

  function dashboardLayoutUrl(roleId) {
    return 'api/dashboard_layout.php' + (roleId ? '?role_id=' + encodeURIComponent(roleId) : '');
  }

  function layoutCollides(placed, x, y, w, h) {
    return placed.some(function (item) {
      return x < Number(item.grid_x) + Number(item.grid_w)
        && x + w > Number(item.grid_x)
        && y < Number(item.grid_y) + Number(item.grid_h)
        && y + h > Number(item.grid_y);
    });
  }

  function repackLayout(items) {
    const placed = [];
    items.forEach(function (source) {
      const item = Object.assign({}, source);
      const w = Math.max(1, Math.min(12, Number(item.grid_w) || 4));
      const h = Math.max(1, Number(item.grid_h) || 2);
      let found = false;
      for (let y = 0; y < 1000 && !found; y += 1) {
        for (let x = 0; x <= 12 - w; x += 1) {
          if (!layoutCollides(placed, x, y, w, h)) {
            item.grid_x = x;
            item.grid_y = y;
            item.grid_w = w;
            item.grid_h = h;
            placed.push(item);
            found = true;
            break;
          }
        }
      }
      if (!found) placed.push(item);
    });
    return placed;
  }

  async function moveDashboardWidget(widgetKey, direction, button) {
    if (!widgetKey || !isMobileLayout()) return;
    const roleId = dashboardRoleId();
    const payload = await dashboardJson(dashboardLayoutUrl(roleId));
    if (!payload.can_edit) return;

    const ordered = (payload.layout || []).slice().sort(function (a, b) {
      return Number(a.grid_y) - Number(b.grid_y) || Number(a.grid_x) - Number(b.grid_x);
    });
    const index = ordered.findIndex(function (item) { return String(item.widget_key) === String(widgetKey); });
    const target = index + direction;
    if (index < 0 || target < 0 || target >= ordered.length) return;

    const temp = ordered[index];
    ordered[index] = ordered[target];
    ordered[target] = temp;
    const nextLayout = repackLayout(ordered);

    const actionButtons = document.querySelectorAll('.merd-dashboard-mobile-order');
    actionButtons.forEach(function (node) { node.disabled = true; });
    if (button) button.setAttribute('aria-busy', 'true');
    try {
      await dashboardJson('api/dashboard_layout.php', {
        method:'POST',
        headers:{'Accept':'application/json','Content-Type':'application/json'},
        body:JSON.stringify({
          action:'save_layout',
          role_id:Number(payload.selected_role && payload.selected_role.id) || roleId,
          csrf:payload.csrf,
          layout:nextLayout
        })
      });
      if (window.MERDPOSDashboardBuilder && typeof window.MERDPOSDashboardBuilder.reloadRoles === 'function') {
        await window.MERDPOSDashboardBuilder.reloadRoles();
      } else {
        window.location.reload();
      }
    } finally {
      actionButtons.forEach(function (node) { node.disabled = false; });
      if (button) button.removeAttribute('aria-busy');
    }
  }

  function closeDashboardDrawer() {
    const drawer = document.getElementById('dashboardWidgetDrawer');
    const add = document.getElementById('dashboardAddButton');
    const backdrop = document.querySelector('.merd-dashboard-drawer-backdrop');
    if (!drawer) return;
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
    if (add) add.setAttribute('aria-expanded', 'false');
    if (backdrop) backdrop.hidden = true;
  }

  function syncDashboardDrawerBackdrop() {
    const drawer = document.getElementById('dashboardWidgetDrawer');
    const backdrop = document.querySelector('.merd-dashboard-drawer-backdrop');
    if (!drawer || !backdrop) return;
    backdrop.hidden = !(isMobileLayout() && drawer.classList.contains('open'));
  }

  function enhanceDashboard() {
    const drawer = document.getElementById('dashboardWidgetDrawer');
    if (!drawer) return;

    let backdrop = document.querySelector('.merd-dashboard-drawer-backdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.className = 'merd-dashboard-drawer-backdrop';
      backdrop.hidden = true;
      backdrop.setAttribute('aria-hidden', 'true');
      backdrop.addEventListener('click', closeDashboardDrawer);
      drawer.parentNode.insertBefore(backdrop, drawer);
    }

    const head = drawer.querySelector('.dashboard-drawer-head');
    if (head && !head.querySelector('.merd-dashboard-drawer-close')) {
      const close = document.createElement('button');
      close.type = 'button';
      close.className = 'merd-dashboard-drawer-close';
      close.setAttribute('aria-label', 'Close widget picker');
      close.textContent = '×';
      close.addEventListener('click', closeDashboardDrawer);
      head.insertBefore(close, head.children[1] || null);
    }

    drawer.querySelectorAll('.dashboard-widget').forEach(function () {});
    document.querySelectorAll('.dashboard-widget').forEach(function (tile) {
      const actions = tile.querySelector('.dashboard-widget-actions');
      const key = tile.dataset.widget;
      if (!actions || !key || actions.querySelector('[data-mobile-order]')) return;
      const remove = actions.querySelector('[data-remove]');
      if (!remove) return; // read-only role dashboards should remain read-only.

      const up = document.createElement('button');
      up.type = 'button';
      up.className = 'dashboard-widget-action merd-dashboard-mobile-order';
      up.dataset.mobileOrder = 'up';
      up.setAttribute('aria-label', 'Move widget up');
      up.textContent = '↑';

      const down = document.createElement('button');
      down.type = 'button';
      down.className = 'dashboard-widget-action merd-dashboard-mobile-order';
      down.dataset.mobileOrder = 'down';
      down.setAttribute('aria-label', 'Move widget down');
      down.textContent = '↓';

      up.addEventListener('click', function () {
        moveDashboardWidget(key, -1, up).catch(function (error) { window.alert(error.message); });
      });
      down.addEventListener('click', function () {
        moveDashboardWidget(key, 1, down).catch(function (error) { window.alert(error.message); });
      });

      actions.insertBefore(up, remove);
      actions.insertBefore(down, remove);
    });

    syncDashboardDrawerBackdrop();
  }

  /* ----------------------------------------------------------------------
     Mobile UX reference adaptation.
     Keeps MERDPOS branding while applying phone-native hierarchy, reachable
     utilities, responsive lists and accessible inline loading states.
     ---------------------------------------------------------------------- */
  const mobilePanelMeta = {
    dashboardPanel: ['Dashboard', 'Live operational overview for your current client.'],
    employeesPanel: ['Employees', 'People, access and store assignments.'],
    storesPanel: ['Stores', 'Store identity, availability and operating timings.'],
    reportsPanel: ['Reports', 'Operational reports and focused drill-down.'],
    timesheetPanel: ['Timesheets', 'Weekly hours, wages and shift activity.'],
    disputesPanel: ['Disputes', 'Attendance issues and correction workflow.'],
    financialPanel: ['Financial', 'Daily cash position and financial workflow.'],
    devPanel: ['DEV', 'Read-only system diagnostics and tools.'],
    clientsPanel: ['Clients', 'Client accounts and tenant identity.']
  };
  const accountIcon = '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0"/></svg>';

  function currentClientLabel() {
    const context = window.MERDPOSAccountContext?.get?.();
    const name = String(context?.client?.name || '').trim();
    const code = String(context?.client?.client_code || '').trim();
    return name ? `${name}${code ? ` - ${code}` : ''}` : 'MERDPOS';
  }

  function panelMeta(panel) {
    const configured = mobilePanelMeta[panel.id];
    if (configured) return configured;
    const source = panel.querySelector(':scope > .app-panel-head h2, :scope > .directory-card .directory-toolbar h2, :scope > .controls-card h2');
    const title = String(source?.textContent || panel.id.replace(/Panel$/, '') || 'MERDPOS').trim();
    return [title, 'Review and manage this area for the current client.'];
  }

  function ensureMobileHeaders() {
    if (!isMobileLayout()) return;
    document.querySelectorAll('.portal-panel').forEach(function (panel) {
      let header = panel.querySelector(':scope > .merd-mobile-page-head');
      if (!header) {
        const meta = panelMeta(panel);
        header = document.createElement('header');
        header.className = 'merd-mobile-page-head';
        header.innerHTML = `<div class="merd-mobile-page-copy"><span class="merd-mobile-context"></span><h1>${meta[0]}</h1></div><button type="button" class="merd-mobile-account-trigger" aria-label="Account, client and app settings">${accountIcon}</button><nav class="merd-mobile-subtabs" aria-label="${meta[0]} sections"></nav>`;
        header.querySelector('.merd-mobile-account-trigger')?.addEventListener('click', function (event) {
          window.MERDPOSShellUtilities?.open?.(event.currentTarget);
        });
        panel.prepend(header);
      }
      const context = header.querySelector('.merd-mobile-context');
      if (context) context.textContent = currentClientLabel();
    });
  }

  function syncMobileHeader() {
    if (!isMobileLayout()) return;
    const panel = Array.from(document.querySelectorAll('.portal-panel')).find(candidate => !candidate.hidden);
    if (!panel) return;
    const header = panel.querySelector(':scope > .merd-mobile-page-head');
    if (!header) return;
    const nav = header.querySelector('.merd-mobile-subtabs');
    if (!nav) return;
    const original = Array.from(document.querySelectorAll('.portal-tab')).find(tab => tab.dataset.panel === panel.id);
    const subgroup = original?.closest('.sidebar-group');
    const tabs = subgroup ? Array.from(subgroup.querySelectorAll('.portal-tab')) : [];
    nav.replaceChildren();
    if (tabs.length <= 1) return;
    tabs.forEach(function (tab) {
      const button = document.createElement('button');
      const label = String(tab.querySelector('span:not(.nav-badge)')?.textContent || tab.dataset.panel || 'Section').trim();
      button.type = 'button';
      button.className = 'merd-mobile-subtab' + (tab.dataset.panel === panel.id ? ' active' : '');
      button.textContent = label;
      if (tab.dataset.panel === panel.id) button.setAttribute('aria-current', 'page');
      button.addEventListener('click', function () { tab.click(); scheduleMobileEnhance(); });
      nav.appendChild(button);
    });
  }

  function enhanceMobileTables(scope = document) {
    scope.querySelectorAll('.table-scroll table').forEach(function (table) {
      if (table.closest('#timesheetPanel') || table.classList.contains('merd-mobile-card-table')) return;
      const headers = Array.from(table.querySelectorAll('thead tr:last-child th')).map(th => String(th.textContent || '').trim());
      if (!headers.length) return;
      table.classList.add('merd-mobile-card-table');
      table.querySelectorAll('tbody tr').forEach(function (row) {
        Array.from(row.children).forEach(function (cell, index) {
          if (cell.tagName !== 'TD') return;
          if (Number(cell.colSpan || 1) > 1) cell.classList.add('merd-mobile-span-cell');
          else if (!cell.dataset.label) cell.dataset.label = headers[index] || `Column ${index + 1}`;
        });
      });
    });
  }

  function markInlineLoading(scope = document) {
    scope.querySelectorAll('.status-card').forEach(function (card) {
      const loading = /^loading\b/i.test(String(card.textContent || '').trim());
      card.classList.toggle('merd-inline-loading', loading);
      if (loading) {
        card.setAttribute('role', 'status');
        card.setAttribute('aria-live', 'polite');
      } else if (card.classList.contains('merd-inline-loading') === false && card.getAttribute('role') === 'status') {
        card.removeAttribute('role');
        card.removeAttribute('aria-live');
      }
    });
  }

  function syncMobileHeaderScroll() {
    body.classList.toggle('merd-mobile-header-collapsed', isMobileLayout() && window.scrollY > 72);
  }

  function enhanceMobileUX() {
    if (!isMobileLayout()) return;
    ensureMobileHeaders();
    enhanceMobileTables(document);
    markInlineLoading(document);
    syncMobileHeader();
    syncMobileHeaderScroll();
  }

  function scheduleMobileEnhance() {
    window.clearTimeout(mobileEnhanceTimer);
    mobileEnhanceTimer = window.setTimeout(enhanceMobileUX, 50);
  }

  /* ----------------------------------------------------------------------
     Mobile runtime audit. This does not replace device testing; it catches the
     common regressions that previously escaped source-only validation.
     ---------------------------------------------------------------------- */
  function visible(element) {
    if (!element || element.hidden) return false;
    const style = window.getComputedStyle(element);
    if (style.display === 'none' || style.visibility === 'hidden') return false;
    const rect = element.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  }

  function auditMobile() {
    const issues = [];
    if (!isMobileLayout()) return issues;

    if (document.documentElement.scrollWidth > document.documentElement.clientWidth + 2) {
      issues.push('page-horizontal-overflow');
    }

    const topbar = document.querySelector('.merd-topbar');
    if (visible(topbar) && topbar.getBoundingClientRect().height > 68) issues.push('topbar-wrapped');

    document.querySelectorAll('button,a[href],summary,input,select,textarea,[role="button"]').forEach(function (element) {
      if (!visible(element)) return;
      const rect = element.getBoundingClientRect();
      const isInlineLink = element.tagName === 'A' && rect.height < 30;
      if (!isInlineLink && (rect.width < 44 || rect.height < 44)) {
        const id = element.id || element.getAttribute('aria-label') || element.className || element.tagName;
        issues.push('small-touch-target:' + String(id).slice(0, 80));
      }
    });

    document.querySelectorAll('dialog[open]').forEach(function (dialog) {
      const rect = dialog.getBoundingClientRect();
      if (rect.bottom > window.innerHeight + 2 || rect.top < -2) issues.push('dialog-outside-viewport:' + (dialog.id || 'dialog'));
    });

    document.querySelectorAll('.merd-icon-action').forEach(function (button) {
      if (!visible(button)) return;
      const rect = button.getBoundingClientRect();
      if (Math.abs(rect.width - rect.height) > 1) issues.push('non-square-icon-action:' + (button.id || 'action'));
    });

    const unique = Array.from(new Set(issues));
    root.dataset.merdMobileAudit = unique.length ? 'fail' : 'pass';
    if (unique.length && window.console && console.warn) console.warn('MERDPOS mobile audit:', unique);
    return unique;
  }

  function scheduleAudit() {
    window.clearTimeout(auditTimer);
    auditTimer = window.setTimeout(auditMobile, 250);
  }

  function scheduleDashboardEnhance() {
    window.clearTimeout(dashboardEnhanceTimer);
    dashboardEnhanceTimer = window.setTimeout(enhanceDashboard, 40);
  }

  const observer = new MutationObserver(function (records) {
    if (records.some(function (record) { return record.addedNodes && record.addedNodes.length; })) {
      enhanceDialogs(document);
      scheduleDashboardEnhance();
      scheduleMobileEnhance();
      scheduleAudit();
    }
    if (records.some(function (record) { return record.type === 'attributes' && record.target && record.target.id === 'dashboardWidgetDrawer'; })) {
      syncDashboardDrawerBackdrop();
    }
    if (records.some(function (record) { return record.type === 'attributes' && record.target; })) scheduleMobileEnhance();
  });
  observer.observe(document.documentElement, {subtree:true, childList:true, attributes:true, attributeFilter:['class','open','hidden']});

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && isMobileLayout()) closeDashboardDrawer();
  });

  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', function () { setViewportState(); scheduleAudit(); });
    window.visualViewport.addEventListener('scroll', setViewportState);
  }
  window.addEventListener('resize', function () { setViewportState(); scheduleDashboardEnhance(); scheduleMobileEnhance(); scheduleAudit(); });
  window.addEventListener('orientationchange', function () { window.setTimeout(function () { setViewportState(); scheduleDashboardEnhance(); scheduleMobileEnhance(); scheduleAudit(); }, 120); });
  layoutQuery.addEventListener && layoutQuery.addEventListener('change', function () { setViewportState(); scheduleDashboardEnhance(); scheduleMobileEnhance(); scheduleAudit(); });

  setViewportState();
  enhanceDialogs(document);
  syncNavigationAccessibility();
  scheduleDashboardEnhance();
  scheduleMobileEnhance();
  window.addEventListener('load', scheduleAudit, {once:true});
  window.setTimeout(scheduleAudit, 700);

  window.addEventListener('scroll', syncMobileHeaderScroll, {passive:true});
  window.addEventListener('merdpos-clientcontext', scheduleMobileEnhance);
  document.addEventListener('click', function (event) {
    if (event.target.closest && (event.target.closest('.portal-tab') || event.target.closest('.rail-group-btn'))) scheduleMobileEnhance();
  });

  window.MERDPOSMobileRuntime = {
    audit: auditMobile,
    enhance: function () {
      setViewportState();
      enhanceDialogs(document);
      enhanceDashboard();
      enhanceMobileUX();
      syncNavigationAccessibility();
      return auditMobile();
    }
  };
})();
