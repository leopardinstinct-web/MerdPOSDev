(() => {
  'use strict';
  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-merdpos-print]');
    if (!button) return;
    event.preventDefault();
    window.print();
  });
})();
