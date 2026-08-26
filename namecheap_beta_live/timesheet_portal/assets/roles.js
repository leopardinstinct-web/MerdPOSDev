(function () {
  'use strict';

  let state = null;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const icon = '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M2 21v-2a6 6 0 0 1 12 0v2"/><path d="M16 8h6M19 5v6"/><path d="M16 16h6"/></svg>';

  async function api(url, options = {}) {
    const response = await fetch(url, {cache:'no-store', ...options});
    const text = await response.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; }
    catch (_) { throw new Error(`Roles API returned invalid data (${response.status}).`); }
    if (!data) throw new Error(`Roles API returned an empty response (${response.status}).`);
    if (!data.success) throw new Error(data.error || `Request failed (${response.status})`);
    return data;
  }

  function operationsGroup() {
    return document.querySelector('.sidebar-group[data-sidebar-group="operations"]')
      || Array.from(document.querySelectorAll('.nav-group')).find(group => group.querySelector('.nav-group-label')?.textContent.trim().toLowerCase() === 'operations') || null;
  }

  function createPanel() {
    if (document.getElementById('rolesPanel')) return;
    const main = document.querySelector('main.merd-page-shell');
    if (!main) return;
    const panel = document.createElement('section');
    panel.id = 'rolesPanel';
    panel.className = 'portal-panel';
    panel.hidden = true;
    panel.innerHTML = `
      <div class="roles-shell">
        <section class="controls-card roles-card">
          <div class="roles-head"><div><h2>Roles</h2><p>Role identity and Level of Authority for the working client. A role receives every portal capability whose required LOA is at or below the role LOA.</p></div><button id="addRoleBtn" type="button" class="primary-btn compact-btn">+ Add role</button></div>
          <div id="rolesStatus" class="role-status"></div>
          <div id="rolesList" class="roles-list"><div class="entity-empty">Loading roles…</div></div>
        </section>
        <section class="controls-card permission-card">
          <div class="permission-head">
            <div><h2>Permission policy</h2><p>Configure the minimum LOA for every delegable MERDPOS capability. This policy controls the sidebar, panels, action buttons, API endpoints and dashboard widgets together.</p></div>
            <div class="permission-actions"><span id="permissionUnsaved" class="permission-unsaved" hidden>Unsaved changes</span><button id="savePermissionPolicy" class="primary-btn compact-btn" type="button">Save policy</button></div>
          </div>
          <div class="permission-danger-note"><strong>Backend enforced.</strong> Lowering a threshold grants that capability to more roles. Raising it removes access immediately; dashboard widgets whose underlying data permission is lost are pruned automatically. DEV-only capabilities remain locked at 1000.</div>
          <div id="permissionSummary" class="permission-summary"></div>
          <div id="permissionGroups" class="permission-groups"><div class="entity-empty">Loading permission policy…</div></div>
          <p class="permission-footnote">A role name does not grant access by itself. The numeric LOA and this client permission policy are authoritative. DEV-only items additionally require an actual DEV identity and cannot be delegated by setting another role to LOA 1000.</p>
        </section>
      </div>`;
    main.appendChild(panel);
    document.getElementById('addRoleBtn')?.addEventListener('click', openAddRole);
    document.getElementById('savePermissionPolicy')?.addEventListener('click', savePermissions);
  }

  function ensureAddDialog() {
    if (document.getElementById('roleAddDialog')) return;
    const dialog = document.createElement('dialog');
    dialog.id = 'roleAddDialog';
    dialog.className = 'admin-dialog role-add-dialog';
    dialog.innerHTML = `
      <form id="roleAddForm">
        <div class="admin-dialog-header"><div><h2>Add role</h2><p class="role-inherit-note">New roles inherit the current Admin dashboard. Anything above the selected role LOA is removed immediately.</p></div><button type="button" class="icon-btn" data-role-close aria-label="Close">×</button></div>
        <div class="admin-dialog-body">
          <div class="admin-form-grid">
            <label>Role name<input name="role_label" maxlength="80" required></label>
            <label>LOA<input name="authority_level" type="number" min="1" max="99" step="1" required></label>
          </div>
          <div class="form-hint">Custom roles use the ADMIN compatibility base internally, but actual access is decided by LOA + Permission Policy.</div>
          <div class="admin-dialog-footer"><button type="button" class="secondary-btn" data-role-close>Cancel</button><button type="submit" class="primary-btn compact-btn">Create role</button></div>
        </div>
      </form>`;
    document.body.appendChild(dialog);
    dialog.querySelectorAll('[data-role-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    document.getElementById('roleAddForm')?.addEventListener('submit', createRole);
  }

  function mountTab() {
    if (document.querySelector('[data-panel="rolesPanel"]')) return true;
    const group = operationsGroup();
    if (!group) return false;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'portal-tab';
    button.dataset.panel = 'rolesPanel';
    button.innerHTML = `${icon}<span>Roles</span>`;
    const workforce = group.querySelector('[data-panel="employeesPanel"]');
    if (workforce) group.insertBefore(button, workforce); else group.appendChild(button);
    button.addEventListener('click', () => {
      document.querySelectorAll('.portal-tab').forEach(tab => tab.classList.toggle('active', tab === button));
      document.querySelectorAll('.portal-panel').forEach(panel => { panel.hidden = panel.id !== 'rolesPanel'; });
      if (!state) load();
    });
    return true;
  }

  function setStatus(message, error = false) {
    const node = document.getElementById('rolesStatus');
    if (!node) return;
    node.textContent = message || '';
    node.classList.toggle('is-error', error);
  }

  function rolesSorted() {
    return (state?.roles || []).slice().sort((a,b) => Number(a.authority_level)-Number(b.authority_level) || Number(a.id)-Number(b.id));
  }

  function renderRoles() {
    const root = document.getElementById('rolesList');
    if (!root || !state) return;
    const roles = rolesSorted();
    root.innerHTML = roles.map(role => {
      const key = String(role.role_key || '').toUpperCase();
      const isDev = key === 'DEV';
      const system = Number(role.is_system) === 1;
      const editableName = !system;
      const allowedCount = Array.isArray(role.allowed_widgets) ? role.allowed_widgets.length : 0;
      return `
        <article class="role-row ${isDev ? 'role-dev-fixed' : ''}" data-role-row="${Number(role.id)}">
          <div class="role-copy">
            <div class="role-title"><strong>${esc(role.role_label)}</strong><span class="role-key">${esc(key)}</span>${system ? '<span class="role-system-chip">SYSTEM</span>' : ''}</div>
            <small>${isDev ? 'Non-delegable developer identity' : (system ? `System role · compatibility base ${esc(role.base_role)}` : 'Custom role · compatibility base ADMIN')} · ${Number(role.employee_count || 0)} employees</small>
          </div>
          <div class="role-meta"><span>Dashboard</span><strong>${Number(role.dashboard_widget_count || 0)} widgets</strong></div>
          <div class="role-meta"><span>Allowed</span><strong>${allowedCount} widgets</strong></div>
          <label class="role-loa"><span class="sr-only">LOA</span><input data-role-loa type="number" min="1" max="99" step="1" value="${Number(role.authority_level)}" ${isDev ? 'disabled' : ''}></label>
          <div class="role-actions">
            ${editableName ? `<button type="button" class="secondary-btn compact-btn" data-rename-role="${Number(role.id)}">Rename</button>` : ''}
            ${!isDev ? `<button type="button" class="secondary-btn compact-btn" data-save-role="${Number(role.id)}">Save</button>` : '<span class="role-system-chip">Fixed LOA 1000</span>'}
            ${!system ? `<button type="button" class="secondary-btn compact-btn role-delete" data-delete-role="${Number(role.id)}" ${Number(role.employee_count || 0) > 0 ? 'disabled title="Reassign employees first"' : ''}>Delete</button>` : ''}
          </div>
        </article>`;
    }).join('');

    root.querySelectorAll('[data-save-role]').forEach(button => button.addEventListener('click', () => saveRole(Number(button.dataset.saveRole))));
    root.querySelectorAll('[data-delete-role]').forEach(button => button.addEventListener('click', () => deleteRole(Number(button.dataset.deleteRole))));
    root.querySelectorAll('[data-rename-role]').forEach(button => button.addEventListener('click', () => renameRole(Number(button.dataset.renameRole))));
    root.querySelectorAll('[data-role-loa]').forEach(input => input.addEventListener('input', renderPermissions));
  }

  function permissionDraftLevel(permission) {
    const input = document.querySelector(`[data-permission-key="${CSS.escape(String(permission.permission_key))}"]`);
    if (input) return Number(input.value);
    return Number(permission.min_authority_level || 1000);
  }

  function draftRoleLevels() {
    const map = new Map();
    rolesSorted().forEach(role => {
      const input = document.querySelector(`[data-role-row="${Number(role.id)}"] [data-role-loa]`);
      map.set(Number(role.id), input && !input.disabled ? Number(input.value) : Number(role.authority_level));
    });
    return map;
  }

  function impactHtml(permission, threshold, roleLevels) {
    const roles = rolesSorted().filter(role => {
      const key = String(role.role_key || '').toUpperCase();
      if (permission.dev_only) return key === 'DEV';
      return Number(roleLevels.get(Number(role.id)) ?? role.authority_level) >= threshold;
    });
    if (!roles.length) return '<span class="impact-none">No current role</span>';
    return roles.map(role => `<span class="impact-role">${esc(role.role_label)} · ${Number(roleLevels.get(Number(role.id)) ?? role.authority_level)}</span>`).join('');
  }

  function renderPermissions() {
    const root = document.getElementById('permissionGroups');
    const summary = document.getElementById('permissionSummary');
    if (!root || !state) return;
    const permissions = (state.permissions || []).slice();
    const roleLevels = draftRoleLevels();
    const groups = new Map();
    permissions.forEach(permission => {
      const category = String(permission.category || 'Other');
      if (!groups.has(category)) groups.set(category, []);
      groups.get(category).push(permission);
    });

    root.innerHTML = Array.from(groups.entries()).map(([category, rows]) => `
      <section class="permission-group">
        <div class="permission-group-title"><strong>${esc(category)}</strong><small>${rows.length} ${rows.length === 1 ? 'capability' : 'capabilities'}</small></div>
        ${rows.map(permission => {
          const threshold = Number(permission.min_authority_level || 1000);
          return `<div class="permission-row">
            <div class="permission-copy"><strong>${esc(permission.label)}</strong><code>${esc(permission.permission_key)}</code></div>
            <label class="permission-level"><span class="sr-only">Minimum LOA</span><input type="number" min="1" max="1000" step="1" value="${threshold}" data-permission-key="${esc(permission.permission_key)}" ${permission.dev_only ? 'disabled' : ''}>${permission.dev_only ? '<span class="permission-lock">DEV</span>' : ''}</label>
            <div class="permission-impact" data-permission-impact="${esc(permission.permission_key)}">${impactHtml(permission, threshold, roleLevels)}</div>
          </div>`;
        }).join('')}
      </section>`).join('');

    root.querySelectorAll('[data-permission-key]').forEach(input => {
      input.addEventListener('input', () => {
        const key = input.dataset.permissionKey;
        const permission = (state.permissions || []).find(item => item.permission_key === key);
        const impact = root.querySelector(`[data-permission-impact="${CSS.escape(String(key))}"]`);
        if (permission && impact) impact.innerHTML = impactHtml(permission, Number(input.value), draftRoleLevels());
        const unsaved = document.getElementById('permissionUnsaved');
        if (unsaved) unsaved.hidden = false;
      });
    });

    if (summary) {
      const delegable = permissions.filter(item => !item.dev_only).length;
      const locked = permissions.filter(item => item.dev_only).length;
      summary.innerHTML = `<span>${permissions.length} capabilities</span><span>${delegable} configurable</span><span>${locked} DEV-only locked</span><span>${rolesSorted().length} roles evaluated</span>`;
    }
  }

  function openAddRole() {
    ensureAddDialog();
    const form = document.getElementById('roleAddForm');
    form?.reset();
    if (form?.elements.authority_level) {
      const admin = (state?.roles || []).find(role => String(role.role_key).toUpperCase() === 'ADMIN');
      form.elements.authority_level.value = admin ? Math.min(99, Number(admin.authority_level)) : 50;
    }
    document.getElementById('roleAddDialog')?.showModal();
  }

  async function createRole(event) {
    event.preventDefault();
    if (!state) return;
    const form = event.currentTarget;
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      state = await api('api/role_authority.php', {
        method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({action:'create_role',csrf:state.csrf,role_label:form.elements.role_label.value,authority_level:Number(form.elements.authority_level.value)}),
      });
      document.getElementById('roleAddDialog')?.close();
      renderAll();
      setStatus('Role created. Its Admin dashboard inheritance was filtered through the current permission policy.');
      window.MERDPOSDashboardBuilder?.reloadRoles?.();
    } catch (error) { setStatus(error.message, true); }
    finally { button.disabled = false; }
  }

  async function saveRole(roleId, labelOverride = null) {
    if (!state) return;
    const role = (state.roles || []).find(item => Number(item.id) === Number(roleId));
    const row = document.querySelector(`[data-role-row="${Number(roleId)}"]`);
    if (!role || !row) return;
    const loa = Number(row.querySelector('[data-role-loa]')?.value);
    try {
      state = await api('api/role_authority.php', {
        method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({action:'save_role',csrf:state.csrf,role_id:roleId,role_label:labelOverride ?? role.role_label,authority_level:loa}),
      });
      renderAll();
      setStatus('Role saved. Portal permissions and dashboard eligibility were recalculated from the new LOA.');
      window.MERDPOSDashboardBuilder?.reloadRoles?.();
    } catch (error) { setStatus(error.message, true); }
  }

  function renameRole(roleId) {
    const role = (state?.roles || []).find(item => Number(item.id) === Number(roleId));
    if (!role) return;
    const next = window.prompt('Role name', role.role_label);
    if (next === null) return;
    const label = next.trim();
    if (!label) return;
    saveRole(roleId, label);
  }

  async function deleteRole(roleId) {
    const role = (state?.roles || []).find(item => Number(item.id) === Number(roleId));
    if (!role) return;
    if (!window.confirm(`Delete role “${role.role_label}”? Its dashboard template will also be deleted.`)) return;
    try {
      state = await api('api/role_authority.php', {
        method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({action:'delete_role',csrf:state.csrf,role_id:roleId}),
      });
      renderAll();
      setStatus('Role and its dashboard template were deleted.');
      window.MERDPOSDashboardBuilder?.reloadRoles?.();
    } catch (error) { setStatus(error.message, true); }
  }

  async function savePermissions() {
    if (!state) return;
    const button = document.getElementById('savePermissionPolicy');
    const levels = {};
    let valid = true;
    document.querySelectorAll('[data-permission-key]').forEach(input => {
      if (input.disabled) return;
      const value = Number(input.value);
      if (!Number.isInteger(value) || value < 1 || value > 1000) valid = false;
      levels[input.dataset.permissionKey] = value;
    });
    if (!valid) { setStatus('Every permission LOA must be a whole number from 1 to 1000.', true); return; }
    if (!window.confirm('Save this permission policy? Access changes take effect immediately across menus, APIs and dashboards.')) return;
    if (button) button.disabled = true;
    try {
      state = await api('api/role_authority.php', {
        method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({action:'save_permissions',csrf:state.csrf,levels}),
      });
      renderAll();
      const unsaved = document.getElementById('permissionUnsaved');
      if (unsaved) unsaved.hidden = true;
      setStatus('Permission policy saved. Restricted dashboard widgets were pruned and the backend policy is active immediately.');
      window.MERDPOSDashboardBuilder?.reloadRoles?.();
    } catch (error) { setStatus(error.message, true); }
    finally { if (button) button.disabled = false; }
  }

  function renderAll() {
    renderRoles();
    renderPermissions();
    const unsaved = document.getElementById('permissionUnsaved');
    if (unsaved) unsaved.hidden = true;
  }

  async function load() {
    setStatus('Loading roles and permission policy…');
    try {
      state = await api('api/role_authority.php?_=' + Date.now(), {headers:{'Accept':'application/json'}});
      renderAll();
      setStatus(`Client ${state.client?.client_code || state.client?.id || ''} · Central LOA authorization active`);
    } catch (error) {
      setStatus(error.message, true);
      const root = document.getElementById('rolesList');
      if (root) root.innerHTML = `<div class="entity-empty is-error">${esc(error.message)}</div>`;
      const permissionRoot = document.getElementById('permissionGroups');
      if (permissionRoot) permissionRoot.innerHTML = `<div class="entity-empty is-error">${esc(error.message)}</div>`;
    }
  }

  ensureAddDialog();
  createPanel();
  if (!mountTab()) {
    let attempts = 0;
    const timer = window.setInterval(() => {
      attempts += 1;
      if (mountTab() || attempts > 30) window.clearInterval(timer);
    }, 120);
  }
  [120,350,800,1500].forEach(delay => window.setTimeout(() => {
    const group = operationsGroup();
    const tab = group?.querySelector('[data-panel="rolesPanel"]');
    const workforce = group?.querySelector('[data-panel="employeesPanel"]');
    if (tab && workforce && tab.nextElementSibling !== workforce) group.insertBefore(tab, workforce);
  }, delay));
})();