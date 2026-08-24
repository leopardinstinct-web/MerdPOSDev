(function () {
  'use strict';

  const numericId = value => {
    const text = String(value ?? '').trim();
    if (!/^\d+$/.test(text)) return null;
    const number = Number(text);
    return Number.isSafeInteger(number) ? number : null;
  };

  const sortObjects = (rows, key = 'id') => {
    if (!Array.isArray(rows)) return rows;
    rows.sort((a, b) => {
      const ak = numericId(a?.[key]);
      const bk = numericId(b?.[key]);
      if (ak === null && bk === null) return 0;
      if (ak === null) return 1;
      if (bk === null) return -1;
      return ak - bk;
    });
    return rows;
  };

  function normalizePayload(url, payload) {
    if (!payload || typeof payload !== 'object') return payload;
    const path = String(url || '');
    if (path.includes('admin_directory.php')) {
      sortObjects(payload.stores, 'id');
      sortObjects(payload.employees, 'id');
    }
    if (path.includes('store_timings.php') || path.includes('store_identity.php')) {
      sortObjects(payload.stores, 'id');
    }
    if (path.includes('beta_state.php')) {
      sortObjects(payload.stores, 'id');
      sortObjects(payload.management?.financial_by_store, 'store_id');
      sortObjects(payload.management?.sales_by_store, 'store_id');
    }
    return payload;
  }

  const originalFetch = window.fetch.bind(window);
  window.fetch = async function (...args) {
    const response = await originalFetch(...args);
    const url = typeof args[0] === 'string' ? args[0] : (args[0]?.url || response.url || '');
    if (!/(admin_directory|store_timings|store_identity|beta_state)\.php(?:\?|$)/.test(url)) return response;
    try {
      const clone = response.clone();
      const text = await clone.text();
      if (!text) return response;
      const payload = normalizePayload(url, JSON.parse(text));
      const headers = new Headers(response.headers);
      headers.set('Content-Type', 'application/json; charset=utf-8');
      return new Response(JSON.stringify(payload), {
        status: response.status,
        statusText: response.statusText,
        headers,
      });
    } catch (_) {
      return response;
    }
  };

  function reorder(parent, nodes, keyFn) {
    if (!parent || nodes.length < 2) return;
    const current = nodes.slice();
    const sorted = nodes.slice().sort((a, b) => {
      const ak = keyFn(a);
      const bk = keyFn(b);
      if (ak === null && bk === null) return 0;
      if (ak === null) return -1;
      if (bk === null) return 1;
      return ak - bk;
    });
    const changed = sorted.some((node, index) => node !== current[index]);
    if (!changed) return;
    sorted.forEach(node => parent.appendChild(node));
  }

  function sortEntityList(rootId, buttonSelector, dataKey) {
    const root = document.getElementById(rootId);
    if (!root) return;
    const rows = Array.from(root.children).filter(node => node.classList?.contains('entity-row'));
    reorder(root, rows, row => {
      const button = row.querySelector(buttonSelector);
      return button ? numericId(button.dataset[dataKey]) : null;
    });
  }

  function sortKnownSelect(select) {
    if (!select) return;
    const identity = `${select.id || ''} ${select.name || ''}`.toLowerCase();
    if (!identity.includes('store') && !identity.includes('employee')) return;
    const options = Array.from(select.options || []);
    reorder(select, options, option => numericId(option.value));
  }

  function sortStoreChoices() {
    const root = document.getElementById('employeeStoreChoices');
    if (!root) return;
    const choices = Array.from(root.children).filter(node => node.classList?.contains('store-choice'));
    reorder(root, choices, choice => numericId(choice.querySelector('input[name="store_ids"]')?.value));
  }

  function sortExplicitDataGroups() {
    document.querySelectorAll('[data-store-id], [data-employee-id]').forEach(node => {
      const parent = node.parentElement;
      if (!parent) return;
      const siblings = Array.from(parent.children).filter(child => child.hasAttribute('data-store-id') || child.hasAttribute('data-employee-id'));
      if (siblings.length < 2) return;
      const keyName = siblings.some(child => child.hasAttribute('data-store-id')) ? 'storeId' : 'employeeId';
      reorder(parent, siblings, child => numericId(child.dataset[keyName]));
    });
  }

  function applyIdOrder() {
    sortEntityList('storeDirectory', '[data-edit-store]', 'editStore');
    sortEntityList('employeeDirectory', '[data-edit-employee]', 'editEmployee');
    sortStoreChoices();
    document.querySelectorAll('select').forEach(sortKnownSelect);
    sortExplicitDataGroups();
  }

  let queued = false;
  function queueApply() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => {
      queued = false;
      applyIdOrder();
    });
  }

  document.addEventListener('DOMContentLoaded', queueApply, { once: true });
  queueApply();

  const observer = new MutationObserver(queueApply);
  observer.observe(document.documentElement, { childList: true, subtree: true });

  window.MERDPOS_ID_ORDER = {
    apply: applyIdOrder,
    normalizePayload,
    stores: 'stores.id ASC',
    employees: 'employees.id ASC',
  };
})();
