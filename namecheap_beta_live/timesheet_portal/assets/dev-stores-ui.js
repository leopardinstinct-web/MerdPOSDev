(function () {
  'use strict';

  const storeRoot = document.getElementById('storeDirectory');
  const searchInput = document.getElementById('storeSearch');
  const dialog = document.getElementById('storeDialog');
  const form = document.getElementById('storeAdminForm');
  if (!storeRoot || !searchInput || !dialog || !form) return;

  if (!document.querySelector('script[data-store-identity-module]')) {
    const script = document.createElement('script');
    script.src = 'assets/store-identity.js?v=20260825d';
    script.dataset.storeIdentityModule = '1';
    document.body.appendChild(script);
  }
  if (!document.querySelector('script[data-roles-module]')) {
    const script = document.createElement('script');
    script.src = 'assets/roles.js?v=20260825a';
    script.dataset.rolesModule = '1';
    document.body.appendChild(script);
  }

  let stores = [];
  let searchBound = false;
  let clickBound = false;

  const numericId = value => {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
  };

  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));

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

  async function loadStores() {
    const response = await fetch('api/store_identity.php?_=' + Date.now(), {
      headers:{'Accept':'application/json'},
      cache:'no-store'
    });
    const text = await response.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; }
    catch (_) { throw new Error(`Store API returned invalid data (${response.status}).`); }
    if (!response.ok || !data?.success || data.actor_role !== 'DEV') {
      throw new Error(data?.error || 'DEV store access is unavailable.');
    }
    stores = (data.stores || []).slice().sort((a,b) => numericId(a.id) - numericId(b.id));
    return stores;
  }

  function storeById(id) {
    return stores.find(store => numericId(store.id) === numericId(id)) || null;
  }

  function storeFromRow(button) {
    const row = button.closest('.entity-row');
    const name = row?.querySelector('.entity-title-line strong')?.textContent?.trim() || 'Store';
    const active = /active/i.test(row?.querySelector('.entity-status')?.textContent || '');
    return {
      id:numericId(button.dataset.editStore),
      store_name:name,
      status:active ? 'active' : 'inactive',
      store_code:'',
      address:'',
      google_maps_url:'',
      logo_path:''
    };
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
      const button = row.querySelector('[data-edit-store]');
      const id = numericId(button?.dataset.editStore);
      const store = storeById(id);
      const haystack = store
        ? [store.store_name,store.store_code,store.id,`id ${store.id}`,`code ${store.store_code}`,store.status,store.address,store.google_maps_url].join(' ').toLowerCase()
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

  function fillBase(store) {
    form.reset();
    if (form.elements.id) form.elements.id.value = String(store.id || '');
    if (form.elements.store_name) form.elements.store_name.value = store.store_name || '';
    if (form.elements.status) form.elements.status.value = store.status || 'active';
    const title = document.getElementById('storeDialogTitle');
    if (title) title.textContent = `Edit ${store.store_name || 'store'}`;
  }

  function fillDevFields(store) {
    const internalId = document.getElementById('storeInternalId');
    const code = document.getElementById('storeCode');
    const address = document.getElementById('storeAddress');
    const maps = document.getElementById('storeMapsUrl');
    const logo = document.getElementById('storeLogoFile');
    const preview = document.getElementById('storeLogoPreview');
    if (internalId) internalId.value = String(store.id || '');
    if (code) { code.value = store.store_code || ''; code.dataset.auto = '0'; }
    if (address) address.value = store.address || '';
    if (maps) maps.value = store.google_maps_url || '';
    if (logo) logo.value = '';
    if (preview) preview.innerHTML = store.logo_path ? `<img src="${esc(store.logo_path)}" alt="Store logo">` : 'No logo';
  }

  function openImmediately(button) {
    const id = numericId(button.dataset.editStore);
    const store = storeById(id) || storeFromRow(button);
    fillBase(store);
    fillDevFields(store);
    if (!dialog.open) dialog.showModal();

    // Enrichment is deliberately non-blocking. The dialog is already visible.
    loadStores().then(() => {
      const fresh = storeById(id);
      if (!fresh || !dialog.open || numericId(form.elements.id?.value) !== id) return;
      fillBase(fresh);
      fillDevFields(fresh);
      [40,120,300,700].forEach(delay => window.setTimeout(() => {
        if (dialog.open && numericId(form.elements.id?.value) === id) fillDevFields(fresh);
      }, delay));
      cleanRows();
      applyFilter();
    }).catch(error => {
      console.error('MERDPOS store editor refresh:', error);
    });
  }

  function bindEdit() {
    if (clickBound) return;
    clickBound = true;

    // Capture at document level and open synchronously. This prevents legacy
    // row handlers or async modules from making the DEV Edit button inert.
    document.addEventListener('click', event => {
      const button = event.target.closest?.('[data-edit-store]');
      if (!button || !storeRoot.contains(button)) return;
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
      try {
        openImmediately(button);
      } catch (error) {
        console.error('MERDPOS store editor open:', error);
        alert(error?.message || 'Store could not be opened for editing.');
      }
    }, true);
  }

  function refreshUi() {
    ensureStyle();
    cleanHeader();
    cleanRows();
    bindSearch();
    bindEdit();
    applyFilter();
  }

  bindEdit();
  refreshUi();
  loadStores().catch(error => console.error('MERDPOS stores:', error)).finally(() => {
    refreshUi();
    [100,300,700,1400].forEach(delay => window.setTimeout(refreshUi, delay));
  });
})();
