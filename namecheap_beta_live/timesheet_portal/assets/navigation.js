(function () {
  if (!document.querySelector('script[data-id-order]')) {
    const sortScript = document.createElement('script');
    sortScript.src = 'assets/id-order.js?v=20260825a';
    sortScript.dataset.idOrder = '1';
    document.body.appendChild(sortScript);
  }

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
  const desktopQuery = window.matchMedia('(min-width: 821px)');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  rawGroups.sort((a, b) => {
    const aKey = groupKey(a.querySelector('.nav-group-label')?.textContent || '');
    const bKey = groupKey(b.querySelector('.nav-group-label')?.textContent || '');
    const ai = order.indexOf(aKey);
    const bi = order.indexOf(bKey);
    return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi);
  });

  const frame = document.createElement('div');
  frame.className = 'app-frame nav-collapsed';
  const rail = document.createElement('aside');
  rail.className = 'app-rail';
  rail.setAttribute('aria-label', 'MERDPOS navigation');

  main.parentNode.insertBefore(frame, main);
  frame.appendChild(rail);
  frame.appendChild(main);
  main.classList.add('app-workspace');

  const sections = [];
  let collapseTimer = null;
  let suppressNextTabCollapse = false;

  const clearCollapseTimer = () => {
    if (collapseTimer !== null) {
      window.clearTimeout(collapseTimer);
      collapseTimer = null;
    }
  };

  const syncExpandedAria = expanded => {
    sections.forEach(section => {
      const active = section.button.classList.contains('active');
      section.button.setAttribute('aria-expanded', expanded && active ? 'true' : 'false');
    });
  };

  const expandRail = () => {
    if (!desktopQuery.matches) return;
    clearCollapseTimer();
    frame.classList.add('nav-expanded');
    frame.classList.remove('nav-collapsed');
    syncExpandedAria(true);
  };

  const collapseRail = (delay = 0) => {
    if (!desktopQuery.matches) return;
    clearCollapseTimer();
    collapseTimer = window.setTimeout(() => {
      frame.classList.remove('nav-expanded');
      frame.classList.add('nav-collapsed');
      syncExpandedAria(false);
      collapseTimer = null;
    }, reducedMotion.matches ? 0 : delay);
  };

  const animatePanel = tab => {
    if (reducedMotion.matches) return;
    const panelId = tab?.dataset?.panel;
    if (!panelId) return;
    window.setTimeout(() => {
      const panel = document.getElementById(panelId);
      if (!panel || panel.hidden) return;
      panel.classList.remove('panel-enter');
      void panel.offsetWidth;
      panel.classList.add('panel-enter');
      window.setTimeout(() => panel.classList.remove('panel-enter'), 280);
    }, 0);
  };

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
    const drawerExpanded = !desktopQuery.matches || frame.classList.contains('nav-expanded');
    sections.forEach(section => {
      const active = section.key === name;
      section.button.classList.toggle('active', active);
      section.button.setAttribute('aria-expanded', active && drawerExpanded ? 'true' : 'false');
      section.subgroup.classList.toggle('active', active);
      section.subgroup.hidden = !active;
      if (active && !reducedMotion.matches) {
        section.subgroup.classList.remove('submenu-enter');
        void section.subgroup.offsetWidth;
        section.subgroup.classList.add('submenu-enter');
      }
    });

    if (openFirst) {
      const section = sections.find(item => item.key === name);
      if (!section) return;
      const current = section.subgroup.querySelector('.portal-tab.active');
      const first = current || section.subgroup.querySelector('.portal-tab');
      if (first && !current) {
        suppressNextTabCollapse = true;
        first.click();
      }
    }
  }

  sections.forEach(section => {
    section.button.addEventListener('click', () => {
      const sameSectionOpen = desktopQuery.matches
        && frame.classList.contains('nav-expanded')
        && section.button.classList.contains('active');

      if (sameSectionOpen) {
        collapseRail(0);
        return;
      }

      expandRail();
      setGroup(section.key, true);
    });

    section.subgroup.querySelectorAll('.portal-tab').forEach(tab => {
      tab.addEventListener('click', () => {
        setGroup(section.key, false);
        animatePanel(tab);
        if (suppressNextTabCollapse) {
          suppressNextTabCollapse = false;
          return;
        }
        collapseRail(180);
      });
    });
  });

  // Desktop expansion is intentionally click-only. Hover and focus do not open the drawer.
  document.addEventListener('pointerdown', event => {
    if (desktopQuery.matches && !rail.contains(event.target)) collapseRail(0);
  });

  const syncResponsiveMode = () => {
    clearCollapseTimer();
    if (desktopQuery.matches) {
      frame.classList.remove('nav-expanded');
      frame.classList.add('nav-collapsed');
      syncExpandedAria(false);
    } else {
      frame.classList.remove('nav-collapsed', 'nav-expanded');
      syncExpandedAria(true);
    }
  };
  desktopQuery.addEventListener?.('change', syncResponsiveMode);

  const initiallyActive = rail.querySelector('.portal-tab.active');
  const initialGroup = initiallyActive?.closest('[data-sidebar-group]')?.dataset.sidebarGroup || sections[0]?.key;
  if (initialGroup) setGroup(initialGroup, false);
  syncResponsiveMode();
})();
