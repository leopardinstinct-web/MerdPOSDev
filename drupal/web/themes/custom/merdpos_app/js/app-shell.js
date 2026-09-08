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
  };
  const syncSystemTheme = () => {
    if ((localStorage.getItem(THEME_KEY) || 'system') === 'system') applyTheme('system');
  };
  media.addEventListener?.('change', syncSystemTheme);

  Drupal.behaviors.merdposAppShell = {
    attach(context) {
      bindThemeControls(context);
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
