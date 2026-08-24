(function () {
  const days = [
    [1,'Monday'],[2,'Tuesday'],[3,'Wednesday'],[4,'Thursday'],[5,'Friday'],[6,'Saturday'],[7,'Sunday']
  ];
  let state = null;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const iconClock = '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';

  function ensureStyles() {
    if (document.querySelector('link[data-timings-css]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'assets/timings.css';
    link.dataset.timingsCss = '1';
    document.head.appendChild(link);
  }

  function ensureStoreIdentityModule() {
    if (document.querySelector('script[data-store-identity-module]')) return;
    const script = document.createElement('script');
    script.src = 'assets/store-identity.js';
    script.dataset.storeIdentityModule = '1';
    document.body.appendChild(script);
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

  function createPanel() {
    if (document.getElementById('timingsPanel')) return;
    const main = document.querySelector('main.merd-page-shell');
    if (!main) return;
    const panel = document.createElement('section');
    panel.id = 'timingsPanel';
    panel.className = 'portal-panel';
    panel.hidden = true;
    panel.innerHTML = `
      <section class="controls-card timings-card">
        <div class="directory-toolbar timing-page-head">
          <div><h2>Timings</h2><p>Set day-wise store start and end times. End time may be after midnight.</p></div>
        </div>
        <div class="timing-controls">
          <label>Apply schedule to<select id="timingTarget"></select></label>
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
        </form>
      </section>`;
    main.appendChild(panel);

    document.getElementById('timingTarget')?.addEventListener('change', renderSelectedSchedule);
    document.getElementById('copyMondayBtn')?.addEventListener('click', copyMondayToAll);
    document.getElementById('timingsForm')?.addEventListener('submit', saveTimings);
  }

  function findOperationsGroup() {
    const sidebar = document.querySelector('.sidebar-group[data-sidebar-group="operations"]');
    if (sidebar) return sidebar;
    return Array.from(document.querySelectorAll('.nav-group')).find(group =>
      group.querySelector('.nav-group-label')?.textContent.trim().toLowerCase() === 'operations'
    ) || null;
  }

  function mountTab() {
    if (document.querySelector('[data-panel="timingsPanel"]')) return true;
    const group = findOperationsGroup();
    if (!group) return false;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'portal-tab';
    button.dataset.panel = 'timingsPanel';
    button.innerHTML = `${iconClock}<span>Timings</span>`;
    group.appendChild(button);
    button.addEventListener('click', () => activateTimings(button));
    return true;
  }

  function activateTimings(button) {
    document.querySelectorAll('.portal-tab').forEach(tab => tab.classList.toggle('active', tab === button));
    document.querySelectorAll('.portal-panel').forEach(panel => { panel.hidden = panel.id !== 'timingsPanel'; });

    const group = button.closest('[data-sidebar-group]');
    if (group) {
      document.querySelectorAll('.sidebar-group').forEach(item => {
        const active = item === group;
        item.hidden = !active;
        item.classList.toggle('active', active);
      });
      document.querySelectorAll('.rail-group-btn').forEach(rail => rail.classList.toggle('active', rail.dataset.navGroup === 'operations'));
      const title = document.getElementById('sidebarGroupTitle');
      if (title) title.textContent = 'Operations';
    }

    if (!state) load();
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
    const active = (state?.stores || []).filter(store => String(store.status).toLowerCase() === 'active');
    const note = document.getElementById('timingScopeNote');
    if (target === 'all') {
      if (!active.length) return days.map(([day]) => blankDay(day));
      const first = normalizedSchedule(active[0].id);
      const same = active.every(store => signaturesEqual(first, normalizedSchedule(store.id)));
      if (note) {
        note.className = 'timing-scope-note' + (same ? '' : ' is-warning');
        note.textContent = same
          ? 'All active stores currently share this schedule. Saving will apply it to every active store.'
          : `Active stores currently have different schedules. The form is showing ${active[0].store_name}; saving will overwrite all active stores with these times.`;
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
      row.querySelectorAll('.timing-start,.timing-end').forEach(field => { field.disabled = event.target.checked; });
    }));
  }

  function renderSelectedSchedule() {
    if (!state) return;
    renderRows(selectedSchedule());
  }

  function populateTargets() {
    const select = document.getElementById('timingTarget');
    if (!select || !state) return;
    const active = (state.stores || []).filter(store => String(store.status).toLowerCase() === 'active');
    select.innerHTML = `<option value="all">All active stores</option>` + active.map(store => `<option value="${Number(store.id)}">${esc(store.store_name)}</option>`).join('');
    if (active.length) select.value = String(active[0].id);
  }

  function copyMondayToAll() {
    const monday = document.querySelector('.timing-row[data-day="1"]');
    if (!monday) return;
    const closed = monday.querySelector('.timing-closed-input').checked;
    const start = monday.querySelector('.timing-start').value;
    const end = monday.querySelector('.timing-end').value;
    document.querySelectorAll('.timing-row').forEach(row => {
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
    return Array.from(document.querySelectorAll('.timing-row')).map(row => {
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
      const payload = {
        action:'save_timings',
        csrf:state.csrf,
        scope:target === 'all' ? 'all' : 'store',
        store_id:target === 'all' ? null : Number(target),
        days:daysPayload,
      };
      state = await api('api/store_timings.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(payload),
      });
      populateTargetsAfterSave(target);
      renderSelectedSchedule();
      setStatus(state.message || 'Timings saved.');
    } catch (error) {
      setStatus(error.message, true);
    } finally {
      button.disabled = false;
    }
  }

  function populateTargetsAfterSave(previous) {
    const select = document.getElementById('timingTarget');
    if (!select || !state) return;
    const active = (state.stores || []).filter(store => String(store.status).toLowerCase() === 'active');
    select.innerHTML = `<option value="all">All active stores</option>` + active.map(store => `<option value="${Number(store.id)}">${esc(store.store_name)}</option>`).join('');
    select.value = previous === 'all' || active.some(store => String(store.id) === String(previous)) ? String(previous) : (active[0] ? String(active[0].id) : 'all');
  }

  function setStatus(message, error = false) {
    const root = document.getElementById('timingStatus');
    if (!root) return;
    root.textContent = message;
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
  ensureStoreIdentityModule();
  createPanel();
  if (!mountTab()) {
    const observer = new MutationObserver(() => {
      if (mountTab()) observer.disconnect();
    });
    observer.observe(document.body, {childList:true,subtree:true});
    setTimeout(() => observer.disconnect(), 8000);
  }
})();