(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.merdposDisputesV1 = {
    attach(context) {
      once('merdpos-disputes-v1', '[data-disputes-root]', context).forEach((root) => {
        const create = root.querySelector('[data-dispute-create]');
        if (create) {
          const type = create.querySelector('[data-dispute-type]');
          const shift = create.querySelector('[data-dispute-shift]');
          const store = create.querySelector('[data-dispute-store]');
          const requestedIn = create.querySelector('[data-dispute-in]');
          const requestedOut = create.querySelector('[data-dispute-out]');

          const toggle = (wrapper, show, required = false) => {
            if (!wrapper) return;
            wrapper.hidden = !show;
            const control = wrapper.querySelector('input,select,textarea');
            if (control) { control.disabled = !show; control.required = show && required; }
          };

          const syncType = () => {
            const value = type?.value || 'other';
            const isNew = value === 'new_shift';
            toggle(shift, !isNew, !isNew);
            toggle(store, isNew, isNew);
            toggle(requestedIn, ['wrong_in','new_shift','other'].includes(value), ['wrong_in','new_shift'].includes(value));
            toggle(requestedOut, ['missing_out','wrong_out','new_shift','other'].includes(value), ['missing_out','wrong_out','new_shift'].includes(value));
          };

          type?.addEventListener('change', syncType);
          syncType();
          create.addEventListener('submit', () => {
            create.classList.add('is-submitting');
            create.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });
          });
        }

        const cards = [...root.querySelectorAll('[data-dispute-card]')];
        const search = root.querySelector('[data-dispute-search]');
        const filters = [...root.querySelectorAll('[data-dispute-filter]')];
        let activeFilter = 'all';

        const matchesFilter = (card) => {
          if (activeFilter === 'all') return true;
          const status = card.dataset.status || '';
          if (activeFilter === 'open') return status === 'pending' || status === 'awaiting_employee';
          return status === activeFilter;
        };

        const applyFilters = () => {
          const query = (search?.value || '').trim().toLowerCase();
          cards.forEach((card) => {
            const matchesSearch = query === '' || card.textContent.toLowerCase().includes(query);
            card.hidden = !(matchesSearch && matchesFilter(card));
          });
        };

        search?.addEventListener('input', applyFilters);
        filters.forEach((button) => button.addEventListener('click', () => {
          activeFilter = button.dataset.disputeFilter || 'all';
          filters.forEach((item) => item.classList.toggle('is-active', item === button));
          applyFilters();
        }));

        root.querySelectorAll('form[data-confirm]').forEach((form) => {
          form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm || 'Continue with this action?')) event.preventDefault();
          });
        });

        root.querySelectorAll('[data-dispute-decision]').forEach((form) => {
          form.addEventListener('submit', (event) => {
            const decision = event.submitter?.value || '';
            const label = decision === 'approved' ? 'Approve' : 'Reject';
            if (!window.confirm(`${label} this attendance dispute? MERDPOS will apply the authoritative workflow.`)) {
              event.preventDefault();
              return;
            }
            form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });
          });
        });
      });
    },
  };
})(Drupal, once);
