let currentWeek = null;
let weeks = [];
let isLoadingReport = false;

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

const fmtMoney = value => Number(value || 0).toFixed(2);
const fmtHours = value => Number(value || 0).toFixed(2);

function fmtShortDate(value) {
  const text = String(value ?? '').trim();
  if (!text) return '—';
  if (!/^\d{4}-\d{2}-\d{2}$/.test(text)) return text;
  const date = new Date(text + 'T00:00:00');
  if (Number.isNaN(date.getTime())) return text;
  return date.toLocaleDateString(undefined, { day: '2-digit', month: 'short' });
}

function fmtClock(value) {
  const text = String(value ?? '').trim();
  if (!text) return '—';
  return /^\d{2}:\d{2}:\d{2}$/.test(text) ? text.slice(0, 5) : text;
}
function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>'"]/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  }[c]));
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
  const text = await res.text();
  let data = null;
  try { data = text ? JSON.parse(text) : null; }
  catch (_) { throw new Error(`Timesheet API returned invalid data (${res.status}).`); }
  if (!data) throw new Error(`Timesheet API returned an empty response (${res.status}).`);
  if (!data.success) throw new Error(data.error || 'Request failed');
  return data;
}

function labelFromDate(weekStart) {
  const start = new Date(weekStart + 'T00:00:00');
  const end = new Date(start);
  end.setDate(end.getDate() + 6);
  return `${start.toLocaleDateString(undefined, { day: '2-digit', month: 'short' })} - ${end.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })}`;
}
function renderWeekSelect() {
  const known = new Map(weeks.map(w => [w.value, w.label]));
  if (currentWeek && !known.has(currentWeek)) known.set(currentWeek, labelFromDate(currentWeek));
  const sorted = Array.from(known.entries()).sort((a, b) => b[0].localeCompare(a[0]));
  els.select.innerHTML = sorted.map(([value, label]) => `<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`).join('');
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
    if (insertBefore) els.select.insertBefore(option, insertBefore);
    else els.select.appendChild(option);
  }
  els.select.value = weekStart;
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

function rateDisplay(emp) {
  if (emp.pay_rate_varies) return 'Varies by date';
  return emp.pay_rate === null || emp.pay_rate === undefined ? 'Missing' : '$' + fmtMoney(emp.pay_rate) + '/hr';
}

function metric(label, value, detail = '') {
  return `<article class="timesheet-metric"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong>${detail ? `<small>${escapeHtml(detail)}</small>` : ''}</article>`;
}

function findEmployee(report, name) {
  return (report.employees || []).find(emp => String(emp.employee_name || '').toLowerCase() === String(name || '').toLowerCase());
}
function renderOverview(report, showPay) {
  const employeeCount = (report.employees || []).length;
  const storeCount = (report.store_summary || []).length;
  const items = [metric('Total hours', fmtHours(report.grand_total_hours), 'Selected week')];
  if (showPay) items.push(metric('Total wages', '$' + fmtMoney(report.grand_total_wage), 'Selected week'));
  items.push(metric('Employees', String(employeeCount), employeeCount === 1 ? 'Worked this week' : 'Worked this week'));
  if (report.is_super) items.push(metric('Stores', String(storeCount), 'With recorded shifts'));
  return `<section class="timesheet-metrics" aria-label="Weekly overview">${items.join('')}</section>`;
}

function renderStoreSummary(report, showPay) {
  if (!report.is_super || !Array.isArray(report.store_summary) || !report.store_summary.length) return '';
  const rows = report.store_summary.map(store => `
    <tr>
      <td><strong>${escapeHtml(store.store_name)}</strong></td>
      <td class="count">${escapeHtml(store.total_employees_worked)}</td>
      <td class="num">${fmtHours(store.total_hours_worked)}</td>
      ${showPay ? `<td class="num">$${fmtMoney(store.total_amount)}</td>` : ''}
    </tr>`).join('');
  return `
    <section class="report-card timesheet-section-card">
      <div class="timesheet-section-head"><div><h3>Store summary</h3><p>Weekly staffing, hours${showPay ? ' and wages' : ''} by store.</p></div></div>
      <div class="table-scroll"><table class="timesheet-summary-table timesheet-store-table"><thead><tr><th>Store</th><th class="count">Employees</th><th class="num">Hours</th>${showPay ? '<th class="num">Wages</th>' : ''}</tr></thead><tbody>${rows}</tbody></table></div>
    </section>`;
}
function renderShiftRows(emp, showPay) {
  return (emp.rows || []).map(row => `
    <tr class="compact-shift-row${row.is_late ? ' late-row' : ''}">
      <td class="shift-store" data-label="Store"><strong>${escapeHtml(row.store_name)}</strong></td>
      <td class="shift-clock" data-label="Clock in"><div class="clock-cell"><strong>${escapeHtml(fmtShortDate(row.in_date))} · ${escapeHtml(fmtClock(row.actual_in_time))}</strong><span>Rounded ${escapeHtml(fmtClock(row.rounded_in_time))}</span></div></td>
      <td class="shift-clock" data-label="Clock out"><div class="clock-cell"><strong>${escapeHtml(fmtShortDate(row.out_date))} · ${escapeHtml(fmtClock(row.actual_out_time))}</strong><span>Rounded ${escapeHtml(fmtClock(row.rounded_out_time))}</span></div></td>
      <td class="shift-hours num" data-label="Hours"><strong>${fmtHours(row.total_hours)}</strong></td>
      ${showPay ? `<td class="shift-wage num" data-label="Wage">$${fmtMoney(row.wage)}</td>` : ''}
    </tr>`).join('');
}

function employeeSummaryEntries(report) {
  const employees = report.employees || [];
  if (report.is_super && Array.isArray(report.employee_summary) && report.employee_summary.length) {
    return report.employee_summary.map(summary => ({ summary, emp: findEmployee(report, summary.employee_name) || {} }));
  }
  return employees.map(emp => ({
    summary: { employee_name: emp.employee_name, user_id: emp.user_id, total_hours: emp.total_hours, total_wage: emp.total_wage, missing_pay_rate: emp.pay_rate === null || emp.pay_rate === undefined },
    emp,
  }));
}

function renderEmployeeSummary(report, showPay) {
  const entries = employeeSummaryEntries(report);
  if (!entries.length) return '';
  const columnCount = showPay ? 6 : 4;
  const rows = entries.map(({ summary, emp }, index) => {
    const shiftCount = Array.isArray(emp.rows) ? emp.rows.length : 0;
    const rate = showPay ? rateDisplay(emp) : '';
    const detailId = `employee-shifts-${index}`;
    return `
      <tr class="employee-summary-row" data-detail-id="${detailId}" tabindex="0" role="button" aria-expanded="false" aria-controls="${detailId}" aria-label="Review shifts for ${escapeHtml(summary.employee_name)}">
        <td><span class="employee-summary-primary"><span class="employee-summary-name"><strong>${escapeHtml(summary.employee_name)}</strong>${summary.missing_pay_rate && showPay ? ' <span class="warn-pill">Missing rate</span>' : ''}</span><span class="employee-summary-chevron" aria-hidden="true">›</span></span></td>
        <td>${escapeHtml(summary.user_id || '\u2014')}</td>
        <td class="count">${shiftCount}</td>
        <td class="num">${fmtHours(summary.total_hours)}</td>
        ${showPay ? `<td class="num">$${fmtMoney(summary.total_wage)}</td><td class="num">${escapeHtml(rate)}</td>` : ''}
      </tr>
      <tr id="${detailId}" class="employee-shifts-row" hidden>
        <td class="employee-shifts-cell" colspan="${columnCount}"><div class="table-scroll compact-shifts-shell"><table class="compact-shift-table${showPay ? ' has-wages' : ''}"><thead><tr><th>Store</th><th>Clock in</th><th>Clock out</th><th class="num">Hours</th>${showPay ? '<th class="num">Wage</th>' : ''}</tr></thead><tbody>${renderShiftRows(emp, showPay)}</tbody></table></div></td>
      </tr>`;
  }).join('');
  return `<section class="report-card timesheet-section-card"><div class="timesheet-section-head"><div><h3>Employee summary</h3><p>Select an employee to review their shifts for the selected week.</p></div></div><div class="table-scroll"><table class="timesheet-summary-table employee-summary-table"><thead><tr><th>Employee</th><th>User ID</th><th class="count">Shifts</th><th class="num">Hours</th>${showPay ? '<th class="num">Wages</th><th class="num">Rate</th>' : ''}</tr></thead><tbody>${rows}</tbody></table></div></section>`;
}

function bindEmployeeSummaryRows() {
  const rows = Array.from(document.querySelectorAll('.employee-summary-row'));
  const details = Array.from(document.querySelectorAll('.employee-shifts-row'));

  const setFocusedEmployee = (row, open) => {
    const detail = document.getElementById(row.dataset.detailId);
    if (!detail) return;

    rows.forEach(candidate => {
      const focused = open && candidate === row;
      candidate.hidden = open && !focused;
      candidate.classList.toggle('is-expanded', focused);
      candidate.setAttribute('aria-expanded', focused ? 'true' : 'false');
    });
    details.forEach(candidate => { candidate.hidden = !(open && candidate === detail); });
  };

  rows.forEach(row => {
    const activate = () => setFocusedEmployee(row, row.getAttribute('aria-expanded') !== 'true');
    row.addEventListener('click', activate);
    row.addEventListener('keydown', event => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      activate();
    });
  });
}

function renderReport(report) {
  const titleText = report.is_super ? 'Timesheets' : 'My Timesheet';
  const showPay = report.payroll_visible !== false && report.show_wages !== false;
  els.title.textContent = titleText;
  els.subtitle.textContent = `${report.week_label} · ${report.is_super ? 'All permitted employees' : 'Your recorded shifts'}`;
  document.title = `MERDPOS · ${titleText}`;

  if (!Array.isArray(report.employees) || !report.employees.length) {
    els.report.innerHTML = `<div class="empty-card"><h3>No timesheet data for this week</h3><p>No completed shifts were found for ${escapeHtml(report.week_label)}.</p></div>`;
    return;
  }

  els.report.innerHTML = [
    renderOverview(report, showPay),
    renderStoreSummary(report, showPay),
    renderEmployeeSummary(report, showPay),
  ].join('');
  bindEmployeeSummaryRows();
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
  const summaryRows = Array.from(document.querySelectorAll('.employee-summary-row'));
  const shiftRows = Array.from(document.querySelectorAll('.employee-shifts-row'));
  summaryRows.forEach(row => {
    row.dataset.pdfWasHidden = row.hidden ? '1' : '0';
    row.dataset.pdfWasExpanded = row.getAttribute('aria-expanded') === 'true' ? '1' : '0';
    row.hidden = false;
    row.setAttribute('aria-expanded', 'false');
  });
  shiftRows.forEach(detail => {
    detail.dataset.pdfWasHidden = detail.hidden ? '1' : '0';
    detail.hidden = false;
  });
  window.print();
  setTimeout(() => {
    document.body.classList.remove('pdf-export-mode');
    summaryRows.forEach(row => {
      row.hidden = row.dataset.pdfWasHidden === '1';
      row.setAttribute('aria-expanded', row.dataset.pdfWasExpanded === '1' ? 'true' : 'false');
      delete row.dataset.pdfWasHidden;
      delete row.dataset.pdfWasExpanded;
    });
    shiftRows.forEach(detail => {
      detail.hidden = detail.dataset.pdfWasHidden === '1';
      delete detail.dataset.pdfWasHidden;
    });
  }, 500);
}
function navigationLocked() {
  return isLoadingReport || Date.now() < window.__timesheetWeekNavLockedUntil;
}

if (!window.__timesheetPortalLoaded) {
  window.__timesheetPortalLoaded = true;

  els.select.addEventListener('change', () => {
    if (!navigationLocked()) loadReport(els.select.value);
  });

  if (els.downloadPdf) els.downloadPdf.addEventListener('click', downloadPdf);

  els.logout.addEventListener('click', async () => {
    try { await fetchJson('api/logout.php'); } catch (_) {}
    window.location.href = 'index.php';
  });

  init();
}
