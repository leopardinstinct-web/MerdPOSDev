(function () {
  if (!document.querySelector('script[data-id-order]')) {
    const sortScript = document.createElement('script');
    sortScript.src = 'assets/id-order.js?v=20260825b';
    sortScript.dataset.idOrder = '1';
    document.body.appendChild(sortScript);
  }
  if (!document.querySelector('link[data-dashboard-builder-css]')) {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'assets/dashboard-builder.css?v=20260825a';
    link.dataset.dashboardBuilderCss = '1';
    document.head.appendChild(link);
  }
  if (!document.querySelector('script[data-dashboard-builder]')) {
    const script = document.createElement('script');
    script.src = 'assets/dashboard-builder.js?v=20260825a';
    script.dataset.dashboardBuilder = '1';
    document.body.appendChild(script);
  }

  const main = document.querySelector('main.merd-page-shell');
  const oldNav = main?.querySelector('.merd-nav');
  if (!main || !oldNav || document.querySelector('.app-frame')) return;

  const rawByLabel = label => Array.from(oldNav.querySelectorAll('.nav-group')).find(group =>
    String(group.querySelector('.nav-group-label')?.textContent || '').trim().toLowerCase() === label
  );

  const isDev = !!oldNav.querySelector('.dev-tab');
  if (isDev && !oldNav.querySelector('[data-panel="clientsPanel"]')) {
    const systemGroup = rawByLabel('system');
    if (systemGroup) {
      const clientsTab = document.createElement('button');
      clientsTab.type = 'button';
      clientsTab.className = 'portal-tab';
      clientsTab.dataset.panel = 'clientsPanel';
      clientsTab.innerHTML = `
        <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/><path d="M16 9h2a2 2 0 0 1 2 2v10"/><path d="M8 7h4M8 11h4M8 15h4M8 19h4"/></svg>
        <span>Clients</span>`;
      systemGroup.appendChild(clientsTab);
    }

    const panel = document.createElement('section');
    panel.id = 'clientsPanel';
    panel.className = 'portal-panel';
    panel.hidden = true;
    panel.innerHTML = `
      <section class="directory-card directory-layout">
        <div class="directory-toolbar">
          <div><h2>Clients</h2><p>Manage client accounts and tenant identity.</p></div>
        </div>
        <div id="clientsOverview"><div class="entity-empty">Loading clients…</div></div>
      </section>`;
    main.appendChild(panel);

    const clientScript = document.createElement('script');
    clientScript.src = 'assets/client.js?v=20260827visual1';
    clientScript.dataset.clientModule = '1';
    document.body.appendChild(clientScript);
  }

  // Operations owns store/workforce administration; Reports owns timesheets/disputes.
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
    operations: 'Operations',
    reports: 'Reports',
    finance: 'Finance',
    system: 'DEV',
  };
  const order = ['home', 'operations', 'reports', 'finance', 'system'];
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

  const clearCollapseTimer = () => {
    if (collapseTimer !== null) {
      window.clearTimeout(collapseTimer);
      collapseTimer = null;
    }
  };

  const syncExpandedAria = expanded => {
    sections.forEach(section => {
      if (section.direct) {
        section.button.removeAttribute('aria-expanded');
        return;
      }
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

  const collapseRail = () => {
    if (!desktopQuery.matches) return;
    clearCollapseTimer();
    frame.classList.remove('nav-expanded');
    frame.classList.add('nav-collapsed');
    syncExpandedAria(false);
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
    const groupLabel = titles[key] || originalLabel;
    const tabs = Array.from(rawGroup.querySelectorAll('.portal-tab'));
    if (!tabs.length) return;

    const direct = tabs.length === 1;
    const directText = direct
      ? String(tabs[0].querySelector('span:not(.nav-badge)')?.textContent || groupLabel).trim()
      : groupLabel;
    const visibleLabel = directText;

    const section = document.createElement('section');
    section.className = `rail-section${direct ? ' rail-section-direct' : ''}`;
    section.dataset.navSection = key;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = `rail-group-btn${direct ? ' rail-direct-btn' : ''}`;
    button.dataset.navGroup = key;
    button.title = visibleLabel;
    button.setAttribute('aria-label', visibleLabel);
    if (!direct) button.setAttribute('aria-expanded', 'false');
    const sourceIcon = tabs[0].querySelector('.ui-icon');
    button.innerHTML = `${sourceIcon ? sourceIcon.outerHTML : ''}<span class="rail-label">${visibleLabel}</span>${direct ? '' : '<span class="rail-chevron" aria-hidden="true">›</span>'}`;

    const subgroup = document.createElement('div');
    subgroup.className = `sidebar-group${direct ? ' sidebar-direct-proxy' : ''}`;
    subgroup.dataset.sidebarGroup = key;
    tabs.forEach(tab => subgroup.appendChild(tab));
    if (direct) {
      subgroup.hidden = true;
      tabs[0].hidden = true;
    }

    section.appendChild(button);
    section.appendChild(subgroup);
    rail.appendChild(section);
    sections.push({ key, button, subgroup, tabs, direct, directTab: direct ? tabs[0] : null });
  });

  const themeSection=document.createElement('section');
  themeSection.className='rail-section rail-utility-section';
  const themeButton=document.createElement('button');
  themeButton.type='button';
  themeButton.className='rail-group-btn rail-theme-toggle';
  const moonIcon='<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"/></svg>';
  const sunIcon='<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/></svg>';
  const syncThemeButton=()=>{const current=window.MERDPOSTheme?.current?.()||document.documentElement.dataset.theme||'light';const target=current==='dark'?'light':'dark';themeButton.innerHTML=`${target==='dark'?moonIcon:sunIcon}<span class="rail-label">${target==='dark'?'Dark mode':'Light mode'}</span>`;themeButton.title=`Switch to ${target} mode`;themeButton.setAttribute('aria-label',themeButton.title);themeButton.setAttribute('aria-pressed',current==='dark'?'true':'false');};
  themeButton.addEventListener('click',()=>{window.MERDPOSTheme?.toggle?.();syncThemeButton();});
  window.addEventListener('merdpos-themechange',syncThemeButton);
  syncThemeButton();
  themeSection.appendChild(themeButton);
  rail.appendChild(themeSection);

  oldNav.remove();

  const syncMobileSubnavState = () => {
    const hasContextSubnav = sections.some(section =>
      !section.direct && section.subgroup.classList.contains('active') && !section.subgroup.hidden
    );
    document.body.classList.toggle('merd-mobile-subnav-open', hasContextSubnav);
  };

  function setGroup(name) {
    const drawerExpanded = !desktopQuery.matches || frame.classList.contains('nav-expanded');
    sections.forEach(section => {
      const active = section.key === name;
      section.button.classList.toggle('active', active);
      if (section.direct) {
        section.subgroup.hidden = true;
        return;
      }
      section.button.setAttribute('aria-expanded', active && drawerExpanded ? 'true' : 'false');
      section.subgroup.classList.toggle('active', active);
      section.subgroup.hidden = !active;
      if (active && !reducedMotion.matches) {
        section.subgroup.classList.remove('submenu-enter');
        void section.subgroup.offsetWidth;
        section.subgroup.classList.add('submenu-enter');
      }
    });
    syncMobileSubnavState();
  }

  sections.forEach(section => {
    section.button.addEventListener('click', () => {
      expandRail();
      if (section.direct) {
        section.directTab?.click();
        return;
      }
      // Parent clicks navigate to the section's first submenu item.
      section.tabs[0]?.click();
    });

    section.tabs.forEach(tab => {
      tab.addEventListener('click', () => {
        activateTab(tab);
        setGroup(section.key);
        animatePanel(tab);
        // Navigation remains open after selecting an item. Only an outside click closes it.
        expandRail();
      });
    });
  });

  document.addEventListener('pointerdown', event => {
    if (desktopQuery.matches && !rail.contains(event.target)) collapseRail();
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
    syncMobileSubnavState();
  };
  desktopQuery.addEventListener?.('change', syncResponsiveMode);

  const initiallyActive = rail.querySelector('.portal-tab.active');
  const initialGroup = initiallyActive?.closest('[data-sidebar-group]')?.dataset.sidebarGroup || sections[0]?.key;
  if (initialGroup) setGroup(initialGroup);
  syncResponsiveMode();
  window.MERDPOSNavigation = { expandRail, collapseRail };

  // Any feature can request a return panel before a context-changing reload.
  // This also covers tabs mounted asynchronously after the navigation shell.
  const requestedPanel = sessionStorage.getItem('merdposReturnPanel');
  if (requestedPanel) {
    let attempts = 0;
    const restoreTimer = window.setInterval(() => {
      attempts += 1;
      const tab = Array.from(document.querySelectorAll('.portal-tab')).find(item => item.dataset.panel === requestedPanel);
      if (tab) {
        sessionStorage.removeItem('merdposReturnPanel');
        tab.click();
        window.clearInterval(restoreTimer);
      } else if (attempts > 40) {
        sessionStorage.removeItem('merdposReturnPanel');
        window.clearInterval(restoreTimer);
      }
    }, 75);
  }
})();
