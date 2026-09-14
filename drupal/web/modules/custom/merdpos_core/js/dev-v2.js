(function (Drupal, once) {
  'use strict';

  const variableForRole = {
    foundation: '--color-brand-navy',
    accent: '--color-brand-cyan',
    secondary: '--color-brand-violet',
  };

  Drupal.behaviors.merdposDevPalette = {
    attach(context) {
      once('merdpos-dev-palette', '[data-brand-palette-form]', context).forEach((form) => {
        const root = document.documentElement;
        const status = form.querySelector('[data-palette-preview-status]');

        const selectedHexForEntry = (entryId) => {
          const row = form.querySelector(`[data-palette-entry="${CSS.escape(entryId)}"]`);
          const select = row?.querySelector('[data-palette-swatch]');
          return select?.selectedOptions?.[0]?.dataset?.hex || '';
        };

        const updateSwatch = (select) => {
          const row = select.closest('[data-palette-entry]');
          const sample = row?.querySelector('.merdpos-dev-palette-swatch');
          const hex = select.selectedOptions?.[0]?.dataset?.hex || '';
          if (sample && hex) sample.style.setProperty('--palette-swatch', hex);
        };

        const preview = () => {
          form.querySelectorAll('[data-palette-swatch]').forEach(updateSwatch);
          Object.entries(variableForRole).forEach(([role, variable]) => {
            const roleSelect = form.querySelector(`[data-palette-role="${role}"]`);
            const hex = roleSelect ? selectedHexForEntry(roleSelect.value) : '';
            if (hex) root.style.setProperty(variable, hex);
          });
          root.dataset.palettePreview = 'true';
          if (status) status.textContent = 'Previewing unsaved palette changes on this page.';
        };

        form.addEventListener('change', (event) => {
          if (event.target.matches('[data-palette-swatch], [data-palette-role]')) preview();
        });
      });
    },
  };
})(Drupal, once);
