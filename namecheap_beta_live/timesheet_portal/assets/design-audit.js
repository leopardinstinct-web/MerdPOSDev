(function () {
  'use strict';

  const root = document.documentElement;
  const mobileQuery = window.matchMedia ? window.matchMedia('(max-width: 820px)') : {matches:false};
  let scheduled = 0;

  const panelTitles = {
    dashboardPanel: 'Dashboard',
    employeesPanel: 'Employees',
    storesPanel: 'Stores',
    timesheetPanel: 'Timesheets',
    disputesPanel: 'Disputes',
    financialPanel: 'Financial',
    devPanel: 'DEV',
    rolesPanel: 'Roles',
    clientsPanel: 'Clients',
    defaultsPanel: 'Defaults'
  };

  function replaceTag(element, tagName) {
    if (!element || element.tagName.toLowerCase() === tagName.toLowerCase()) return element;
    const replacement = document.createElement(tagName);
    Array.from(element.attributes).forEach(function (attribute) {
      replacement.setAttribute(attribute.name, attribute.value);
    });
    while (element.firstChild) replacement.appendChild(element.firstChild);
    element.replaceWith(replacement);
    return replacement;
  }

  function normalizeHeadingSemantics() {
    const main = document.querySelector('main.merd-page-shell');
    if (!main) return;

    /* The portal is one SPA-like document with many permission-dependent panels.
       One application H1 stays in the document; each panel owns H2 sections and
       nested cards/widgets use H3. */
    let appTitle = document.getElementById('merdApplicationTitle');
    if (!appTitle) {
      appTitle = document.createElement('h1');
      appTitle.id = 'merdApplicationTitle';
      appTitle.className = 'sr-only';
      appTitle.textContent = 'MERDPOS';
      main.insertBefore(appTitle, main.firstChild);
    }

    document.querySelectorAll('.hero-title').forEach(function (heading) {
      const h2 = replaceTag(heading, 'h2');
      h2.classList.add('ui-page-title');
      h2.dataset.panelTitle = '1';
    });

    document.querySelectorAll('.mgmt-card-head h2').forEach(function (heading) {
      replaceTag(heading, 'h3').classList.add('ui-card-title');
    });

    document.querySelectorAll('.dashboard-widget-title').forEach(function (title) {
      if (title.tagName.toLowerCase() === 'h3') return;
      replaceTag(title, 'h3').classList.add('ui-card-title');
    });

    document.querySelectorAll('.portal-panel').forEach(function (panel) {
      const expected = panelTitles[panel.id] || panel.getAttribute('aria-label') || 'Section';
      let pageHeading = panel.querySelector(':scope > .ui-page-title, :scope > [data-panel-title="1"], :scope > section .directory-toolbar h2, :scope > section .roles-head h2');
      if (pageHeading) {
        pageHeading = replaceTag(pageHeading, 'h2');
        pageHeading.classList.add('ui-page-title');
        pageHeading.dataset.panelTitle = '1';
        return;
      }

      const heading = document.createElement('h2');
      heading.className = 'sr-only ui-page-title';
      heading.dataset.panelTitle = '1';
      heading.textContent = expected;
      panel.insertBefore(heading, panel.firstChild);
    });
  }

  function visible(element) {
    if (!element || element.hidden) return false;
    const style = window.getComputedStyle(element);
    if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0) return false;
    const rect = element.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  }

  function accessibleName(element) {
    return String(
      element.getAttribute('aria-label') ||
      element.getAttribute('aria-labelledby') ||
      element.getAttribute('title') ||
      element.textContent ||
      element.value ||
      ''
    ).replace(/\s+/g, ' ').trim();
  }

  function parseColor(value) {
    const match = String(value || '').match(/rgba?\(([^)]+)\)/i);
    if (!match) return null;
    const parts = match[1].split(',').map(function (part) { return Number.parseFloat(part.trim()); });
    if (parts.length < 3 || parts.some(function (v, i) { return i < 3 && !Number.isFinite(v); })) return null;
    return {r:parts[0], g:parts[1], b:parts[2], a:parts.length > 3 && Number.isFinite(parts[3]) ? parts[3] : 1};
  }

  function effectiveBackground(element) {
    let current = element;
    while (current && current !== document.documentElement) {
      const color = parseColor(window.getComputedStyle(current).backgroundColor);
      if (color && color.a >= .98) return color;
      current = current.parentElement;
    }
    return parseColor(window.getComputedStyle(document.body).backgroundColor) || {r:255,g:255,b:255,a:1};
  }

  function channelLinear(value) {
    const normalized = value / 255;
    return normalized <= .04045 ? normalized / 12.92 : Math.pow((normalized + .055) / 1.055, 2.4);
  }

  function luminance(color) {
    return .2126 * channelLinear(color.r) + .7152 * channelLinear(color.g) + .0722 * channelLinear(color.b);
  }

  function contrastRatio(a, b) {
    const first = luminance(a), second = luminance(b);
    const lighter = Math.max(first, second), darker = Math.min(first, second);
    return (lighter + .05) / (darker + .05);
  }

  function visiblePanel() {
    return Array.from(document.querySelectorAll('.portal-panel')).find(function (panel) { return !panel.hidden; }) || null;
  }

  function auditHeadings(issues) {
    const h1s = Array.from(document.querySelectorAll('h1'));
    if (h1s.length !== 1) issues.push('heading:h1-count:' + h1s.length);

    const panel = visiblePanel();
    if (!panel) return;
    const headings = Array.from(panel.querySelectorAll('h2,h3,h4,h5,h6')).filter(visible);
    if (!headings.length) {
      issues.push('heading:visible-panel-missing');
      return;
    }
    if (headings[0].tagName !== 'H2') issues.push('heading:panel-does-not-start-h2:' + panel.id);
    let previous = 1;
    headings.forEach(function (heading) {
      const level = Number(heading.tagName.substring(1));
      if (level > previous + 1) issues.push('heading:skipped:' + previous + '-to-' + level + ':' + (heading.textContent || '').trim().slice(0, 40));
      previous = level;
    });
  }

  function auditInteractive(issues) {
    document.querySelectorAll('button,a[href],summary,[role="button"],input,select,textarea').forEach(function (element) {
      if (!visible(element)) return;
      if (['BUTTON','A','SUMMARY'].includes(element.tagName) || element.getAttribute('role') === 'button') {
        if (!accessibleName(element)) issues.push('a11y:missing-name:' + (element.id || element.className || element.tagName));
      }
      if (mobileQuery.matches) {
        const rect = element.getBoundingClientRect();
        const inlineLink = element.tagName === 'A' && rect.height < 30;
        if (!inlineLink && (rect.width < 44 || rect.height < 44)) {
          issues.push('touch:under-44:' + String(element.id || element.getAttribute('aria-label') || element.className || element.tagName).slice(0, 70));
        }
      }
    });
  }

  function auditActionClusters(issues) {
    document.querySelectorAll('.merd-action-cluster').forEach(function (cluster) {
      if (!visible(cluster)) return;
      const search = cluster.querySelector('.merd-collapsible-search');
      const add = cluster.querySelector('.merd-add-action');
      if (!search || !add) return;
      const sr = search.getBoundingClientRect(), ar = add.getBoundingClientRect();
      if (!search.classList.contains('is-open') && Math.abs(sr.width - ar.width) > 1) issues.push('placement:search-add-width');
      if (Math.abs(sr.height - ar.height) > 1) issues.push('placement:search-add-height');
      if (Math.abs((sr.top + sr.height / 2) - (ar.top + ar.height / 2)) > 1) issues.push('placement:search-add-vertical-align');
      if (sr.left > ar.left) issues.push('placement:add-before-search');
    });
  }

  function auditOverflow(issues) {
    if (document.documentElement.scrollWidth > document.documentElement.clientWidth + 2) issues.push('layout:page-horizontal-overflow');
    document.querySelectorAll('dialog[open]').forEach(function (dialog) {
      const rect = dialog.getBoundingClientRect();
      if (rect.left < -2 || rect.right > window.innerWidth + 2 || rect.top < -2 || rect.bottom > window.innerHeight + 2) {
        issues.push('layout:dialog-outside-viewport:' + (dialog.id || 'dialog'));
      }
    });
  }

  function auditContrast(issues) {
    const scope = visiblePanel() || document.body;
    const selectors = 'h1,h2,h3,h4,p,small,label,button,.entity-sub,.muted,.status-pill,.entity-status,.entity-role';
    Array.from(scope.querySelectorAll(selectors)).filter(visible).slice(0, 180).forEach(function (element) {
      const style = window.getComputedStyle(element);
      const fg = parseColor(style.color);
      const bg = effectiveBackground(element);
      if (!fg || !bg || fg.a < .95) return;
      const size = Number.parseFloat(style.fontSize) || 16;
      const weight = Number.parseInt(style.fontWeight, 10) || 400;
      const large = size >= 24 || (size >= 18.66 && weight >= 700);
      const minimum = large ? 3 : 4.5;
      const ratio = contrastRatio(fg, bg);
      if (ratio + .02 < minimum) {
        issues.push('contrast:' + ratio.toFixed(2) + ':' + String(element.className || element.tagName).slice(0, 55));
      }
    });
  }

  function auditDuplicateIds(issues) {
    const seen = new Set();
    document.querySelectorAll('[id]').forEach(function (element) {
      if (seen.has(element.id)) issues.push('dom:duplicate-id:' + element.id);
      seen.add(element.id);
    });
  }

  function runAudit() {
    normalizeHeadingSemantics();
    const issues = [];
    auditHeadings(issues);
    auditInteractive(issues);
    auditActionClusters(issues);
    auditOverflow(issues);
    auditContrast(issues);
    auditDuplicateIds(issues);
    const unique = Array.from(new Set(issues));
    root.dataset.merdDesignAudit = unique.length ? 'fail' : 'pass';
    root.dataset.merdDesignAuditCount = String(unique.length);
    if (unique.length && window.console && console.warn) console.warn('MERDPOS design audit:', unique);
    return unique;
  }

  function scheduleAudit() {
    window.clearTimeout(scheduled);
    scheduled = window.setTimeout(runAudit, 180);
  }

  const observer = new MutationObserver(function (records) {
    if (records.some(function (record) { return record.addedNodes && record.addedNodes.length; })) scheduleAudit();
    if (records.some(function (record) { return record.type === 'attributes'; })) scheduleAudit();
  });

  observer.observe(document.documentElement, {
    subtree: true,
    childList: true,
    attributes: true,
    attributeFilter: ['hidden','open','class','aria-expanded']
  });

  window.addEventListener('resize', scheduleAudit);
  window.addEventListener('orientationchange', scheduleAudit);
  document.addEventListener('DOMContentLoaded', scheduleAudit, {once:true});
  window.setTimeout(scheduleAudit, 500);

  window.MERDPOSDesignAudit = {
    run: runAudit,
    normalize: normalizeHeadingSemantics
  };
})();
