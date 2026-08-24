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

  // Normalize API arrays before existing renderers consume them. This deliberately
  // avoids a global MutationObserver so ordering can never trap the page in a DOM loop.
  const originalFetch = window.fetch.bind(window);
  window.fetch = async function (...args) {
    const response = await originalFetch(...args);
    const url = typeof args[0] === 'string' ? args[0] : (args[0]?.url || response.url || '');
    if (!/(admin_directory|store_timings|store_identity|beta_state)\.php(?:\?|$)/.test(url)) return response;

    try {
      const text = await response.clone().text();
      if (!text) return response;
      const payload = normalizePayload(url, JSON.parse(text));
      const headers = new Headers(response.headers);
      headers.set('Content-Type', 'application/json; charset=utf-8');
      const normalized = new Response(JSON.stringify(payload), {
        status: response.status,
        statusText: response.statusText,
        headers,
      });
      queueDomOrder();
      return normalized;
    } catch (_) {
      return response;
    }
  };

  function reorderRows(rootId, selector, dataKey) {
    const root = document.getElementById(rootId);
    if (!root) return;
    const rows = Array.from(root.querySelectorAll(':scope > .entity-row'));
    if (rows.length < 2) return;
    const sorted = rows.slice().sort((a, b) => {
      const aa = a.querySelector(selector);
      const bb = b.querySelector(selector);
      const ak = aa ? numericId(aa.dataset[dataKey]) : null;
      const bk = bb ? numericId(bb.dataset[dataKey]) : null;
      return (ak ?? Number.MAX_SAFE_INTEGER) - (bk ?? Number.MAX_SAFE_INTEGER);
    });
    if (sorted.every((row, i) => row === rows[i])) return;
    sorted.forEach(row => root.appendChild(row));
  }

  function reorderStoreOptions(select) {
    if (!select) return;
    const identity = `${select.id || ''} ${select.name || ''}`.toLowerCase();
    if (!identity.includes('store')) return;
    const options = Array.from(select.options || []);
    if (options.length < 2) return;

    const synthetic = options.filter(option => numericId(option.value) === null);
    const real = options.filter(option => numericId(option.value) !== null)
      .sort((a, b) => numericId(a.value) - numericId(b.value));
    const sorted = [...synthetic, ...real];
    if (sorted.every((option, i) => option === options[i])) return;
    const selected = select.value;
    sorted.forEach(option => select.appendChild(option));
    select.value = selected;
  }

  function reorderStoreChoices() {
    const root = document.getElementById('employeeStoreChoices');
    if (!root) return;
    const choices = Array.from(root.querySelectorAll(':scope > .store-choice'));
    if (choices.length < 2) return;
    const sorted = choices.slice().sort((a, b) => {
      const av = numericId(a.querySelector('input[name="store_ids"]')?.value);
      const bv = numericId(b.querySelector('input[name="store_ids"]')?.value);
      return (av ?? Number.MAX_SAFE_INTEGER) - (bv ?? Number.MAX_SAFE_INTEGER);
    });
    if (sorted.every((choice, i) => choice === choices[i])) return;
    sorted.forEach(choice => root.appendChild(choice));
  }

  function applyDomOrder() {
    reorderRows('storeDirectory', '[data-edit-store]', 'editStore');
    reorderRows('employeeDirectory', '[data-edit-employee]', 'editEmployee');
    reorderStoreChoices();
    document.querySelectorAll('select').forEach(reorderStoreOptions);
  }

  let timers = [];
  function queueDomOrder() {
    timers.forEach(clearTimeout);
    timers = [0, 40, 120, 300, 800].map(delay => setTimeout(applyDomOrder, delay));
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', queueDomOrder, { once: true });
  } else {
    queueDomOrder();
  }

  document.addEventListener('click', event => {
    if (event.target.closest('[data-panel], [data-edit-store], [data-edit-employee], #addStoreBtn, #addEmployeeBtn')) {
      queueDomOrder();
    }
  });

  window.MERDPOS_ID_ORDER = {
    apply: applyDomOrder,
    normalizePayload,
    stores: 'stores.id ASC',
    employees: 'employees.id ASC',
  };
})();
