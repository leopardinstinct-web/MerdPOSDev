(() => {
  'use strict';
  const q = (selector, root = document) => root.querySelector(selector);
  const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
  const truthy = (value) => value === '1';
  const dialog = q('[data-timesheet-dialog]');

  document.addEventListener('click', (event) => {
    const print = event.target.closest('[data-merdpos-print]');
    if (print) { event.preventDefault(); window.print(); return; }
    const trigger = event.target.closest('[data-timesheet-action]');
    if (trigger && dialog) { event.preventDefault(); openDialog(trigger); return; }
    if (event.target.closest('[data-timesheet-dialog-close]') && dialog) dialog.close();
  });

  if (!dialog) return;
  const createSection = q('[data-timesheet-create]', dialog);
  const existingSection = q('[data-timesheet-existing]', dialog);
  const createForm = q('[data-timesheet-create-form]', dialog);
  const typeSelect = q('[data-dispute-type-select]', dialog);
  const typeHidden = q('[data-dispute-type-hidden]', dialog);
  const storeField = q('[data-proposed-store-field]', dialog);
  const inField = q('[data-requested-in-field]', dialog);
  const outField = q('[data-requested-out-field]', dialog);
  const submit = q('[data-timesheet-submit]', dialog);
  let activeTrigger = null;

  const setReturnQuery = () => qa('input[name="return_query"]', dialog).forEach((input) => { input.value = window.location.search; });
  const setRequired = (field, required) => {
    field.hidden = !required;
    const control = q('input, select', field);
    if (control) control.required = required;
  };
  const syncCorrectionFields = () => {
    const type = typeHidden.value;
    setRequired(storeField, type === 'new_shift');
    setRequired(inField, type === 'new_shift' || type === 'wrong_in');
    setRequired(outField, type === 'new_shift' || type === 'missing_out' || type === 'wrong_out');
    q('[data-timesheet-dispute-fields]', dialog).hidden = type === 'new_shift';
    if (submit) submit.textContent = type === 'new_shift' ? 'Submit missing shift' : 'Submit dispute';
  };
  const setMode = (mode) => {
    if (!activeTrigger) return;
    const missing = mode === 'missing';
    typeHidden.value = missing ? 'new_shift' : (typeSelect.value || 'other');
    createForm.elements.shift_id.value = missing ? '' : activeTrigger.dataset.shiftId;
    qa('[data-timesheet-mode]', dialog).forEach((button) => button.classList.toggle('is-active', button.dataset.timesheetMode === mode));
    syncCorrectionFields();
  };
  typeSelect?.addEventListener('change', () => { if (typeHidden.value !== 'new_shift') { typeHidden.value = typeSelect.value; syncCorrectionFields(); } });
  qa('[data-timesheet-mode]', dialog).forEach((button) => button.addEventListener('click', () => setMode(button.dataset.timesheetMode)));

  function bindExisting(trigger) {
    const disputeId = trigger.dataset.disputeId || '';
    existingSection.hidden = !disputeId;
    if (!disputeId) return false;
    q('[data-dispute-status]', dialog).textContent = trigger.dataset.disputeStatusLabel || trigger.dataset.disputeStatus || 'Status';
    q('[data-dispute-type]', dialog).textContent = trigger.dataset.disputeTypeLabel || 'Dispute';
    const requested = [trigger.dataset.disputeRequestedIn ? `IN ${trigger.dataset.disputeRequestedIn}` : '', trigger.dataset.disputeRequestedOut ? `OUT ${trigger.dataset.disputeRequestedOut}` : ''].filter(Boolean).join(' · ');
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

  function openDialog(trigger) {
    activeTrigger = trigger;
    createForm.reset();
    setReturnQuery();
    q('[data-timesheet-dialog-context]', dialog).textContent = `${trigger.dataset.employee || 'Employee'} · ${trigger.dataset.date || ''} · ${trigger.dataset.in || '—'}–${trigger.dataset.out || '—'}`;
    const hasExisting = bindExisting(trigger);
    createSection.hidden = hasExisting || (!truthy(trigger.dataset.canDispute) && !truthy(trigger.dataset.canAddMissing));
    const disputeMode = q('[data-timesheet-mode="dispute"]', dialog);
    const missingMode = q('[data-timesheet-mode="missing"]', dialog);
    disputeMode.hidden = !truthy(trigger.dataset.canDispute);
    missingMode.hidden = !truthy(trigger.dataset.canAddMissing);
    if (!hasExisting) setMode(!disputeMode.hidden ? 'dispute' : 'missing');
    if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
  }

  dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
  dialog.addEventListener('close', () => { activeTrigger = null; });
})();
