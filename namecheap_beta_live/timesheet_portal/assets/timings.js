(function () {
  'use strict';

  const storesPanel = document.getElementById('storesPanel');
  if (!storesPanel) return;

  const days = [[1,'Monday'],[2,'Tuesday'],[3,'Wednesday'],[4,'Thursday'],[5,'Friday'],[6,'Saturday'],[7,'Sunday']];
  let state = null;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function ensureStyles() {
    if (document.querySelector('link[data-timings-css]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'assets/timings.css?v=20260825b';
    link.dataset.timingsCss = '1';
    document.head.appendChild(link);
  }

  async function api(url, options = {}) {
    const response = await fetch(url, options);
    const text = await response.text();
    let data = null;
    if (text) {
      try { data = JSON.parse(text); }
      catch (_) {
        const snippet = text.replace(/\s+/g, ' ').trim().slice(0,160);
        throw new Error(`Store timings API returned invalid data (${response.status})${snippet ? ': ' + snippet : '.'}`);
      }
    }
    if (!data) throw new Error(`Store timings API returned an empty response (${response.status}).`);
    if (!data.success) throw new Error(data.error || `Request failed (${response.status})`);
    return data;
  }

  function createSection() {
    if (document.getElementById('storeTimingsSection')) return;
    const section = document.createElement('section');
    section.id = 'storeTimingsSection';
    section.className = 'controls-card timings-card store-timings-inline';
    section.innerHTML = `
      <div class="directory-toolbar timing-page-head">
        <div><h2>Weekly timings</h2><p>Opening and closing hours belong to the store. End time may be after midnight.</p></div>
      </div>
      <div class="timing-controls">
        <label>Store<select id="timingTarget"></select></label>
        <button type="button" id="copyMondayBtn" class="secondary-btn compact-btn">Copy Monday to all days</button>
      </div>
      <div id="timingScopeNote" class="timing-scope-note"></div>
      <form id="timingsForm">
        <div class="timing-grid timing-grid-head"><span>Day</span><span>Closed</span><span>Start</span><span>End</span></div>
        <div id="timingRows"></div>
        <div class="timing-footer">
          <span id="timingStatus" class="muted"></span>
          <button type="submit" class="primary-btn compact-btn">Save timings</button>
        </div>
      </form>`;
    storesPanel.appendChild(section);

    document.getElementById('timingTarget')?.addEventListener('change', renderSelectedSchedule);
    document.getElementById('copyMondayBtn')?.addEventListener('click', copyMondayToAll);
    document.getElementById('timingsForm')?.addEventListener('submit', saveTimings);
  }

  function scheduleMap() {
    const map = new Map();
    for (const row of state?.timings || []) {
      if (!map.has(Number(row.store_id))) map.set(Number(row.store_id), new Map());
      map.get(Number(row.store_id)).set(Number(row.day_of_week), row);
    }
    return map;
  }

  function timeValue(value) {
    const text = String(value || '');
    return /^\d{2}:\d{2}/.test(text) ? text.slice(0,5) : '';
  }

  function blankDay(day) {
    return {day_of_week:day,start_time:'',end_time:'',is_closed:0};
  }

  function normalizedSchedule(storeId) {
    const map = scheduleMap().get(Number(storeId)) || new Map();
    return days.map(([day]) => {
      const row = map.get(day) || blankDay(day);
      return {
        day_of_week:day,
        start_time:timeValue(row.start_time),
        end_time:timeValue(row.end_time),
        is_closed:Number(row.is_closed) === 1 ? 1 : 0,
      };
    });
  }

  function signaturesEqual(a, b) {
    return JSON.stringify(a) === JSON.stringify(b);
  }

  function selectedSchedule() {
    const target = document.getElementById('timingTarget')?.value || '';
    const active = (state?.stores || [])
      .filter(store => String(store.status).toLowerCase() === 'active')
      .slice().sort((a,b) => Number(a.id) - Number(b.id));
    const note = document.getElementById('timingScopeNote');

    if (target === 'all') {
      if (!active.length) return days.map(([day]) => blankDay(day));
      const first = normalizedSchedule(active[0].id);
      const same = active.every(store => signaturesEqual(first, normalizedSchedule(store.id)));
      if (note) {
        note.className = 'timing-scope-note' + (same ? '' : ' is-warning');
        note.textContent = same
          ? 'All active stores currently share this schedule. Saving applies it to every active store.'
          : `Stores currently have different schedules. This form shows ${active[0].store_name}; saving will overwrite all active stores.`;
      }
      return first;
    }

    if (note) {
      note.className = 'timing-scope-note';
      note.textContent = 'Changes apply only to the selected store.';
    }
    return normalizedSchedule(Number(target));
  }

  function renderRows(schedule) {
    const root = document.getElementById('timingRows');
    if (!root) return;
    const byDay = new Map(schedule.map(row => [Number(row.day_of_week), row]));
    root.innerHTML = days.map(([day,label]) => {
      const row = byDay.get(day) || blankDay(day);
      const closed = Number(row.is_closed) === 1;
      return `<div class="timing-grid timing-row" data-day="${day}">
        <strong>${label}</strong>
        <label class="timing-closed"><input type="checkbox" class="timing-closed-input" ${closed ? 'checked' : ''}><span>Closed</span></label>
        <label><span class="mobile-field-label">Start</span><input type="time" class="timing-start" value="${esc(row.start_time)}" ${closed ? 'disabled' : ''}></label>
        <label><span class="mobile-field-label">End</span><input type="time" class="timing-end" value="${esc(row.end_time)}" ${closed ? 'disabled' : ''}></label>
      </div>`;
    }).join('');
    root.querySelectorAll('.timing-closed-input').forEach(input => input.addEventListener('change', event => {
      const row = event.target.closest('.timing-row');
      row?.querySelectorAll('.timing-start,.timing-end').forEach(field => { field.disabled = event.target.checked; });
    }));
  }

  function renderSelectedSchedule() {
    if (!state) return;
    renderRows(selectedSchedule());
  }

  function populateTargets(previous = null) {
    const select = document.getElementById('timingTarget');
    if (!select || !state) return;
    const active = (state.stores || [])
      .filter(store => String(store.status).toLowerCase() === 'active')
      .slice().sort((a,b) => Number(a.id) - Number(b.id));
    select.innerHTML = `<option value="all">All active stores</option>` + active
      .map(store => `<option value="${Number(store.id)}">ID ${Number(store.id)} · ${esc(store.store_name)}</option>`).join('');
    if (previous === 'all' || active.some(store => String(store.id) === String(previous))) select.value = String(previous);
    else if (active.length) select.value = String(active[0].id);
    else select.value = 'all';
  }

  function copyMondayToAll() {
    const monday = document.querySelector('#storeTimingsSection .timing-row[data-day="1"]');
    if (!monday) return;
    const closed = monday.querySelector('.timing-closed-input').checked;
    const start = monday.querySelector('.timing-start').value;
    const end = monday.querySelector('.timing-end').value;
    document.querySelectorAll('#storeTimingsSection .timing-row').forEach(row => {
      const closedInput = row.querySelector('.timing-closed-input');
      const startInput = row.querySelector('.timing-start');
      const endInput = row.querySelector('.timing-end');
      closedInput.checked = closed;
      startInput.disabled = closed;
      endInput.disabled = closed;
      startInput.value = closed ? '' : start;
      endInput.value = closed ? '' : end;
    });
  }

  function collectDays() {
    return Array.from(document.querySelectorAll('#storeTimingsSection .timing-row')).map(row => {
      const closed = row.querySelector('.timing-closed-input').checked;
      return {
        day_of_week:Number(row.dataset.day),
        is_closed:closed ? 1 : 0,
        start_time:closed ? '' : row.querySelector('.timing-start').value,
        end_time:closed ? '' : row.querySelector('.timing-end').value,
      };
    });
  }

  async function saveTimings(event) {
    event.preventDefault();
    if (!state) return;
    const target = document.getElementById('timingTarget')?.value || '';
    const daysPayload = collectDays();
    for (const row of daysPayload) {
      if (!row.is_closed && (!row.start_time || !row.end_time)) {
        setStatus('Every open day needs both a start time and an end time.', true);
        return;
      }
    }

    const button = event.currentTarget.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      state = await api('api/store_timings.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
          action:'save_timings',
          csrf:state.csrf,
          scope:target === 'all' ? 'all' : 'store',
          store_id:target === 'all' ? null : Number(target),
          days:daysPayload,
        }),
      });
      populateTargets(target);
      renderSelectedSchedule();
      setStatus(state.message || 'Timings saved.');
    } catch (error) {
      setStatus(error.message, true);
    } finally {
      button.disabled = false;
    }
  }

  function setStatus(message, error = false) {
    const root = document.getElementById('timingStatus');
    if (!root) return;
    root.textContent = message || '';
    root.classList.toggle('is-error', error);
  }

  async function load() {
    setStatus('Loading timings…');
    try {
      state = await api('api/store_timings.php');
      populateTargets();
      renderSelectedSchedule();
      setStatus('');
    } catch (error) {
      setStatus(error.message, true);
    }
  }

  ensureStyles();
  createSection();
  load();
})();
