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
    },
  };
})(Drupal, once);
