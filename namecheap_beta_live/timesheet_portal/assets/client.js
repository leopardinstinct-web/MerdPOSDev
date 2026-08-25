(function () {
  const root = document.getElementById('clientOverview');
  if (!root) return;

  if (!document.querySelector('script[data-dev-stores-ui]')) {
    const devStoresScript = document.createElement('script');
    devStoresScript.src = 'assets/dev-stores-ui.js?v=20260825e';
    devStoresScript.dataset.devStoresUi = '1';
    document.body.appendChild(devStoresScript);
  }
  if (!document.querySelector('script[data-defaults-module]')) {
    const defaultsScript = document.createElement('script');
    defaultsScript.src = 'assets/defaults.js?v=20260825a';
    defaultsScript.dataset.defaultsModule = '1';
    document.body.appendChild(defaultsScript);
  }
  if (!document.querySelector('script[data-roles-module]')) {
    const rolesScript = document.createElement('script');
    rolesScript.src = 'assets/roles.js?v=20260825a';
    rolesScript.dataset.rolesModule = '1';
    document.body.appendChild(rolesScript);
  }

  const clientTab = document.querySelector('.portal-tab[data-panel="clientPanel"]');
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let state = null;

  function ensureStyles() {
    if (document.getElementById('devClientContextStyles')) return;
    const style = document.createElement('style');
    style.id = 'devClientContextStyles';
    style.textContent = `
      .dev-client-context-card{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;padding:14px 16px;margin:0 0 14px;border:1px solid #DCE5F0;border-radius:14px;background:#F8FBFF}
      .dev-client-context-card label{display:flex;flex-direction:column;gap:6px;min-width:min(360px,100%);font-size:11px;font-weight:700;color:#53677F}
      .dev-client-context-card select{height:38px;border:1px solid #CBD8E7;border-radius:9px;background:#fff;color:#152A43;padding:0 34px 0 10px;font:inherit}
      .dev-client-context-note{font-size:11px;color:#64748B;line-height:1.45}
      .client-parent-row{border-left:3px solid #2F80ED}
      @media(max-width:820px){.dev-client-context-card{align-items:stretch;flex-direction:column}.dev-client-context-card label{min-width:0;width:100%}}
    `;
    document.head.appendChild(style);
  }

  function activatePanel(panelId, sourceTab = null) {
    const panel = document.getElementById(panelId);
    if (!panel) return false;
    document.querySelectorAll('.portal-tab').forEach(tab => {
      tab.classList.toggle('active', sourceTab ? tab === sourceTab : tab.dataset.panel === panelId);
    });
    document.querySelectorAll('.portal-panel').forEach(candidate => {
      candidate.hidden = candidate.id !== panelId;
    });
    return true;
  }

  clientTab?.addEventListener('click', () => activatePanel('clientPanel', clientTab));

  async function api(url, options = {}) {
    const response = await fetch(url, {headers:{'Accept':'application/json', ...(options.headers || {})}, ...options});
    const text = await response.text();
    let data = null;
    if (text) {
      try { data = JSON.parse(text); }
      catch (_) {
        const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 160);
        throw new Error(`Client API returned invalid data (${response.status})${snippet ? ': ' + snippet : '.'}`);
      }
    }
    if (!data) throw new Error(`Client API returned an empty response (${response.status}).`);
    if (!data.success) throw new Error(data.error || `Request failed (${response.status})`);
    return data;
  }

  function statusPill(status) {
    const active = String(status || '').toLowerCase() === 'active';
    return `<span class="entity-status ${active ? 'is-active' : 'is-inactive'}">${active ? 'Active' : 'Inactive'}</span>`;
  }

  function storeIcon() {
    return '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9l2-5h14l2 5"/><path d="M5 13v7h14v-7"/><path d="M9 20v-6h6v6"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/></svg>';
  }

  function clientOptions(clients, selectedId) {
    return clients.map(client => {
      const suffix = String(client.status).toLowerCase() === 'inactive' ? ' · inactive' : '';
      return `<option value="${Number(client.id)}" ${Number(client.id) === Number(selectedId) ? 'selected' : ''}>${esc(client.name)} · ${esc(client.client_code)}${suffix}</option>`;
    }).join('');
  }

  function syncSidebarClientLabel(client) {
    const code = String(client?.client_code || '').trim() || String(client?.id || '');
    if (!code) return;
    const labelText = `Client: ${code}`;
    const apply = () => {
      const button = document.querySelector('.rail-group-btn[data-nav-group="client"]');
      if (button) {
        const label = button.querySelector('.rail-label');
        if (label) label.textContent = labelText;
        button.title = labelText;
        button.setAttribute('aria-label', labelText);
      }
    };
    [0,80,240,600].forEach(delay => window.setTimeout(apply, delay));
  }

  async function switchClient(clientId) {
    if (!state || Number(clientId) === Number(state.active_client_id)) return;
    const selected = (state.clients || []).find(client => Number(client.id) === Number(clientId));
    const label = selected ? selected.name : `Client ${clientId}`;
    try {
      document.querySelectorAll('#clientOverview [data-dev-client-select]').forEach(select => { select.disabled = true; });
      await api('api/client_context.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'select_client',client_id:Number(clientId),csrf:state.csrf}),
      });
      sessionStorage.setItem('merdposReturnPanel', 'clientPanel');
      sessionStorage.setItem('merdposContextNotice', `Working client changed to ${label}.`);
      window.location.reload();
    } catch (error) {
      alert(error.message);
      document.querySelectorAll('#clientOverview [data-dev-client-select]').forEach(select => {
        select.disabled = false;
        select.value = String(state.active_client_id);
      });
    }
  }

  function restoreClientPanel() {
    if (sessionStorage.getItem('merdposReturnPanel') !== 'clientPanel') return;
    sessionStorage.removeItem('merdposReturnPanel');
    let attempts = 0;
    const timer = window.setInterval(() => {
      attempts += 1;
      const tab = document.querySelector('.portal-tab[data-panel="clientPanel"]');
      if (tab) {
        tab.click();
        window.clearInterval(timer);
      } else if (attempts > 20) window.clearInterval(timer);
    }, 60);
  }

  function render(data) {
    state = data;
    ensureStyles();
    const client = data.client || {};
    syncSidebarClientLabel(client);
    const clients = (data.clients || []).slice().sort((a,b) => Number(a.id) - Number(b.id));
    const stores = (data.stores || []).slice().sort((a,b) => Number(a.id) - Number(b.id));
    const counts = data.counts || {};
    const contextNote = data.cross_client_context
      ? `Your DEV identity remains on Client ${Number(data.home_client_id)}. Only the working data context is Client ${Number(data.active_client_id)}.`
      : 'This is also the client that owns your DEV login. Switching context does not move or duplicate your employee account.';

    root.innerHTML = `
      <section class="dev-client-context-card">
        <label>Working client
          <select data-dev-client-select aria-label="Select working client">${clientOptions(clients, data.active_client_id)}</select>
        </label>
        <div class="dev-client-context-note">${esc(contextNote)}</div>
      </section>
      <div class="entity-list client-entity-list">
        <article class="entity-row client-parent-row">
          <div class="entity-avatar">C</div>
          <div class="entity-copy">
            <div class="entity-title-line"><strong>${esc(client.name)}</strong><span class="entity-role role-dev">CLIENT</span></div>
            <div class="entity-sub">Client ID ${esc(client.id)} · Code ${esc(client.client_code)}</div>
            <div class="entity-sub">Current parent account for Operations, Workforce and other client-scoped data.</div>
          </div>
          <div class="entity-meta">
            <span class="store-access-summary">${Number(counts.stores || 0)} stores</span>
            <span class="store-access-summary">${Number(counts.active_employees || 0)} active staff</span>
            <span class="store-access-summary">${Number(counts.devices || 0)} devices</span>
            ${statusPill(client.status)}
          </div>
        </article>
      </div>
      <div class="app-panel-head client-child-head"><div><h3>Stores under this client</h3><p>Ordered by Store ID.</p></div><button type="button" class="secondary-btn compact-btn" id="clientViewStores">Open Stores</button></div>
      <div class="entity-list client-store-list">
        ${stores.length ? stores.map(store => `
          <article class="entity-row">
            <div class="entity-avatar store-avatar">${storeIcon()}</div>
            <div class="entity-copy">
              <div class="entity-title-line"><strong>${esc(store.store_name)}</strong></div>
              <div class="entity-sub">Store ID ${Number(store.id)} · Code ${esc(store.store_code)}</div>
            </div>
            <div class="entity-meta">${statusPill(store.status)}</div>
          </article>`).join('') : '<div class="entity-empty">No stores are assigned to this client.</div>'}
      </div>`;

    root.querySelector('[data-dev-client-select]')?.addEventListener('change', event => switchClient(event.target.value));
    document.getElementById('clientViewStores')?.addEventListener('click', () => {
      document.querySelector('[data-panel="storesPanel"]')?.click();
    });

    const notice = sessionStorage.getItem('merdposContextNotice');
    if (notice) {
      sessionStorage.removeItem('merdposContextNotice');
      const noticeRoot = document.getElementById('directoryNotice');
      if (noticeRoot) {
        noticeRoot.textContent = notice;
        noticeRoot.hidden = false;
        setTimeout(() => { noticeRoot.hidden = true; }, 3500);
      }
    }
    restoreClientPanel();
  }

  async function load() {
    try {
      root.innerHTML = '<div class="entity-empty">Loading client…</div>';
      render(await api('api/client_context.php'));
    } catch (error) {
      root.innerHTML = `<div class="entity-empty is-error">${esc(error.message)}</div>`;
    }
  }

  load();
})();
