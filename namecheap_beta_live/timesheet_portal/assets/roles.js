(function () {
  'use strict';

  let state = null;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const icon = '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M2 21v-2a6 6 0 0 1 12 0v2"/><path d="M16 8h6M19 5v6"/><path d="M16 16h6"/></svg>';

  function ensureStyles() {
    if (document.getElementById('rolesAuthorityStyles')) return;
    const style = document.createElement('style');
    style.id = 'rolesAuthorityStyles';
    style.textContent = `
      .roles-card{display:grid;gap:16px}.roles-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
      .roles-head h2{margin:0 0 4px}.roles-head p{margin:0;color:#65758A}.roles-list{display:grid;gap:10px}
      .role-row{display:grid;grid-template-columns:minmax(180px,1.3fr) 120px 120px 130px auto;align-items:center;gap:12px;padding:13px 14px;border:1px solid #DFE7F0;border-radius:13px;background:#fff}
      .role-copy{min-width:0}.role-title{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.role-title strong{font-size:13px}.role-key{font:700 9px ui-monospace,SFMono-Regular,Menlo,monospace;color:#718096;background:#F3F6FA;padding:3px 6px;border-radius:999px}
      .role-copy small{display:block;margin-top:4px;color:#6B7D92;font-size:10.5px}.role-meta{display:grid;gap:3px}.role-meta span:first-child{font-size:9px;font-weight:800;color:#8090A4;text-transform:uppercase;letter-spacing:.06em}.role-meta strong{font-size:12px;color:#20344D}
      .role-loa{display:flex;align-items:center;gap:6px}.role-loa input{width:72px;height:36px;border:1px solid #CBD8E7;border-radius:8px;padding:0 8px}.role-actions{display:flex;gap:7px;justify-content:flex-end}
      .role-system-chip{font-size:8.5px;font-weight:800;color:#2D62A7;background:#EEF5FF;border:1px solid #D4E4FA;border-radius:999px;padding:3px 6px}.role-dev-fixed{background:#F8FAFD}.role-status{font-size:11px;color:#64748B}.role-status.is-error{color:#B42318}
      .role-delete{color:#A32B28!important;border-color:#E8C7C5!important}.role-add-dialog .admin-form-grid{grid-template-columns:1fr 160px}.role-inherit-note{font-size:10.5px;color:#64748B;line-height:1.45}
      @media(max-width:900px){.role-row{grid-template-columns:1fr 100px 100px}.role-actions{grid-column:1/-1;justify-content:flex-start}}
      @media(max-width:620px){.roles-head{flex-direction:column}.roles-head .primary-btn{width:100%;justify-content:center}.role-row{grid-template-columns:1fr}.role-actions{grid-column:auto}.role-add-dialog .admin-form-grid{grid-template-columns:1fr}}
    `;
    document.head.appendChild(style);
  }

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
      <section class="controls-card roles-card">
        <div class="roles-head"><div><h2>Roles</h2><p>Role identity, Level of Authority and inherited dashboard access for the working client.</p></div><button id="addRoleBtn" type="button" class="primary-btn compact-btn">+ Add role</button></div>
        <div id="rolesStatus" class="role-status"></div>
        <div id="rolesList" class="roles-list"><div class="entity-empty">Loading roles…</div></div>
      </section>`;
    main.appendChild(panel);
    document.getElementById('addRoleBtn')?.addEventListener('click', openAddRole);
  }

  function ensureAddDialog() {
    if (document.getElementById('roleAddDialog')) return;
    const dialog = document.createElement('dialog');
    dialog.id = 'roleAddDialog';
    dialog.className = 'admin-dialog role-add-dialog';
    dialog.innerHTML = `
      <form id="roleAddForm">
        <div class="admin-dialog-header"><div><h2>Add role</h2><p class="role-inherit-note">New roles inherit the Admin dashboard, then widgets above the selected LOA are automatically removed.</p></div><button type="button" class="icon-btn" data-role-close aria-label="Close">×</button></div>
        <div class="admin-dialog-body">
          <div class="admin-form-grid">
            <label>Role name<input name="role_label" maxlength="80" required></label>
            <label>LOA<input name="authority_level" type="number" min="1" max="99" step="1" required></label>
          </div>
          <div class="form-hint">Base capability: ADMIN. LOA determines which authority-sensitive dashboard widgets are available.</div>
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

  function render() {
    const root = document.getElementById('rolesList');
    if (!root || !state) return;
    const roles = (state.roles || []).slice().sort((a,b) => Number(a.authority_level)-Number(b.authority_level) || Number(a.id)-Number(b.id));
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
            <small>${system ? `Base ${esc(role.base_role)}` : 'Custom · inherits ADMIN'} · ${Number(role.employee_count || 0)} employees</small>
          </div>
          <div class="role-meta"><span>Dashboard</span><strong>${Number(role.dashboard_widget_count || 0)} widgets</strong></div>
          <div class="role-meta"><span>Allowed</span><strong>${allowedCount} widgets</strong></div>
          <label class="role-loa"><span class="sr-only">LOA</span><input data-role-loa type="number" min="1" max="99" step="1" value="${Number(role.authority_level)}" ${isDev ? 'disabled' : ''}></label>
          <div class="role-actions">
            ${editableName ? `<button type="button" class="secondary-btn compact-btn" data-rename-role="${Number(role.id)}">Rename</button>` : ''}
            ${!isDev ? `<button type="button" class="secondary-btn compact-btn" data-save-role="${Number(role.id)}">Save</button>` : '<span class="role-system-chip">Fixed 1000</span>'}
            ${!system ? `<button type="button" class="secondary-btn compact-btn role-delete" data-delete-role="${Number(role.id)}" ${Number(role.employee_count || 0) > 0 ? 'disabled title="Reassign employees first"' : ''}>Delete</button>` : ''}
          </div>
        </article>`;
    }).join('');

    root.querySelectorAll('[data-save-role]').forEach(button => button.addEventListener('click', () => saveRole(Number(button.dataset.saveRole))));
    root.querySelectorAll('[data-delete-role]').forEach(button => button.addEventListener('click', () => deleteRole(Number(button.dataset.deleteRole))));
    root.querySelectorAll('[data-rename-role]').forEach(button => button.addEventListener('click', () => renameRole(Number(button.dataset.renameRole))));
  }

  function openAddRole() {
    ensureAddDialog();
    const form = document.getElementById('roleAddForm');
    form?.reset();
    if (form?.elements.authority_level) {
      const admin = (state?.roles || []).find(role => String(role.role_key).toUpperCase() === 'ADMIN');
      form.elements.authority_level.value = admin ? Number(admin.authority_level) : 50;
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
      render();
      setStatus('Role created from the Admin dashboard template.');
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
      render();
      setStatus('Role saved. Dashboard access was revalidated against LOA.');
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
      render();
      setStatus('Role and its dashboard template were deleted.');
      window.MERDPOSDashboardBuilder?.reloadRoles?.();
    } catch (error) { setStatus(error.message, true); }
  }

  async function load() {
    setStatus('Loading roles…');
    try {
      state = await api('api/role_authority.php?_=' + Date.now(), {headers:{'Accept':'application/json'}});
      render();
      setStatus(`Client ${state.client?.client_code || state.client?.id || ''}`);
    } catch (error) {
      setStatus(error.message, true);
      const root = document.getElementById('rolesList');
      if (root) root.innerHTML = `<div class="entity-empty is-error">${esc(error.message)}</div>`;
    }
  }

  ensureStyles();
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
