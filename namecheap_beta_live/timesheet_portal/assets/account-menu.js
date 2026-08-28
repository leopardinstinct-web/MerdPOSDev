(function () {
  'use strict';

  const source = document.getElementById('shellAccountSources');
  if (!source) return;

  const auth = window.MERDPOS_AUTH || {};
  const passwordBtn = document.getElementById('passwordBtn');
  const logoutBtn = document.getElementById('logoutBtn');
  const name = String(source.dataset.userName || 'Account').trim() || 'Account';
  const roleLabel = String(source.dataset.roleLabel || auth.role_label || 'USER').trim() || 'USER';
  const roleKey = String(source.dataset.roleKey || auth.role_key || roleLabel).trim().toUpperCase();
  const roleClass = ['DEV','SUPER','ADMIN','USER'].includes(roleKey) ? roleKey.toLowerCase() : 'user';
  let context = null;
  let mounted = false;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[char]));

  const clientIcon = '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/><path d="M16 9h2a2 2 0 0 1 2 2v10"/><path d="M8 7h4M8 11h4M8 15h4M8 19h4"/></svg>';
  const toolsIcon = '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>';

  async function api(url, options = {}) {
    const response = await fetch(url, {cache:'no-store', ...options});
    const text = await response.text();
    let data = null;    try { data = text ? JSON.parse(text) : null; }
    catch (_) { throw new Error(`Client context returned invalid data (${response.status}).`); }
    if (!data) throw new Error(`Client context returned an empty response (${response.status}).`);
    if (!data.success) throw new Error(data.error || 'Client context could not be loaded.');
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
  function closeMobileTools() {
    document.body.classList.remove('merd-mobile-tools-open');
    document.querySelector('.rail-mobile-tools-btn')?.setAttribute('aria-expanded', 'false');
  }

  async function switchClient(clientId, selects) {
    if (!context || !context.can_select_client || Number(clientId) === Number(context.active_client_id)) return;
    const selected = (context.clients || []).find(client => Number(client.id) === Number(clientId));
    const selectedName = selected?.name || `Client ${clientId}`;
    selects.forEach(select => { select.disabled = true; });
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
    utilities.innerHTML = `
      <div class="rail-mobile-client-context"><span>Working client</span><select class="rail-mobile-client-select" aria-label="Select working client" disabled></select></div>
      <div class="rail-user-summary"><span class="rail-user-avatar">${esc(name.charAt(0).toUpperCase())}</span><span class="rail-user-copy"><strong>${esc(name)}</strong><small class="account-role-badge account-role-${roleClass}">${esc(roleLabel)}</small></span></div>`;

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
    aboutBtn.innerHTML = '<img class="rail-asset-icon" src="assets/brand/M_Icon.svg?v=20260828about1" alt=""><span class="rail-label">About MERDPOS</span>';
    aboutBtn.title = 'About MERDPOS';
    aboutBtn.setAttribute('aria-label', 'About MERDPOS');
    aboutSection.appendChild(aboutBtn);
    utilities.appendChild(aboutSection);
    rail.appendChild(utilities);

    const toolsSection = document.createElement('section');
    toolsSection.className = 'rail-section rail-mobile-tools-section';
    toolsSection.innerHTML = `<button type="button" class="rail-group-btn rail-mobile-tools-btn" aria-label="Account and app tools" aria-expanded="false">${toolsIcon}<span class="rail-label">More</span></button>`;
    rail.appendChild(toolsSection);

    const desktopSelect = clientSection.querySelector('#accountClientSelect');
    const mobileSelect = utilities.querySelector('.rail-mobile-client-select');
    const selects = [desktopSelect, mobileSelect].filter(Boolean);

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
    }

    selects.forEach(select => select.addEventListener('change', event => switchClient(event.target.value, selects)));
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

    const toolsButton = toolsSection.querySelector('.rail-mobile-tools-btn');
    toolsButton?.addEventListener('click', event => {
      event.stopPropagation();
      const open = !document.body.classList.contains('merd-mobile-tools-open');
      document.body.classList.toggle('merd-mobile-tools-open', open);
      toolsButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    [passwordBtn, logoutBtn].filter(Boolean).forEach(button => {
      button.addEventListener('click', () => window.setTimeout(closeMobileTools, 0));
    });

    document.addEventListener('pointerdown', event => {
      if (!document.body.classList.contains('merd-mobile-tools-open')) return;
      if (utilities.contains(event.target) || toolsSection.contains(event.target)) return;
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
