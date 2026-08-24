let currentWeek = null;
let weeks = [];
let isLoadingReport = false;

// Prevent double-loading this script and double-firing week navigation.
window.__timesheetPortalLoaded = window.__timesheetPortalLoaded || false;
window.__timesheetWeekNavLockedUntil = window.__timesheetWeekNavLockedUntil || 0;

const els = {
  select: document.getElementById('weekSelect'),
  logout: document.getElementById('logoutBtn'),
  status: document.getElementById('statusBox'),
  report: document.getElementById('reportContainer'),
  title: document.getElementById('reportTitle'),
  subtitle: document.getElementById('reportSubtitle'),
  downloadPdf: document.getElementById('downloadPdfBtn'),
};

function fmtMoney(value) {
  return Number(value || 0).toFixed(2);
}

function fmtHours(value) {
  return Number(value || 0).toFixed(2);
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>'"]/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  }[c]));
}

function addDays(dateStr, days) {
  const d = new Date(dateStr + 'T00:00:00');
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

function showStatus(message, isError = false) {
  els.status.textContent = message;
  els.status.hidden = false;
  els.status.classList.toggle('error-card', isError);
}

function hideStatus() {
  els.status.hidden = true;
}

async function fetchJson(url, options = {}) {
  const res = await fetch(url, options);
  const data = await res.json();
  if (!data.success) throw new Error(data.error || 'Request failed');
  return data;
}

async function init() {
  try {
    showStatus('Loading available weeks...');
    const data = await fetchJson('api/weeks.php');
    weeks = data.weeks || [];
    currentWeek = data.current_week;
    renderWeekSelect();
    els.select.value = currentWeek;
    await loadReport(currentWeek);
  } catch (err) {
    showStatus(err.message, true);
  }
}

function renderWeekSelect() {
  const known = new Map(weeks.map(w => [w.value, w.label]));
  if (currentWeek && !known.has(currentWeek)) known.set(currentWeek, labelFromDate(currentWeek));
  const sorted = Array.from(known.entries()).sort((a, b) => b[0].localeCompare(a[0]));
  els.select.innerHTML = sorted.map(([value, label]) => `<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`).join('');
}

function labelFromDate(weekStart) {
  const start = new Date(weekStart + 'T00:00:00');
  const end = new Date(start);
  end.setDate(end.getDate() + 6);
  return `${start.toLocaleDateString(undefined, { day: '2-digit', month: 'short' })} - ${end.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })}`;
}

function ensureWeekOption(weekStart) {
  if (!weekStart) return;

  const exists = Array.from(els.select.options).some(option => option.value === weekStart);
  if (!exists) {
    const option = document.createElement('option');
    option.value = weekStart;
    option.textContent = labelFromDate(weekStart);

    const options = Array.from(els.select.options);
    const insertBefore = options.find(existing => existing.value < weekStart);
    if (insertBefore) {
      els.select.insertBefore(option, insertBefore);
    } else {
      els.select.appendChild(option);
    }
  }

  els.select.value = weekStart;
}

async function loadReport(weekStart) {
  if (isLoadingReport) return;
  isLoadingReport = true;
  setControlsDisabled(true);
  currentWeek = weekStart;
  ensureWeekOption(weekStart);
  showStatus('Loading timesheet...');
  els.report.innerHTML = '';
  try {
    const data = await fetchJson('api/timesheet.php?week_start=' + encodeURIComponent(weekStart));
    hideStatus();
    renderReport(data.report);
    ensureWeekOption(weekStart);
  } catch (err) {
    showStatus(err.message, true);
    ensureWeekOption(weekStart);
  } finally {
    isLoadingReport = false;
    const unlockDelay = Math.max(0, window.__timesheetWeekNavLockedUntil - Date.now());
    setTimeout(() => setControlsDisabled(false), unlockDelay);
  }
}

function renderReport(report) {
  const titleText = report.is_super
    ? `Consolidated Time Sheet (${report.week_label})`
    : `My Time Sheet (${report.week_label})`;

  els.title.textContent = titleText;
  els.subtitle.textContent = `${report.week_start} to ${report.week_end}`;
  document.title = titleText;

  const hasRows = report.employees && report.employees.length > 0;
  if (!hasRows) {
    els.report.innerHTML = `
      <div class="empty-card">
        <h2>No timesheet data for this week</h2>
        <p>This week is still available because the portal opens on the current Monday–Sunday calendar week by default.</p>
      </div>`;
    return;
  }

  const parts = [`<section class="report-card main-report-card">`, `<h2 class="report-title-in-card">${escapeHtml(titleText)}</h2>`];
  if (report.is_super) {
    parts.push(renderStoreSummary(report));
    parts.push(renderEmployeeSummary(report));
    parts.push(`<h2 class="section-title employee-wise-title">Employee-wise Report</h2>`);
    parts.push(...report.employees.map(emp => renderEmployeeSection(emp, report.show_wages, true)));
  } else {
    parts.push(renderPersonalSummary(report));
    parts.push(...report.employees.map(emp => renderEmployeeSection(emp, report.show_wages, false)));
  }
  parts.push(`</section>`);

  els.report.innerHTML = parts.join('');
}

function metricCard(label, value, tone = 'blue', detail = '') {
  return `
    <article class="report-metric report-metric-${tone}">
      <span class="report-metric-label">${escapeHtml(label)}</span>
      <strong>${escapeHtml(value)}</strong>
      ${detail ? `<small>${escapeHtml(detail)}</small>` : ''}
    </article>`;
}

function renderStoreSummary(report) {
  const rows = report.store_summary.map(s => `
    <tr>
      <td>${escapeHtml(s.store_name)}</td>
      <td>${s.total_employees_worked}</td>
      <td class="num">${fmtHours(s.total_hours_worked)}</td>
      <td class="num">${fmtMoney(s.total_amount)}</td>
    </tr>`).join('');

  return `
    <div class="summary-block">
      <h2 class="section-title">Executive Summary</h2>
      <div class="table-scroll app-table-shell">
        <table class="summary-table app-data-table">
          <thead><tr><th>Store</th><th>Employees</th><th>Total hours</th><th>Total amount</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
      <div class="report-metrics report-metrics-summary">
        ${metricCard('Total hours', fmtHours(report.grand_total_hours), 'blue', 'All stores')}
        ${metricCard('Total wages', '$' + fmtMoney(report.grand_total_wage), 'green', 'All stores')}
      </div>
    </div>`;
}

function renderEmployeeSummary(report) {
  const rows = report.employee_summary.map(e => `
    <tr>
      <td>${escapeHtml(e.employee_name)}${e.missing_pay_rate ? ' <span class="warn-pill">Missing rate</span>' : ''}</td>
      <td>${escapeHtml(e.user_id || '')}</td>
      <td class="num">${fmtHours(e.total_hours)}</td>
      <td class="num">${fmtMoney(e.total_wage)}</td>
    </tr>`).join('');

  return `
    <div class="summary-block employee-summary-block">
      <div class="table-scroll app-table-shell">
        <table class="summary-table employee-summary-table app-data-table">
          <thead><tr><th>Employee</th><th>Phone</th><th>Total hours</th><th>Total wage</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    </div>`;
}

function renderPersonalSummary(report) {
  const emp = report.employees[0];
  if (!emp) return '';
  const shiftCount = emp.rows ? emp.rows.length : 0;
  return `
    <section class="stat-grid user-stat-grid">
      <div class="stat-card stat-hours"><span>Total Hours</span><strong>${fmtHours(emp.total_hours)}</strong></div>
      <div class="stat-card stat-wage"><span>Total Wage</span><strong>${fmtMoney(emp.total_wage)}</strong></div>
      <div class="stat-card stat-rate"><span>Pay Rate</span><strong>${emp.pay_rate === null ? 'Missing' : fmtMoney(emp.pay_rate) + '/hr'}</strong></div>
      <div class="stat-card stat-shifts"><span>Total Shifts</span><strong>${shiftCount}</strong></div>
    </section>`;
}

function renderEmployeeSection(emp, showWages, showEmployeeHeading = true) {
  const rows = emp.rows.map(r => `
    <tr class="shift-row ${r.is_late ? 'late-row' : ''}">
      <td data-label="Employee">${escapeHtml(r.user_name)}</td>
      <td data-label="Store">${escapeHtml(r.store_name)}</td>
      <td data-label="In date">${escapeHtml(r.in_date)}</td>
      <td data-label="Actual in">${escapeHtml(r.actual_in_time)}</td>
      <td data-label="Rounded in">${escapeHtml(r.rounded_in_time)}</td>
      <td data-label="Out date">${escapeHtml(r.out_date)}</td>
      <td data-label="Actual out">${escapeHtml(r.actual_out_time)}</td>
      <td data-label="Rounded out">${escapeHtml(r.rounded_out_time)}</td>
      <td data-label="Hours" class="num">${fmtHours(r.total_hours)}</td>
    </tr>`).join('');

  const employeeHeading = showEmployeeHeading ? `<h2 class="employee-name">${escapeHtml(emp.employee_name)}</h2>` : '';
  const wageValue = emp.pay_rate === null ? 'Rate missing' : '$' + fmtMoney(emp.total_wage);
  const wageDetail = emp.pay_rate === null ? 'Set a pay rate' : '$' + fmtMoney(emp.pay_rate) + '/hour';

  return `
    <section class="employee-section ${showEmployeeHeading ? '' : 'single-user-section'}">
      ${employeeHeading}
      <div class="table-scroll app-table-shell employee-shifts-shell">
        <table class="detail-table app-data-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Store</th>
              <th>In date</th>
              <th>Actual in</th>
              <th>Rounded in</th>
              <th>Out date</th>
              <th>Actual out</th>
              <th>Rounded out</th>
              <th>Hours</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
      <div class="report-metrics employee-metrics">
        ${metricCard('Hours worked', fmtHours(emp.total_hours), 'blue', `${emp.rows.length} shift${emp.rows.length === 1 ? '' : 's'}`)}
        ${showWages ? metricCard('Wage', wageValue, 'green', wageDetail) : ''}
      </div>
    </section>`;
}

function setControlsDisabled(disabled) {
  els.select.disabled = disabled;
  if (els.downloadPdf) els.downloadPdf.disabled = disabled;
}

function downloadPdf() {
  if (!els.report || !els.report.innerHTML.trim()) {
    showStatus('Load a timesheet before downloading the PDF.', true);
    return;
  }

  document.body.classList.add('pdf-export-mode');
  window.print();
  setTimeout(() => document.body.classList.remove('pdf-export-mode'), 500);
}

function navigationLocked() {
  return isLoadingReport || Date.now() < window.__timesheetWeekNavLockedUntil;
}

if (!window.__timesheetPortalLoaded) {
  window.__timesheetPortalLoaded = true;

  els.select.addEventListener('change', () => {
    if (!navigationLocked()) loadReport(els.select.value);
  });

  if (els.downloadPdf) {
    els.downloadPdf.addEventListener('click', downloadPdf);
  }

  els.logout.addEventListener('click', async () => {
    try { await fetchJson('api/logout.php'); } catch (_) {}
    window.location.href = 'index.php';
  });

  init();
}
