(() => {
  'use strict';
  const q = (selector, root = document) => root.querySelector(selector);
  const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
  const truthy = (value) => value === '1';
  const root = q('[data-query-root]');
  const dialog = q('[data-timesheet-dialog]');
  const menu = q('[data-timesheet-menu]');
  const QUEUE_KEY = 'merdpos_query_queue_v1';
  const NOTICE_KEY = 'merdpos_query_notice_v1';
  let activeTrigger = null;
  let menuTrigger = null;
  let flushing = false;

  const uuid = () => crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = crypto.getRandomValues(new Uint8Array(1))[0] & 15;
    return (c === 'x' ? r : (r & 3 | 8)).toString(16);
  });
  const clientId = Number.parseInt(root?.dataset.queryClientId || '0', 10);
  const employeeId = Number.parseInt(root?.dataset.queryEmployeeId || '0', 10);
  const currentContext = (item) => Number(item?.client_id) === clientId && Number(item?.employee_id) === employeeId;
  const getQueue = () => {
    try { const value = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); return Array.isArray(value) ? value : []; }
    catch (_) { return []; }
  };
  const setQueue = (queue) => {
    try { localStorage.setItem(QUEUE_KEY, JSON.stringify(queue)); return true; }
    catch (_) { return false; }
  };
  const notice = q('[data-query-notice]', root || document);
  const syncBadge = q('[data-query-sync-badge]', root || document);
  const activeValue = q('[data-query-active-value]', root || document);
  const serverActive = Number.parseInt(activeValue?.dataset.serverValue || activeValue?.textContent || '0', 10) || 0;
  const setNotice = (message, error = false) => {
    if (!notice) return;
    notice.textContent = message || '';
    notice.hidden = !message;
    notice.classList.toggle('is-error', error);
  };
  const updateQueryUi = () => {
    const queued = getQueue().filter(currentContext).filter((item) => item?.retryable !== false).length;
    if (activeValue) activeValue.textContent = String(serverActive + queued);
    if (syncBadge) {
      syncBadge.hidden = queued === 0;
      syncBadge.textContent = queued === 1 ? '1 saved for sync' : `${queued} saved for sync`;
    }
  };
  try {
    const saved = JSON.parse(sessionStorage.getItem(NOTICE_KEY) || 'null');
    if (saved?.message) setNotice(saved.message, saved.error === true);
    sessionStorage.removeItem(NOTICE_KEY);
  } catch (_) { sessionStorage.removeItem(NOTICE_KEY); }

  const weekSelect = q('[data-timesheet-week-select]');
  weekSelect?.addEventListener('change', () => {
    const form = weekSelect.form;
    if (!form) return;
    if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
  });

  const viewSearch = q('[data-timesheet-view-search]');
  const applyViewSearch = () => {
    const term = (viewSearch?.value || '').trim().toLocaleLowerCase();
    qa('.merdpos-reports-groups tbody tr').forEach((row) => {
      row.hidden = term !== '' && !row.textContent.toLocaleLowerCase().includes(term);
    });
  };
  viewSearch?.addEventListener('input', applyViewSearch);

  const closeMenu = () => {
    if (!menu) return;
    menu.hidden = true;
    menu.style.left = '';
    menu.style.top = '';
    if (menuTrigger) menuTrigger.setAttribute('aria-expanded', 'false');
    menuTrigger = null;
  };
  const positionMenu = (trigger) => {
    if (!menu) return;
    const rect = trigger.getBoundingClientRect();
    menu.hidden = false;
    const width = menu.offsetWidth;
    const height = menu.offsetHeight;
    const left = Math.min(Math.max(8, rect.right - width), window.innerWidth - width - 8);
    const below = rect.bottom + 6;
    const top = below + height <= window.innerHeight - 8 ? below : Math.max(8, rect.top - height - 6);
    menu.style.left = `${left}px`;
    menu.style.top = `${top}px`;
  };
  const openMenu = (trigger) => {
    if (!menu) return;
    closeMenu();
    menuTrigger = trigger;
    const missing = q('[data-timesheet-menu-choice="missing"]', menu);
    const query = q('[data-timesheet-menu-choice="query"]', menu);
    if (missing) missing.hidden = !truthy(trigger.dataset.canAddMissing);
    if (query) query.hidden = !truthy(trigger.dataset.canDispute);
    trigger.setAttribute('aria-expanded', 'true');
    positionMenu(trigger);
    q('[role="menuitem"]:not([hidden])', menu)?.focus({preventScroll:true});
  };

  document.addEventListener('click', (event) => {
    const print = event.target.closest('[data-merdpos-print]');
    if (print) { event.preventDefault(); window.print(); return; }
    const choice = event.target.closest('[data-timesheet-menu-choice]');
    if (choice && menuTrigger) {
      event.preventDefault();
      const trigger = menuTrigger;
      const mode = choice.dataset.timesheetMenuChoice;
      closeMenu();
      openDialog(trigger, mode);
      return;
    }
    const trigger = event.target.closest('[data-timesheet-action]');
    if (trigger && dialog) {
      event.preventDefault();
      if (trigger.dataset.disputeId) { closeMenu(); openDialog(trigger, 'existing'); }
      else openMenu(trigger);
      return;
    }
    if (event.target.closest('[data-timesheet-dialog-close]') && dialog) { dialog.close(); return; }
    if (menu && !menu.hidden && !event.target.closest('[data-timesheet-menu]')) closeMenu();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (menu && !menu.hidden) { closeMenu(); return; }
    if (dialog?.open) dialog.close();
  });
  window.addEventListener('resize', closeMenu);

  if (!dialog) { updateQueryUi(); return; }
  const title = q('[data-timesheet-dialog-title]', dialog);
  const context = q('[data-timesheet-dialog-context]', dialog);
  const help = q('[data-timesheet-create-help]', dialog);
  const createSection = q('[data-timesheet-create]', dialog);
  const existingSection = q('[data-timesheet-existing]', dialog);
  const createForm = q('[data-timesheet-create-form]', dialog);
  const typeSelect = q('[data-dispute-type-select]', dialog);
  const typeHidden = q('[data-dispute-type-hidden]', dialog);
  const storeField = q('[data-proposed-store-field]', dialog);
  const inField = q('[data-requested-in-field]', dialog);
  const outField = q('[data-requested-out-field]', dialog);
  const submit = q('[data-timesheet-submit]', dialog);
  const preview = q('[data-query-shift-preview]', dialog);
  const inControl = q('input[name="requested_clock_in"]', dialog);
  const outControl = q('input[name="requested_clock_out"]', dialog);

  const setReturnQuery = () => qa('input[name="return_query"]', dialog).forEach((input) => { input.value = window.location.search; });
  const setRequired = (field, required, visible = true) => {
    field.hidden = !visible;
    const control = q('input, select', field);
    if (!control) return;
    required ? control.setAttribute('required', '') : control.removeAttribute('required');
  };
  const syncCorrectionFields = () => {
    const type = typeHidden.value;
    const missing = type === 'new_shift';
    setRequired(storeField, missing || type === 'wrong_store', missing || type === 'wrong_store');
    setRequired(inField, missing || type === 'wrong_in', true);
    setRequired(outField, missing || type === 'missing_out' || type === 'wrong_out', true);
    q('[data-timesheet-dispute-fields]', dialog).hidden = missing;
    if (submit) submit.textContent = missing ? 'Submit missing shift' : 'Submit Query';
  };
  typeSelect?.addEventListener('change', () => {
    if (typeHidden.value === 'new_shift') return;
    typeHidden.value = typeSelect.value;
    syncCorrectionFields();
  });

  function bindExisting(trigger) {
    const disputeId = trigger.dataset.disputeId || '';
    existingSection.hidden = !disputeId;
    if (!disputeId) return false;
    q('[data-dispute-status]', dialog).textContent = trigger.dataset.disputeStatusLabel || trigger.dataset.disputeStatus || 'Status';
    q('[data-dispute-type]', dialog).textContent = trigger.dataset.disputeTypeLabel || 'Query';
    const requested = [
      trigger.dataset.disputeRequestedIn ? `IN ${trigger.dataset.disputeRequestedIn}` : '',
      trigger.dataset.disputeRequestedOut ? `OUT ${trigger.dataset.disputeRequestedOut}` : '',
    ].filter(Boolean).join(' · ');
    const requestedNode = q('[data-dispute-requested]', dialog);
    requestedNode.hidden = !requested;
    requestedNode.textContent = requested ? `Requested: ${requested}` : '';
    q('[data-dispute-reason]', dialog).textContent = trigger.dataset.disputeReason || 'No reason recorded.';
    q('[data-dispute-meta]', dialog).textContent = trigger.dataset.disputeSubmitted ? `Submitted ${trigger.dataset.disputeSubmitted}` : '';
    const note = q('[data-dispute-note]', dialog);
    note.hidden = !trigger.dataset.disputeDecisionNote;
    note.textContent = trigger.dataset.disputeDecisionNote ? `Decision note: ${trigger.dataset.disputeDecisionNote}` : '';
    qa('input[name="dispute_id"]', existingSection).forEach((input) => { input.value = disputeId; });
    q('[data-dispute-cancel]', dialog).hidden = !truthy(trigger.dataset.canCancel);
    q('[data-dispute-review]', dialog).hidden = !truthy(trigger.dataset.canReview);
    q('[data-dispute-handover]', dialog).hidden = !truthy(trigger.dataset.canHandover);
    return true;
  }

  function rowContext(trigger) {
    return `${trigger.dataset.employee || 'Employee'} · ${trigger.dataset.date || ''} · ${trigger.dataset.in || '—'}–${trigger.dataset.out || '—'}`;
  }
  function bindPreview(trigger) {
    if (!preview) return;
    preview.hidden = false;
    const values = {
      '[data-query-preview-employee]': trigger.dataset.employee || '—',
      '[data-query-preview-store]': trigger.dataset.store || '—',
      '[data-query-preview-date]': trigger.dataset.date || '—',
      '[data-query-preview-in]': trigger.dataset.in || '—',
      '[data-query-preview-out]': trigger.dataset.out || '—',
    };
    Object.entries(values).forEach(([selector, value]) => { const node = q(selector, preview); if (node) node.textContent = value; });
  }

  function openDialog(trigger, mode) {
    activeTrigger = trigger;
    createForm.reset();
    setReturnQuery();
    existingSection.hidden = true;
    createSection.hidden = true;
    if (preview) preview.hidden = true;

    if (mode === 'existing') {
      title.textContent = 'Query details';
      context.textContent = rowContext(trigger);
      bindExisting(trigger);
    } else if (mode === 'missing') {
      title.textContent = 'Add missing shift';
      context.textContent = 'Enter the shift that is missing from this timesheet.';
      help.textContent = 'This submits a missing-shift Query; it does not alter an existing shift until approved.';
      createSection.hidden = false;
      typeHidden.value = 'new_shift';
      createForm.elements.shift_id.value = '';
      if (inControl) inControl.value = '';
      if (outControl) outControl.value = '';
      syncCorrectionFields();
    } else {
      title.textContent = 'Query existing shift';
      context.textContent = rowContext(trigger);
      help.textContent = 'The shift log is prefilled below. Adjust the clock times only if they need correcting.';
      createSection.hidden = false;
      bindPreview(trigger);
      typeSelect.value = 'other';
      typeHidden.value = 'other';
      createForm.elements.shift_id.value = trigger.dataset.shiftId || '';
      if (inControl) inControl.value = trigger.dataset.clockInLocal || '';
      if (outControl) outControl.value = trigger.dataset.clockOutLocal || '';
      syncCorrectionFields();
    }
    if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
  }

  const markQueuedTrigger = (trigger) => {
    if (!trigger) return;
    trigger.classList.add('has-dispute');
    trigger.dataset.queuedQuery = '1';
    trigger.title = 'Query saved for sync';
  };

  const enqueueQuery = (body, trigger) => {
    const item = {
      id: body.submission_id,
      client_id: Number(body.expected_client_id),
      employee_id: Number(body.expected_employee_id),
      created_at: new Date().toISOString(),
      body,
    };
    const queue = getQueue();
    if (!queue.some((entry) => entry?.id === item.id)) queue.push(item);
    if (!setQueue(queue)) {
      setNotice('This browser could not save the Query for retry. Nothing was submitted.', true);
      return false;
    }
    markQueuedTrigger(trigger);
    updateQueryUi();
    setNotice(navigator.onLine ? 'Query saved. Sending…' : 'Query saved on this device. Waiting for internet sync.');
    return true;
  };

  const flushQueue = async () => {
    if (flushing || !root || !clientId || !employeeId) return;
    const queue = getQueue();
    if (!queue.some(currentContext)) { updateQueryUi(); return; }
    if (!navigator.onLine) {
      setNotice('Query saved on this device. Waiting for internet sync.');
      updateQueryUi();
      return;
    }
    flushing = true;
    const remaining = [];
    let submitted = 0;
    let lastError = '';
    for (const item of queue) {
      if (!currentContext(item)) { remaining.push(item); continue; }
      try {
        const response = await fetch(root.dataset.queryPostUrl || '', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-MERDPOS-CSRF': root.dataset.queryToken || '',
          },
          body: JSON.stringify(item.body),
        });
        const data = await response.json().catch(() => null);
        if (response.ok && data?.success === true) { submitted += 1; continue; }
        item.last_error = data?.error || `Query submit failed (${response.status}).`;
        item.retryable = data?.retryable === true;
        if (item.retryable) remaining.push(item);
        lastError = item.last_error;
      } catch (_) {
        item.last_error = 'Internet connection unavailable.';
        item.retryable = true;
        remaining.push(item);
        lastError = item.last_error;
      }
    }
    setQueue(remaining);
    flushing = false;
    updateQueryUi();
    if (submitted > 0) {
      sessionStorage.setItem(NOTICE_KEY, JSON.stringify({message: submitted === 1 ? 'Query submitted.' : `${submitted} Queries submitted.`, error:false}));
      window.location.reload();
      return;
    }
    const currentPending = remaining.filter(currentContext).length;
    if (currentPending > 0) {
      setNotice('Query saved on this device. Waiting for internet sync.');
    } else if (lastError) {
      setNotice(lastError, true);
    }
  };

  createForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    if (!createForm.reportValidity()) return;
    if (!clientId || !employeeId) {
      setNotice('Reload Timesheets before submitting this Query.', true);
      return;
    }
    const body = Object.fromEntries(new FormData(createForm));
    body.dispute_action = 'create';
    body.submission_id = uuid();
    body.expected_client_id = clientId;
    body.expected_employee_id = employeeId;
    if (!enqueueQuery(body, activeTrigger)) return;
    createForm.reset();
    if (dialog.open) dialog.close();
    flushQueue();
  });

  dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
  dialog.addEventListener('close', () => { activeTrigger = null; });
  window.addEventListener('online', flushQueue);
  window.addEventListener('offline', () => {
    if (getQueue().some(currentContext)) setNotice('Query saved on this device. Waiting for internet sync.');
  });
  updateQueryUi();
  flushQueue();
})();
