(function () {
  const form = document.getElementById('storeAdminForm');
  const dialog = document.getElementById('storeDialog');
  const storeRoot = document.getElementById('storeDirectory');
  if (!form || !dialog || !storeRoot) return;

  let state = null;
  let isDev = false;
  let patchTimers = [];
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot',"'":'&#39;'}[c]));

  async function api(url, options = {}) {
    const response = await fetch(url, options);
    const text = await response.text();
    let data = null;
    if (text) {
      try { data = JSON.parse(text); }
      catch (_) {
        const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 160);
        throw new Error(`Store identity API returned invalid data (${response.status})${snippet ? ': ' + snippet : '.'}`);
      }
    }
    if (!data) throw new Error(`Store identity API returned an empty response (${response.status}).`);
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
    `;
    document.head.appendChild(style);
  }

  function ensureFields() {
    if (document.getElementById('storeCode')) return;
    const grid = form.querySelector('.admin-form-grid');
    const nameLabel = form.elements.store_name?.closest('label');
    if (!grid || !nameLabel) return;

    const idLabel = document.createElement('label');
    idLabel.className = 'dev-store-id-field';
    idLabel.innerHTML = 'Internal Store ID <span class="dev-field-chip">DEV</span><input id="storeInternalId" type="text" readonly tabindex="-1"><p class="form-hint">Database primary key. Read-only because attendance, devices, finance and other records reference it.</p>';

    const codeLabel = document.createElement('label');
    codeLabel.className = 'dev-store-code-field';
    codeLabel.innerHTML = 'Store Code <span class="dev-field-chip">DEV</span><input id="storeCode" name="store_code" type="text" minlength="2" maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9_-]{1,49}" autocomplete="off" autocapitalize="characters" spellcheck="false" required><p class="form-hint">Unique within MERDPOS. 2–50 characters; A–Z, 0–9, hyphen and underscore only.</p>';

    nameLabel.insertAdjacentElement('afterend', idLabel);
    idLabel.insertAdjacentElement('afterend', codeLabel);

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
  }

  function suggestedCode(name) {
    return String(name || '')
      .toUpperCase()
      .trim()
      .replace(/[^A-Z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 50);
  }

  function storeById(id) {
    return (state?.stores || []).find(store => Number(store.id) === Number(id)) || null;
  }

  function populate(store) {
    ensureFields();
    const internalId = document.getElementById('storeInternalId');
    const code = document.getElementById('storeCode');
    if (internalId) internalId.value = store ? String(store.id) : 'Assigned automatically';
    if (code) {
      code.value = store?.store_code || '';
      code.dataset.auto = store ? '0' : '1';
    }
  }

  function patchRows() {
    if (!isDev || !state) return;
    storeRoot.querySelectorAll('[data-edit-store]').forEach(button => {
      const store = storeById(button.dataset.editStore);
      if (!store) return;
      const copy = button.closest('.entity-row')?.querySelector('.entity-copy');
      if (!copy) return;
      let line = copy.querySelector('.dev-store-identity');
      if (!line) {
        line = document.createElement('div');
        line.className = 'entity-sub dev-store-identity';
        const timingLine = copy.querySelector('.entity-sub');
        if (timingLine) timingLine.insertAdjacentElement('beforebegin', line);
        else copy.appendChild(line);
      }
      const nextText = `Code ${store.store_code} · ID ${store.id}`;
      if (line.textContent !== nextText) line.textContent = nextText;
    });
  }

  function queuePatchRows() {
    patchTimers.forEach(clearTimeout);
    patchTimers = [0, 40, 120, 300, 800].map(delay => setTimeout(patchRows, delay));
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
      } else if (attempts > 20) {
        clearInterval(timer);
      }
    }, 80);
  }

  document.addEventListener('click', event => {
    if (!isDev) return;
    const edit = event.target.closest('[data-edit-store]');
    if (edit) {
      queueMicrotask(() => populate(storeById(edit.dataset.editStore)));
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

  document.getElementById('storeSearch')?.addEventListener('input', queuePatchRows);

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
      status:form.elements.status.value,
    };

    if (button) button.disabled = true;
    try {
      const result = await api('api/store_identity.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(payload),
      });
      state = result;
      notice(result.message || 'Store saved.');
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
      if (error.status !== 403) console.error('MERDPOS store identity:', error);
    }
  }

  init();
})();
