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
  rail.setAttribute('aria-label', 'MERDPOS navigation');

  main.parentNode.insertBefore(frame, main);
  frame.appendChild(rail);
  frame.appendChild(main);
  main.classList.add('app-workspace');

  const sections = [];

  rawGroups.forEach((rawGroup, index) => {
    const labelNode = rawGroup.querySelector('.nav-group-label');
    const originalLabel = labelNode?.textContent.trim() || `Section ${index + 1}`;
    const key = groupKey(originalLabel) || `section-${index + 1}`;
    const label = titles[key] || originalLabel;
    const tabs = Array.from(rawGroup.querySelectorAll('.portal-tab'));
    if (!tabs.length) return;

    const section = document.createElement('section');
    section.className = 'rail-section';
    section.dataset.navSection = key;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'rail-group-btn';
    button.dataset.navGroup = key;
    button.title = label;
    button.setAttribute('aria-label', label);
    button.setAttribute('aria-expanded', 'false');
    const sourceIcon = tabs[0].querySelector('.ui-icon');
    button.innerHTML = `${sourceIcon ? sourceIcon.outerHTML : ''}<span class="rail-label">${label}</span><span class="rail-chevron" aria-hidden="true">›</span>`;

    const subgroup = document.createElement('div');
    subgroup.className = 'sidebar-group';
    subgroup.dataset.sidebarGroup = key;
    tabs.forEach(tab => subgroup.appendChild(tab));

    section.appendChild(button);
    section.appendChild(subgroup);
    rail.appendChild(section);
    sections.push({ key, button, subgroup, tabs });
  });

  oldNav.remove();

  function setGroup(name, openFirst = false) {
    sections.forEach(section => {
      const active = section.key === name;
      section.button.classList.toggle('active', active);
      section.button.setAttribute('aria-expanded', active ? 'true' : 'false');
      section.subgroup.classList.toggle('active', active);
      section.subgroup.hidden = !active;
    });

    if (openFirst) {
      const section = sections.find(item => item.key === name);
      if (!section) return;
      const current = section.subgroup.querySelector('.portal-tab.active');
      const first = current || section.subgroup.querySelector('.portal-tab');
      if (first) first.click();
    }
  }

  sections.forEach(section => {
    section.button.addEventListener('click', () => setGroup(section.key, true));
    section.subgroup.querySelectorAll('.portal-tab').forEach(tab => {
      tab.addEventListener('click', () => setGroup(section.key, false));
    });
  });

  const initiallyActive = rail.querySelector('.portal-tab.active');
  const initialGroup = initiallyActive?.closest('[data-sidebar-group]')?.dataset.sidebarGroup || sections[0]?.key;
  if (initialGroup) setGroup(initialGroup, false);
})();
