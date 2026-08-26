(function () {
  'use strict';

  const root = document.getElementById('clientsOverview');
  if (!root) return;

  // These DEV modules are global Operations features. Keep loading them from the
  // always-mounted DEV Clients module so they do not depend on visiting Clients.
  if (!document.querySelector('script[data-dev-stores-ui]')) {
    const script = document.createElement('script');
    script.src = 'assets/dev-stores-ui.js?v=20260825h';
    script.dataset.devStoresUi = '1';
    document.body.appendChild(script);
  }
  if (!document.querySelector('script[data-defaults-module]')) {
    const script = document.createElement('script');
    script.src = 'assets/defaults.js?v=20260825a';
    script.dataset.defaultsModule = '1';
    document.body.appendChild(script);
  }
  if (!document.querySelector('script[data-roles-module]')) {
    const script = document.createElement('script');
    script.src = 'assets/roles.js?v=20260825a';
    script.dataset.rolesModule = '1';
    document.body.appendChild(script);
  }

  let state = null;
  let filter = '';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));

  function ensureStyles() {
    if (document.getElementById('devClientsAdminStyles')) return;
    const style = document.createElement('style');
    style.id = 'devClientsAdminStyles';
    style.textContent = `
      .clients-admin-toolbar{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:12px}
      .clients-admin-search{display:flex;align-items:center;gap:8px;width:min(460px,100%);min-height:42px;padding:0 12px;border:1px solid #CBD8E7;border-radius:10px;background:#fff}
      .clients-admin-search svg{width:17px;height:17px;color:#6B7F96;fill:none;stroke:currentColor;stroke-width:1.8}
      .clients-admin-search input{width:100%;border:0!important;outline:0!important;background:transparent!important;padding:0!important;box-shadow:none!important}
      .client-code-line{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace!important;font-size:10.5px!important;color:#49627F!important}
      .client-counts{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
      .client-count-chip{display:inline-flex;align-items:center;min-height:22px;padding:3px 7px;border-radius:999px;background:#F2F6FB;color:#536A84;font-size:9.5px;font-weight:700}
      .client-admin-id{background:#F3F6FA!important;color:#607086!important;cursor:not-allowed}
      .client-admin-code{text-transform:uppercase;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace!important;letter-spacing:.035em}
      @media(max-width:720px){.clients-admin-toolbar{display:grid}.clients-admin-search{width:100%}.clients-admin-toolbar .primary-btn{width:100%;justify-content:center}}
    `;
    document.head.appendChild(style);
  }

  async function api(url, options = {}) {
    const response = await fetch(url, {cache:'no-store', ...options});
    const text = await response.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; }
    catch (_) { throw new Error(`Clients API returned invalid data (${response.status}).`); }
    if (!data) throw new Error(`Clients API returned an empty response (${response.status}).`);
    if (!data.success) throw new Error(data.error || 'Client request failed.');
    return data;
  }

  function statusPill(status) {
    const active = String(status || '').toLowerCase() === 'active';
    return `<span class="entity-status ${active ? 'is-active' : 'is-inactive'}">${active ? 'Active' : 'Inactive'}</span>`;
  }

  function editIcon() {
    return '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg>';
  }

  function searchIcon() {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>';
  }

  function ensureDialog() {
    if (document.getElementById('clientAdminDialog')) return;
    const dialog = document.createElement('dialog');
    dialog.id = 'clientAdminDialog';
    dialog.className = 'admin-dialog';
    dialog.innerHTML = `
      <form id="clientAdminForm" method="dialog" class="admin-form">
        <div class="dialog-head">
          <div><h2 id="clientAdminDialogTitle">Add client</h2><p>Client identity is global. Setup keys are generated securely and never displayed.</p></div>
          <button type="button" class="dialog-close" data-client-dialog-close aria-label="Close">×</button>
        </div>
        <input type="hidden" name="id">
        <div class="admin-form-grid">
          <label>Internal Client ID <span class="dev-field-chip">DEV</span><input class="client-admin-id" id="clientAdminId" type="text" readonly tabindex="-1"></label>
          <label>Client name<input name="name" type="text" maxlength="100" autocomplete="organization" required></label>
          <label>Client Code <span class="dev-field-chip">DEV</span><input class="client-admin-code" name="client_code" type="text" minlength="2" maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9_-]{1,49}" autocomplete="off" spellcheck="false" required><p class="form-hint">Globally unique. A–Z, 0–9, hyphen and underscore.</p></label>
          <label>Status<select name="status" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
        </div>
        <div class="dialog-actions">
          <button type="button" class="secondary-btn" data-client-dialog-close>Cancel</button>
          <button type="submit" class="primary-btn">Save client</button>
        </div>
      </form>`;
    document.body.appendChild(dialog);

    const form = document.getElementById('clientAdminForm');
    const code = form.elements.client_code;
    code?.addEventListener('input', () => {
      code.value = code.value.toUpperCase().replace(/\s+/g, '-').replace(/[^A-Z0-9_-]/g, '');
      code.dataset.auto = '0';
    });
    form.elements.name?.addEventListener('input', () => {
      if (form.elements.id.value || (code.value && code.dataset.auto !== '1')) return;
      code.value = String(form.elements.name.value || '')
        .toUpperCase().trim().replace(/[^A-Z0-9]+/g,'-').replace(/^-+|-+$/g,'').slice(0,50);
      code.dataset.auto = '1';
    });
    form.addEventListener('submit', saveClient);
    dialog.querySelectorAll('[data-client-dialog-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
  }

  function openClient(id = null) {
    ensureDialog();
    const dialog = document.getElementById('clientAdminDialog');
    const form = document.getElementById('clientAdminForm');
    if (!dialog || !form || !state) return;
    form.reset();
    const client = id ? (state.clients || []).find(row => Number(row.id) === Number(id)) : null;
    form.elements.id.value = client ? String(client.id) : '';
    form.elements.name.value = client?.name || '';
    form.elements.client_code.value = client?.client_code || '';
    form.elements.client_code.dataset.auto = client ? '0' : '1';
    form.elements.status.value = client?.status || 'active';
    document.getElementById('clientAdminId').value = client ? String(client.id) : 'Assigned automatically';
    document.getElementById('clientAdminDialogTitle').textContent = client ? `Edit ${client.name}` : 'Add client';
    if (!dialog.open) dialog.showModal();
  }

  function matches(client, query) {
    if (!query) return true;
    return [
      client.name,
      client.client_code,
      client.id,
      `id ${client.id}`,
      client.status,
      `${client.store_count} stores`,
      `${client.employee_count} employees`,
    ].some(value => String(value ?? '').toLowerCase().includes(query));
  }

  function renderList() {
    const list = document.getElementById('clientsAdminList');
    if (!list || !state) return;
    const query = String(filter || '').trim().toLowerCase();
    const clients = (state.clients || []).slice().sort((a,b) => Number(a.id) - Number(b.id)).filter(client => matches(client, query));
    list.innerHTML = clients.length ? clients.map(client => `
      <article class="entity-row ${String(client.status).toLowerCase() === 'inactive' ? 'is-muted' : ''}">
        <div class="entity-avatar">C</div>
        <div class="entity-copy">
          <div class="entity-title-line"><strong>${esc(client.name)}</strong></div>
          <div class="entity-sub client-code-line">Code ${esc(client.client_code)} · ID ${Number(client.id)}</div>
          <div class="client-counts">
            <span class="client-count-chip">${Number(client.store_count || 0)} stores</span>
            <span class="client-count-chip">${Number(client.active_employee_count || 0)} active staff</span>
            <span class="client-count-chip">${Number(client.device_count || 0)} devices</span>
          </div>
        </div>
        <div class="entity-meta">${statusPill(client.status)}</div>
        <button type="button" class="icon-text-btn" data-edit-client="${Number(client.id)}">${editIcon()}<span>Edit</span></button>
      </article>`).join('') : '<div class="entity-empty">No clients match this search.</div>';

    list.querySelectorAll('[data-edit-client]').forEach(button => button.addEventListener('click', () => openClient(Number(button.dataset.editClient))));
  }

  function render() {
    ensureStyles();
    ensureDialog();
    root.innerHTML = `
      <div class="clients-admin-toolbar">
        <label class="clients-admin-search" aria-label="Search clients">${searchIcon()}<input id="clientsAdminSearch" type="search" placeholder="Search name, code, ID or status" value="${esc(filter)}"></label>
        <button id="addClientBtn" class="primary-btn compact-btn" type="button">+ Add client</button>
      </div>
      <div id="clientsAdminNotice" class="directory-notice" hidden></div>
      <div id="clientsAdminList" class="entity-list"></div>`;

    document.getElementById('clientsAdminSearch')?.addEventListener('input', event => {
      filter = event.target.value;
      renderList();
    });
    document.getElementById('addClientBtn')?.addEventListener('click', () => openClient());
    renderList();
  }

  function notice(message, error = false) {
    const node = document.getElementById('clientsAdminNotice');
    if (!node) return;
    node.textContent = message;
    node.classList.toggle('is-error', error);
    node.hidden = !message;
    if (message && !error) window.setTimeout(() => { node.hidden = true; }, 3500);
  }

  async function saveClient(event) {
    event.preventDefault();
    if (!state) return;
    const form = event.currentTarget;
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      const result = await api('api/clients.php', {
        method:'POST',
        headers:{'Accept':'application/json','Content-Type':'application/json'},
        body:JSON.stringify({
          action:'save_client',
          csrf:state.csrf,
          id:form.elements.id.value || null,
          name:form.elements.name.value,
          client_code:form.elements.client_code.value,
          status:form.elements.status.value,
        }),
      });
      state = result;
      document.getElementById('clientAdminDialog')?.close();
      render();
      notice(result.message || 'Client saved.');
      window.MERDPOSAccountContext?.refresh?.();
    } catch (error) {
      alert(error.message);
    } finally {
      button.disabled = false;
    }
  }

  async function load() {
    root.innerHTML = '<div class="entity-empty">Loading clients…</div>';
    try {
      state = await api('api/clients.php?_=' + Date.now(), {headers:{'Accept':'application/json'}});
      render();
    } catch (error) {
      root.innerHTML = `<div class="entity-empty is-error">${esc(error.message)}</div>`;
    }
  }

  load();
})();
