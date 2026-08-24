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
      user:'<path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/>',
      store:'<path d="M3 9l2-5h14l2 5"/><path d="M5 13v7h14v-7"/><path d="M9 20v-6h6v6"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/>',
      search:'<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>'
    };
    return `<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true">${paths[name] || ''}</svg>`;
  };

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

  async function loadDirectory() {
    try {
      if (employeeRoot) employeeRoot.innerHTML = '<div class="entity-empty">Loading employees…</div>';
      if (storeRoot) storeRoot.innerHTML = '<div class="entity-empty">Loading stores…</div>';
      directory = await api('api/admin_directory.php');
      renderEmployees();
      renderStores();
      populateEmployeeStoreOptions();
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

  function renderEmployees(filter = '') {
    if (!employeeRoot || !directory) return;
    const query = String(filter).trim().toLowerCase();
    const rows = (directory.employees || []).filter(e => !query || [e.full_name,e.user_id,e.store_name,e.employee_type,e.status,'all stores'].some(v => String(v || '').toLowerCase().includes(query)));
    if (!rows.length) {
      employeeRoot.innerHTML = '<div class="entity-empty">No employees match this search.</div>';
      return;
    }
    employeeRoot.innerHTML = rows.map(employee => `
      <article class="entity-row ${employee.status === 'inactive' ? 'is-muted' : ''}">
        <div class="entity-avatar">${esc(initials(employee.full_name))}</div>
        <div class="entity-copy">
          <div class="entity-title-line"><strong>${esc(employee.full_name)}</strong>${employee.self ? '<span class="you-chip">You</span>' : ''}</div>
          <div class="entity-sub">ID ${esc(employee.user_id)} · All active stores · Default/log: ${esc(employee.store_name || 'Not set')}</div>
        </div>
        <div class="entity-meta">
          ${rolePill(employee.employee_type)}
          <span class="entity-rate">${money(employee.hourly_rate)}/hr</span>
          ${statusPill(employee.status)}
        </div>
        <button type="button" class="icon-text-btn" data-edit-employee="${Number(employee.id)}" ${employee.editable ? '' : 'disabled'}>${icon('edit')}<span>Edit</span></button>
      </article>`).join('');
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
    form.reset();
    const employee = id ? (directory.employees || []).find(e => Number(e.id) === Number(id)) : null;
    form.elements.id.value = employee ? employee.id : '';
    form.elements.full_name.value = employee?.full_name || '';
    form.elements.user_id.value = employee?.user_id || '';
    form.elements.hourly_rate.value = employee ? Number(employee.hourly_rate || 0).toFixed(2) : '';
    form.elements.status.value = employee?.status || 'active';
    populateRoles(employee?.employee_type || 'USER');
    populateEmployeeStoreOptions();
    if (employee?.store_id) form.elements.store_id.value = employee.store_id;
    form.elements.new_password.value = '';
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

  document.getElementById('addEmployeeBtn')?.addEventListener('click', () => openEmployee());
  document.getElementById('addStoreBtn')?.addEventListener('click', () => openStore());
  document.getElementById('employeeAdminForm')?.addEventListener('submit', event => { event.preventDefault(); saveForm(event.currentTarget, 'save_employee'); });
  document.getElementById('storeAdminForm')?.addEventListener('submit', event => { event.preventDefault(); saveForm(event.currentTarget, 'save_store'); });
  document.getElementById('employeeSearch')?.addEventListener('input', event => renderEmployees(event.target.value));
  document.getElementById('storeSearch')?.addEventListener('input', event => renderStores(event.target.value));
  document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog')?.close()));

  loadDirectory();
})();
