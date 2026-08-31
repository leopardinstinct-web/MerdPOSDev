(function () {
  'use strict';

  const boundRoots = new WeakSet();
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
  const num = value => Number.isFinite(Number(value)) ? Number(value) : 0;
  const clamp = (value,min,max) => Math.max(min,Math.min(max,value));

  function normalizeColumn(column) {
    if (typeof column === 'string') return { key:column, label:column, type:'string' };
    const key = String(column?.key || '').trim();
    if (!key) throw new Error('Analytics columns require a key.');
    const type = ['string','number','date'].includes(column?.type) ? column.type : 'string';
    return { key, label:String(column?.label || key), type };
  }

  function coerce(value,type) {
    if (type === 'number') return num(value);
    if (type === 'date') return String(value ?? '');
    return String(value ?? '');
  }

  function dataset(id, columns, rows = [], meta = {}) {
    const cleanColumns = (columns || []).map(normalizeColumn);
    const cleanRows = (Array.isArray(rows) ? rows : []).map(source => {
      const row = {};
      cleanColumns.forEach(column => { row[column.key] = coerce(source?.[column.key], column.type); });
      return row;
    });
    return { id:String(id || 'dataset'), columns:cleanColumns, rows:cleanRows, meta:{...meta} };
  }

  function view(table, options = {}) {
    let rows = Array.isArray(table?.rows) ? table.rows.slice() : [];
    if (typeof options.filter === 'function') rows = rows.filter(options.filter);
    if (typeof options.sort === 'function') rows.sort(options.sort);
    if (Number.isInteger(options.limit) && options.limit >= 0) rows = rows.slice(0, options.limit);
    return dataset(table?.id || 'dataset', table?.columns || [], rows, table?.meta || {});
  }

  function infoAttr(label, value, payload = {}) {
    return esc(JSON.stringify({ label:String(label ?? ''), value:String(value ?? ''), payload:payload || {} }));
  }

  function empty(message = 'No data yet.') {
    return `<div class="merd-chart-empty">${esc(message)}</div>`;
  }

  function bar(table, options = {}) {
    const rows = Array.isArray(table?.rows) ? table.rows : [];
    if (!rows.length) return empty(options.emptyMessage);
    const labelKey = options.labelKey || table.columns?.[0]?.key;
    const valueKey = options.valueKey || table.columns?.[1]?.key;
    const format = typeof options.formatValue === 'function' ? options.formatValue : value => String(value);
    const payload = typeof options.payload === 'function' ? options.payload : () => ({});
    const width = 640, left = 176, right = 108, top = 14, rowHeight = 34;
    const height = Math.max(86, top * 2 + rows.length * rowHeight);
    const plot = width - left - right;
    const max = Math.max(1, ...rows.map(row => num(row[valueKey])));
    const groups = rows.map((row,index) => {
      const value = num(row[valueKey]), barWidth = clamp(value / max * plot, value > 0 ? 4 : 0, plot);
      const y = top + index * rowHeight, label = row[labelKey], display = format(value,row);
      return `<g class="merd-chart-item" role="button" tabindex="0" data-merd-chart-info="${infoAttr(label,display,payload(row,index))}" aria-label="${esc(`${label}: ${display}`)}"><text class="merd-chart-axis-label" x="${left-12}" y="${y+18}" text-anchor="end">${esc(label)}</text><rect class="merd-chart-track" x="${left}" y="${y+7}" width="${plot}" height="12" rx="6"></rect><rect class="merd-chart-bar" x="${left}" y="${y+7}" width="${barWidth.toFixed(1)}" height="12" rx="6"></rect><text class="merd-chart-value" x="${left+plot+12}" y="${y+18}">${esc(display)}</text></g>`;
    }).join('');
    return `<div class="merd-chart-shell" data-chart-id="${esc(table.id)}"><svg class="merd-chart-svg merd-chart-svg-bar" viewBox="0 0 ${width} ${height}" role="img" aria-label="${esc(options.ariaLabel || table.meta?.label || table.id)}">${groups}</svg><div class="merd-chart-selection" aria-live="polite"></div></div>`;
  }

  function line(table, options = {}) {
    const rows = Array.isArray(table?.rows) ? table.rows : [];
    if (!rows.length) return empty(options.emptyMessage || 'No trend data yet.');
    const labelKey = options.labelKey || table.columns?.[0]?.key;
    const valueKey = options.valueKey || table.columns?.[1]?.key;
    const format = typeof options.formatValue === 'function' ? options.formatValue : value => String(value);
    const payload = typeof options.payload === 'function' ? options.payload : () => ({});
    const width = 640, height = 220, left = 32, right = 28, top = 24, bottom = 46;
    const values = rows.map(row => num(row[valueKey])), max = Math.max(...values), min = Math.min(...values);
    const range = Math.max(1, max - min), plotW = width-left-right, plotH = height-top-bottom;
    const coords = rows.map((row,index) => ({
      x:left + (rows.length === 1 ? plotW / 2 : index * plotW / (rows.length - 1)),
      y:top + (max - num(row[valueKey])) * plotH / range,
      row,index
    }));
    const points = coords.map(point => `${point.x.toFixed(1)},${point.y.toFixed(1)}`).join(' ');
    const labelStep = Math.max(1, Math.ceil(rows.length / 6));
    const nodes = coords.map(point => {
      const label = point.row[labelKey], display = format(point.row[valueKey],point.row);
      const showLabel = rows.length <= 9 || point.index === 0 || point.index === rows.length - 1 || point.index % labelStep === 0;
      return `<g class="merd-chart-item merd-chart-point" role="button" tabindex="0" data-merd-chart-info="${infoAttr(label,display,payload(point.row,point.index))}" aria-label="${esc(`${label}: ${display}`)}"><circle cx="${point.x.toFixed(1)}" cy="${point.y.toFixed(1)}" r="6"></circle>${showLabel?`<text x="${point.x.toFixed(1)}" y="${height-18}" text-anchor="middle">${esc(label)}</text>`:''}</g>`;
    }).join('');
    return `<div class="merd-chart-shell" data-chart-id="${esc(table.id)}"><svg class="merd-chart-svg merd-chart-svg-line" viewBox="0 0 ${width} ${height}" role="img" aria-label="${esc(options.ariaLabel || table.meta?.label || table.id)}"><line class="merd-chart-gridline" x1="${left}" y1="${top+plotH}" x2="${width-right}" y2="${top+plotH}"></line><polyline class="merd-chart-line" points="${points}"></polyline>${nodes}</svg><div class="merd-chart-selection" aria-live="polite"></div></div>`;
  }

  function donut(table, options = {}) {
    const rows = Array.isArray(table?.rows) ? table.rows : [];
    if (!rows.length) return empty(options.emptyMessage);
    const labelKey = options.labelKey || table.columns?.[0]?.key;
    const valueKey = options.valueKey || table.columns?.[1]?.key;
    const format = typeof options.formatValue === 'function' ? options.formatValue : value => String(value);
    const payload = typeof options.payload === 'function' ? options.payload : () => ({});
    const total = rows.reduce((sum,row) => sum + Math.max(0,num(row[valueKey])),0);
    if (total <= 0) return empty(options.emptyMessage || 'No values to chart.');
    const radius = 54, circumference = 2 * Math.PI * radius;
    let offset = 0;
    const segments = rows.map((row,index) => {
      const value = Math.max(0,num(row[valueKey])), length = value / total * circumference;
      const label = row[labelKey], display = format(value,row), start = offset;
      offset += length;
      return `<g class="merd-chart-item merd-chart-segment series-${(index%5)+1}" role="button" tabindex="0" data-merd-chart-info="${infoAttr(label,display,payload(row,index))}" aria-label="${esc(`${label}: ${display}`)}"><circle cx="72" cy="72" r="${radius}" pathLength="${circumference.toFixed(3)}" stroke-dasharray="${length.toFixed(3)} ${(circumference-length).toFixed(3)}" stroke-dashoffset="${(-start).toFixed(3)}"></circle></g>`;
    }).join('');
    const legend = rows.map((row,index) => `<div class="merd-chart-legend-row"><span class="merd-chart-legend-dot series-${(index%5)+1}"></span><span>${esc(row[labelKey])}</span><strong>${esc(format(row[valueKey],row))}</strong></div>`).join('');
    return `<div class="merd-chart-shell merd-chart-donut-layout" data-chart-id="${esc(table.id)}"><svg class="merd-chart-svg merd-chart-svg-donut" viewBox="0 0 144 144" role="img" aria-label="${esc(options.ariaLabel || table.meta?.label || table.id)}"><circle class="merd-chart-donut-track" cx="72" cy="72" r="${radius}"></circle>${segments}</svg><div class="merd-chart-legend">${legend}</div><div class="merd-chart-selection" aria-live="polite"></div></div>`;
  }

  function activate(item) {
    let info = null;
    try { info = JSON.parse(item.getAttribute('data-merd-chart-info') || '{}'); } catch (_) { info = {}; }
    const shell = item.closest('.merd-chart-shell');
    shell?.querySelectorAll('.merd-chart-item[aria-pressed="true"]').forEach(node => node.removeAttribute('aria-pressed'));
    item.setAttribute('aria-pressed','true');
    const selection = shell?.querySelector('.merd-chart-selection');
    if (selection) selection.textContent = info.label ? `${info.label}: ${info.value}` : '';
    item.dispatchEvent(new CustomEvent('merdpos-chart-select', {
      bubbles:true,
      detail:{ chartId:shell?.dataset.chartId || '', label:info.label || '', value:info.value || '', payload:info.payload || {} }
    }));
  }

  function bind(root = document) {
    if (!root || boundRoots.has(root)) return root;
    boundRoots.add(root);
    root.addEventListener('click', event => {
      const item = event.target.closest?.('[data-merd-chart-info]');
      if (item && root.contains(item)) activate(item);
    });
    root.addEventListener('keydown', event => {
      if (!['Enter',' '].includes(event.key)) return;
      const item = event.target.closest?.('[data-merd-chart-info]');
      if (!item || !root.contains(item)) return;
      event.preventDefault(); activate(item);
    });
    return root;
  }

  window.MERDPOSAnalytics = Object.freeze({ dataset, view, bar, line, donut, bind });
})();
