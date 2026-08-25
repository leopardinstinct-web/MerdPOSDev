(function () {
  'use strict';

  const actions = document.querySelector('.merd-topbar .topbar-actions');
  const userLine = actions?.querySelector('.user-line');
  const passwordBtn = document.getElementById('passwordBtn');
  const logoutBtn = document.getElementById('logoutBtn');
  if (!actions || !userLine || !passwordBtn || !logoutBtn) return;
  if (document.getElementById('accountMenu')) return;

  const name = String(userLine.querySelector('strong')?.textContent || '').trim() || 'Account';
  const role = String(userLine.querySelector('.merd-role-pill')?.textContent || 'USER').trim().toUpperCase();
  const roleClass = ['DEV','SUPER','ADMIN','USER'].includes(role) ? role.toLowerCase() : 'user';

  const menu = document.createElement('details');
  menu.id = 'accountMenu';
  menu.className = 'account-menu';
  menu.innerHTML = `
    <summary class="account-trigger" aria-label="Open account menu">
      <span class="account-name"></span>
      <span class="account-role-badge account-role-${roleClass}"></span>
      <svg class="account-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </summary>
    <div class="account-popover" role="menu" aria-label="Account options">
      <div class="account-menu-slot account-password-slot"></div>
      <div class="account-menu-divider"></div>
      <div class="account-menu-slot account-logout-slot"></div>
    </div>`;

  menu.querySelector('.account-name').textContent = name;
  menu.querySelector('.account-role-badge').textContent = role;

  passwordBtn.className = 'account-menu-item';
  passwordBtn.setAttribute('role', 'menuitem');
  const passwordText = passwordBtn.querySelector('span');
  if (passwordText) passwordText.textContent = 'Change password';

  logoutBtn.className = 'account-menu-item is-danger';
  logoutBtn.setAttribute('role', 'menuitem');
  const logoutText = logoutBtn.querySelector('span');
  if (logoutText) logoutText.textContent = 'Log out';

  menu.querySelector('.account-password-slot').appendChild(passwordBtn);
  menu.querySelector('.account-logout-slot').appendChild(logoutBtn);

  userLine.remove();
  actions.replaceChildren(menu);

  const close = () => { if (menu.open) menu.open = false; };

  document.addEventListener('pointerdown', event => {
    if (menu.open && !menu.contains(event.target)) close();
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && menu.open) {
      close();
      menu.querySelector('summary')?.focus();
    }
  });

  [passwordBtn, logoutBtn].forEach(button => {
    button.addEventListener('click', () => window.setTimeout(close, 0));
  });
})();
