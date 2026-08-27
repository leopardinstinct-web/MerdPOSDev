(function () {
  'use strict';

  const actions = document.querySelector('.merd-topbar .topbar-actions');
  const userLine = actions?.querySelector('.user-line');
  const passwordBtn = document.getElementById('passwordBtn');
  const logoutBtn = document.getElementById('logoutBtn');
  if (!actions || !userLine || !logoutBtn) return;
  if (document.getElementById('accountMenu')) return;

  const auth = window.MERDPOS_AUTH || {};
  const name = String(userLine.querySelector('strong')?.textContent || '').trim() || 'Account';
  const roleLabel = String(userLine.querySelector('.merd-role-pill')?.textContent || auth.role_label || 'USER').trim() || 'USER';
  const roleKey = String(auth.role_key || roleLabel).trim().toUpperCase();
  const roleClass = ['DEV','SUPER','ADMIN','USER'].includes(roleKey) ? roleKey.toLowerCase() : 'user';
  let context = null;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));

  const menu = document.createElement('details');
  menu.id = 'accountMenu';
  menu.className = 'account-menu';
  menu.innerHTML = `
    <summary class="account-trigger" aria-label="Open account menu">
      <span class="account-name"></span>
      <span class="account-role-badge account-role-${roleClass}"></span>
      <span class="account-client-pill" id="accountClientPill" hidden></span>
      <svg class="account-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </summary>
    <div class="account-popover" role="menu" aria-label="Account options">
      <div class="account-client-context" id="accountClientContext" hidden>
        <div class="account-client-context-head"><strong>Working client</strong></div>
        <select id="accountClientSelect" aria-label="Select working client"></select>
      </div>
      <div class="account-menu-divider account-client-divider" hidden></div>
      <div class="account-menu-slot account-password-slot"></div>
      <div class="account-menu-divider account-password-divider"></div>
      <div class="account-menu-slot account-logout-slot"></div>
    </div>`;

  menu.querySelector('.account-name').textContent = name;
  menu.querySelector('.account-role-badge').textContent = roleLabel;

  if (passwordBtn) {
    passwordBtn.className = 'account-menu-item';
    passwordBtn.setAttribute('role', 'menuitem');
    const passwordText = passwordBtn.querySelector('span');
    if (passwordText) passwordText.textContent = 'Change password';
    menu.querySelector('.account-password-slot').appendChild(passwordBtn);
  } else {
    menu.querySelector('.account-password-slot')?.remove();
    menu.querySelector('.account-password-divider')?.remove();
  }

  logoutBtn.className = 'account-menu-item is-danger';
  logoutBtn.setAttribute('role', 'menuitem');
  const logoutText = logoutBtn.querySelector('span');
  if (logoutText) logoutText.textContent = 'Log out';
  menu.querySelector('.account-logout-slot').appendChild(logoutBtn);

  userLine.remove();
  actions.replaceChildren(menu);

  const pill = document.getElementById('accountClientPill');
  const clientBlock = document.getElementById('accountClientContext');
  const clientSelect = document.getElementById('accountClientSelect');
  const clientDivider = menu.querySelector('.account-client-divider');

  async function api(url, options = {}) {
    const response = await fetch(url, {cache:'no-store', ...options});
    const text = await response.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; }
    catch (_) { throw new Error(`Client context returned invalid data (${response.status}).`); }
    if (!data) throw new Error(`Client context returned an empty response (${response.status}).`);
    if (!data.success) throw new Error(data.error || 'Client context could not be loaded.');
    return data;
  }

  function clientOptions(clients, selectedId) {
    return (clients || []).slice().sort((a,b) => Number(a.id) - Number(b.id)).map(client => {
      const inactive = String(client.status || '').toLowerCase() === 'inactive' ? ' · inactive' : '';
      return `<option value="${Number(client.id)}" ${Number(client.id) === Number(selectedId) ? 'selected' : ''}>${esc(client.name)} · ${esc(client.client_code)}${inactive}</option>`;
    }).join('');
  }

  function applyContext(data) {
    context = data;
    const client = data?.client || {};
    const clientName = String(client.name || '').trim();
    const clientCode = String(client.client_code || '').trim();
    if (pill && clientName) {
      pill.textContent = clientName;
      pill.title = `Working client: ${clientName}${clientCode ? ` (${clientCode})` : ''}`;
      pill.hidden = false;
    }

    const selectable = !!data?.can_select_client && Array.isArray(data.clients) && data.clients.length > 0;
    if (clientBlock) clientBlock.hidden = !selectable;
    if (clientDivider) clientDivider.hidden = !selectable;
    if (clientSelect) {
      clientSelect.innerHTML = selectable ? clientOptions(data.clients, data.active_client_id) : '';
      clientSelect.value = selectable ? String(data.active_client_id) : '';
      clientSelect.disabled = false;
    }
  }

  async function loadContext() {
    try {
      applyContext(await api('api/client_context.php?_=' + Date.now(), {headers:{'Accept':'application/json'}}));
    } catch (error) {
      console.error('MERDPOS account client context:', error);
    }
  }

  function visiblePanelId() {
    const panel = Array.from(document.querySelectorAll('.portal-panel')).find(candidate => !candidate.hidden);
    return panel?.id || 'dashboardPanel';
  }

  async function switchClient(clientId) {
    if (!context || !context.can_select_client || Number(clientId) === Number(context.active_client_id)) return;
    if (!clientSelect) return;
    const selected = (context.clients || []).find(client => Number(client.id) === Number(clientId));
    const selectedName = selected?.name || `Client ${clientId}`;
    clientSelect.disabled = true;
    try {
      const result = await api('api/client_context.php', {
        method:'POST',
        headers:{'Accept':'application/json','Content-Type':'application/json'},
        body:JSON.stringify({
          action:'select_client',
          client_id:Number(clientId),
          csrf:context.csrf,
        }),
      });
      applyContext(result);
      sessionStorage.setItem('merdposReturnPanel', visiblePanelId());
      sessionStorage.setItem('merdposContextNotice', `Working client changed to ${selectedName}.`);
      window.location.reload();
    } catch (error) {
      clientSelect.disabled = false;
      clientSelect.value = String(context.active_client_id);
      window.alert(error.message);
    }
  }

  clientSelect?.addEventListener('change', event => switchClient(event.target.value));

  const close = () => { if (menu.open) menu.open = false; };

  document.addEventListener('pointerdown', event => {
    if (menu.open && !menu.contains(event.target)) close();
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && menu.open) {
      close();
      menu.querySelector('summary')?.focus();
    }
  });

  [passwordBtn, logoutBtn].filter(Boolean).forEach(button => {
    button.addEventListener('click', () => window.setTimeout(close, 0));
  });

  window.MERDPOSAccountContext = {
    refresh: loadContext,
    get: () => context,
  };

  loadContext();
})();
