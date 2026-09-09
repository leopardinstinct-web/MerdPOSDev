(() => {
  'use strict';
  const q = (selector, root = document) => root.querySelector(selector);
  const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
  const truthy = (value) => value === '1';
  const dialog = q('[data-timesheet-dialog]');
  const menu = q('[data-timesheet-menu]');
  let activeTrigger = null;
  let menuTrigger = null;

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
    const dispute = q('[data-timesheet-menu-choice="dispute"]', menu);
    if (missing) missing.hidden = !truthy(trigger.dataset.canAddMissing);
    if (dispute) dispute.hidden = !truthy(trigger.dataset.canDispute);
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

  if (!dialog) return;
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

  const setReturnQuery = () => qa('input[name="return_query"]', dialog).forEach((input) => { input.value = window.location.search; });
  const setRequired = (field, required) => {
    field.hidden = !required;
    const control = q('input, select', field);
    if (control) required ? control.setAttribute('required', '') : control.removeAttribute('required');
  };
  const syncCorrectionFields = () => {
    const type = typeHidden.value;
    setRequired(storeField, type === 'new_shift');
    setRequired(inField, type === 'new_shift' || type === 'wrong_in');
    setRequired(outField, type === 'new_shift' || type === 'missing_out' || type === 'wrong_out');
    q('[data-timesheet-dispute-fields]', dialog).hidden = type === 'new_shift';
    if (submit) submit.textContent = type === 'new_shift' ? 'Submit missing shift' : 'Submit dispute';
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
    q('[data-dispute-type]', dialog).textContent = trigger.dataset.disputeTypeLabel || 'Dispute';
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

  function openDialog(trigger, mode) {
    activeTrigger = trigger;
    createForm.reset();
    setReturnQuery();
    existingSection.hidden = true;
    createSection.hidden = true;

    if (mode === 'existing') {
      title.textContent = 'Dispute details';
      context.textContent = rowContext(trigger);
      bindExisting(trigger);
    } else if (mode === 'missing') {
      title.textContent = 'Add missing shift';
      context.textContent = 'Enter the shift that is missing from this timesheet.';
      help.textContent = 'This creates a new missing-shift request; it does not modify the row you clicked.';
      createSection.hidden = false;
      typeHidden.value = 'new_shift';
      createForm.elements.shift_id.value = '';
      syncCorrectionFields();
    } else {
      title.textContent = 'Dispute existing shift';
      context.textContent = rowContext(trigger);
      help.textContent = 'Request a correction to this selected shift.';
      createSection.hidden = false;
      typeSelect.value = 'other';
      typeHidden.value = 'other';
      createForm.elements.shift_id.value = trigger.dataset.shiftId || '';
      syncCorrectionFields();
    }

    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  }

  dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
  dialog.addEventListener('close', () => { activeTrigger = null; });
})();
