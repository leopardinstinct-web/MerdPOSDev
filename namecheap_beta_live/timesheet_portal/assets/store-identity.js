(function () {
  'use strict';

  const form = document.getElementById('storeAdminForm');
  const dialog = document.getElementById('storeDialog');
  const storeRoot = document.getElementById('storeDirectory');
  if (!form || !dialog || !storeRoot) return;

  let state = null;
  let isDev = false;
  let patchTimers = [];
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  async function api(url, options = {}) {
    const response = await fetch(url, options);
    const text = await response.text();
    let data = null;
    if (text) {
      try { data = JSON.parse(text); }
      catch (_) {
        const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 160);
        throw new Error(`Store API returned invalid data (${response.status})${snippet ? ': ' + snippet : '.'}`);
      }
    }
    if (!data) throw new Error(`Store API returned an empty response (${response.status}).`);
    if (!data.success) {
      const error = new Error(data.error || `Request failed (${response.status})`);
      error.status = response.status;
      throw error;
    }
    return data;
  }

  function notice(message, error = false) {
    const root = document.getElementById('directoryNotice');
    if (root) {
      root.textContent = message;
      root.classList.toggle('is-error', error);
      root.hidden = false;
      if (!error) setTimeout(() => { root.hidden = true; }, 3500);
      return;
    }
    if (error) alert(message);
  }

  function ensureStyles() {
    if (document.getElementById('storeIdentityDevStyle')) return;
    const style = document.createElement('style');
    style.id = 'storeIdentityDevStyle';
    style.textContent = `
      .dev-store-id-field input[readonly]{background:#F3F6FA!important;color:#607086!important;cursor:not-allowed}
      .dev-store-code-field input{text-transform:uppercase;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace!important;letter-spacing:.035em}
      .dev-store-identity{color:#44607F!important;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace!important;font-size:10.5px!important}
      .dev-field-chip{display:inline-flex;align-items:center;margin-left:6px;padding:2px 6px;border-radius:999px;background:#EEF4FF;color:#2859A8;font-size:8px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;vertical-align:middle}
      .store-logo-field{grid-column:1/-1;display:grid!important;grid-template-columns:88px minmax(0,1fr);gap:14px;align-items:center;padding:12px;border:1px solid #E1E8F0;border-radius:12px;background:#F8FAFC}
      .store-logo-preview{width:76px;height:76px;border-radius:12px;border:1px solid #D8E2EE;background:#fff;display:grid;place-items:center;overflow:hidden;color:#8796A8;font-size:10px;text-align:center}
      .store-logo-preview img{width:100%;height:100%;object-fit:contain;background:#fff}
      .store-logo-controls{display:grid;gap:6px;min-width:0}
      .store-logo-controls input[type=file]{min-height:40px!important;padding:7px!important;background:#fff!important}
      .store-profile-link{display:inline-flex;align-items:center;gap:4px;margin-top:3px;color:#2F67B1;font-size:10.5px;font-weight:600;text-decoration:none}
      .store-profile-link:hover{text-decoration:underline}
      .entity-avatar.store-avatar.has-logo{background:#fff!important;padding:3px;overflow:hidden}
      .entity-avatar.store-avatar.has-logo img{width:100%;height:100%;object-fit:contain;border-radius:8px}
      @media(max-width:620px){.store-logo-field{grid-template-columns:64px minmax(0,1fr)}.store-logo-preview{width:58px;height:58px}}
    `;
    document.head.appendChild(style);
  }

  function suggestedCode(name) {
    return String(name || '')
      .toUpperCase()
      .trim()
      .replace(/[^A-Z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 50);
  }

  function ensureFields() {
    if (document.getElementById('storeCode')) return;
    const grid = form.querySelector('.admin-form-grid');
    const nameLabel = form.elements.store_name?.closest('label');
    if (!grid || !nameLabel) return;

    const idLabel = document.createElement('label');
    idLabel.className = 'dev-store-id-field';
    idLabel.innerHTML = 'Internal Store ID <span class="dev-field-chip">DEV</span><input id="storeInternalId" type="text" readonly tabindex="-1"><p class="form-hint">Database primary key; intentionally read-only.</p>';

    const codeLabel = document.createElement('label');
    codeLabel.className = 'dev-store-code-field';
    codeLabel.innerHTML = 'Store Code <span class="dev-field-chip">DEV</span><input id="storeCode" name="store_code" type="text" minlength="2" maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9_-]{1,49}" autocomplete="off" autocapitalize="characters" spellcheck="false" required><p class="form-hint">Unique inside the client. A–Z, 0–9, hyphen and underscore.</p>';

    const addressLabel = document.createElement('label');
    addressLabel.className = 'full-field';
    addressLabel.innerHTML = 'Shop address<textarea id="storeAddress" name="address" maxlength="1000" rows="3" placeholder="Street address, suburb, state, postcode"></textarea>';

    const mapsLabel = document.createElement('label');
    mapsLabel.className = 'full-field';
    mapsLabel.innerHTML = 'Google Maps URL<input id="storeMapsUrl" name="google_maps_url" type="url" maxlength="2048" placeholder="https://maps.app.goo.gl/..."><p class="form-hint">HTTPS Google Maps links only.</p>';

    const logoLabel = document.createElement('label');
    logoLabel.className = 'store-logo-field';
    logoLabel.innerHTML = '<span class="store-logo-preview" id="storeLogoPreview">No logo</span><span class="store-logo-controls"><strong>Store logo</strong><input id="storeLogoFile" name="logo" type="file" accept="image/png,image/jpeg,image/webp"><span class="form-hint">PNG, JPEG or WebP · max 2 MB · 32–4096 px. Square or rectangular shop logos are supported.</span></span>';

    nameLabel.insertAdjacentElement('afterend', idLabel);
    idLabel.insertAdjacentElement('afterend', codeLabel);
    const statusLabel = form.elements.status?.closest('label');
    if (statusLabel) {
      statusLabel.insertAdjacentElement('afterend', addressLabel);
      addressLabel.insertAdjacentElement('afterend', mapsLabel);
      mapsLabel.insertAdjacentElement('afterend', logoLabel);
    } else {
      grid.append(addressLabel, mapsLabel, logoLabel);
    }

    const code = document.getElementById('storeCode');
    code?.addEventListener('input', () => {
      code.value = code.value.toUpperCase().replace(/\s+/g, '-');
      code.dataset.auto = '0';
    });
    form.elements.store_name?.addEventListener('input', () => {
      if (form.elements.id.value) return;
      if (!code || (code.value && code.dataset.auto !== '1')) return;
      code.value = suggestedCode(form.elements.store_name.value);
      code.dataset.auto = '1';
    });
    document.getElementById('storeLogoFile')?.addEventListener('change', previewSelectedLogo);
  }

  function storeById(id) {
    return (state?.stores || []).find(store => Number(store.id) === Number(id)) || null;
  }

  function logoMarkup(path) {
    return path ? `<img src="${esc(path)}" alt="Store logo">` : 'No logo';
  }

  function previewSelectedLogo() {
    const input = document.getElementById('storeLogoFile');
    const preview = document.getElementById('storeLogoPreview');
    if (!input || !preview) return;
    const file = input.files?.[0];
    if (!file) {
      const current = storeById(form.elements.id.value);
      preview.innerHTML = logoMarkup(current?.logo_path || '');
      return;
    }
    if (!['image/png','image/jpeg','image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
      input.value = '';
      notice('Choose a PNG, JPEG or WebP logo up to 2 MB.', true);
      return;
    }
    const url = URL.createObjectURL(file);
    preview.innerHTML = `<img src="${esc(url)}" alt="Selected store logo">`;
    const img = preview.querySelector('img');
    img?.addEventListener('load', () => URL.revokeObjectURL(url), {once:true});
  }

  function populate(store) {
    ensureFields();
    const internalId = document.getElementById('storeInternalId');
    const code = document.getElementById('storeCode');
    const address = document.getElementById('storeAddress');
    const maps = document.getElementById('storeMapsUrl');
    const logo = document.getElementById('storeLogoFile');
    const preview = document.getElementById('storeLogoPreview');
    if (internalId) internalId.value = store ? String(store.id) : 'Assigned automatically';
    if (code) {
      code.value = store?.store_code || '';
      code.dataset.auto = store ? '0' : '1';
    }
    if (address) address.value = store?.address || '';
    if (maps) maps.value = store?.google_maps_url || '';
    if (logo) logo.value = '';
    if (preview) preview.innerHTML = logoMarkup(store?.logo_path || '');
  }

  function patchRows() {
    if (!isDev || !state) return;
    storeRoot.querySelectorAll('[data-edit-store]').forEach(button => {
      const store = storeById(button.dataset.editStore);
      if (!store) return;
      const row = button.closest('.entity-row');
      const copy = row?.querySelector('.entity-copy');
      const avatar = row?.querySelector('.entity-avatar.store-avatar');
      if (!copy) return;

      let identity = copy.querySelector('.dev-store-identity');
      if (!identity) {
        identity = document.createElement('div');
        identity.className = 'entity-sub dev-store-identity';
        copy.appendChild(identity);
      }
      identity.textContent = `Code ${store.store_code} · ID ${store.id}`;

      let address = copy.querySelector('.dev-store-address');
      if (store.address) {
        if (!address) {
          address = document.createElement('div');
          address.className = 'entity-sub dev-store-address';
          copy.appendChild(address);
        }
        address.textContent = store.address;
      } else {
        address?.remove();
      }

      let mapLink = copy.querySelector('.store-profile-link');
      if (store.google_maps_url) {
        if (!mapLink) {
          mapLink = document.createElement('a');
          mapLink.className = 'store-profile-link';
          mapLink.target = '_blank';
          mapLink.rel = 'noopener noreferrer';
          mapLink.textContent = 'Open in Google Maps ↗';
          copy.appendChild(mapLink);
        }
        mapLink.href = store.google_maps_url;
      } else {
        mapLink?.remove();
      }

      if (avatar) {
        avatar.classList.toggle('has-logo', !!store.logo_path);
        if (store.logo_path) avatar.innerHTML = `<img src="${esc(store.logo_path)}" alt="${esc(store.store_name)} logo">`;
      }
    });
  }

  function queuePatchRows() {
    patchTimers.forEach(clearTimeout);
    patchTimers = [0, 60, 180, 450, 900].map(delay => setTimeout(patchRows, delay));
  }

  function restoreStoresPanel() {
    if (sessionStorage.getItem('merdposReturnPanel') !== 'storesPanel') return;
    sessionStorage.removeItem('merdposReturnPanel');
    let attempts = 0;
    const timer = setInterval(() => {
      attempts++;
      const tab = document.querySelector('[data-panel="storesPanel"]');
      if (tab) {
        tab.click();
        clearInterval(timer);
      } else if (attempts > 25) clearInterval(timer);
    }, 80);
  }

  document.addEventListener('click', event => {
    if (!isDev) return;
    const edit = event.target.closest('[data-edit-store]');
    if (edit) {
      const store = storeById(edit.dataset.editStore);
      queueMicrotask(() => populate(store));
      return;
    }
    if (event.target.closest('#addStoreBtn')) {
      queueMicrotask(() => {
        populate(null);
        const code = document.getElementById('storeCode');
        if (code) {
          code.value = suggestedCode(form.elements.store_name?.value || '');
          code.dataset.auto = '1';
        }
      });
    }
    if (event.target.closest('[data-panel="storesPanel"]')) queuePatchRows();
  });

  async function uploadLogo(storeId, csrf) {
    const input = document.getElementById('storeLogoFile');
    const file = input?.files?.[0];
    if (!file) return null;
    const body = new FormData();
    body.append('csrf', csrf);
    body.append('store_id', String(storeId));
    body.append('logo', file, file.name);
    return api('api/store_logo.php', {method:'POST', body});
  }

  form.addEventListener('submit', async event => {
    if (!isDev || !state) return;
    event.preventDefault();
    event.stopImmediatePropagation();

    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    const button = form.querySelector('[type="submit"]');
    const code = document.getElementById('storeCode');
    const payload = {
      action:'save_store',
      csrf:state.csrf,
      id:form.elements.id.value || null,
      store_name:form.elements.store_name.value,
      store_code:code?.value || '',
      address:document.getElementById('storeAddress')?.value || '',
      google_maps_url:document.getElementById('storeMapsUrl')?.value || '',
      status:form.elements.status.value,
    };

    if (button) button.disabled = true;
    try {
      let result = await api('api/store_identity.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(payload),
      });
      await uploadLogo(result.store_id, result.csrf || state.csrf);
      state = result;
      notice(document.getElementById('storeLogoFile')?.files?.length ? 'Store and logo saved.' : (result.message || 'Store saved.'));
      sessionStorage.setItem('merdposReturnPanel', 'storesPanel');
      window.location.reload();
    } catch (error) {
      notice(error.message, true);
      if (button) button.disabled = false;
    }
  }, true);

  async function init() {
    try {
      state = await api('api/store_identity.php');
      isDev = state.actor_role === 'DEV';
      if (!isDev) return;
      ensureStyles();
      ensureFields();
      queuePatchRows();
      restoreStoresPanel();
    } catch (error) {
      if (error.status !== 403) console.error('MERDPOS store profile:', error);
    }
  }

  init();
})();
