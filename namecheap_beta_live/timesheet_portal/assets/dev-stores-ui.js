(function () {
  'use strict';

  const storeRoot = document.getElementById('storeDirectory');
  const searchInput = document.getElementById('storeSearch');
  if (!storeRoot || !searchInput) return;

  if (!document.querySelector('script[data-store-identity-module]')) {
    const identityScript = document.createElement('script');
    identityScript.src = 'assets/store-identity.js?v=20260825d';
    identityScript.dataset.storeIdentityModule = '1';
    document.body.appendChild(identityScript);
  }
  if (!document.querySelector('script[data-roles-module]')) {
    const rolesScript = document.createElement('script');
    rolesScript.src = 'assets/roles.js?v=20260825a';
    rolesScript.dataset.rolesModule = '1';
    document.body.appendChild(rolesScript);
  }

  let stores = [];
  let searchBound = false;
  let editBound = false;
  let identityPromise = null;

  const numericId = value => {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
  };
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function ensureStyle() {
    if (document.getElementById('devStoresUiStyle')) return;
    const style = document.createElement('style');
    style.id = 'devStoresUiStyle';
    style.textContent = `
      #storesPanel .directory-toolbar.dev-store-toolbar{align-items:flex-start}
      #storesPanel .dev-store-heading{display:grid;gap:10px;min-width:min(460px,100%)}
      #storesPanel .dev-store-heading>p{display:none!important}
      #storesPanel .dev-store-search{width:min(460px,100%);margin:0}
      #storesPanel .dev-store-search input{width:100%}
      #storesPanel .dev-store-identity{margin-top:3px}
      @media(max-width:720px){
        #storesPanel .directory-toolbar.dev-store-toolbar{display:grid;gap:12px}
        #storesPanel .dev-store-heading,#storesPanel .dev-store-search{width:100%;min-width:0}
      }
    `;
    document.head.appendChild(style);
  }

  async function loadIdentity(force = false) {
    if (identityPromise && !force) return identityPromise;
    identityPromise = (async () => {
      const response = await fetch('api/store_identity.php', {headers:{'Accept':'application/json'}, cache:'no-store'});
      const text = await response.text();
      let data = null;
      try { data = text ? JSON.parse(text) : null; }
      catch (_) { throw new Error(`Store API returned invalid data (${response.status}).`); }
      if (!response.ok || !data?.success || data.actor_role !== 'DEV') {
        throw new Error(data?.error || 'DEV store access is unavailable.');
      }
      stores = (data.stores || []).slice().sort((a,b) => numericId(a.id) - numericId(b.id));
      return data;
    })();
    try { return await identityPromise; }
    finally { identityPromise = null; }
  }

  function storeById(id) {
    return stores.find(store => numericId(store.id) === numericId(id)) || null;
  }

  function cleanHeader() {
    const toolbar = storeRoot.closest('.directory-card')?.querySelector('.directory-toolbar');
    if (!toolbar) return;
    toolbar.classList.add('dev-store-toolbar');

    const headingWrap = toolbar.firstElementChild;
    if (headingWrap) {
      headingWrap.classList.add('dev-store-heading');
      headingWrap.querySelector('p')?.remove();
      const searchBox = searchInput.closest('.search-box');
      if (searchBox && searchBox.parentElement !== headingWrap) headingWrap.appendChild(searchBox);
      searchBox?.classList.add('dev-store-search');
    }

    searchInput.placeholder = 'Search name, code, ID, address or status';
  }

  function cleanRows() {
    const rows = Array.from(storeRoot.querySelectorAll('.entity-row'));
    rows.sort((a,b) => {
      const aid = numericId(a.querySelector('[data-edit-store]')?.dataset.editStore);
      const bid = numericId(b.querySelector('[data-edit-store]')?.dataset.editStore);
      return aid - bid;
    }).forEach(row => storeRoot.appendChild(row));

    rows.forEach(row => {
      const button = row.querySelector('[data-edit-store]');
      if (!button) return;
      const store = storeById(button.dataset.editStore);
      const copy = row.querySelector('.entity-copy');
      if (!copy) return;

      Array.from(copy.querySelectorAll('.entity-sub')).forEach(line => {
        if (/weekly opening and closing hours are managed in timings/i.test(line.textContent || '')) line.remove();
      });

      if (store) {
        let identity = copy.querySelector('.dev-store-identity');
        if (!identity) {
          identity = document.createElement('div');
          identity.className = 'entity-sub dev-store-identity';
          copy.appendChild(identity);
        }
        identity.textContent = `Code ${store.store_code || '—'} · ID ${store.id}`;
      }
    });
  }

  function applyFilter() {
    const query = String(searchInput.value || '').trim().toLowerCase();
    storeRoot.querySelectorAll('.entity-row').forEach(row => {
      const id = numericId(row.querySelector('[data-edit-store]')?.dataset.editStore);
      const store = storeById(id);
      const haystack = store
        ? [store.store_name, store.store_code, store.id, `id ${store.id}`, `code ${store.store_code}`, store.status, store.address, store.google_maps_url]
            .join(' ').toLowerCase()
        : (row.textContent || '').toLowerCase();
      row.hidden = !!query && !haystack.includes(query);
    });
  }

  function bindSearch() {
    if (searchBound) return;
    searchBound = true;
    searchInput.addEventListener('input', event => {
      event.stopImmediatePropagation();
      cleanRows();
      applyFilter();
    }, true);
  }

  function waitForIdentityFields(timeout = 800) {
    if (document.getElementById('storeCode')) return Promise.resolve();
    return new Promise(resolve => {
      const started = Date.now();
      const timer = window.setInterval(() => {
        if (document.getElementById('storeCode') || Date.now() - started >= timeout) {
          window.clearInterval(timer);
          resolve();
        }
      }, 25);
    });
  }

  function fillAndOpen(store) {
    const dialog = document.getElementById('storeDialog');
    const form = document.getElementById('storeAdminForm');
    if (!dialog || !form || !store) return false;

    form.reset();
    if (form.elements.id) form.elements.id.value = String(store.id);
    if (form.elements.store_name) form.elements.store_name.value = store.store_name || '';
    if (form.elements.status) form.elements.status.value = store.status || 'active';

    const title = document.getElementById('storeDialogTitle');
    if (title) title.textContent = `Edit ${store.store_name || 'store'}`;

    const internalId = document.getElementById('storeInternalId');
    const code = document.getElementById('storeCode');
    const address = document.getElementById('storeAddress');
    const maps = document.getElementById('storeMapsUrl');
    const logo = document.getElementById('storeLogoFile');
    const preview = document.getElementById('storeLogoPreview');
    if (internalId) internalId.value = String(store.id);
    if (code) { code.value = store.store_code || ''; code.dataset.auto = '0'; }
    if (address) address.value = store.address || '';
    if (maps) maps.value = store.google_maps_url || '';
    if (logo) logo.value = '';
    if (preview) preview.innerHTML = store.logo_path ? `<img src="${esc(store.logo_path)}" alt="Store logo">` : 'No logo';

    if (!dialog.open) dialog.showModal();
    return true;
  }

  function bindStoreEdit() {
    if (editBound) return;
    editBound = true;

    // Capture the click before the legacy per-row handler. DEV Store Edit is
    // self-contained here, so row replacement cannot make the button inert.
    storeRoot.addEventListener('click', async event => {
      const button = event.target.closest('[data-edit-store]');
      if (!button || !storeRoot.contains(button)) return;

      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();

      const id = numericId(button.dataset.editStore);
      const wasDisabled = button.disabled;
      button.disabled = true;
      try {
        if (!storeById(id)) await loadIdentity(true);
        await waitForIdentityFields();
        const store = storeById(id);
        if (!store || !fillAndOpen(store)) throw new Error('Store could not be opened for editing.');
      } catch (error) {
        alert(error.message || 'Store could not be opened for editing.');
      } finally {
        button.disabled = wasDisabled;
      }
    }, true);
  }

  function refreshUi() {
    ensureStyle();
    cleanHeader();
    cleanRows();
    bindSearch();
    bindStoreEdit();
    applyFilter();
  }

  loadIdentity().catch(() => {}).finally(() => {
    refreshUi();
    [120, 350, 800, 1500].forEach(delay => window.setTimeout(refreshUi, delay));
  });
})();
