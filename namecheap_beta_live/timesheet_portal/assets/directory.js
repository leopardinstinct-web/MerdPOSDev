(function () {
  const employeeRoot = document.getElementById('employeeDirectory');
  const storeRoot = document.getElementById('storeDirectory');
  if (!employeeRoot && !storeRoot) return;

  let directory = null;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = value => '$' + Number(value || 0).toFixed(2);
  const icon = name => {
    const paths = {
      plus:'<path d="M12 5v14M5 12h14"/>',
      edit:'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/>',
      user:'<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
      store:'<path d="M3 9l2-5h14l2 5"/><path d="M5 13v7h14v-7"/><path d="M9 20v-6h6v6"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/>',
      search:'<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>'
    };
    return `<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true">${paths[name] || ''}</svg>`;
  };

  function ensureStylesheet() {
    if (document.querySelector('link[data-directory-access-css]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'assets/directory-access.css';
    link.dataset.directoryAccessCss = '1';
    document.head.appendChild(link);
  }

  async function api(url, options = {}) {
    const response = await fetch(url, options);
    const text = await response.text();
    let data = null;
    if (text) {
      try { data = JSON.parse(text); }
      catch (_) {
        const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 160);
        throw new Error(`MERDPOS admin API returned invalid data (${response.status})${snippet ? ': ' + snippet : '.'}`);
      }
    }
    if (!data) throw new Error(`MERDPOS admin API returned an empty response (${response.status}).`);
    if (!data.success) throw new Error(data.error || `Request failed (${response.status})`);
    return data;
  }

  function notice(message, isError = false) {
    const root = document.getElementById('directoryNotice');
    if (!root) return;
    root.textContent = message;
    root.classList.toggle('is-error', isError);
    root.hidden = !message;
    if (message && !isError) setTimeout(() => { root.hidden = true; }, 3500);
  }

  function clarifyStoreMeaning() {
    const select = document.getElementById('employeeStore');
    if (select) {
      const label = select.closest('label');
      if (label) {
        const textNode = Array.from(label.childNodes).find(node => node.nodeType === Node.TEXT_NODE && node.textContent.trim());
        if (textNode) textNode.nodeValue = 'Default / Log Store';
        if (!label.querySelector('.store-access-hint')) {
          const hint = document.createElement('p');
          hint.className = 'form-hint store-access-hint';
          hint.textContent = 'Used as the employee’s default/log store. Store permissions are configured separately below.';
          label.appendChild(hint);
        }
      }
    }

    const description = employeeRoot?.closest('.directory-card')?.querySelector('.directory-toolbar p');
    if (description) {
      description.textContent = 'Accounts, default/log store, allowed stores, access level and pay rate.';
    }
  }

  function ensureStoreAccessControls() {
    const defaultStore = document.getElementById('employeeStore');
    if (!defaultStore || document.getElementById('employeeStoreAccessMode')) return;
    const defaultLabel = defaultStore.closest('label');
    if (!defaultLabel) return;

    const block = document.createElement('div');
    block.className = 'store-access-block';
    block.innerHTML = `
      <label>Store access
        <select name="store_access_mode" id="employeeStoreAccessMode" required>
          <option value="all">All active stores</option>
          <option value="selected">Selected stores</option>
        </select>
        <p class="form-hint">Choose All stores, or Selected stores to allow one store or any combination of stores.</p>
      </label>
      <div id="employeeStoreChoicesWrap" hidden>
        <div class="store-access-heading"><span>Allowed stores</span><span>Select one or more</span></div>
        <div id="employeeStoreChoices" class="store-choice-grid"></div>
      </div>`;
    defaultLabel.insertAdjacentElement('afterend', block);

    document.getElementById('employeeStoreAccessMode')?.addEventListener('change', () => {
      syncStoreAccessVisibility(true);
    });
    defaultStore.addEventListener('change', () => {
      if (document.getElementById('employeeStoreAccessMode')?.value !== 'selected') return;
      const checkbox = document.querySelector(`#employeeStoreChoices input[value="${CSS.escape(defaultStore.value)}"]`);
      if (checkbox) checkbox.checked = true;
    });
  }

  function activeStores() {
    return (directory?.stores || []).filter(store => String(store.status).toLowerCase() === 'active');
  }

  function renderStoreChoices(selectedIds = []) {
    const root = document.getElementById('employeeStoreChoices');
    if (!root || !directory) return;
    const selected = new Set((selectedIds || []).map(Number));
    root.innerHTML = activeStores().map(store => `
      <label class="store-choice">
        <input type="checkbox" name="store_ids" value="${Number(store.id)}" ${selected.has(Number(store.id)) ? 'checked' : ''}>
        <span>${esc(store.store_name)}</span>
      </label>`).join('');
  }

  function syncStoreAccessVisibility(selectDefault = false) {
    const mode = document.getElementById('employeeStoreAccessMode');
    const wrap = document.getElementById('employeeStoreChoicesWrap');
    if (!mode || !wrap) return;
    const selectedMode = mode.value === 'selected';
    wrap.hidden = !selectedMode;
    if (selectedMode && selectDefault && !document.querySelector('#employeeStoreChoices input:checked')) {
      const defaultId = document.getElementById('employeeStore')?.value || '';
      const checkbox = document.querySelector(`#employeeStoreChoices input[value="${CSS.escape(defaultId)}"]`);
      if (checkbox) checkbox.checked = true;
    }
  }

  async function loadDirectory() {
    try {
      if (employeeRoot) employeeRoot.innerHTML = '<div class="entity-empty">Loading employees…</div>';
      if (storeRoot) storeRoot.innerHTML = '<div class="entity-empty">Loading stores…</div>';
      directory = await api('api/admin_directory.php');
      renderEmployees();
      renderStores();
      populateEmployeeStoreOptions();
      clarifyStoreMeaning();
      ensureStoreAccessControls();
    } catch (error) {
      const html = `<div class="entity-empty is-error">${esc(error.message)}</div>`;
      if (employeeRoot) employeeRoot.innerHTML = html;
      if (storeRoot) storeRoot.innerHTML = html;
    }
  }

  function initials(name) {
    return String(name || '?').trim().split(/\s+/).slice(0,2).map(p => p[0] || '').join('').toUpperCase();
  }

  function statusPill(status) {
    const active = String(status).toLowerCase() === 'active';
    return `<span class="entity-status ${active ? 'is-active' : 'is-inactive'}">${active ? 'Active' : 'Inactive'}</span>`;
  }

  function rolePill(role) {
    const normalized = String(role || 'USER').toUpperCase();
    return `<span class="entity-role role-${esc(normalized.toLowerCase())}">${esc(normalized)}</span>`;
  }

  function storeById(id) {
    return (directory?.stores || []).find(store => Number(store.id) === Number(id));
  }

  function employeeStoreNames(employee) {
    if (String(employee.store_access_mode || 'all') !== 'selected') return [];
    return (employee.assigned_store_ids || []).map(id => storeById(id)?.store_name).filter(Boolean);
  }

  function storeAccessPill(employee) {
    if (String(employee.store_access_mode || 'all') !== 'selected') {
      return '<span class="store-access-summary">All stores</span>';
    }
    const count = (employee.assigned_store_ids || []).length;
    return `<span class="store-access-summary">${count} ${count === 1 ? 'store' : 'stores'}</span>`;
  }

  function renderEmployees(filter = '') {
    if (!employeeRoot || !directory) return;
    const query = String(filter).trim().toLowerCase();
    const rows = (directory.employees || []).filter(employee => {
      const assignedNames = employeeStoreNames(employee).join(' ');
      return !query || [employee.full_name,employee.user_id,employee.store_name,employee.employee_type,employee.status,assignedNames,employee.store_access_mode === 'all' ? 'all stores' : 'selected stores']
        .some(value => String(value || '').toLowerCase().includes(query));
    });
    if (!rows.length) {
      employeeRoot.innerHTML = '<div class="entity-empty">No employees match this search.</div>';
      return;
    }
    employeeRoot.innerHTML = rows.map(employee => {
      const accessNames = employeeStoreNames(employee);
      const accessText = employee.store_access_mode === 'selected'
        ? (accessNames.length ? accessNames.join(', ') : 'No stores selected')
        : 'All active stores';
      return `
      <article class="entity-row ${employee.status === 'inactive' ? 'is-muted' : ''}">
        <div class="entity-avatar">${esc(initials(employee.full_name))}</div>
        <div class="entity-copy">
          <div class="entity-title-line"><strong>${esc(employee.full_name)}</strong>${employee.self ? '<span class="you-chip">You</span>' : ''}</div>
          <div class="entity-sub">ID ${esc(employee.user_id)} · Default/log: ${esc(employee.store_name || 'Not set')}</div>
          <div class="entity-sub">Allowed: ${esc(accessText)}</div>
        </div>
        <div class="entity-meta">
          ${rolePill(employee.employee_type)}
          ${storeAccessPill(employee)}
          <span class="entity-rate">${money(employee.hourly_rate)}/hr</span>
          ${statusPill(employee.status)}
        </div>
        <button type="button" class="icon-text-btn" data-edit-employee="${Number(employee.id)}" ${employee.editable ? '' : 'disabled'}>${icon('edit')}<span>Edit</span></button>
      </article>`;
    }).join('');
    employeeRoot.querySelectorAll('[data-edit-employee]').forEach(button => button.addEventListener('click', () => openEmployee(Number(button.dataset.editEmployee))));
  }

  function renderStores(filter = '') {
    if (!storeRoot || !directory) return;
    const query = String(filter).trim().toLowerCase();
    const rows = (directory.stores || []).filter(s => !query || [s.store_name,s.status,s.shift_start_time].some(v => String(v || '').toLowerCase().includes(query)));
    if (!rows.length) {
      storeRoot.innerHTML = '<div class="entity-empty">No stores match this search.</div>';
      return;
    }
    storeRoot.innerHTML = rows.map(store => `
      <article class="entity-row ${store.status === 'inactive' ? 'is-muted' : ''}">
        <div class="entity-avatar store-avatar">${icon('store')}</div>
        <div class="entity-copy">
          <div class="entity-title-line"><strong>${esc(store.store_name)}</strong></div>
          <div class="entity-sub">Shift start ${esc(formatTime(store.shift_start_time) || 'Not set')}</div>
        </div>
        <div class="entity-meta">${statusPill(store.status)}</div>
        <button type="button" class="icon-text-btn" data-edit-store="${Number(store.id)}">${icon('edit')}<span>Edit</span></button>
      </article>`).join('');
    storeRoot.querySelectorAll('[data-edit-store]').forEach(button => button.addEventListener('click', () => openStore(Number(button.dataset.editStore))));
  }

  function formatTime(value) {
    const text = String(value || '');
    return /^\d{2}:\d{2}/.test(text) ? text.slice(0,5) : text;
  }

  function populateEmployeeStoreOptions() {
    const select = document.getElementById('employeeStore');
    if (!select || !directory) return;
    select.innerHTML = (directory.stores || []).map(s => `<option value="${Number(s.id)}">${esc(s.store_name)}${s.status === 'inactive' ? ' (inactive)' : ''}</option>`).join('');
  }

  function populateRoles(selected = 'USER') {
    const select = document.getElementById('employeeRole');
    if (!select || !directory) return;
    select.innerHTML = (directory.actor.allowed_roles || ['USER']).map(role => `<option value="${esc(role)}" ${role === selected ? 'selected' : ''}>${esc(role)}</option>`).join('');
  }

  function openEmployee(id = null) {
    const dialog = document.getElementById('employeeDialog');
    const form = document.getElementById('employeeAdminForm');
    if (!dialog || !form || !directory) return;
    ensureStoreAccessControls();
    form.reset();
    const employee = id ? (directory.employees || []).find(e => Number(e.id) === Number(id)) : null;
    form.elements.id.value = employee ? employee.id : '';
    form.elements.full_name.value = employee?.full_name || '';
    form.elements.user_id.value = employee?.user_id || '';
    form.elements.hourly_rate.value = employee ? Number(employee.hourly_rate || 0).toFixed(2) : '';
    form.elements.status.value = employee?.status || 'active';
    populateRoles(employee?.employee_type || 'USER');
    populateEmployeeStoreOptions();
    clarifyStoreMeaning();
    if (employee?.store_id) form.elements.store_id.value = employee.store_id;
    form.elements.new_password.value = '';

    const accessMode = document.getElementById('employeeStoreAccessMode');
    if (accessMode) accessMode.value = employee?.store_access_mode || 'all';
    renderStoreChoices(employee?.assigned_store_ids || []);
    syncStoreAccessVisibility(false);

    document.getElementById('employeeDialogTitle').textContent = employee ? `Edit ${employee.full_name}` : 'Add employee';
    document.getElementById('employeePasswordHint').textContent = employee ? 'Leave blank to keep the current password.' : 'Required for a new employee. Use 4–20 digits.';
    const selfGuard = document.getElementById('employeeSelfGuard');
    if (selfGuard) selfGuard.hidden = !employee?.self;
    if (employee?.self) {
      form.elements.employee_type.disabled = true;
      form.elements.status.disabled = true;
    } else {
      form.elements.employee_type.disabled = false;
      form.elements.status.disabled = false;
    }
    dialog.showModal();
  }

  function openStore(id = null) {
    const dialog = document.getElementById('storeDialog');
    const form = document.getElementById('storeAdminForm');
    if (!dialog || !form || !directory) return;
    form.reset();
    const store = id ? (directory.stores || []).find(s => Number(s.id) === Number(id)) : null;
    form.elements.id.value = store ? store.id : '';
    form.elements.store_name.value = store?.store_name || '';
    form.elements.shift_start_time.value = formatTime(store?.shift_start_time || '');
    form.elements.status.value = store?.status || 'active';
    document.getElementById('storeDialogTitle').textContent = store ? `Edit ${store.store_name}` : 'Add store';
    dialog.showModal();
  }

  async function saveForm(form, action) {
    const values = Object.fromEntries(new FormData(form));
    if (action === 'save_employee') {
      if (form.elements.employee_type.disabled) values.employee_type = form.elements.employee_type.value;
      if (form.elements.status.disabled) values.status = form.elements.status.value;
      values.store_access_mode = document.getElementById('employeeStoreAccessMode')?.value || 'all';
      values.store_ids = Array.from(document.querySelectorAll('#employeeStoreChoices input[name="store_ids"]:checked')).map(input => Number(input.value));
    }
    values.action = action;
    values.csrf = directory.csrf;
    try {
      const button = form.querySelector('[type="submit"]');
      button.disabled = true;
      directory = await api('api/admin_directory.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(values),
      });
      renderEmployees(document.getElementById('employeeSearch')?.value || '');
      renderStores(document.getElementById('storeSearch')?.value || '');
      populateEmployeeStoreOptions();
      clarifyStoreMeaning();
      form.closest('dialog')?.close();
      notice(action === 'save_employee' ? 'Employee saved.' : 'Store saved.');
      document.getElementById('refreshBetaBtn')?.click();
    } catch (error) {
      notice(error.message, true);
    } finally {
      const button = form.querySelector('[type="submit"]');
      if (button) button.disabled = false;
    }
  }

  ensureStylesheet();
  ensureStoreAccessControls();
  clarifyStoreMeaning();
  document.getElementById('addEmployeeBtn')?.addEventListener('click', () => openEmployee());
  document.getElementById('addStoreBtn')?.addEventListener('click', () => openStore());
  document.getElementById('employeeAdminForm')?.addEventListener('submit', event => { event.preventDefault(); saveForm(event.currentTarget, 'save_employee'); });
  document.getElementById('storeAdminForm')?.addEventListener('submit', event => { event.preventDefault(); saveForm(event.currentTarget, 'save_store'); });
  document.getElementById('employeeSearch')?.addEventListener('input', event => renderEmployees(event.target.value));
  document.getElementById('storeSearch')?.addEventListener('input', event => renderStores(event.target.value));
  document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog')?.close()));

  loadDirectory();
})();
