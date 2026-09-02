(function () {
  'use strict';

  const source = document.getElementById('shellAccountSources');
  if (!source) return;

  const auth = window.MERDPOS_AUTH || {};
  const passwordBtn = document.getElementById('passwordBtn');
  const logoutBtn = document.getElementById('logoutBtn');
  const name = String(source.dataset.userName || 'Account').trim() || 'Account';
  const roleLabel = String(source.dataset.roleLabel || auth.actual_role_label || auth.role_label || 'USER').trim() || 'USER';
  const roleKey = String(source.dataset.roleKey || auth.actual_role_key || auth.role_key || roleLabel).trim().toUpperCase();
  const viewRoleKey = String(source.dataset.viewRoleKey || auth.view_role_key || (roleKey==='DEV'?'DEV':'ADMIN')).trim().toUpperCase();
  const roleClass = ['DEV','SUPER','ADMIN','USER'].includes(roleKey) ? roleKey.toLowerCase() : 'user';
  const STUDIO_SETTINGS_KEY='merdpos-ui-studio-settings-v1';
  const ACCOUNT_CONTEXT_STATE_KEY='merdpos-account-context-sections-v1';
  const ACCOUNT_UI_STATE_KEY='merdpos-account-tools-ui-v1';
  function readStudioSettings(){try{const saved=JSON.parse(localStorage.getItem(STUDIO_SETTINGS_KEY)||'null');return saved&&typeof saved==='object'?saved:{};}catch(_){return {};}}
  function writeStudioEnabled(enabled){const saved=readStudioSettings();saved.enabled=!!enabled;try{localStorage.setItem(STUDIO_SETTINGS_KEY,JSON.stringify(saved));}catch(_){}return saved;}
  function readContextSectionState(){try{const saved=JSON.parse(localStorage.getItem(ACCOUNT_CONTEXT_STATE_KEY)||'{}');return saved&&typeof saved==='object'?saved:{};}catch(_){return {};}}
  function writeContextSectionState(state){try{localStorage.setItem(ACCOUNT_CONTEXT_STATE_KEY,JSON.stringify(state));}catch(_){}}
  function readAccountUiState(){try{const saved=JSON.parse(localStorage.getItem(ACCOUNT_UI_STATE_KEY)||'{}');return saved&&typeof saved==='object'?saved:{};}catch(_){return {};}}
  function writeAccountUiState(open){try{localStorage.setItem(ACCOUNT_UI_STATE_KEY,JSON.stringify({open:!!open}));}catch(_){}}
  function wireContextSections(root){const saved=readContextSectionState();root.querySelectorAll('.rail-collapsible-context').forEach(section=>{const key=section.dataset.contextKey||'',toggle=section.querySelector('.rail-context-toggle'),body=section.querySelector('.rail-context-body');if(!key||!toggle||!body)return;const setCollapsed=collapsed=>{section.classList.toggle('is-collapsed',collapsed);body.hidden=collapsed;toggle.setAttribute('aria-expanded',collapsed?'false':'true');toggle.title=collapsed?'Expand section':'Minimize section';};setCollapsed(saved[key]===true);toggle.addEventListener('click',()=>{const state=readContextSectionState(),collapsed=!section.classList.contains('is-collapsed');state[key]=collapsed;writeContextSectionState(state);setCollapsed(collapsed);});});}
  let context = null;
  let mounted = false;
  let utilityTrigger = null;
  let utilityBackdrop = null;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[char]));

  const clientIcon = '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/><path d="M16 9h2a2 2 0 0 1 2 2v10"/><path d="M8 7h4M8 11h4M8 15h4M8 19h4"/></svg>';

  async function api(url, options = {}) {
    const response = await fetch(url, {cache:'no-store', ...options});
    const text = await response.text();
    let data = null;    try { data = text ? JSON.parse(text) : null; }
    catch (_) { throw new Error(`MERDPOS API returned invalid data (${response.status}).`); }
    if (!data) throw new Error(`MERDPOS API returned an empty response (${response.status}).`);
    if (!data.success) throw new Error(data.error || 'MERDPOS request could not be completed.');
    return data;
  }

  function clientOptions(data) {
    const clients = data?.can_select_client && Array.isArray(data.clients) && data.clients.length
      ? data.clients.slice().sort((a,b) => Number(a.id) - Number(b.id))
      : [data?.client].filter(Boolean);
    return clients.map(client => {
      const inactive = String(client.status || '').toLowerCase() === 'inactive' ? ' · inactive' : '';
      const code = String(client.client_code || '').trim();
      const label = `${String(client.name || 'Client').trim()}${code ? ` · ${code}` : ''}${inactive}`;
      return `<option value="${Number(client.id)}" ${Number(client.id) === Number(data.active_client_id) ? 'selected' : ''}>${esc(label)}</option>`;
    }).join('');
  }

  function visiblePanelId() {
    const panel = Array.from(document.querySelectorAll('.portal-panel')).find(candidate => !candidate.hidden);
    return panel?.id || 'dashboardPanel';
  }

  function expandRail() {
    if (window.matchMedia('(max-width: 51.25rem)').matches) return;
    window.MERDPOSNavigation?.expandRail?.();
  }
  function syncUtilityTriggers(open) {
    document.querySelectorAll('.merd-shell-account-trigger,.merd-mobile-account-trigger').forEach(button => button.setAttribute('aria-expanded', open ? 'true' : 'false'));
  }
  function openMobileTools(trigger, options = {}) {
    utilityTrigger = trigger || document.activeElement;
    document.body.classList.add('merd-mobile-tools-open');
    syncUtilityTriggers(true);
    if (utilityBackdrop) utilityBackdrop.hidden = false;
    if(options.persist!==false)writeAccountUiState(true);
    window.setTimeout(function () {
      const first = document.querySelector('.rail-shell-utilities select:not([disabled]), .rail-shell-utilities button:not([disabled])');
      first?.focus?.({preventScroll:true});
    }, 40);
  }
  function closeMobileTools(options = {}) {
    const wasOpen = document.body.classList.contains('merd-mobile-tools-open');
    document.body.classList.remove('merd-mobile-tools-open');
    syncUtilityTriggers(false);
    if (utilityBackdrop) utilityBackdrop.hidden = true;
    if(options.persist!==false)writeAccountUiState(false);
    if (wasOpen && options.restoreFocus !== false) window.setTimeout(() => utilityTrigger?.focus?.({preventScroll:true}), 30);
  }

  async function switchClient(clientId, selects, syncButton = null) {
    if (!context || !context.can_select_client || Number(clientId) === Number(context.active_client_id)) return;
    const selected = (context.clients || []).find(client => Number(client.id) === Number(clientId));
    const selectedName = selected?.name || `Client ${clientId}`;
    selects.forEach(select => { select.disabled = true; });
    if (syncButton) syncButton.disabled = true;
    try {
      const result = await api('api/client_context.php', {
        method:'POST',
        headers:{'Accept':'application/json','Content-Type':'application/json'},
        body:JSON.stringify({action:'select_client',client_id:Number(clientId),csrf:context.csrf}),
      });
      context = result;
      sessionStorage.setItem('merdposReturnPanel', visiblePanelId());
      sessionStorage.setItem('merdposContextNotice', `Working client changed to ${selectedName}.`);
      window.location.reload();
    } catch (error) {
      selects.forEach(select => {
        select.disabled = !context.can_select_client;
        select.value = String(context.active_client_id || '');
      });
      if (syncButton) syncButton.disabled = auth.is_dev !== true || !Number(context.active_client_id);
      window.alert(error.message);
    }
  }

  function mount() {
    const rail = document.querySelector('.app-rail');
    const themeSection = rail?.querySelector('.rail-utility-section');
    if (!rail || !themeSection || mounted) return false;
    mounted = true;
    const clientSection = document.createElement('section');
    clientSection.className = 'rail-client-section';
    clientSection.setAttribute('aria-label', 'Working client');
    clientSection.innerHTML = `${clientIcon}<div class="rail-client-copy"><span>Working client</span><select id="accountClientSelect" aria-label="Select working client" disabled></select></div>`;
    clientSection.addEventListener('pointerdown', expandRail);
    clientSection.addEventListener('focusin', expandRail);
    rail.insertBefore(clientSection, rail.firstElementChild);

    const utilities = document.createElement('div');
    utilities.className = 'rail-shell-utilities';
    utilities.id = 'merdShellUtilities';
    const studioMetrics = auth.is_dev===true ? `<button type="button" class="rail-studio-metric rail-studio-copy-metric" data-studio-metric="copy" title="Copy unresolved patches for ChatGPT" hidden><span class="rail-studio-metric-count">0</span><img src="assets/vendor/devstudio/folder_match_24dp.svg" alt=""></button>` : '';
    utilities.innerHTML = `
      <div class="rail-user-summary"><span class="rail-user-avatar">${esc(name.charAt(0).toUpperCase())}</span><span class="rail-user-copy"><strong>${esc(name)}</strong><small class="account-role-badge account-role-${roleClass}">${esc(roleLabel)}</small></span>${studioMetrics}${auth.is_dev===true?'<button type="button" class="rail-devstudio-toggle" data-ui-studio="toggle" aria-label="Enable DevStudio" aria-pressed="false" title="DevStudio"><span aria-hidden="true"></span></button>':''}</div>
      <div class="rail-mobile-client-context rail-collapsible-context" data-context-key="working-client"><button type="button" class="rail-context-toggle" aria-expanded="true"><span>Working client</span><span class="rail-context-chevron" aria-hidden="true"></span></button><div class="rail-context-body"><select class="rail-mobile-client-select" aria-label="Select working client" disabled></select>${auth.is_dev===true ? `<div class="rail-timesheet-sync-row"><span>Sync</span><button type="button" class="rail-timesheet-sync-btn" aria-label="Sync Time Sheet from Google" title="Replace SQL Time Sheet from Google" disabled><img src="assets/vendor/google-material-symbols/restart_alt_48px.svg" alt=""></button></div>` : ''}</div></div>
      ${auth.is_dev===true ? `<div class="rail-dev-role-context rail-collapsible-context" data-context-key="current-role"><button type="button" class="rail-context-toggle" aria-expanded="true"><span>Current role</span><span class="rail-context-chevron" aria-hidden="true"></span></button><div class="rail-context-body"><select class="rail-dev-role-select" aria-label="Preview website as role"><option value="DEV">Developer</option><option value="ADMIN">Admin</option><option value="SUPER">Super</option><option value="USER">User</option></select></div></div>` : ''}`;

    const accountSection = document.createElement('section');
    accountSection.className = 'rail-account-section';
    [passwordBtn, logoutBtn].filter(Boolean).forEach(button => {
      button.hidden = false;
      button.className = `rail-group-btn rail-account-action${button === logoutBtn ? ' is-danger' : ''}`;
      button.removeAttribute('role');
      const label = button.querySelector('span');
      if (label) label.className = 'rail-label';
      accountSection.appendChild(button);
    });
    utilities.appendChild(accountSection);

    themeSection.classList.add('rail-theme-section');
    utilities.appendChild(themeSection);
    const aboutSection = document.createElement('section');
    aboutSection.className = 'rail-about-section';
    const aboutBtn = document.createElement('button');
    aboutBtn.type = 'button';
    aboutBtn.className = 'rail-group-btn rail-about-toggle';
    aboutBtn.innerHTML = '<img class="rail-asset-icon" src="assets/brand/M_Icon_v2.svg?v=20260902ds117" alt=""><span class="rail-label">About MERDPOS</span>';
    aboutBtn.title = 'About MERDPOS';
    aboutBtn.setAttribute('aria-label', 'About MERDPOS');
    aboutSection.appendChild(aboutBtn);
    utilities.appendChild(aboutSection);

    const accountDock = document.createElement('section');
    accountDock.className = 'rail-account-dock';
    const accountTrigger = document.createElement('button');
    accountTrigger.type = 'button';
    accountTrigger.className = 'merd-shell-account-trigger';
    accountTrigger.dataset.accountToolsTrigger = '1';
    accountTrigger.title = `${name} · Account and working client`;
    accountTrigger.setAttribute('aria-label', 'Account, working client and app settings');
    accountTrigger.setAttribute('aria-controls', 'merdShellUtilities');
    accountTrigger.setAttribute('aria-expanded', 'false');
    accountTrigger.innerHTML = `<span class="rail-user-avatar">${esc(name.charAt(0).toUpperCase())}</span>`;
    accountTrigger.addEventListener('click', event => document.body.classList.contains('merd-mobile-tools-open') ? closeMobileTools() : openMobileTools(event.currentTarget));
    accountDock.appendChild(accountTrigger);
    rail.appendChild(accountDock);
    rail.appendChild(utilities);
    utilityBackdrop = document.createElement('div');
    utilityBackdrop.className = 'rail-mobile-tools-backdrop';
    utilityBackdrop.hidden = true;
    utilityBackdrop.setAttribute('aria-hidden', 'true');
    utilityBackdrop.addEventListener('click', () => closeMobileTools());
    document.body.appendChild(utilityBackdrop);

    wireContextSections(utilities);
    if(readAccountUiState().open===true)window.setTimeout(()=>openMobileTools(accountTrigger,{persist:false}),0);
    window.addEventListener('storage',event=>{if(event.key!==ACCOUNT_UI_STATE_KEY)return;const open=readAccountUiState().open===true;if(open)openMobileTools(accountTrigger,{persist:false});else closeMobileTools({persist:false,restoreFocus:false});});

    const desktopSelect = clientSection.querySelector('#accountClientSelect');
    const mobileSelect = utilities.querySelector('.rail-mobile-client-select');
    const selects = [desktopSelect, mobileSelect].filter(Boolean);
    const roleViewSelect = utilities.querySelector('.rail-dev-role-select');
    const timesheetSyncButton = utilities.querySelector('.rail-timesheet-sync-btn');
    if (roleViewSelect) {
      roleViewSelect.value = ['DEV','ADMIN','SUPER','USER'].includes(viewRoleKey) ? viewRoleKey : 'ADMIN';
      roleViewSelect.addEventListener('change', event => {
        const next = ['DEV','ADMIN','SUPER','USER'].includes(event.target.value) ? event.target.value : 'ADMIN';
        const nextLabel=String(event.target.selectedOptions?.[0]?.textContent||next).trim();
        try{localStorage.setItem('merdpos-preview-role-action-v1',JSON.stringify({key:next,label:nextLabel,at:Date.now()}));}catch(_){}
        document.cookie = `merdpos_dev_view_role=${next}; Path=/beta/timesheet_portal/; SameSite=Lax`;
        window.location.reload();
      });
    }

    async function syncGoogleTimeSheet() {
      if (!timesheetSyncButton || auth.is_dev !== true || !context || !Number(context.active_client_id)) return;
      const clientName = String(context?.client?.name || `Client ${context.active_client_id}`).trim();
      const approved = window.confirm(`Replace all ${clientName} SQL Time Sheet data with the latest Google "Time Sheet" worksheet?\n\nThe Google worksheet is fully validated first. Only this Working client's attendance events are replaced.`);
      if (!approved) return;
      timesheetSyncButton.disabled = true;
      timesheetSyncButton.setAttribute('aria-busy', 'true');
      selects.forEach(select => { select.disabled = true; });
      try {
        const result = await api('api/timesheet_google_refresh.php', {
          method:'POST',
          headers:{'Accept':'application/json','Content-Type':'application/json'},
          body:JSON.stringify({action:'refresh_timesheet',client_id:Number(context.active_client_id),csrf:context.csrf}),
        });
        const imported = Math.max(0, Number(result.inserted_rows || result.source_rows || 0));
        sessionStorage.setItem('merdposReturnPanel', visiblePanelId());
        sessionStorage.setItem('merdposContextNotice', `Time Sheet synced - ${imported.toLocaleString()} rows imported from Google.`);
        window.location.reload();
      } catch (error) {
        timesheetSyncButton.disabled = false;
        timesheetSyncButton.removeAttribute('aria-busy');
        selects.forEach(select => { select.disabled = !context.can_select_client; });
        window.alert(error.message);
      }
    }
    timesheetSyncButton?.addEventListener('click', syncGoogleTimeSheet);

    const studioToggle = utilities.querySelector('.rail-devstudio-toggle');
    function syncStudioToggle(detail={}) {
      if (!studioToggle) return;
      const saved = readStudioSettings();
      const enabled = typeof detail.enabled === 'boolean' ? detail.enabled : !!saved.enabled;
      const accent = /^#[0-9a-f]{6}$/i.test(String(detail.accent||saved.accent||'')) ? String(detail.accent||saved.accent).toUpperCase() : '#F59E0B';
      document.documentElement.style.setProperty('--merd-ui-accent', accent);
      document.body.classList.toggle('merd-ui-studio-enabled', enabled);
      studioToggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
      studioToggle.setAttribute('aria-label', enabled ? 'Disable DevStudio' : 'Enable DevStudio');
      studioToggle.title = enabled ? 'DevStudio enabled' : 'DevStudio disabled';
      const copyMetric=utilities.querySelector('[data-studio-metric="copy"]');
      if(copyMetric)copyMetric.hidden=!enabled;
    }
    if (studioToggle) {
      syncStudioToggle();
      studioToggle.addEventListener('click', () => {
        const next = studioToggle.getAttribute('aria-pressed') !== 'true';
        if (window.MERDPOS_UI_STUDIO?.setEnabled) window.MERDPOS_UI_STUDIO.setEnabled(next);
        else { writeStudioEnabled(next); syncStudioToggle({enabled:next}); window.dispatchEvent(new CustomEvent('merdpos-uistudio-toggle',{detail:{enabled:next}})); }
      });
      window.addEventListener('merdpos-uistudio-state', event => syncStudioToggle(event.detail||{}));
      window.addEventListener('storage', event => { if(event.key===STUDIO_SETTINGS_KEY) syncStudioToggle(); });
    }


    const studioMetricButtons=[...utilities.querySelectorAll('.rail-studio-metric')];
    function syncStudioMetrics(detail={}) {
      if (!studioMetricButtons.length) return;
      const counts={requests:Number(detail.requests||0),patches:Number(detail.patches||0),copy:Number(detail.copy||0)};
      studioMetricButtons.forEach(button=>{
        const key=button.dataset.studioMetric,count=Math.max(0,counts[key]||0);
        const label=key==='requests'?'implementation requests':key==='patches'?'global unresolved patches':'patches ready to copy for ChatGPT';
        button.querySelector('.rail-studio-metric-count').textContent=String(count);
        button.setAttribute('aria-label',`${count} ${label}`);
      });
    }

    studioMetricButtons.forEach(button=>button.addEventListener('click',()=>{
      const studio=window.MERDPOS_UI_STUDIO;
      if(button.dataset.studioMetric==='copy') studio?.copyForChat?.();
      else studio?.openChanges?.();
    }));
    window.addEventListener('merdpos-uistudio-patches',event=>syncStudioMetrics(event.detail||{}));
    window.setTimeout(()=>syncStudioMetrics(window.MERDPOS_UI_STUDIO?.getCounts?.()||{}),120);

    function applyContext(data) {
      context = data;
      const options = clientOptions(data);
      selects.forEach(select => {
        select.innerHTML = options;
        select.value = String(data.active_client_id || data?.client?.id || '');
        select.disabled = !data.can_select_client;
      });
      const clientName = String(data?.client?.name || 'Working client').trim();
      clientSection.title = `Working client: ${clientName}`;
      if (timesheetSyncButton) {
        timesheetSyncButton.disabled = auth.is_dev !== true || !Number(data.active_client_id);
        timesheetSyncButton.removeAttribute('aria-busy');
        timesheetSyncButton.title = `Replace ${clientName} SQL Time Sheet from Google`;
      }
      window.dispatchEvent(new CustomEvent('merdpos-clientcontext', {detail:data}));
    }

    selects.forEach(select => select.addEventListener('change', event => switchClient(event.target.value, selects, timesheetSyncButton)));
    const aboutDialog = document.getElementById('merdposAboutDialog');
    const aboutClose = document.getElementById('merdposAboutClose');
    aboutBtn.addEventListener('click', () => {
      closeMobileTools();
      aboutDialog?.showModal?.();
    });
    aboutClose?.addEventListener('click', () => aboutDialog?.close?.());
    aboutDialog?.addEventListener('click', event => {
      if (event.target === aboutDialog) aboutDialog.close();
    });


    [passwordBtn, logoutBtn].filter(Boolean).forEach(button => {
      button.addEventListener('click', () => window.setTimeout(closeMobileTools, 0));
    });

    document.addEventListener('pointerdown', event => {
      if (!document.body.classList.contains('merd-mobile-tools-open')) return;
      if (utilities.contains(event.target)) return;
      if (event.target.closest?.('.merd-shell-account-trigger,.merd-mobile-account-trigger')) return;
      if (event.target.closest?.('[data-ui-studio]')) return;
      closeMobileTools();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') closeMobileTools();
    });
    async function loadContext() {
      try {
        applyContext(await api('api/client_context.php?_=' + Date.now(), {headers:{'Accept':'application/json'}}));
      } catch (error) {
        console.error('MERDPOS rail client context:', error);
      }
    }

    source.remove();
    window.MERDPOSAccountContext = {refresh: loadContext, get: () => context};
    window.MERDPOSShellUtilities = {open: openMobileTools, close: closeMobileTools};
    loadContext();
    return true;
  }

  if (mount()) return;
  let attempts = 0;
  const mountTimer = window.setInterval(() => {
    attempts += 1;
    if (mount() || attempts > 80) window.clearInterval(mountTimer);
  }, 50);
})();
