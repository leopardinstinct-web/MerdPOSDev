(function (Drupal, once) {
  Drupal.behaviors.merdposAppShell = {
    attach(context) {
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
