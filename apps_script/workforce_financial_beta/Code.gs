const BETA_ROSTER_ID = '1JyWMrqyRq3nh-uTpaVhd_XNyfeRFKrdQ09xMxRsGOQA';
const BETA_FINANCIAL_ID = '1f-8hWJcz6pH_7x9dv-C3KHu4tt4Lp1_jFKM-z5ZhXwQ';
const BETA_TZ = 'Australia/Sydney';

/** Deploy as a separate web app. Store SYNC_SECRET in Script Properties. */
function doPost(e) {
  try {
    const body = JSON.parse((e.postData && e.postData.contents) || '{}');
    validateEnvelope_(body);
    const lock = LockService.getScriptLock();
    if (!lock.tryLock(30000)) throw new Error('sync_busy');
    try {
      ensureBetaSheets_();
      const results = body.events.map(applyEventSafely_);
      return json_({ success: true, results: results });
    } finally {
      lock.releaseLock();
    }
  } catch (error) {
    return json_({ success: false, error: String(error && error.message || error) });
  }
}

function validateEnvelope_(body) {
  const secret = PropertiesService.getScriptProperties().getProperty('SYNC_SECRET');
  if (!secret) throw new Error('sync_not_configured');
  if (!Number.isInteger(body.ts) || Math.abs(Math.floor(Date.now() / 1000) - body.ts) > 300) throw new Error('expired_request');
  if (!/^[A-Za-z0-9_-]{16,100}$/.test(String(body.nonce || ''))) throw new Error('invalid_nonce');
  if (!Array.isArray(body.events) || body.events.length < 1 || body.events.length > 50) throw new Error('invalid_events');
  const message = body.ts + '.' + body.nonce + '.' + JSON.stringify(body.events);
  const expected = Utilities.computeHmacSha256Signature(message, secret)
    .map(function (b) { return ('0' + (b & 255).toString(16)).slice(-2); }).join('');
  if (expected !== String(body.signature || '').toLowerCase()) throw new Error('invalid_signature');
  const cache = CacheService.getScriptCache();
  if (cache.get('nonce:' + body.nonce)) throw new Error('replayed_request');
  cache.put('nonce:' + body.nonce, '1', 600);
}

function ensureBetaSheets_() {
  const roster = SpreadsheetApp.openById(BETA_ROSTER_ID);
  setHeaderIfBlank_(roster.getSheetByName('Time Sheet'),6,'BETA_EVENT_ID');
  ensureSheet_(roster, 'Beta Sync Ledger', ['EVENT_ID','EVENT_TYPE','APPLIED_AT','RESULT']);
  ensureSheet_(roster, 'Shift Disputes', [
    'DISPUTE_ID','SHIFT_ID','EMPLOYEE_NAME','TYPE','REQUESTED_IN_UTC','REQUESTED_OUT_UTC',
    'REASON','STATUS','SUBMITTED_AT_UTC','DECIDED_BY','DECIDED_AT_UTC','DECISION_NOTE','EVENT_ID'
  ]);
  const financial = SpreadsheetApp.openById(BETA_FINANCIAL_ID);
  setHeaderIfBlank_(financial.getSheetByName('General Ledger'),7,'BETA_EVENT_ID');
  setHeaderIfBlank_(financial.getSheetByName('zReport Ledger'),6,'BETA_EVENT_ID');
  ensureSheet_(financial, 'Beta Sync Ledger', ['EVENT_ID','EVENT_TYPE','APPLIED_AT','RESULT']);
}

function setHeaderIfBlank_(sheet,column,label){if(sheet&&sheet.getRange(1,column).isBlank())sheet.getRange(1,column).setValue(label).setFontWeight('bold');}

function ensureSheet_(book, name, headers) {
  let sheet = book.getSheetByName(name);
  if (!sheet) sheet = book.insertSheet(name);
  if (sheet.getLastRow() === 0) {
    sheet.getRange(1, 1, 1, headers.length).setValues([headers]).setFontWeight('bold').setBackground('#0b63b6').setFontColor('#ffffff');
    sheet.setFrozenRows(1);
  }
  return sheet;
}

function applyEventSafely_(event) {
  const id = String(event.event_id || '');
  const type = String(event.event_type || '');
  if (!/^[0-9a-f-]{36}$/i.test(id)) return { event_id: id, success: false, error: 'invalid_event_id' };
  const ledgerBook = type === 'financial_submission'
    ? SpreadsheetApp.openById(BETA_FINANCIAL_ID) : SpreadsheetApp.openById(BETA_ROSTER_ID);
  const ledger = ledgerBook.getSheetByName('Beta Sync Ledger');
  if (ledger.getLastRow() > 1) {
    const hit = ledger.getRange(2, 1, ledger.getLastRow() - 1, 1).createTextFinder(id).matchEntireCell(true).findNext();
    if (hit) return { event_id: id, success: true, duplicate: true };
  }
  try {
    if (targetEventExists_(type, id)) {
      ledger.appendRow([id, type, new Date(), 'RECOVERED']);
      return { event_id: id, success: true, duplicate: true };
    }
    applyEvent_(type, event.payload || {}, id);
    ledger.appendRow([id, type, new Date(), 'OK']);
    return { event_id: id, success: true, duplicate: false };
  } catch (error) {
    return { event_id: id, success: false, error: String(error && error.message || error).slice(0, 500) };
  }
}

function applyEvent_(type, payload, eventId) {
  if (type === 'attendance_event') return appendAttendance_(payload, eventId);
  if (type === 'employee_log_store') return setLogStore_(payload);
  if (type === 'attendance_correction') return correctAttendance_(payload);
  if (type === 'attendance_correction_in') return correctAttendanceIn_(payload);
  if (type === 'attendance_delete') return deleteAttendance_(payload,eventId);
  if (type === 'attendance_security_flag') return appendSecurityFlag_(payload, eventId);
  if (type === 'dispute_audit') return appendDispute_(payload, eventId);
  if (type === 'financial_submission') return appendFinancial_(payload, eventId);
  throw new Error('unsupported_event_type');
}

function appendSecurityFlag_(p,eventId){
  const book=SpreadsheetApp.openById(BETA_ROSTER_ID),sheet=ensureSheet_(book,'Attendance Security Flags',['FLAG_ID','EMPLOYEE_NAME','OPEN_SHIFT_ID','ATTEMPTED_STORE','REASON','STATUS','CREATED_AT_UTC','RESOLVED_BY','RESOLVED_AT_UTC','NOTE','EVENT_ID']);
  sheet.appendRow([p.flag_id||'',p.employee_name||'',p.open_shift_id||'',p.attempted_store_name||'',p.reason||'',p.status||'open',p.created_at_utc||'',p.resolved_by||'',p.resolved_at_utc||'',p.resolution_note||'',eventId]);
}

function deleteAttendance_(p,eventId){
  const book=SpreadsheetApp.openById(BETA_ROSTER_ID),tombstones=ensureSheet_(book,'Attendance Deletion Ledger',['EVENT_ID','SHIFT_ID','EMPLOYEE_NAME','STORE_NAME','STATUS','CREATED_AT']);
  let marker=null;
  if(tombstones.getLastRow()>1) marker=tombstones.getRange(2,1,tombstones.getLastRow()-1,1).createTextFinder(eventId).matchEntireCell(true).findNext();
  if(!marker){tombstones.appendRow([eventId,p.shift_id||'',p.employee_name||'',p.store_name||'','PREPARED',new Date()]);marker=tombstones.getRange(tombstones.getLastRow(),1);}
  const sheet=book.getSheetByName('Time Sheet'),values=sheet.getDataRange().getValues(),targets=[];
  const inPart=localParts_(p.clock_in_at_utc),outPart=p.clock_out_at_utc?localParts_(p.clock_out_at_utc):null;
  for(let i=values.length-1;i>=1;i--){const row=values[i],type=String(row[2]).trim(),matchTime=type==='IN'?inPart:(type==='OUT'?outPart:null);
    if(matchTime&&String(row[0]).trim()===p.employee_name&&String(row[1]).trim()===p.store_name&&formatSheetDate_(row[3])===matchTime.date&&formatSheetTime_(row[4])===matchTime.time) targets.push(i+1);
  }
  targets.sort(function(a,b){return b-a;}).forEach(function(row){sheet.deleteRow(row);});
  tombstones.getRange(marker.getRow(),5).setValue('DONE');
}

function appendAttendance_(p, eventId) {
  const sheet = SpreadsheetApp.openById(BETA_ROSTER_ID).getSheetByName('Time Sheet');
  const stamp = parseUtc_(p.occurred_at_utc);
  sheet.appendRow([
    required_(p.employee_name), required_(p.store_name), /^(IN|OUT)$/.test(p.log_type) ? p.log_type : required_(''),
    Utilities.formatDate(stamp, BETA_TZ, 'yyyy-MM-dd'), Utilities.formatDate(stamp, BETA_TZ, 'HH:mm:ss'), eventId
  ]);
}

function correctAttendanceIn_(p) {
  const sheet = SpreadsheetApp.openById(BETA_ROSTER_ID).getSheetByName('Time Sheet');
  const values = sheet.getDataRange().getValues(), oldIn = localParts_(p.old_clock_in_at_utc), next = localParts_(p.new_clock_in_at_utc);
  let already=false;
  for (let i = values.length - 1; i >= 1; i--) {
    const row=values[i];
    if (String(row[0]).trim()===p.employee_name && String(row[1]).trim()===p.store_name && String(row[2]).trim()==='IN'
      && formatSheetDate_(row[3])===oldIn.date && formatSheetTime_(row[4])===oldIn.time) {
      sheet.getRange(i+1,4,1,2).setValues([[next.date,next.time]]); return;
    }
    if(String(row[0]).trim()===p.employee_name&&String(row[1]).trim()===p.store_name&&String(row[2]).trim()==='IN'&&formatSheetDate_(row[3])===next.date&&formatSheetTime_(row[4])===next.time) already=true;
  }
  if(already)return;
  throw new Error('attendance_in_row_not_found');
}

function setLogStore_(p) {
  const sheet = SpreadsheetApp.openById(BETA_ROSTER_ID).getSheetByName('Employee Setup');
  const values = sheet.getDataRange().getValues();
  const headerRow = values.findIndex(function (row) { return row.indexOf('NAME') >= 0 && row.indexOf('LOG_STORE') >= 0; });
  if (headerRow < 0) throw new Error('employee_setup_headers_missing');
  const nameCol = values[headerRow].indexOf('NAME');
  const storeCol = values[headerRow].indexOf('LOG_STORE');
  const target = values.slice(headerRow + 1).findIndex(function (row) { return String(row[nameCol]).trim() === String(p.employee_name).trim(); });
  if (target < 0) throw new Error('employee_not_found');
  sheet.getRange(headerRow + 2 + target, storeCol + 1).setValue(String(p.log_store || ''));
}

function correctAttendance_(p) {
  const sheet = SpreadsheetApp.openById(BETA_ROSTER_ID).getSheetByName('Time Sheet');
  const values = sheet.getDataRange().getValues();
  const oldIn = localParts_(p.old_clock_in_at_utc), oldOut = localParts_(p.old_clock_out_at_utc);
  const newIn = localParts_(p.new_clock_in_at_utc), newOut = localParts_(p.new_clock_out_at_utc);
  let inDone = false, outDone = false;
  for (let i = values.length - 1; i >= 1 && !(inDone && outDone); i--) {
    const row = values[i], name = String(row[0]).trim(), store = String(row[1]).trim(), type = String(row[2]).trim();
    const date = formatSheetDate_(row[3]), time = formatSheetTime_(row[4]);
    if (name !== p.employee_name || store !== p.store_name) continue;
    if (!inDone && type === 'IN' && date === oldIn.date && time === oldIn.time) {
      sheet.getRange(i + 1, 4, 1, 2).setValues([[newIn.date, newIn.time]]); inDone = true;
    }
    if (!outDone && type === 'OUT' && date === oldOut.date && time === oldOut.time) {
      sheet.getRange(i + 1, 4, 1, 2).setValues([[newOut.date, newOut.time]]); outDone = true;
    }
  }
  if(!inDone||!outDone){
    const newInFound=values.some(function(row){return String(row[0]).trim()===p.employee_name&&String(row[1]).trim()===p.store_name&&String(row[2]).trim()==='IN'&&formatSheetDate_(row[3])===newIn.date&&formatSheetTime_(row[4])===newIn.time;});
    const newOutFound=values.some(function(row){return String(row[0]).trim()===p.employee_name&&String(row[1]).trim()===p.store_name&&String(row[2]).trim()==='OUT'&&formatSheetDate_(row[3])===newOut.date&&formatSheetTime_(row[4])===newOut.time;});
    if(!(newInFound&&newOutFound))throw new Error('attendance_rows_not_found');
  }
}

function appendDispute_(p, eventId) {
  const sheet = SpreadsheetApp.openById(BETA_ROSTER_ID).getSheetByName('Shift Disputes');
  sheet.appendRow([
    p.dispute_id || '', p.shift_id || '', p.employee_name || '', p.type || '',
    p.requested_clock_in_at_utc || '', p.requested_clock_out_at_utc || '', p.reason || '', p.status || '',
    p.submitted_at_utc || '', p.decided_by || '', p.decided_at_utc || '', p.decision_note || '', eventId
  ]);
}

function appendFinancial_(p, eventId) {
  const book = SpreadsheetApp.openById(BETA_FINANCIAL_ID);
  const type = required_(p.submission_type), data = p.payload || {}, store = required_(p.store_name);
  const date = businessDate_(p.business_date);
  if (type === 'open_day') {
    const registerOpening = Number(data.register_opening), pettyOpening = Number(data.petty_cash_opening);
    if (!Number.isFinite(registerOpening) || registerOpening < 0 || !Number.isFinite(pettyOpening) || pettyOpening < 0) throw new Error('invalid_opening');
    book.getSheetByName('General Ledger').getRange(book.getSheetByName('General Ledger').getLastRow() + 1, 1, 2, 7).setValues([
      [date, store, 'Register', 'OPENING', 'OPENING', registerOpening, eventId],
      [date, store, 'Petty Cash', 'OPENING', 'OPENING', pettyOpening, eventId]
    ]);
    return;
  }
  if (type === 'cash_in' || type === 'cash_out') {
    const transactions = Array.isArray(data.transactions) ? data.transactions : [];
    if (!transactions.length) throw new Error('financial_rows_missing');
    const rows = transactions.map(function (tx) {
      const amount = Number(tx.amount);
      if (!Number.isFinite(amount) || amount <= 0) throw new Error('invalid_amount');
      return [date, store, required_(tx.account), type === 'cash_in' ? 'IN' : 'OUT', required_(tx.head), amount, eventId];
    });
    const sheet = book.getSheetByName('General Ledger');
    sheet.getRange(sheet.getLastRow() + 1, 1, rows.length, 7).setValues(rows);
    return;
  }
  if (type === 'z_report') return appendZReport_(book, date, store, p.employee_name || 'Unknown Employee', data, eventId);
  throw new Error('invalid_financial_type');
}

function appendZReport_(book, date, store, employee, data, eventId) {
  const total = Number(data.register_total), petty = Number(data.petty_cash_addin || 0);
  if (!Number.isFinite(total) || total < 0 || !Number.isFinite(petty) || petty < 0) throw new Error('invalid_z_report');
  const general = book.getSheetByName('General Ledger'), z = book.getSheetByName('zReport Ledger');
  const calculated = data._calculated || null;
  const ledger = general.getDataRange().getValues();
  const openingRegister = lastUnclosedOpening_(ledger, store, 'Register');
  const openingPetty = lastUnclosedOpening_(ledger, store, 'Petty Cash');
  let registerIn = 0, registerOut = 0, pettyBalance = openingPetty.amount;
  ledger.slice(1).forEach(function (row) {
    if (String(row[1]) !== store) return;
    const rowDate = formatSheetDate_(row[0]);
    if (rowDate === formatSheetDate_(date) && row[2] === 'Register') {
      if (row[3] === 'IN') registerIn += Number(row[5] || 0);
      if (row[3] === 'OUT') registerOut += Number(row[5] || 0);
    }
    if (formatSheetDate_(row[0]) === formatSheetDate_(openingPetty.date) && row[2] === 'Petty Cash') {
      if (row[3] === 'IN') pettyBalance += Number(row[5] || 0);
      if (row[3] === 'OUT') pettyBalance -= Number(row[5] || 0);
    }
  });
  pettyBalance += petty;
  const sales = calculated ? Number(calculated.sales_actual) : total - openingRegister.amount - registerIn + registerOut + petty;
  if (calculated) pettyBalance = Number(calculated.petty_cash_closing);
  if (!Number.isFinite(sales) || sales < 0 || !Number.isFinite(pettyBalance) || pettyBalance < 0) throw new Error('invalid_calculated_closing');
  const next = calculated ? businessDate_(calculated.next_business_date) : new Date(date.getTime());
  if (!calculated) next.setDate(next.getDate() + 1);
  const zExists=z.getLastRow()>1&&!!z.getRange(2,6,z.getLastRow()-1,1).createTextFinder(eventId).matchEntireCell(true).findNext();
  if(!zExists)z.appendRow([date, store, Array.isArray(data.denominations) ? data.denominations.join(', ') : String(data.denominations || ''), total, petty, eventId]);
  const rows = [
    [date,store,'Register','IN','Sales (ACTUAL)',sales,eventId], [date,store,'Register','OUT','Cash Out ('+employee+')',petty,eventId],
    [date,store,'Petty Cash','IN','Cash In ('+employee+')',petty,eventId], [date,store,'Register','CLOSING','CLOSING',total,eventId],
    [openingPetty.date || date,store,'Petty Cash','CLOSING','CLOSING',pettyBalance,eventId], [next,store,'Register','OPENING','OPENING',total,eventId],
    [next,store,'Petty Cash','OPENING','OPENING',pettyBalance,eventId]
  ];
  general.getRange(general.getLastRow() + 1, 1, rows.length, 7).setValues(rows);
}

function targetEventExists_(type, eventId) {
  let sheet, column;
  if (type === 'attendance_event') { sheet=SpreadsheetApp.openById(BETA_ROSTER_ID).getSheetByName('Time Sheet'); column=6; }
  else if (type === 'dispute_audit') { sheet=SpreadsheetApp.openById(BETA_ROSTER_ID).getSheetByName('Shift Disputes'); column=13; }
  else if (type === 'financial_submission') { sheet=SpreadsheetApp.openById(BETA_FINANCIAL_ID).getSheetByName('General Ledger'); column=7; }
  else if (type === 'attendance_security_flag') { sheet=SpreadsheetApp.openById(BETA_ROSTER_ID).getSheetByName('Attendance Security Flags'); column=11; }
  else return false;
  if (!sheet || sheet.getLastRow() < 2) return false;
  return !!sheet.getRange(2,column,sheet.getLastRow()-1,1).createTextFinder(eventId).matchEntireCell(true).findNext();
}

function lastUnclosedOpening_(rows, store, account) {
  let result = { date: null, amount: 0 };
  rows.slice(1).forEach(function (row) {
    if (row[1] !== store || row[2] !== account || row[3] !== 'OPENING') return;
    const key = formatSheetDate_(row[0]);
    const closed = rows.some(function (candidate) {
      return candidate[1] === store && candidate[2] === account && candidate[3] === 'CLOSING' && formatSheetDate_(candidate[0]) === key;
    });
    if (!closed) result = { date: row[0], amount: Number(row[5] || 0) };
  });
  return result;
}

function parseUtc_(text) { const d = new Date(String(text).replace(' ', 'T') + 'Z'); if (isNaN(d.getTime())) throw new Error('invalid_datetime'); return d; }
function localParts_(text) { const d = parseUtc_(text); return { date: Utilities.formatDate(d,BETA_TZ,'yyyy-MM-dd'), time: Utilities.formatDate(d,BETA_TZ,'HH:mm:ss') }; }
function formatSheetDate_(v) { return v instanceof Date ? Utilities.formatDate(v,BETA_TZ,'yyyy-MM-dd') : String(v).trim().replace(/^(\d{2})\/(\d{2})\/(\d{4})$/, '$3-$2-$1'); }
function formatSheetTime_(v) { return v instanceof Date ? Utilities.formatDate(v,BETA_TZ,'HH:mm:ss') : String(v).trim(); }
function businessDate_(v) { const d = new Date(String(v) + 'T12:00:00+10:00'); if (isNaN(d.getTime())) throw new Error('invalid_business_date'); return d; }
function required_(v) { const s=String(v == null ? '' : v).trim(); if (!s) throw new Error('required_value_missing'); return s; }
function json_(value) { return ContentService.createTextOutput(JSON.stringify(value)).setMimeType(ContentService.MimeType.JSON); }
