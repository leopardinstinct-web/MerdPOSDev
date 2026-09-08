(function (Drupal, once) {
  Drupal.behaviors.merdposAdministration = {
    attach(context) {
      once('merdpos-admin', '[data-merdpos-admin]', context).forEach((root) => {
        const panels = [...root.querySelectorAll('[data-admin-panel]')];
        const activate = (key) => {
          panels.forEach((panel) => panel.classList.toggle('is-active', panel.dataset.adminPanel === key));
        };
        root.querySelectorAll('[data-admin-search]').forEach((input) => {
          const panel = root.querySelector(`[data-admin-panel="${input.dataset.adminSearch}"]`);
          const items = [...(panel?.querySelectorAll('.merdpos-admin-list > .merdpos-admin-editor') || [])];
          const apply = () => {
            const query = input.value.trim().toLowerCase();
            items.forEach((item) => { const haystack = (item.dataset.searchText || '').toLowerCase(); item.hidden = query !== '' && !haystack.includes(query); });
          };
          input.addEventListener('input', apply);
        });
        let requested = '';
        try { requested = new URLSearchParams(window.location.search).get('tab') || ''; } catch (_) {}
        if (requested && panels.some((panel) => panel.dataset.adminPanel === requested)) activate(requested);

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
