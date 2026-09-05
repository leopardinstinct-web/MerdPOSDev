(function (Drupal, once) {
  Drupal.behaviors.merdposAdministration = {
    attach(context) {
      once('merdpos-admin', '[data-merdpos-admin]', context).forEach((root) => {
        const tabs = [...root.querySelectorAll('[data-admin-tab]')];
        const panels = [...root.querySelectorAll('[data-admin-panel]')];
        const activate = (key) => {
          tabs.forEach((tab) => tab.classList.toggle('is-active', tab.dataset.adminTab === key));
          panels.forEach((panel) => panel.classList.toggle('is-active', panel.dataset.adminPanel === key));
          try { sessionStorage.setItem('merdpos-admin-tab', key); } catch (_) {}
        };
        tabs.forEach((tab) => tab.addEventListener('click', () => activate(tab.dataset.adminTab)));
        let saved = '';
        try { saved = sessionStorage.getItem('merdpos-admin-tab') || ''; } catch (_) {}
        if (saved && tabs.some((tab) => tab.dataset.adminTab === saved)) activate(saved);

        root.querySelectorAll('[data-store-mode]').forEach((mode) => {
          const form = mode.closest('form');
          const list = form?.querySelector('[data-store-list]');
          if (!list) return;
          const sync = () => {
            const selected = mode.value === 'selected';
            list.disabled = !selected;
            list.closest('label')?.classList.toggle('is-disabled', !selected);
          };
          mode.addEventListener('change', sync);
          sync();
        });
      });
    },
  };
})(Drupal, once);
