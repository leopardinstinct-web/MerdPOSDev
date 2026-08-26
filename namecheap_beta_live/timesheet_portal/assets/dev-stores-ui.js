(function () {
  'use strict';

  const storeRoot = document.getElementById('storeDirectory');
  const searchInput = document.getElementById('storeSearch');
  if (!storeRoot || !searchInput) return;

  if (!document.querySelector('script[data-store-identity-module]')) {
    const script = document.createElement('script');
    script.src = 'assets/store-identity.js?v=20260825e';
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

  const numericId = value => {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
  };

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
    const actorRole = String(data?.actor_role || '').trim().toUpperCase();
    if (!response.ok || !data?.success || !['DEV','DEVELOPER'].includes(actorRole)) {
      throw new Error(data?.error || 'DEV store access is unavailable.');
    }
    stores = (data.stores || []).slice().sort((a,b) => numericId(a.id) - numericId(b.id));
    return stores;
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
    const sorted = rows.slice().sort((a,b) => {
      const aid = numericId(a.querySelector('[data-edit-store]')?.dataset.editStore);
      const bid = numericId(b.querySelector('[data-edit-store]')?.dataset.editStore);
      return aid - bid;
    });
    if (!sorted.every((row,index) => row === rows[index])) {
      sorted.forEach(row => storeRoot.appendChild(row));
    }

    sorted.forEach(row => {
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
      // DEV search owns this field so the legacy name/status-only filter does not
      // replace the rows and discard the richer search result.
      event.stopImmediatePropagation();
      cleanRows();
      applyFilter();
    }, true);
  }

  function refreshUi() {
    ensureStyle();
    cleanHeader();
    cleanRows();
    bindSearch();
    applyFilter();
  }

  // Store Edit is intentionally NOT handled here. directory.js owns Edit exactly
  // like Workforce Edit; store-identity.js only enriches the dialog after it opens.
  refreshUi();
  loadStores().catch(error => console.error('MERDPOS stores:', error)).finally(() => {
    refreshUi();
    [100,300,700,1400].forEach(delay => window.setTimeout(refreshUi, delay));
  });
})();
