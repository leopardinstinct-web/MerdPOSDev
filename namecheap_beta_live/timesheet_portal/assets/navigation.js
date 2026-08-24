(function () {
  const main = document.querySelector('main.merd-page-shell');
  const oldNav = main?.querySelector('.merd-nav');
  if (!main || !oldNav || document.querySelector('.app-frame')) return;

  const rawGroups = Array.from(oldNav.querySelectorAll('.nav-group'));
  if (!rawGroups.length) return;

  const normalise = text => String(text || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '-');
  const groupKey = label => {
    const key = normalise(label);
    return key === 'overview' ? 'home' : key;
  };
  const titles = {
    home: 'Home',
    operations: 'Operations',
    workforce: 'Workforce',
    finance: 'Finance',
    system: 'System',
  };
  const order = ['home', 'operations', 'workforce', 'finance', 'system'];

  rawGroups.sort((a, b) => {
    const aKey = groupKey(a.querySelector('.nav-group-label')?.textContent || '');
    const bKey = groupKey(b.querySelector('.nav-group-label')?.textContent || '');
    const ai = order.indexOf(aKey);
    const bi = order.indexOf(bKey);
    return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi);
  });

  const frame = document.createElement('div');
  frame.className = 'app-frame';
  const rail = document.createElement('aside');
  rail.className = 'app-rail';
  rail.setAttribute('aria-label', 'Primary navigation');
  const sidebar = document.createElement('aside');
  sidebar.className = 'app-sidebar';
  sidebar.setAttribute('aria-label', 'Section navigation');
  sidebar.innerHTML = '<div class="sidebar-heading"><strong id="sidebarGroupTitle">Home</strong></div>';

  main.parentNode.insertBefore(frame, main);
  frame.appendChild(rail);
  frame.appendChild(sidebar);
  frame.appendChild(main);
  main.classList.add('app-workspace');

  const sidebarGroups = [];
  const railButtons = [];

  rawGroups.forEach((rawGroup, index) => {
    const labelNode = rawGroup.querySelector('.nav-group-label');
    const originalLabel = labelNode?.textContent.trim() || `Section ${index + 1}`;
    const key = groupKey(originalLabel) || `section-${index + 1}`;
    const label = titles[key] || originalLabel;
    const tabs = Array.from(rawGroup.querySelectorAll('.portal-tab'));
    if (!tabs.length) return;

    const group = document.createElement('div');
    group.className = 'sidebar-group';
    group.dataset.sidebarGroup = key;
    group.hidden = true;
    tabs.forEach(tab => group.appendChild(tab));
    sidebar.appendChild(group);
    sidebarGroups.push(group);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'rail-group-btn';
    button.dataset.navGroup = key;
    button.title = label;
    button.setAttribute('aria-label', label);
    const sourceIcon = tabs[0].querySelector('.ui-icon');
    button.innerHTML = `${sourceIcon ? sourceIcon.outerHTML : ''}<span class="rail-label">${label}</span>`;
    rail.appendChild(button);
    railButtons.push(button);
  });

  oldNav.remove();
  const sectionTitle = document.getElementById('sidebarGroupTitle');

  function setGroup(name, openFirst) {
    railButtons.forEach(button => button.classList.toggle('active', button.dataset.navGroup === name));
    sidebarGroups.forEach(group => {
      const active = group.dataset.sidebarGroup === name;
      group.hidden = !active;
      group.classList.toggle('active', active);
    });
    if (sectionTitle) sectionTitle.textContent = titles[name] || name.replace(/-/g, ' ').replace(/\b\w/g, m => m.toUpperCase());

    if (openFirst) {
      const group = sidebarGroups.find(item => item.dataset.sidebarGroup === name);
      const current = group?.querySelector('.portal-tab.active');
      const first = current || group?.querySelector('.portal-tab');
      if (first && !first.classList.contains('active')) first.click();
    }
  }

  railButtons.forEach(button => button.addEventListener('click', () => setGroup(button.dataset.navGroup, true)));

  sidebar.querySelectorAll('.portal-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      const group = tab.closest('[data-sidebar-group]');
      if (group) setGroup(group.dataset.sidebarGroup, false);
    });
  });

  const initiallyActive = sidebar.querySelector('.portal-tab.active');
  const initialGroup = initiallyActive?.closest('[data-sidebar-group]')?.dataset.sidebarGroup || sidebarGroups[0]?.dataset.sidebarGroup;
  if (initialGroup) setGroup(initialGroup, false);
})();