(function () {
  'use strict';
  const employeeRoot = document.getElementById('employeeDirectory');
  const storeRoot = document.getElementById('storeDirectory');
  if (!employeeRoot && !storeRoot) return;

  let directory = null;
  const authPermissions = window.MERDPOS_AUTH?.permissions || {};
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = value => '$' + Number(value || 0).toFixed(2);
  const can = key => {
    if (directory?.permissions && Object.prototype.hasOwnProperty.call(directory.permissions, key)) return !!directory.permissions[key];
    return !!authPermissions[key];
  };
  const icon = name => {
    const paths = {
      edit:'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/>',
      store:'<path d="M3 9l2-5h14l2 5"/><path d="M5 13v7h14v-7"/><path d="M9 20v-6h6v6"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/>'
    };
    return `<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true">${paths[name] || ''}</svg>`;
  };

  function ensureStylesheet() {
    if (document.querySelector('link[data-directory-access-css]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'assets/directory-access.css?v=20260826loa1';
    link.dataset.directoryAccessCss = '1';
    document.head.appendChild(link);
  }

  function ensureTimingsModule() {
    if (!can('stores.timings.manage') || document.querySelector('script[data-store-timings-module]')) return;
    const script = document.createElement('script');
    script.src = 'assets/timings.js?v=20260831storeedit1';
    script.dataset.storeTimingsModule = '1';
    document.body.appendChild(script);
  }

  async function api(url, options = {}) {
    const response = await fetch(url, {cache:'no-store', ...options});
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

  function activeStores() {
    return (directory?.stores || []).filter(store => String(store.status).toLowerCase() === 'active');
  }

  function hideLegacyStoreFields() {
    const storeSelect = document.getElementById('employeeStore');
    const storeLabel = storeSelect?.closest('label');
    if (storeLabel) { storeLabel.hidden = true; storeLabel.classList.add('internal-only-field'); }
    const shiftStart = document.querySelector('#storeAdminForm [name="shift_start_time"]');
    const shiftLabel = shiftStart?.closest('label');
    if (shiftLabel) { shiftLabel.hidden = true; shiftLabel.classList.add('internal-only-field'); }
    const employeeDescription = employeeRoot?.closest('.directory-card')?.querySelector('.directory-toolbar p');
    if (employeeDescription) {
      employeeDescription.textContent = can('workforce.payrates.manage')
        ? 'Accounts, store access, role, Level of Authority, pay rate and effective dates.'
        : 'Accounts, store access, role and Level of Authority.';
    }
  }

  function ensureStoreAccessControls() {
    if (!can('workforce.manage')) return;
    const internalStore = document.getElementById('employeeStore');
    if (!internalStore || document.getElementById('employeeStoreAccessMode')) return;
    const internalLabel = internalStore.closest('label');
    if (!internalLabel) return;
    const block = document.createElement('div');
    block.className = 'store-access-block full-field';
    block.innerHTML = `
      <label>Store access
        <select name="store_access_mode" id="employeeStoreAccessMode" required>
          <option value="all">All active stores</option>
          <option value="selected">Selected stores</option>
        </select>
        <p class="form-hint">Use Selected stores for one store or any combination of stores.</p>
      </label>
      <div id="employeeStoreChoicesWrap" hidden>
        <div class="store-access-heading"><span>Allowed stores</span><span>Select one or more</span></div>
        <div id="employeeStoreChoices" class="store-choice-grid"></div>
      </div>`;
    internalLabel.insertAdjacentElement('afterend', block);
    document.getElementById('employeeStoreAccessMode')?.addEventListener('change', () => syncStoreAccessVisibility(true));
    block.addEventListener('change', event => { if (event.target.matches('input[name="store_ids"]')) syncInternalStore(); });
  }

  function ensureRateEffectiveField() {
    if (!can('workforce.manage') || !can('workforce.payrates.manage')) return;
    const rate = document.querySelector('#employeeAdminForm [name="hourly_rate"]');
    if (!rate || document.getElementById('employeeRateEffective')) return;
    const rateLabel = rate.closest('label');
    if (!rateLabel) return;
    rateLabel.hidden = false;
    const label = document.createElement('label');
    label.innerHTML = 'Rate effective from<input id="employeeRateEffective" name="rate_effective_date" type="date" required><p id="employeeRateHint" class="form-hint">Historical payroll keeps the rate that applied on each shift date.</p>';
    rateLabel.insertAdjacentElement('afterend', label);
  }

  function renderStoreChoices(selectedIds = []) {
    const root = document.getElementById('employeeStoreChoices');
    if (!root || !directory) return;
    const selected = new Set((selectedIds || []).map(Number));
    root.innerHTML = activeStores().map(store => `
      <label class="store-choice"><input type="checkbox" name="store_ids" value="${Number(store.id)}" ${selected.has(Number(store.id)) ? 'checked' : ''}><span>${esc(store.store_name)}</span></label>`).join('');
  }

  function syncInternalStore() {
    const select = document.getElementById('employeeStore');
    if (!select) return;
    const mode = document.getElementById('employeeStoreAccessMode')?.value || 'all';
    if (mode === 'selected') {
      const checked = document.querySelector('#employeeStoreChoices input[name="store_ids"]:checked');
      if (checked) select.value = checked.value;
    } else if (!select.value) {
      const first = activeStores()[0];
      if (first) select.value = String(first.id);
    }
  }

  function syncStoreAccessVisibility(selectOne = false) {
    const mode = document.getElementById('employeeStoreAccessMode');
    const wrap = document.getElementById('employeeStoreChoicesWrap');
    if (!mode || !wrap) return;
    const selectedMode = mode.value === 'selected';
    wrap.hidden = !selectedMode;
    if (selectedMode && selectOne && !document.querySelector('#employeeStoreChoices input:checked')) {
      const first = document.querySelector('#employeeStoreChoices input[name="store_ids"]');
      if (first) first.checked = true;
    }
    syncInternalStore();
  }

  async function loadDirectory() {
    try {
      if (employeeRoot) employeeRoot.innerHTML = '<div class="entity-empty">Loading employees…</div>';
      if (storeRoot) storeRoot.innerHTML = '<div class="entity-empty">Loading stores…</div>';
      directory = await api('api/admin_directory.php?_=' + Date.now());
      renderEmployees();
      renderStores();
      populateEmployeeStoreOptions();
      hideLegacyStoreFields();
      ensureStoreAccessControls();
      ensureRateEffectiveField();
      ensureTimingsModule();
    } catch (error) {
      const html = `<div class="entity-empty is-error">${esc(error.message)}</div>`;
      if (employeeRoot) employeeRoot.innerHTML = html;
      if (storeRoot) storeRoot.innerHTML = html;
    }
  }

  function initials(name) { return String(name || '?').trim().split(/\s+/).slice(0,2).map(p => p[0] || '').join('').toUpperCase(); }
  function statusPill(status) { const active=String(status).toLowerCase()==='active'; return `<span class="entity-status ${active?'is-active':'is-inactive'}">${active?'Active':'Inactive'}</span>`; }
  function rolePill(employee) {
    const key = String(employee?.role_key || employee?.employee_type || 'USER').toUpperCase();
    const label = String(employee?.role_label || key);
    return `<span class="entity-role role-${esc(key.toLowerCase())}" title="${esc(key)} · LOA ${Number(employee?.role_authority || 0)}">${esc(label)}</span>`;
  }
  function storeById(id) { return (directory?.stores || []).find(store => Number(store.id) === Number(id)); }
  function employeeStoreNames(employee) { if (String(employee.store_access_mode || 'all') !== 'selected') return []; return (employee.assigned_store_ids || []).map(id => storeById(id)?.store_name).filter(Boolean); }
  function storeAccessPill(employee) { if (String(employee.store_access_mode || 'all') !== 'selected') return '<span class="store-access-summary">All stores</span>'; const count=(employee.assigned_store_ids||[]).length; return `<span class="store-access-summary">${count} ${count===1?'store':'stores'}</span>`; }
  function formatDate(value) { const text=String(value||''); if(!/^\d{4}-\d{2}-\d{2}$/.test(text))return text; const date=new Date(text+'T00:00:00'); return Number.isNaN(date.getTime())?text:date.toLocaleDateString(undefined,{day:'2-digit',month:'short',year:'numeric'}); }

  function renderEmployees(filter = '') {
    if (!employeeRoot || !directory) return;
    if (!can('workforce.view')) { employeeRoot.innerHTML = '<div class="entity-empty">Your LOA does not include Workforce.</div>'; return; }
    const query = String(filter).trim().toLowerCase();
    const rows = (directory.employees || []).filter(employee => {
      const assignedNames = employeeStoreNames(employee).join(' ');
      return !query || [employee.full_name,employee.user_id,employee.employee_type,employee.role_label,employee.role_key,employee.status,assignedNames,employee.store_access_mode === 'all' ? 'all stores' : 'selected stores']
        .some(value => String(value || '').toLowerCase().includes(query));
    });
    if (!rows.length) { employeeRoot.innerHTML = '<div class="entity-empty">No employees match this search.</div>'; return; }
    employeeRoot.innerHTML = rows.map(employee => {
      const nextRate = can('workforce.payrates.manage') && employee.next_rate ? `<div class="entity-sub rate-future">Next rate ${money(employee.next_rate.hourly_rate)} from ${esc(formatDate(employee.next_rate.effective_from))}</div>` : '';
      const edit = can('workforce.manage') ? `<button type="button" class="merd-icon-action directory-edit-icon-btn" data-edit-employee="${Number(employee.id)}" aria-label="Edit ${esc(employee.full_name)}" title="Edit ${esc(employee.full_name)}" ${employee.editable?'':'disabled'}>${icon('edit')}</button>` : '';
      return `<article class="entity-row ${employee.status === 'inactive' ? 'is-muted' : ''}">
        <div class="entity-avatar">${esc(initials(employee.full_name))}</div>
        <div class="entity-copy"><div class="entity-title-line"><strong>${esc(employee.full_name)}</strong>${employee.self?'<span class="you-chip">You</span>':''}</div><div class="entity-sub">ID ${esc(employee.user_id)}</div>${nextRate}</div>
        <div class="entity-meta">${statusPill(employee.status)}</div>${edit}
      </article>`;
    }).join('');
    employeeRoot.querySelectorAll('[data-edit-employee]').forEach(button => button.addEventListener('click', () => openEmployee(Number(button.dataset.editEmployee))));
  }

  function renderStores(filter = '') {
    if (!storeRoot || !directory) return;
    if (!can('stores.view')) { storeRoot.innerHTML = '<div class="entity-empty">Your LOA does not include Stores.</div>'; return; }
    const query = String(filter).trim().toLowerCase();
    const rows = (directory.stores || []).filter(s => !query || [s.store_name,s.status,s.id].some(v => String(v || '').toLowerCase().includes(query)));
    if (!rows.length) { storeRoot.innerHTML = '<div class="entity-empty">No stores match this search.</div>'; return; }
    storeRoot.innerHTML = rows.map(store => {
      const edit = can('stores.manage') ? `<button type="button" class="merd-icon-action directory-edit-icon-btn store-edit-icon-btn" data-edit-store="${Number(store.id)}" aria-label="Edit ${esc(store.store_name)}" title="Edit ${esc(store.store_name)}">${icon('edit')}</button>` : '';
      return `<article class="entity-row ${store.status==='inactive'?'is-muted':''}"><div class="entity-avatar store-avatar">${icon('store')}</div><div class="entity-copy"><div class="entity-title-line"><strong>${esc(store.store_name)}</strong></div></div><div class="entity-meta">${statusPill(store.status)}</div>${edit}</article>`;
    }).join('');
    storeRoot.querySelectorAll('[data-edit-store]').forEach(button => button.addEventListener('click', () => openStore(Number(button.dataset.editStore))));
  }

  function populateEmployeeStoreOptions() {
    const select = document.getElementById('employeeStore');
    if (!select || !directory) return;
    select.innerHTML = (directory.stores || []).map(s => `<option value="${Number(s.id)}">${esc(s.store_name)}</option>`).join('');
  }

  function populateRoles(selectedRoleId = null, fallbackBase = 'USER') {
    const select = document.getElementById('employeeRole');
    if (!select || !directory) return;
    const roles = directory.actor?.roles || [];
    select.innerHTML = roles.map(role => `<option value="${Number(role.id)}" data-base-role="${esc(role.base_role)}" ${Number(role.id)===Number(selectedRoleId)?'selected':''}>${esc(role.role_label)} · LOA ${Number(role.authority_level)}</option>`).join('');
    if (!selectedRoleId) {
      const fallback = roles.find(role => String(role.role_key).toUpperCase() === String(fallbackBase).toUpperCase()) || roles[0];
      if (fallback) select.value = String(fallback.id);
    }
  }

  function syncCredentialField(employee) {
    const input = document.querySelector('#employeeAdminForm [name="new_password"]');
    if (!input) return;
    const label = input.closest('label');
    const isNew = !employee;
    const allowed = isNew || can('workforce.credentials.reset');
    if (label) label.hidden = !allowed;
    input.disabled = !allowed;
    input.required = isNew;
    input.value = '';
    const hint = document.getElementById('employeePasswordHint');
    if (hint) hint.textContent = isNew ? 'Required for a new employee. Use 4–20 digits.' : (allowed ? 'Leave blank to keep the current password.' : 'Password reset requires a higher LOA.');
  }

  function openEmployee(id = null) {
    if (!can('workforce.manage')) return;
    const dialog = document.getElementById('employeeDialog');
    const form = document.getElementById('employeeAdminForm');
    if (!dialog || !form || !directory) return;
    ensureStoreAccessControls(); ensureRateEffectiveField(); hideLegacyStoreFields(); form.reset();
    const employee = id ? (directory.employees || []).find(e => Number(e.id) === Number(id)) : null;
    form.elements.id.value = employee ? employee.id : '';
    form.elements.full_name.value = employee?.full_name || '';
    form.elements.user_id.value = employee?.user_id || '';
    form.elements.status.value = employee?.status || 'active';
    populateRoles(employee?.client_role_id || null, employee?.employee_type || 'USER');
    populateEmployeeStoreOptions();
    if (employee?.store_id) form.elements.store_id.value = employee.store_id;
    syncCredentialField(employee);
    const accessMode = document.getElementById('employeeStoreAccessMode'); if (accessMode) accessMode.value = employee?.store_access_mode || 'all';
    renderStoreChoices(employee?.assigned_store_ids || []); syncStoreAccessVisibility(false);

    const rateInput = form.elements.hourly_rate;
    const rateEffective = form.elements.rate_effective_date;
    if (can('workforce.payrates.manage') && rateInput) {
      const scheduled = employee?.next_rate || null;
      rateInput.disabled = false;
      rateInput.required = true;
      rateInput.value = scheduled ? Number(scheduled.hourly_rate).toFixed(2) : (employee ? Number(employee.hourly_rate || 0).toFixed(2) : '');
      if (rateEffective) rateEffective.value = scheduled?.effective_from || directory.today || new Date().toISOString().slice(0,10);
      const rateHint = document.getElementById('employeeRateHint'); if (rateHint) rateHint.textContent = scheduled ? `A future rate is already scheduled from ${formatDate(scheduled.effective_from)}. Saving on the same date updates that scheduled rate.` : 'Historical payroll keeps the rate that applied on each shift date.';
    } else if (rateInput) {
      rateInput.disabled = true;
      rateInput.required = false;
    }

    document.getElementById('employeeDialogTitle').textContent = employee ? `Edit ${employee.full_name}` : 'Add employee';
    const selfGuard = document.getElementById('employeeSelfGuard'); if (selfGuard) selfGuard.hidden = !employee?.self;
    const roleSelect = document.getElementById('employeeRole'); if (roleSelect) roleSelect.disabled = !!employee?.self;
    form.elements.status.disabled = !!employee?.self;
    dialog.showModal();
  }

  function openStore(id = null) {
    if (!can('stores.manage')) return;
    const dialog = document.getElementById('storeDialog');
    const form = document.getElementById('storeAdminForm');
    if (!dialog || !form || !directory) return;
    hideLegacyStoreFields(); form.reset();
    const store = id ? (directory.stores || []).find(s => Number(s.id) === Number(id)) : null;
    form.elements.id.value = store ? store.id : '';
    form.elements.store_name.value = store?.store_name || '';
    form.elements.status.value = store?.status || 'active';
    if (form.elements.week_start_day) form.elements.week_start_day.value = String(store?.week_start_day || 1);
    document.getElementById('storeDialogTitle').textContent = store ? `Edit ${store.store_name}` : 'Add store';
    dialog.showModal();
    window.MERDPOSStoreTimings?.openStore?.(store?.id || null);
  }

  async function saveForm(form, action) {
    if (action === 'save_employee' && !can('workforce.manage')) return;
    if (action === 'save_store' && !can('stores.manage')) return;
    const values = Object.fromEntries(new FormData(form));
    if (action === 'save_store') values.week_start_day = form.elements.week_start_day?.value || '1';
    if (action === 'save_employee') {
      const roleSelect = document.getElementById('employeeRole');
      const selectedOption = roleSelect?.selectedOptions?.[0];
      values.client_role_id = roleSelect?.value || '';
      values.employee_type = selectedOption?.dataset?.baseRole || 'USER';
      if (form.elements.status.disabled) values.status = form.elements.status.value;
      values.store_access_mode = document.getElementById('employeeStoreAccessMode')?.value || 'all';
      values.store_ids = Array.from(document.querySelectorAll('#employeeStoreChoices input[name="store_ids"]:checked')).map(input => Number(input.value));
      if (can('workforce.payrates.manage')) values.rate_effective_date = document.getElementById('employeeRateEffective')?.value || '';
      else { delete values.hourly_rate; delete values.rate_effective_date; }
      if (!can('workforce.credentials.reset') && values.id) delete values.new_password;
      syncInternalStore();
      values.store_id = document.getElementById('employeeStore')?.value || '';
    }
    values.action = action;
    values.csrf = directory.csrf;
    const button = form.querySelector('[type="submit"]');
    try {
      if (button) button.disabled = true;
      directory = await api('api/admin_directory.php', {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(values)});
      renderEmployees(document.getElementById('employeeSearch')?.value || '');
      renderStores(document.getElementById('storeSearch')?.value || '');
      populateEmployeeStoreOptions(); hideLegacyStoreFields(); form.closest('dialog')?.close();
      notice(action === 'save_employee' ? 'Employee saved.' : 'Store saved.');
      document.getElementById('refreshBetaBtn')?.click();
    } catch (error) { notice(error.message, true); }
    finally { if (button) button.disabled = false; }
  }

  ensureStylesheet();
  hideLegacyStoreFields();
  if (can('workforce.manage')) { ensureStoreAccessControls(); ensureRateEffectiveField(); }
  if (can('stores.timings.manage')) ensureTimingsModule();
  document.getElementById('addEmployeeBtn')?.addEventListener('click', () => openEmployee());
  document.getElementById('addStoreBtn')?.addEventListener('click', () => openStore());
  document.getElementById('employeeAdminForm')?.addEventListener('submit', event => { event.preventDefault(); saveForm(event.currentTarget, 'save_employee'); });
  document.getElementById('storeAdminForm')?.addEventListener('submit', event => { event.preventDefault(); saveForm(event.currentTarget, 'save_store'); });
  document.getElementById('employeeSearch')?.addEventListener('input', event => renderEmployees(event.target.value));
  document.getElementById('storeSearch')?.addEventListener('input', event => renderStores(event.target.value));
  document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog')?.close()));
  loadDirectory();
})();
