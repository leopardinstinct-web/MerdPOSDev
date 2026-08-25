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
      .roles-card{display:grid;gap:18px}
      .roles-intro{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
      .roles-intro h2{margin:0 0 4px}.roles-intro p{margin:0;color:#65758A}
      .roles-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
      .role-authority-row{border:1px solid #E1E7EF;border-radius:14px;background:#fff;padding:14px;display:grid;gap:10px}
      .role-authority-head{display:flex;align-items:center;justify-content:space-between;gap:10px}
      .role-authority-head strong{font-size:14px}.role-authority-code{font-size:10px;color:#65758A;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
      .role-authority-row label{display:grid;gap:6px;color:#53677F;font-size:11px;font-weight:700}
      .role-authority-row input{height:40px;border:1px solid #CBD8E7;border-radius:9px;padding:0 10px;background:#fff;color:#152A43;font:inherit}
      .role-authority-help{font-size:10.5px;color:#65758A;line-height:1.45}
      .dev-role-fixed{border:1px dashed #B9C9DD;border-radius:12px;background:#F8FAFD;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px}
      .dev-role-fixed strong{font-size:12px}.dev-role-fixed span{font-size:10.5px;color:#65758A}
      .roles-footer{display:flex;align-items:center;justify-content:space-between;gap:12px}
      .roles-status{font-size:11px;color:#65758A}.roles-status.is-error{color:#B42318}
      @media(max-width:820px){.roles-grid{grid-template-columns:1fr}.roles-intro,.roles-footer{align-items:stretch;flex-direction:column}}
    `;
    document.head.appendChild(style);
  }

  async function api(url, options = {}) {
    const response = await fetch(url, options);
    const text = await response.text();
    let data = null;
    if (text) {
      try { data = JSON.parse(text); }
      catch (_) {
        const snippet = text.replace(/\s+/g, ' ').trim().slice(0,160);
        throw new Error(`Roles API returned invalid data (${response.status})${snippet ? ': ' + snippet : '.'}`);
      }
    }
    if (!data) throw new Error(`Roles API returned an empty response (${response.status}).`);
    if (!data.success) throw new Error(data.error || `Request failed (${response.status})`);
    return data;
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
        <div class="roles-intro"><div><h2>Roles</h2><p>Set the management authority hierarchy for the selected client.</p></div></div>
        <form id="rolesAuthorityForm">
          <div id="rolesAuthorityGrid" class="roles-grid"><div class="entity-empty">Loading roles…</div></div>
          <div class="dev-role-fixed"><div><strong>DEV</strong><br><span>System/developer authority is fixed and cannot be changed here.</span></div><strong>1000</strong></div>
          <div class="roles-footer"><span id="rolesAuthorityStatus" class="roles-status"></span><button type="submit" class="primary-btn compact-btn">Save authority levels</button></div>
        </form>
      </section>`;
    main.appendChild(panel);
    document.getElementById('rolesAuthorityForm')?.addEventListener('submit', save);
  }

  function operationsGroup() {
    return document.querySelector('.sidebar-group[data-sidebar-group="operations"]')
      || Array.from(document.querySelectorAll('.nav-group')).find(group =>
        group.querySelector('.nav-group-label')?.textContent.trim().toLowerCase() === 'operations'
      ) || null;
  }

  function activate(button) {
    document.querySelectorAll('.portal-tab').forEach(tab => tab.classList.toggle('active', tab === button));
    document.querySelectorAll('.portal-panel').forEach(panel => { panel.hidden = panel.id !== 'rolesPanel'; });
    const group = button.closest('[data-sidebar-group]');
    if (group) {
      document.querySelectorAll('.sidebar-group').forEach(item => {
        const active = item === group;
        item.hidden = !active;
        item.classList.toggle('active', active);
      });
      document.querySelectorAll('.rail-group-btn').forEach(rail => rail.classList.toggle('active', rail.dataset.navGroup === 'operations'));
    }
    if (!state) load();
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
    if (workforce) group.insertBefore(button, workforce);
    else group.appendChild(button);
    button.addEventListener('click', () => activate(button));
    return true;
  }

  function repositionBeforeWorkforce() {
    const group = operationsGroup();
    const tab = group?.querySelector('[data-panel="rolesPanel"]');
    const workforce = group?.querySelector('[data-panel="employeesPanel"]');
    if (tab && workforce && tab.nextElementSibling !== workforce) group.insertBefore(tab, workforce);
  }

  function render() {
    const root = document.getElementById('rolesAuthorityGrid');
    if (!root || !state) return;
    root.innerHTML = (state.roles || []).map(role => `
      <article class="role-authority-row">
        <div class="role-authority-head"><strong>${esc(role.label)}</strong><span class="role-authority-code">${esc(role.role_name)}</span></div>
        <label>Authority level
          <input type="number" min="1" max="99" step="1" required data-role-authority="${esc(role.role_name)}" value="${Number(role.authority_level)}">
        </label>
        <div class="role-authority-help">Higher numbers can manage roles at the same or lower authority level. Values must be unique.</div>
      </article>`).join('');
  }

  function status(message, error = false) {
    const root = document.getElementById('rolesAuthorityStatus');
    if (!root) return;
    root.textContent = message || '';
    root.classList.toggle('is-error', error);
  }

  async function load() {
    status('Loading roles…');
    try {
      state = await api('api/role_authority.php');
      render();
      status(`Client ${state.client?.client_code || state.client?.id || ''}`);
    } catch (error) {
      status(error.message, true);
      const root = document.getElementById('rolesAuthorityGrid');
      if (root) root.innerHTML = `<div class="entity-empty is-error">${esc(error.message)}</div>`;
    }
  }

  async function save(event) {
    event.preventDefault();
    if (!state) return;
    const form = event.currentTarget;
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const levels = {};
    form.querySelectorAll('[data-role-authority]').forEach(input => { levels[input.dataset.roleAuthority] = Number(input.value); });
    if (new Set(Object.values(levels)).size !== 3) {
      status('User, Admin and Super must each have a different authority level.', true);
      return;
    }
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      state = await api('api/role_authority.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'save_authority',levels,csrf:state.csrf}),
      });
      render();
      status('Authority levels saved.');
    } catch (error) {
      status(error.message, true);
    } finally {
      button.disabled = false;
    }
  }

  ensureStyles();
  createPanel();
  if (!mountTab()) {
    let attempts = 0;
    const timer = window.setInterval(() => {
      attempts += 1;
      if (mountTab() || attempts > 30) window.clearInterval(timer);
    }, 120);
  }
  [120,350,800,1500].forEach(delay => window.setTimeout(repositionBeforeWorkforce, delay));
})();
