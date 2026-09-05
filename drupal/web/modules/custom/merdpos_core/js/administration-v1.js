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
        root.querySelectorAll('[data-admin-search]').forEach((input) => {
          const panel = root.querySelector(`[data-admin-panel="${input.dataset.adminSearch}"]`);
          const items = [...(panel?.querySelectorAll('.merdpos-admin-list > .merdpos-admin-editor') || [])];
          const apply = () => {
            const query = input.value.trim().toLowerCase();
            items.forEach((item) => { item.hidden = query !== '' && !item.textContent.toLowerCase().includes(query); });
          };
          input.addEventListener('input', apply);
        });
        let saved = '';
        try {
          const requested = new URLSearchParams(window.location.search).get('tab') || '';
          saved = requested || sessionStorage.getItem('merdpos-admin-tab') || '';
        } catch (_) {}
        if (saved && tabs.some((tab) => tab.dataset.adminTab === saved)) activate(saved);

        root.querySelectorAll('[data-onboard-form]').forEach((form) => {
          const scheduleToggle = form.querySelector('[data-onboard-schedule-toggle]');
          const schedule = form.querySelector('[data-onboard-schedule]');
          if (scheduleToggle && schedule) {
            const syncSchedule = () => { schedule.hidden = !scheduleToggle.checked; };
            scheduleToggle.addEventListener('change', syncSchedule);
            syncSchedule();
            schedule.querySelectorAll('[data-onboard-day-closed]').forEach((closed) => {
              const row = closed.closest('.merdpos-onboard-day');
              const syncDay = () => row?.querySelectorAll('input[type="time"]').forEach((input) => { input.disabled = closed.checked; });
              closed.addEventListener('change', syncDay);
              syncDay();
            });
          }
          form.addEventListener('submit', () => {
            form.classList.add('is-submitting');
            const button = form.querySelector('[data-onboard-submit]');
            if (button) { button.disabled = true; button.dataset.originalText = button.textContent; button.textContent = 'Provisioning'; }
          });
        });

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
