(function () {
  const railButtons = Array.from(document.querySelectorAll('[data-nav-group]'));
  const groups = Array.from(document.querySelectorAll('[data-sidebar-group]'));
  const sectionTitle = document.getElementById('sidebarGroupTitle');
  if (!railButtons.length || !groups.length) return;

  const titles = {
    overview: 'Overview',
    workforce: 'Workforce',
    operations: 'Operations',
    finance: 'Finance',
    system: 'System',
  };

  function setGroup(name, openFirst) {
    railButtons.forEach(button => button.classList.toggle('active', button.dataset.navGroup === name));
    groups.forEach(group => {
      const active = group.dataset.sidebarGroup === name;
      group.hidden = !active;
      group.classList.toggle('active', active);
    });
    if (sectionTitle) sectionTitle.textContent = titles[name] || 'MERDPOS';

    if (openFirst) {
      const group = groups.find(item => item.dataset.sidebarGroup === name);
      const current = group?.querySelector('.portal-tab.active');
      const first = current || group?.querySelector('.portal-tab');
      if (first && !first.classList.contains('active')) first.click();
    }
  }

  railButtons.forEach(button => {
    button.addEventListener('click', () => setGroup(button.dataset.navGroup, true));
  });

  document.querySelectorAll('.app-sidebar .portal-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      const group = tab.closest('[data-sidebar-group]');
      if (group) setGroup(group.dataset.sidebarGroup, false);
    });
  });

  const initiallyActive = document.querySelector('.app-sidebar .portal-tab.active');
  const initialGroup = initiallyActive?.closest('[data-sidebar-group]')?.dataset.sidebarGroup || 'overview';
  setGroup(initialGroup, false);
})();
