(function () {
  'use strict';

  const menu = document.getElementById('accountMenu');
  if (!menu) return;

  const close = () => { if (menu.open) menu.open = false; };

  document.addEventListener('pointerdown', event => {
    if (menu.open && !menu.contains(event.target)) close();
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && menu.open) {
      close();
      menu.querySelector('summary')?.focus();
    }
  });

  menu.querySelectorAll('#passwordBtn,#logoutBtn').forEach(button => {
    button.addEventListener('click', () => window.setTimeout(close, 0));
  });
})();
