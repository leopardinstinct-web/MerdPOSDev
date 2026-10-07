'use strict';
/**
 * Payload-contract proof for roster-v1.js (M1, M2, m4, m5, m6).
 *
 * jsdom is not installed and must not be added, so this harness supplies its own
 * minimal DOM stub: element tree, attribute selectors, classList, dataset,
 * closest, events, localStorage, navigator and a controllable fetch. It is a stub,
 * not a browser: it executes the real roster-v1.js module and asserts the payload
 * the module would POST, nothing more.
 *
 * Run: node drupal/tools/verify_roster_payload.js <path to roster-v1.js>
 */
const fs = require('fs');
const path = require('path');

const jsPath = process.argv[2];
if (!jsPath) { console.error('usage: node proof.js <roster-v1.js>'); process.exit(2); }
const source = fs.readFileSync(jsPath, 'utf8');

const kebab = (s) => s.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase());

class El {
  constructor(tag, attrs = {}) {
    this.tagName = String(tag).toUpperCase();
    this.attrs = {};
    for (const [k, v] of Object.entries(attrs)) this.attrs[k] = String(v);
    this.children = [];
    this.parent = null;
    this.listeners = {};
    this.value = this.attrs.value !== undefined ? this.attrs.value : '';
    this.textContent = '';
    this.hidden = false;
    this.classes = new Set();
    const self = this;
    this.dataset = new Proxy({}, {
      get: (_t, prop) => (typeof prop === 'string' ? self.attrs['data-' + kebab(prop)] : undefined),
      set: (_t, prop, value) => { self.attrs['data-' + kebab(prop)] = String(value); return true; },
      has: (_t, prop) => typeof prop === 'string' && ('data-' + kebab(prop)) in self.attrs,
      // A real DOMStringMap supports `delete element.dataset.x`, and the script relies
      // on it to revive a cell that was cleared. Without this trap the stub silently
      // ignored the delete, which made that behaviour untestable.
      deleteProperty: (_t, prop) => {
        if (typeof prop === 'string') delete self.attrs['data-' + kebab(prop)];
        return true;
      },
    });
  }
  setAttribute(name, value) { this.attrs[name] = String(value); if (name === 'class') this.setClasses(value); }
  getAttribute(name) { return this.attrs[name] !== undefined ? this.attrs[name] : null; }
  setClasses(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); }
  get className() { return [...this.classes].join(' '); }
  set className(value) { this.setClasses(value); }
  get classList() {
    const self = this;
    return {
      add: (...names) => names.forEach((n) => self.classes.add(n)),
      remove: (...names) => names.forEach((n) => self.classes.delete(n)),
      contains: (name) => self.classes.has(name),
      toggle: (name, force) => {
        const on = force === undefined ? !self.classes.has(name) : Boolean(force);
        if (on) self.classes.add(name); else self.classes.delete(name);
        return on;
      },
    };
  }
  append(...nodes) { for (const node of nodes) { node.parent = this; this.children.push(node); } }
  remove() {
    if (!this.parent) return;
    this.parent.children = this.parent.children.filter((child) => child !== this);
    this.parent = null;
  }
  walk(fn) { for (const child of this.children) { fn(child); child.walk(fn); } }
  matches(selector) {
    const sel = selector.trim();
    if (sel.startsWith('[') && sel.endsWith(']')) {
      const body = sel.slice(1, -1);
      const eq = body.indexOf('=');
      if (eq === -1) return Object.prototype.hasOwnProperty.call(this.attrs, body);
      const name = body.slice(0, eq);
      let want = body.slice(eq + 1).trim();
      if ((want.startsWith('"') && want.endsWith('"')) || (want.startsWith("'") && want.endsWith("'"))) want = want.slice(1, -1);
      return this.attrs[name] === want;
    }
    return this.tagName === sel.toUpperCase();
  }
  querySelectorAll(selector) {
    const parts = selector.split(',').map((s) => s.trim()).filter(Boolean);
    const out = [];
    this.walk((el) => { if (parts.some((p) => el.matches(p))) out.push(el); });
    return out;
  }
  querySelector(selector) { const all = this.querySelectorAll(selector); return all.length ? all[0] : null; }
  closest(selector) {
    let el = this;
    while (el) { if (el.matches && el.matches(selector)) return el; el = el.parent; }
    return null;
  }
  addEventListener(type, handler) { (this.listeners[type] = this.listeners[type] || []).push(handler); }
  dispatch(type, event) { (this.listeners[type] || []).forEach((handler) => handler(event)); }
}

const results = [];
const check = (name, condition, detail) => {
  results.push({ name, ok: Boolean(condition), detail: detail === undefined ? '' : String(detail) });
};

const buildHarness = (options) => {
  const document = new El('document');
  document.createElement = (tag) => new El(tag);

  const root = new El('section', {
    'data-roster-offline-root': '',
    'data-roster-queue-key': 'test_queue_v1',
    'data-roster-submit-url': '/merdpos/roster/submit',
    'data-roster-token': 'tok',
    'data-roster-store-id': '4',
    'data-roster-week-start': '2026-10-05',
    'data-roster-can-manage': options.canManage === false ? '0' : '1',
  });
  document.append(root);

  const badge = new El('span', { 'data-roster-queue-badge': '' });
  const message = new El('span', { 'data-roster-status-message': '' });
  const offlineNote = new El('p', { 'data-roster-offline-note': '' });
  const note = new El('input', { 'data-roster-note': '' });
  const saveDraft = new El('button', { 'data-roster-save': 'draft' });
  root.append(badge, message, offlineNote, note, saveDraft);

  (options.cells || []).forEach((spec) => {
    const cell = new El('div', {
      'data-roster-cell': spec.key,
      'data-roster-date': '2026-10-05',
      'data-roster-slot': spec.slot,
      'data-roster-original': spec.original,
      // The confirmed times of a stored shift, which the template renders so an
      // emptied time input cannot silently delete the shift.
      'data-roster-original-start': spec.originalStart || '',
      'data-roster-original-end': spec.originalEnd || '',
      'data-roster-default-start': spec.defaultStart,
      'data-roster-default-end': spec.defaultEnd,
    });
    // The template marks a never-planned cell. Both spellings are rendered so the
    // same harness can run against the pre-fix script (which keys off is-empty) and
    // the fixed one (which keys off data-roster-original / is-vacant).
    if (spec.original === 'none') cell.className = 'merdpos-roster-cell is-vacant is-empty';
    else cell.className = 'merdpos-roster-cell';
    const start = new El('input', { 'data-roster-start': '' });
    start.value = spec.start;
    const end = new El('input', { 'data-roster-end': '' });
    end.value = spec.end;
    const indicator = new El('span', { 'data-roster-next-day': '' });
    indicator.hidden = !spec.nextDayInitial;
    const list = new El('ul', { 'data-roster-assignments': '' });
    (spec.employees || []).forEach((id) => {
      const chip = new El('li', { 'data-roster-employee': String(id) });
      const label = new El('span'); label.textContent = 'Employee ' + id;
      const removeButton = new El('button', { 'data-roster-remove': '' });
      chip.append(label, removeButton);
      list.append(chip);
    });
    const add = new El('div', { class: 'merdpos-roster-add' });
    const select = new El('select', { 'data-roster-add': '' });
    select.options = [{ value: '', textContent: 'Add employee…' }, { value: '7', textContent: 'Ada' }];
    select.selectedIndex = 0;
    add.append(select);
    const clear = new El('button', { 'data-roster-clear': '' });
    clear.textContent = 'Clear shift';
    cell.append(start, end, indicator, list, add, clear);
    root.append(cell);
  });

  const storage = new Map();
  if (options.seedQueue) storage.set('test_queue_v1', JSON.stringify(options.seedQueue));
  const localStorage = {
    getItem: (key) => (storage.has(key) ? storage.get(key) : null),
    setItem: (key, value) => { storage.set(key, String(value)); },
    removeItem: (key) => { storage.delete(key); },
  };

  const pendingFetches = [];
  const window_ = {
    crypto: options.crypto === 'none' ? undefined : { randomUUID: () => '11111111-2222-4333-8444-555555555555' },
    alert: () => {},
    location: { reload: () => {} },
    addEventListener: (type, handler) => { (window_.listeners[type] = window_.listeners[type] || []).push(handler); },
    listeners: {},
    setTimeout: (fn, ms) => setTimeout(fn, ms),
    clearTimeout: (id) => clearTimeout(id),
    fetch: (url, init) => new Promise((resolve, reject) => {
      pendingFetches.push({ url, init, resolve, reject, body: JSON.parse(init.body) });
    }),
  };

  const navigator_ = { onLine: true };
  const Drupal = { behaviors: {} };
  const once = (name, selector, context) => context.querySelectorAll(selector);

  const factory = new Function('Drupal', 'once', 'window', 'document', 'navigator', 'localStorage', source);
  factory(Drupal, once, window_, document, navigator_, localStorage);
  Drupal.behaviors.merdposRoster.attach(document);

  return {
    root, note, saveDraft, storage, localStorage, window: window_, navigator: navigator_,
    pendingFetches, queue: () => JSON.parse(storage.get('test_queue_v1') || '[]'),
    cells: root.querySelectorAll('[data-roster-cell]'),
    clickSave: () => saveDraft.dispatch('click', { target: saveDraft }),
    // What the planner actually reads: the status line beside the queue badge.
    messageText: () => String(message.textContent || ''),
  };
};

const byteLength = (value) => Buffer.byteLength(String(value), 'utf8');
const v4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

// ---- M1: the default late 16:00-00:00 cell must save with ends_next_day true ----
// The defect path: a planner adds the first employee to a never-planned late cell
// (so it has no stored shift and its times are still the defaults) and saves. The
// old payload sent ends_next_day = false from an unticked checkbox and the
// controller rejected the whole week with a 422.
{
  const h = buildHarness({
    cells: [
      { key: '2026-10-05|late', slot: 'late', original: 'none', defaultStart: '16:00', defaultEnd: '00:00', start: '16:00', end: '00:00', employees: [7], nextDayInitial: true },
      { key: '2026-10-05|early', slot: 'early', original: 'none', defaultStart: '07:00', defaultEnd: '16:00', start: '07:00', end: '16:00' },
    ],
  });
  const lateCell = h.cells[0];
  check('M1 the late cell starts with its +1d indicator visible', lateCell.querySelector('[data-roster-next-day]').hidden === false);
  check('M1 an untouched early cell is still shown as vacant', h.cells[1].classList.contains('is-vacant'));
  h.clickSave();
  const queued = h.queue();
  const shifts = queued.length ? queued[0].shifts : [];
  const late = shifts.find((s) => s.slot_key === 'late');
  check('M1 a staffed 16:00-00:00 cell is included in the payload', Boolean(late), JSON.stringify(shifts));
  check('M1 a 16:00-00:00 cell is sent with ends_next_day = true', late && late.ends_next_day === true, late && String(late.ends_next_day));
  check('M1 the payload carries exactly the one planned shift', shifts.length === 1, 'shifts=' + shifts.length);
  check('M1 the late cell is no longer treated as vacant once it is a shift', lateCell.classList.contains('is-vacant') === false);
}

// ---- M2a: removing the last employee must not delete a stored shift ----
{
  const h = buildHarness({
    cells: [{ key: '2026-10-06|early', slot: 'early', original: 'shift', defaultStart: '07:00', defaultEnd: '16:00', start: '09:00', end: '17:00', employees: [7], nextDayInitial: false }],
  });
  const cell = h.cells[0];
  const removeButton = cell.querySelector('[data-roster-remove]');
  h.root.dispatch('click', { target: removeButton });
  check('M2a the chip is gone after remove', cell.querySelectorAll('[data-roster-employee]').length === 0);
  check('M2a an emptied stored shift is not marked vacant', cell.classList.contains('is-vacant') === false);
  h.clickSave();
  const shifts = h.queue()[0].shifts;
  const kept = shifts.find((s) => s.slot_key === 'early');
  check('M2a the emptied stored shift survives the save', Boolean(kept), JSON.stringify(shifts));
  check('M2a the surviving shift carries zero assignments', kept && Array.isArray(kept.assignments) && kept.assignments.length === 0, kept && JSON.stringify(kept.assignments));
  check('M2a the surviving shift keeps its own times', kept && kept.start_time === '09:00:00' && kept.end_time === '17:00:00', kept && kept.start_time + '-' + kept.end_time);
}

// ---- M2d: an emptied time input must not delete a stored shift ----
// A "time" input can be cleared from the keyboard, and the portal replaces the week
// wholesale, so a cell omitted from the payload takes its shift with it. The shift's
// confirmed times are the fallback. This path previously had NO behavioural coverage:
// every other case supplies non-empty times, so a silently dead fallback would have
// passed the whole suite while still deleting the shift.
{
  const h = buildHarness({
    cells: [{ key: '2026-10-06|early', slot: 'early', original: 'shift', originalStart: '09:00', originalEnd: '17:00', defaultStart: '07:00', defaultEnd: '16:00', start: '', end: '', employees: [7], nextDayInitial: false }],
  });
  const cell = h.cells[0];
  h.clickSave();
  const shifts = h.queue()[0].shifts;
  const kept = shifts.find((s) => s.slot_key === 'early');
  check('M2d a stored shift with cleared times is still sent', Boolean(kept), JSON.stringify(shifts));
  check('M2d it is sent with the times the server last confirmed', kept && kept.start_time === '09:00:00' && kept.end_time === '17:00:00', kept && kept.start_time + '-' + kept.end_time);
  check('M2d the cleared inputs are restored to those times', cell.querySelector('[data-roster-start]').value === '09:00' && cell.querySelector('[data-roster-end]').value === '17:00', cell.querySelector('[data-roster-start]').value + '/' + cell.querySelector('[data-roster-end]').value);
  check('M2d it keeps its employee', kept && kept.assignments.length === 1 && kept.assignments[0].employee_id === 7, kept && JSON.stringify(kept.assignments));
}

// ---- M2e: the fallback must not depend on the time FORMAT ----
// The template renders HH:MM while the controller's normal form is HH:MM:SS. If the
// parser accepted only one of them, the fallback would no-op silently - the same
// data loss - so both spellings are exercised.
{
  const h = buildHarness({
    cells: [{ key: '2026-10-06|early', slot: 'early', original: 'shift', originalStart: '09:00:00', originalEnd: '17:30:00', defaultStart: '07:00', defaultEnd: '16:00', start: '', end: '', employees: [7], nextDayInitial: false }],
  });
  h.clickSave();
  const kept = h.queue()[0].shifts.find((s) => s.slot_key === 'early');
  check('M2e seconds-bearing confirmed times are accepted as a fallback', kept && kept.start_time === '09:00:00' && kept.end_time === '17:30:00', kept && kept.start_time + '-' + kept.end_time);
}

// ---- M2f: negative control - a cell that never held a shift stays omitted ----
{
  const h = buildHarness({
    cells: [{ key: '2026-10-07|early', slot: 'early', original: 'none', defaultStart: '07:00', defaultEnd: '16:00', start: '', end: '', employees: [] }],
  });
  h.clickSave();
  const shifts = h.queue()[0].shifts;
  check('M2f an emptied never-planned cell is still omitted', shifts.length === 0, JSON.stringify(shifts));
}

// ---- M3: clearing a shift is deliberate, visible, and omits the cell ----
{
  const h = buildHarness({
    cells: [{ key: '2026-10-08|early', slot: 'early', original: 'shift', originalStart: '09:00', originalEnd: '17:00', defaultStart: '07:00', defaultEnd: '16:00', start: '09:00', end: '17:00', employees: [7], nextDayInitial: false }],
  });
  const cell = h.cells[0];
  h.root.dispatch('click', { target: cell.querySelector('[data-roster-clear]') });
  check('M3 a cleared cell is marked removed and not vacant', cell.dataset.rosterRemoved === '1' && cell.classList.contains('is-removed') && cell.classList.contains('is-vacant') === false, 'removed=' + cell.dataset.rosterRemoved + ' vacant=' + cell.classList.contains('is-vacant'));
  check('M3 clearing takes the staff out', cell.querySelectorAll('[data-roster-employee]').length === 0);
  h.clickSave();
  check('M3 a cleared shift is omitted, which is what deletes it', h.queue()[0].shifts.filter((s) => s.slot_key === 'early').length === 0, JSON.stringify(h.queue()[0].shifts));
}

// ---- M3b: touching a cleared cell revives it ----
{
  const h = buildHarness({
    cells: [{ key: '2026-10-08|early', slot: 'early', original: 'shift', originalStart: '09:00', originalEnd: '17:00', defaultStart: '07:00', defaultEnd: '16:00', start: '09:00', end: '17:00', employees: [7], nextDayInitial: false }],
  });
  const cell = h.cells[0];
  h.root.dispatch('click', { target: cell.querySelector('[data-roster-clear]') });
  const startInput = cell.querySelector('[data-roster-start]');
  startInput.value = '10:00';
  // Dispatched on the input itself: the script registers time listeners per element
  // and only delegates clicks to the root, and this stub does not bubble.
  startInput.dispatch('input', { target: startInput });
  check('M3b editing the times revives a cleared cell', cell.dataset.rosterRemoved === undefined && cell.classList.contains('is-removed') === false);
  h.clickSave();
  const revived = h.queue()[0].shifts.find((s) => s.slot_key === 'early');
  check('M3b the revived shift is sent again', revived && revived.start_time === '10:00:00', revived && revived.start_time);
}

// ---- N1/N2: a rejection must be reported, must not be called success, and must not
// strand the OTHER queued week ----
// Async because it has to let the drain run between responses; wrapped in a function
// rather than using top-level await, which Node refuses alongside require().
async function checkRejectionAndDrain() {
  const h = buildHarness({
    cells: [{ key: '2026-10-05|late', slot: 'late', original: 'none', defaultStart: '16:00', defaultEnd: '00:00', start: '16:00', end: '00:00' }],
    seedQueue: [
      { submission_id: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', store_id: 4, week_start: '2026-10-05', status: 'draft', note: '', shifts: [] },
      { submission_id: 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff', store_id: 4, week_start: '2026-10-12', status: 'draft', note: '', shifts: [] },
    ],
  });
  check('N1 the seeded queue is being flushed', h.pendingFetches.length === 1, 'fetches=' + h.pendingFetches.length);
  h.pendingFetches[0].resolve({ ok: false, status: 422, json: () => Promise.resolve({ success: false, error: 'That store is not active.' }) });
  await new Promise((resolve) => setTimeout(resolve, 20));
  const text = h.messageText();
  check('N1 the rejection reason is shown', /not active/.test(text), text);
  check('N1 the rejection is not reported as success', !/Saved successfully/.test(text), text);
  check('N1 the rejected week is dropped from the queue', !h.queue().some((i) => i.submission_id === 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'), JSON.stringify(h.queue().map((i) => i.submission_id)));
  check('N1 the second week is still sent rather than stranded', h.pendingFetches.length === 2, 'fetches=' + h.pendingFetches.length);
  if (h.pendingFetches[1]) h.pendingFetches[1].resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true }) });
  await new Promise((resolve) => setTimeout(resolve, 20));
  check('N1 the queue drains to empty', h.queue().length === 0, 'queued=' + h.queue().length);
  check('N1 a later successful drain still does not claim success over the rejection', !/Saved successfully/.test(h.messageText()), h.messageText());
}

// ---- M2b: a never-planned cell that was added to and emptied is not a shift ----
{
  const h = buildHarness({
    cells: [{ key: '2026-10-07|early', slot: 'early', original: 'none', defaultStart: '07:00', defaultEnd: '16:00', start: '07:00', end: '16:00' }],
  });
  const cell = h.cells[0];
  const select = cell.querySelector('[data-roster-add]');
  select.value = '7';
  select.selectedIndex = 1;
  select.dispatch('change', {});
  check('M2b adding an employee creates a chip', cell.querySelectorAll('[data-roster-employee]').length === 1);
  check('M2b a cell with an added employee is not vacant', cell.classList.contains('is-vacant') === false);
  h.root.dispatch('click', { target: cell.querySelector('[data-roster-remove]') });
  check('M2b removing it returns the cell to vacant', cell.classList.contains('is-vacant') === true);
  h.clickSave();
  const shifts = h.queue()[0].shifts;
  check('M2b an added-then-emptied brand-new cell produces no shift', shifts.length === 0, JSON.stringify(shifts));
}

// ---- M2c: changed times with no employees are a shift ----
{
  const h = buildHarness({
    cells: [{ key: '2026-10-08|early', slot: 'early', original: 'none', defaultStart: '07:00', defaultEnd: '16:00', start: '07:00', end: '16:00' }],
  });
  const cell = h.cells[0];
  const start = cell.querySelector('[data-roster-start]');
  start.value = '05:30';
  start.dispatch('input', {});
  check('M2c a time change makes the cell non-vacant', cell.classList.contains('is-vacant') === false);
  h.clickSave();
  const shifts = h.queue()[0].shifts;
  const changed = shifts.find((s) => s.slot_key === 'early');
  check('M2c a cell with changed times and no employees is included', Boolean(changed), JSON.stringify(shifts));
  check('M2c it is sent with the changed start and no assignments', changed && changed.start_time === '05:30:00' && changed.assignments.length === 0, changed && JSON.stringify(changed));
  check('M2c 05:30-16:00 does not end the next day', changed && changed.ends_next_day === false, changed && String(changed.ends_next_day));
}

// ---- m4: the fallback id is v4-shaped and never constant ----
{
  const h = buildHarness({
    crypto: 'none',
    cells: [{ key: '2026-10-05|late', slot: 'late', original: 'none', defaultStart: '16:00', defaultEnd: '00:00', start: '16:00', end: '00:00' }],
  });
  h.clickSave();
  h.clickSave();
  const ids = h.queue().map((item) => item.submission_id);
  check('m4 the Math.random fallback still yields v4 ids', ids.every((id) => v4.test(id)), JSON.stringify(ids));
  check('m4 two saves do not share a submission id', ids.length === 2 && ids[0] !== ids[1], JSON.stringify(ids));
  check('m4 the fallback is not the all-zero UUID', ids.every((id) => id !== '00000000-0000-4000-8000-000000000000'));
}

// ---- m5: the note is truncated in UTF-8 bytes, not UTF-16 units ----
{
  const h = buildHarness({
    cells: [{ key: '2026-10-05|late', slot: 'late', original: 'none', defaultStart: '16:00', defaultEnd: '00:00', start: '16:00', end: '00:00' }],
  });
  h.note.value = 'é'.repeat(300);
  h.clickSave();
  const queuedNote = h.queue()[0].note;
  check('m5 a 600-byte note fits the controller\'s 255-byte limit', byteLength(queuedNote) <= 255, 'bytes=' + byteLength(queuedNote));
  check('m5 truncation happens on a character boundary', queuedNote === 'é'.repeat(127), 'chars=' + queuedNote.length);
  check('m5 the field is corrected to what is sent, not left longer', h.note.value === queuedNote, 'field chars=' + h.note.value.length);
}

// ---- m6: a save during a flush is neither lost nor a resurrection ----
const runConcurrency = async () => {
  const h = buildHarness({
    cells: [{ key: '2026-10-05|late', slot: 'late', original: 'none', defaultStart: '16:00', defaultEnd: '00:00', start: '16:00', end: '00:00' }],
    // A week the portal will authoritatively reject, already queued when the page
    // loads: attach flushes it, so the first request is in flight before any click.
    seedQueue: [{
      submission_id: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', store_id: 4, week_start: '2026-10-05', status: 'draft', note: '', shifts: [],
    }],
  });
  check('m6 the load flush is in flight', h.pendingFetches.length === 1, 'in flight=' + h.pendingFetches.length);
  // Manager saves while that flush is still in flight.
  h.clickSave();
  check('m6 a save during a flush does not start a second concurrent request', h.pendingFetches.length === 1, 'in flight=' + h.pendingFetches.length);
  check('m6 the concurrent save is queued', h.queue().length === 2, 'queued=' + h.queue().length);
  // The portal rejects the seeded week authoritatively.
  h.pendingFetches[0].resolve({ ok: false, status: 422, json: () => Promise.resolve({ success: false, error: 'rejected' }) });
  await new Promise((resolve) => setTimeout(resolve, 10));
  const afterReject = h.queue();
  check('m6 the rejected week is dropped', !afterReject.some((item) => item.submission_id === 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'), JSON.stringify(afterReject.map((i) => i.submission_id)));
  check('m6 the concurrent save survives the rejection', afterReject.length === 1, 'queued=' + afterReject.length);
  check('m6 the remaining week is retried immediately', h.pendingFetches.length === 2, 'total fetches=' + h.pendingFetches.length);
  h.pendingFetches[1].resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true }) });
  await new Promise((resolve) => setTimeout(resolve, 10));
  check('m6 the queue drains to empty', h.queue().length === 0, 'queued=' + h.queue().length);
};

runConcurrency().then(checkRejectionAndDrain).then(() => {
  const failed = results.filter((r) => !r.ok);
  for (const r of results) {
    console.log(`${r.ok ? 'PASS' : 'FAIL'}  ${r.name}${r.ok || !r.detail ? '' : '  [' + r.detail + ']'}`);
  }
  console.log(`\n${results.length - failed.length}/${results.length} assertions passed`);
  process.exit(failed.length ? 1 : 0);
}).catch((error) => {
  console.error('harness error: ' + (error && error.stack ? error.stack : error));
  process.exit(3);
});
