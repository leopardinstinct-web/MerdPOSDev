(function () {
  'use strict';

  let state = null;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const icon = '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/><circle cx="8" cy="6" r="1.5"/><circle cx="16" cy="12" r="1.5"/><circle cx="12" cy="18" r="1.5"/></svg>';

  function ensureStyles() {
    if (document.querySelector('link[data-defaults-css]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'assets/defaults.css?v=20260825a';
    link.dataset.defaultsCss = '1';
    document.head.appendChild(link);
  }

  async function api(url, options = {}) {
    const response = await fetch(url, options);
    const text = await response.text();
    let data = null;
    if (text) {
      try { data = JSON.parse(text); }
      catch (_) {
        const snippet = text.replace(/\s+/g, ' ').trim().slice(0,160);
        throw new Error(`Defaults API returned invalid data (${response.status})${snippet ? ': ' + snippet : '.'}`);
      }
    }
    if (!data) throw new Error(`Defaults API returned an empty response (${response.status}).`);
    if (!data.success) throw new Error(data.error || `Request failed (${response.status})`);
    return data;
  }

  function createPanel() {
    if (document.getElementById('defaultsPanel')) return;
    const main = document.querySelector('main.merd-page-shell');
    if (!main) return;
    const panel = document.createElement('section');
    panel.id = 'defaultsPanel';
    panel.className = 'portal-panel';
    panel.hidden = true;
    panel.innerHTML = `
      <section class="controls-card defaults-card">
        <div class="directory-toolbar defaults-page-head">
          <div><h2>Defaults</h2><p>Set client defaults and optional per-store overrides for currency and timezone.</p></div>
        </div>
        <section class="defaults-section">
          <div class="defaults-section-head"><div><h3>Client defaults</h3><p>Stores inherit these values unless you set an override below.</p></div></div>
          <form id="clientDefaultsForm" class="defaults-form">
            <label>Currency<select id="clientDefaultCurrency" required></select></label>
            <label>Timezone<select id="clientDefaultTimezone" required></select></label>
            <button type="submit" class="primary-btn compact-btn">Save client defaults</button>
          </form>
        </section>
        <section class="defaults-section">
          <div class="defaults-section-head"><div><h3>Store overrides</h3><p>Leave a field on “Use client default” to inherit automatically.</p></div></div>
          <div id="storeDefaultsList" class="defaults-store-list"><div class="entity-empty">Loading defaults…</div></div>
        </section>
        <div id="defaultsStatus" class="defaults-status"></div>
      </section>`;
    main.appendChild(panel);
    document.getElementById('clientDefaultsForm')?.addEventListener('submit', saveClientDefaults);
  }

  function operationsGroup() {
    return document.querySelector('.sidebar-group[data-sidebar-group="operations"]')
      || Array.from(document.querySelectorAll('.nav-group')).find(group =>
        group.querySelector('.nav-group-label')?.textContent.trim().toLowerCase() === 'operations'
      ) || null;
  }

  function activate(button) {
    document.querySelectorAll('.portal-tab').forEach(tab => tab.classList.toggle('active', tab === button));
    document.querySelectorAll('.portal-panel').forEach(panel => { panel.hidden = panel.id !== 'defaultsPanel'; });
    const group = button.closest('[data-sidebar-group]');
    if (group) {
      document.querySelectorAll('.sidebar-group').forEach(item => {
        const active = item === group;
        item.hidden = !active;
        item.classList.toggle('active', active);
      });
      document.querySelectorAll('.rail-group-btn').forEach(rail => rail.classList.toggle('active', rail.dataset.navGroup === 'operations'));
    }
    if (!state) load();
  }

  function mountTab() {
    if (document.querySelector('[data-panel="defaultsPanel"]')) return true;
    const group = operationsGroup();
    if (!group) return false;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'portal-tab';
    button.dataset.panel = 'defaultsPanel';
    button.innerHTML = `${icon}<span>Defaults</span>`;
    const timings = group.querySelector('[data-panel="timingsPanel"]');
    if (timings) timings.insertAdjacentElement('afterend', button);
    else group.appendChild(button);
    button.addEventListener('click', () => activate(button));
    return true;
  }

  function repositionAfterTimings() {
    const group = operationsGroup();
    const tab = group?.querySelector('[data-panel="defaultsPanel"]');
    const timings = group?.querySelector('[data-panel="timingsPanel"]');
    if (tab && timings && timings.nextElementSibling !== tab) timings.insertAdjacentElement('afterend', tab);
  }

  function currencyOptions(selected, allowInherit = false) {
    const clientCurrency = String(state?.client?.default_currency || 'AUD');
    const items = [];
    if (allowInherit) items.push(`<option value="">Use client default (${esc(clientCurrency)})</option>`);
    for (const currency of state?.currencies || []) {
      items.push(`<option value="${esc(currency)}" ${String(selected || '') === String(currency) ? 'selected' : ''}>${esc(currency)}</option>`);
    }
    if (selected && !(state?.currencies || []).includes(selected)) {
      items.push(`<option value="${esc(selected)}" selected>${esc(selected)}</option>`);
    }
    return items.join('');
  }

  function timezoneOptions(selected, allowInherit = false) {
    const clientTimezone = String(state?.client?.default_timezone || 'Australia/Sydney');
    const items = [];
    if (allowInherit) items.push(`<option value="">Use client default (${esc(clientTimezone)})</option>`);
    for (const timezone of state?.timezones || []) {
      items.push(`<option value="${esc(timezone)}" ${String(selected || '') === String(timezone) ? 'selected' : ''}>${esc(timezone)}</option>`);
    }
    return items.join('');
  }

  function render() {
    if (!state) return;
    const clientCurrency = document.getElementById('clientDefaultCurrency');
    const clientTimezone = document.getElementById('clientDefaultTimezone');
    if (clientCurrency) clientCurrency.innerHTML = currencyOptions(state.client?.default_currency, false);
    if (clientTimezone) clientTimezone.innerHTML = timezoneOptions(state.client?.default_timezone, false);

    const root = document.getElementById('storeDefaultsList');
    if (!root) return;
    const stores = (state.stores || []).slice().sort((a,b) => Number(a.id) - Number(b.id));
    root.innerHTML = stores.length ? stores.map(store => `
      <article class="defaults-store-row ${String(store.status).toLowerCase() === 'inactive' ? 'is-muted' : ''}" data-store-default-row="${Number(store.id)}">
        <div class="defaults-store-copy">
          <strong>${esc(store.store_name)}</strong>
          <small>Code ${esc(store.store_code)} · ID ${Number(store.id)} · ${esc(store.status)}</small>
          <div class="defaults-effective">
            <span class="defaults-chip">Effective ${esc(store.effective_currency)}</span>
            <span class="defaults-chip">${esc(store.effective_timezone)}</span>
          </div>
        </div>
        <div class="store-default-controls">
          <label>Currency<select data-store-currency>${currencyOptions(store.currency_code || '', true)}</select></label>
          <label>Timezone<select data-store-timezone>${timezoneOptions(store.timezone || '', true)}</select></label>
        </div>
        <button type="button" class="secondary-btn compact-btn" data-save-store-defaults="${Number(store.id)}">Save</button>
      </article>`).join('') : '<div class="entity-empty">No stores exist for this client.</div>';

    root.querySelectorAll('[data-save-store-defaults]').forEach(button => button.addEventListener('click', () => saveStoreDefaults(button)));
  }

  function setStatus(message, error = false) {
    const root = document.getElementById('defaultsStatus');
    if (!root) return;
    root.textContent = message || '';
    root.classList.toggle('is-error', error);
  }

  async function saveClientDefaults(event) {
    event.preventDefault();
    if (!state) return;
    const button = event.currentTarget.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      state = await api('api/defaults.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
          action:'save_client_defaults',
          csrf:state.csrf,
          default_currency:document.getElementById('clientDefaultCurrency')?.value || '',
          default_timezone:document.getElementById('clientDefaultTimezone')?.value || '',
        }),
      });
      render();
      setStatus('Client defaults saved. Stores without overrides now inherit the new values.');
    } catch (error) {
      setStatus(error.message, true);
    } finally {
      button.disabled = false;
    }
  }

  async function saveStoreDefaults(button) {
    if (!state) return;
    const row = button.closest('[data-store-default-row]');
    if (!row) return;
    const storeId = Number(row.dataset.storeDefaultRow);
    button.disabled = true;
    try {
      state = await api('api/defaults.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
          action:'save_store_defaults',
          csrf:state.csrf,
          store_id:storeId,
          currency_code:row.querySelector('[data-store-currency]')?.value || '',
          timezone:row.querySelector('[data-store-timezone]')?.value || '',
        }),
      });
      render();
      setStatus('Store defaults saved.');
    } catch (error) {
      setStatus(error.message, true);
      button.disabled = false;
    }
  }

  async function load() {
    setStatus('Loading defaults…');
    try {
      state = await api('api/defaults.php');
      render();
      setStatus('');
    } catch (error) {
      setStatus(error.message, true);
    }
  }

  ensureStyles();
  createPanel();
  if (!mountTab()) {
    let attempts = 0;
    const timer = window.setInterval(() => {
      attempts += 1;
      if (mountTab() || attempts > 30) window.clearInterval(timer);
    }, 120);
  }
  [150,400,900,1600].forEach(delay => window.setTimeout(repositionAfterTimings, delay));
})();
