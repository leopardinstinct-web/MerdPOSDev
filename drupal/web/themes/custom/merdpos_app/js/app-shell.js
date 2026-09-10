(function (Drupal, once) {
  const THEME_KEY = 'merdpos-theme';
  const media = window.matchMedia('(prefers-color-scheme: dark)');

  const applyTheme = (preference) => {
    const safe = ['system', 'light', 'dark'].includes(preference) ? preference : 'system';
    const resolved = safe === 'system' ? (media.matches ? 'dark' : 'light') : safe;
    document.documentElement.dataset.themePreference = safe;
    document.documentElement.dataset.theme = resolved;
    document.querySelectorAll('[data-merdpos-theme]').forEach((select) => {
      select.value = safe;
    });
    document.querySelectorAll('[data-merdpos-theme-label]').forEach((label) => {
      label.textContent = resolved === 'dark' ? 'Dark theme' : 'Light theme';
    });
    document.querySelectorAll('[data-merdpos-shell-brand-image]').forEach((image) => {
      const source = resolved === 'dark' ? image.dataset.darkSrc : image.dataset.lightSrc;
      if (source && image.getAttribute('src') !== source) image.setAttribute('src', source);
    });
  };

  const bindThemeControls = (context) => {
    const saved = localStorage.getItem(THEME_KEY) || 'system';
    applyTheme(saved);
    once('merdpos-theme', '[data-merdpos-theme]', context).forEach((select) => {
      select.value = saved;
      select.addEventListener('change', () => {
        localStorage.setItem(THEME_KEY, select.value);
        applyTheme(select.value);
      });
    });
    once('merdpos-theme-toggle', '[data-merdpos-theme-toggle]', context).forEach((button) => {
      button.addEventListener('click', () => {
        const current = document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
        const next = current === 'dark' ? 'light' : 'dark';
        localStorage.setItem(THEME_KEY, next);
        applyTheme(next);
      });
    });
  };
  const syncSystemTheme = () => {
    if ((localStorage.getItem(THEME_KEY) || 'system') === 'system') applyTheme('system');
  };
  media.addEventListener?.('change', syncSystemTheme);

  Drupal.behaviors.merdposAppShell = {
    attach(context) {
      bindThemeControls(context);
      once('merdpos-context-notice', '[data-merdpos-context-notice]', context).forEach((notice) => {
        const message = sessionStorage.getItem('merdposContextNotice') || '';
        if (!message) return;
        notice.textContent = message;
        sessionStorage.removeItem('merdposContextNotice');
      });
      once('merdpos-working-client', '[data-merdpos-working-client]', context).forEach((select) => {
        select.addEventListener('change', async () => {
          const prior = select.dataset.activeValue || select.querySelector('option[selected]')?.value || '';
          const clientId = Number.parseInt(select.value, 10);
          if (!Number.isInteger(clientId) || clientId <= 0) return;
          select.disabled = true;
          try {
            const response = await fetch(select.dataset.endpoint || '', {method:'POST', credentials:'same-origin', headers:{'Accept':'application/json','Content-Type':'application/json','X-MERDPOS-CSRF':select.dataset.csrf || ''}, body:JSON.stringify({client_id:clientId})});
            const payload = await response.json().catch(() => null);
            if (!response.ok || !payload || payload.success !== true) throw new Error(payload?.error || `Working client change failed (${response.status}).`);
            sessionStorage.setItem('merdposContextNotice', payload.message || 'Working client changed.');
            window.location.reload();
          } catch (error) {
            if (prior) select.value = prior;
            select.disabled = false;
            window.alert(error.message);
          }
        });
        select.dataset.activeValue = select.value;
      });
      once('merdpos-working-role', '[data-merdpos-working-role]', context).forEach((select) => {
        select.addEventListener('change', async () => {
          const prior = select.dataset.activeValue || select.value;
          const roleKey = String(select.value || '').toUpperCase();
          if (!['DEV','ADMIN','SUPER','USER'].includes(roleKey)) return;
          select.disabled = true;
          try {
            const response = await fetch(select.dataset.endpoint || '', {method:'POST', credentials:'same-origin', headers:{'Accept':'application/json','Content-Type':'application/json','X-MERDPOS-CSRF':select.dataset.csrf || ''}, body:JSON.stringify({role_key:roleKey})});
            const payload = await response.json().catch(() => null);
            if (!response.ok || !payload || payload.success !== true) throw new Error(payload?.error || `Working Role change failed (${response.status}).`);
            sessionStorage.setItem('merdposContextNotice', payload.message || 'Working Role changed.');
            window.location.reload();
          } catch (error) {
            select.value = prior;
            select.disabled = false;
            window.alert(error.message);
          }
        });
        select.dataset.activeValue = select.value;
      });
      once('merdpos-timesheet-sync', '[data-merdpos-timesheet-sync]', context).forEach((button) => {
        button.addEventListener('click', async () => {
          const clientId = Number.parseInt(button.dataset.clientId || '', 10);
          const clientName = String(button.dataset.clientName || `Client ${clientId}`).trim();
          if (!Number.isInteger(clientId) || clientId <= 0) return;
          const approved = window.confirm(`Replace all ${clientName} SQL Time Sheet data with the latest Google "Time Sheet" worksheet?\n\nThe Google worksheet is fully validated first. Only this Working client's attendance events are replaced.`);
          if (!approved) return;
          button.disabled = true;
          button.setAttribute('aria-busy', 'true');
          const select = button.closest('[data-merdpos-working-client-block]')?.querySelector('[data-merdpos-working-client]');
          if (select) select.disabled = true;
          try {
            const response = await fetch(button.dataset.endpoint || '', {method:'POST', credentials:'same-origin', headers:{'Accept':'application/json','Content-Type':'application/json','X-MERDPOS-CSRF':button.dataset.csrf || ''}, body:JSON.stringify({client_id:clientId})});
            const payload = await response.json().catch(() => null);
            if (!response.ok || !payload || payload.success !== true) throw new Error(payload?.error || `Time Sheet sync failed (${response.status}).`);
            const imported = Math.max(0, Number(payload.inserted_rows || payload.source_rows || 0));
            sessionStorage.setItem('merdposContextNotice', `Time Sheet synced - ${imported.toLocaleString()} rows imported from Google.`);
            window.location.reload();
          } catch (error) {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            if (select) select.disabled = false;
            window.alert(error.message);
          }
        });
      });
      once('merdpos-account', '[data-merdpos-account-toggle]', context).forEach((button) => {
        const account = button.closest('.merdpos-account');
        const menu = account?.querySelector('[data-merdpos-account-menu]');
        if (!menu) return;
        const close = () => {
          menu.hidden = true;
          button.setAttribute('aria-expanded', 'false');
        };
        button.addEventListener('click', () => {
          const open = menu.hidden;
          menu.hidden = !open;
          button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', (event) => {
          if (!account.contains(event.target)) close();
        });
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') close();
        });
      });
      once('merdpos-password-dialog', '[data-merdpos-password-dialog]', context).forEach((dialog) => {
        dialog.querySelectorAll('[data-merdpos-password-close]').forEach((button) => {
          button.addEventListener('click', () => dialog.close());
        });
        dialog.addEventListener('click', (event) => {
          if (event.target === dialog) dialog.close();
        });
        dialog.addEventListener('close', () => dialog.querySelector('form')?.reset());
      });
      once('merdpos-password-open', '[data-merdpos-password-open]', context).forEach((button) => {
        button.addEventListener('click', () => {
          const account = button.closest('.merdpos-account');
          const menu = account?.querySelector('[data-merdpos-account-menu]');
          const toggle = account?.querySelector('[data-merdpos-account-toggle]');
          if (menu) menu.hidden = true;
          toggle?.setAttribute('aria-expanded', 'false');
          const dialog = document.querySelector('[data-merdpos-password-dialog]');
          if (!dialog) return;
          if (typeof dialog.showModal === 'function') dialog.showModal();
          else dialog.setAttribute('open', '');
          requestAnimationFrame(() => dialog.querySelector('[name="current_password"]')?.focus());
        });
      });
    },
  };
})(Drupal, once);
