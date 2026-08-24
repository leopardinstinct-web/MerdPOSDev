(function () {
  const root = document.getElementById('clientOverview');
  if (!root) return;

  if (!document.querySelector('script[data-dev-stores-ui]')) {
    const devStoresScript = document.createElement('script');
    devStoresScript.src = 'assets/dev-stores-ui.js?v=20260825a';
    devStoresScript.dataset.devStoresUi = '1';
    document.body.appendChild(devStoresScript);
  }

  const clientTab = document.querySelector('.portal-tab[data-panel="clientPanel"]');
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

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

  // Client/Account is created dynamically by navigation.js, after beta.js binds
  // the original portal tabs. Give this dynamic tab its own explicit router.
  clientTab?.addEventListener('click', () => activatePanel('clientPanel', clientTab));

  async function api(url) {
    const response = await fetch(url, {headers:{'Accept':'application/json'}});
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

  function render(data) {
    const client = data.client || {};
    const stores = (data.stores || []).slice().sort((a,b) => Number(a.id) - Number(b.id));
    const counts = data.counts || {};

    root.innerHTML = `
      <div class="entity-list client-entity-list">
        <article class="entity-row client-parent-row">
          <div class="entity-avatar">C</div>
          <div class="entity-copy">
            <div class="entity-title-line"><strong>${esc(client.name)}</strong><span class="entity-role role-dev">CLIENT</span></div>
            <div class="entity-sub">Client ID ${esc(client.id)} · Code ${esc(client.client_code)}</div>
            <div class="entity-sub">Parent account for stores, employees and POS devices.</div>
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

    document.getElementById('clientViewStores')?.addEventListener('click', () => {
      document.querySelector('[data-panel="storesPanel"]')?.click();
    });
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
