(function () {
  if (!document.querySelector('script[data-id-order]')) {
    const sortScript = document.createElement('script');
    sortScript.src = 'assets/id-order.js?v=20260825b';
    sortScript.dataset.idOrder = '1';
    document.body.appendChild(sortScript);
  }

  const main = document.querySelector('main.merd-page-shell');
  const oldNav = main?.querySelector('.merd-nav');
  if (!main || !oldNav || document.querySelector('.app-frame')) return;

  const isDev = !!oldNav.querySelector('.dev-tab');
  if (isDev && !oldNav.querySelector('[data-panel="clientPanel"]')) {
    const clientGroup = document.createElement('div');
    clientGroup.className = 'nav-group';
    clientGroup.innerHTML = `
      <span class="nav-group-label">Client</span>
      <button class="portal-tab" data-panel="clientPanel">
        <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/><path d="M16 9h2a2 2 0 0 1 2 2v10"/><path d="M8 7h4M8 11h4M8 15h4M8 19h4"/></svg>
        <span>Account</span>
      </button>`;

    const operationsGroup = Array.from(oldNav.querySelectorAll('.nav-group')).find(group =>
      String(group.querySelector('.nav-group-label')?.textContent || '').trim().toLowerCase() === 'operations'
    );
    if (operationsGroup) oldNav.insertBefore(clientGroup, operationsGroup);
    else oldNav.appendChild(clientGroup);

    const panel = document.createElement('section');
    panel.id = 'clientPanel';
    panel.className = 'portal-panel';
    panel.hidden = true;
    panel.innerHTML = `
      <section class="directory-card directory-layout">
        <div class="directory-toolbar">
          <div><h2>Client</h2><p>Parent account for stores, employees and POS devices.</p></div>
        </div>
        <div id="clientOverview"><div class="entity-empty">Loading client…</div></div>
      </section>`;
    main.appendChild(panel);

    const clientScript = document.createElement('script');
    clientScript.src = 'assets/client.js?v=20260825f';
    clientScript.dataset.clientModule = '1';
    document.body.appendChild(clientScript);
  }

  // Re-shape the legacy navigation before building the modern rail.
  // Operations owns store/workforce administration; Reports owns timesheets/disputes.
  const rawByLabel = label => Array.from(oldNav.querySelectorAll('.nav-group')).find(group =>
    String(group.querySelector('.nav-group-label')?.textContent || '').trim().toLowerCase() === label
  );
  const workforceGroup = rawByLabel('workforce');
  const operationsGroup = rawByLabel('operations');
  if (workforceGroup) {
    const employeeTab = workforceGroup.querySelector('[data-panel="employeesPanel"]');
    if (employeeTab && operationsGroup) {
      const text = employeeTab.querySelector('span:not(.nav-badge)');
      if (text) text.textContent = 'Workforce';
      operationsGroup.appendChild(employeeTab);
    }

    const reportTabs = Array.from(workforceGroup.querySelectorAll('[data-panel="timesheetPanel"],[data-panel="disputesPanel"]'));
    if (reportTabs.length) {
      let reportsGroup = rawByLabel('reports');
      if (!reportsGroup) {
        reportsGroup = document.createElement('div');
        reportsGroup.className = 'nav-group';
        reportsGroup.innerHTML = '<span class="nav-group-label">Reports</span>';
        const financeGroup = rawByLabel('finance');
        if (financeGroup) oldNav.insertBefore(reportsGroup, financeGroup);
        else oldNav.appendChild(reportsGroup);
      }
      reportTabs.forEach(tab => reportsGroup.appendChild(tab));
    }

    if (!workforceGroup.querySelector('.portal-tab')) workforceGroup.remove();
  }

  const rawGroups = Array.from(oldNav.querySelectorAll('.nav-group'));
  if (!rawGroups.length) return;

  const normalise = text => String(text || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '-');
  const groupKey = label => {
    const key = normalise(label);
    return key === 'overview' ? 'home' : key;
  };
  const titles = {
    home: 'Home',
    client: 'Client',
    operations: 'Operations',
    reports: 'Reports',
    finance: 'Finance',
    system: 'System',
  };
  const order = ['home', 'client', 'operations', 'reports', 'finance', 'system'];
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

  const activateTab = tab => {
    const panelId = tab?.dataset?.panel;
    const panel = panelId ? document.getElementById(panelId) : null;
    if (!panel) return false;

    rail.querySelectorAll('.portal-tab').forEach(item => item.classList.toggle('active', item === tab));
    main.querySelectorAll('.portal-panel').forEach(candidate => {
      candidate.hidden = candidate.id !== panelId;
    });
    return true;
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
        activateTab(tab);
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
