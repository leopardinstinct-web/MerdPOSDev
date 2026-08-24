(function () {
  'use strict';

  const numericId = value => {
    const text = String(value ?? '').trim();
    if (!/^\d+$/.test(text)) return null;
    const number = Number(text);
    return Number.isSafeInteger(number) ? number : null;
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
      if (!parent || parent.dataset.idOrderHandled === '1') return;
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
    stores: 'stores.id ASC',
    employees: 'employees.id ASC',
  };
})();
