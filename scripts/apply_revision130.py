from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[1]
PORTAL=ROOT/'namecheap_beta_live'/'timesheet_portal'


def read(path):
    return path.read_text(encoding='utf-8-sig')

def write(path,text):
    path.write_text(text,encoding='utf-8')

def replace_once(text,old,new,label):
    count=text.count(old)
    if count!=1:
        raise SystemExit(f'{label}: expected one anchor, found {count}')
    return text.replace(old,new,1)

def regex_once(text,pattern,repl,label):
    out,count=re.subn(pattern,repl,text,count=1,flags=re.S)
    if count!=1:
        raise SystemExit(f'{label}: expected one regex anchor, found {count}')
    return out

# 1) Restore canonical product accent semantics to cyan while preserving all five brand masters.
p=PORTAL/'assets'/'design-tokens.css'; t=read(p)
replacements={
'--color-brand-primary: var(--color-brand-violet);':'--color-brand-primary: var(--color-brand-cyan);',
'--color-brand-primary-hover: color-mix(in srgb, var(--color-brand-violet) 88%, var(--color-brand-navy));':'--color-brand-primary-hover: color-mix(in srgb, var(--color-brand-cyan) 88%, var(--color-brand-navy));',
'--color-brand-primary-active: color-mix(in srgb, var(--color-brand-violet) 76%, var(--color-brand-navy));':'--color-brand-primary-active: color-mix(in srgb, var(--color-brand-cyan) 76%, var(--color-brand-navy));',
'--color-surface-selected: color-mix(in srgb, var(--color-brand-violet) 8%, var(--color-brand-white));':'--color-surface-selected: color-mix(in srgb, var(--color-brand-cyan) 8%, var(--color-brand-white));',
'--color-focus: var(--color-brand-violet);':'--color-focus: var(--color-brand-cyan);',
'--color-focus-soft: color-mix(in srgb, var(--color-brand-violet) 22%, transparent);':'--color-focus-soft: color-mix(in srgb, var(--color-brand-cyan) 22%, transparent);',
'--color-nav-active: var(--color-brand-violet);':'--color-nav-active: var(--color-brand-cyan);',
'--color-nav-sub-active: color-mix(in srgb, var(--color-brand-violet) 38%, var(--color-brand-navy));':'--color-nav-sub-active: color-mix(in srgb, var(--color-brand-cyan) 38%, var(--color-brand-navy));',
'--color-chart-1: var(--color-brand-violet);\n  --color-chart-2: var(--color-brand-cyan);':'--color-chart-1: var(--color-brand-cyan);\n  --color-chart-2: var(--color-brand-violet);',
'--shadow-action: 0 .5rem 1.25rem color-mix(in srgb, var(--color-brand-violet) 20%, transparent);':'--shadow-action: 0 .5rem 1.25rem color-mix(in srgb, var(--color-brand-cyan) 20%, transparent);',
'--color-surface-selected: color-mix(in srgb, var(--color-brand-violet) 28%, var(--color-brand-navy));':'--color-surface-selected: color-mix(in srgb, var(--color-brand-cyan) 28%, var(--color-brand-navy));',
'--color-focus-soft: color-mix(in srgb, var(--color-brand-violet) 30%, transparent);':'--color-focus-soft: color-mix(in srgb, var(--color-brand-cyan) 30%, transparent);',
}
for old,new in replacements.items():
    if old not in t: raise SystemExit(f'design tokens anchor missing: {old}')
    t=t.replace(old,new)
write(p,t)

# 2) Shared alignment owner: dialog titles/actions and directory title/action rows.
p=PORTAL/'assets'/'design-system.css'; t=read(p)
block='''\n\n/* DS130 recurrent header-alignment guard: headings and actions share one control row. */\n.merd-shell :where(.dialog-heading, .dialog-head, .admin-dialog-header) {\n  align-items: center;\n}\n.merd-shell :where(.dialog-heading, .dialog-head, .admin-dialog-header) > :where(h1,h2,h3,strong) {\n  min-height: var(--size-icon-action);\n  display: flex;\n  align-items: center;\n}\n.merd-shell .directory-card > .directory-toolbar > :first-child {\n  min-height: var(--size-icon-action);\n  display: flex;\n  align-items: center;\n}\n.merd-shell .directory-card > .directory-toolbar > :first-child > :where(h1,h2,h3,.ui-page-title) {\n  min-height: var(--size-icon-action);\n  display: flex;\n  align-items: center;\n}\n.merd-shell .directory-card > .directory-toolbar > :is(.directory-actions,.merd-action-cluster,.dev-store-actions,.directory-toolbar-actions) {\n  display: flex;\n  align-items: center;\n}\n.merd-shell #storeProfileFields {\n  display: contents;\n}\n'''
if 'DS130 recurrent header-alignment guard' not in t: t += block
write(p,t)

# 3) Dashboard title belongs to the dashboard builder itself.
p=PORTAL/'assets'/'dashboard-builder.js'; t=read(p)
t=replace_once(t,"  builder.innerHTML = `\n    <div class=\"dashboard-rolebar\" id=\"dashboardRolebar\">","  builder.innerHTML = `\n    <header class=\"dashboard-page-head\"><h2 class=\"ui-page-title\">Dashboard</h2></header>\n    <div class=\"dashboard-rolebar\" id=\"dashboardRolebar\">",'dashboard heading')
write(p,t)
p=PORTAL/'assets'/'dashboard-builder.css'; t=read(p)
if '.dashboard-page-head {' not in t:
    t += '''\n\n.dashboard-page-head {\n  display:flex;\n  align-items:center;\n  min-height:var(--size-icon-action);\n  margin:0 0 var(--space-3);\n}\n.dashboard-page-head .ui-page-title { margin:0; }\n'''
write(p,t)

# 4) Store dialog surface: schema-aware fields, no redundant hint/cancel, one Save button.
p=PORTAL/'dashboard.php'; t=read(p)
old='''        <div class="admin-form-grid">\n          <input type="hidden" name="id">\n          <label class="full-field">Store name<input name="store_name" maxlength="150" required></label>\n          <label>Week start day<select name="week_start_day" id="storeWeekStartDay"><option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option><option value="4">Thursday</option><option value="5">Friday</option><option value="6">Saturday</option><option value="7">Sunday</option></select></label>\n          <label>Status<select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>\n        </div>\n        <p class="form-hint">Stores are inactivated rather than deleted so historical attendance, payroll and financial records stay intact.</p>\n        <div class="admin-dialog-footer"><button type="button" class="secondary-btn" data-close-dialog>Cancel</button><button type="submit" class="primary-btn compact-btn">Save store</button></div>'''
new='''        <div class="admin-form-grid">\n          <input type="hidden" name="id">\n          <label class="full-field">Store name<input name="store_name" maxlength="150" required></label>\n          <div id="storeProfileFields" class="store-profile-fields full-field"></div>\n          <label>Week start day<select name="week_start_day" id="storeWeekStartDay"><option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option><option value="4">Thursday</option><option value="5">Friday</option><option value="6">Saturday</option><option value="7">Sunday</option></select></label>\n          <label>Status<select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>\n        </div>\n        <div class="admin-dialog-footer"><button type="submit" class="primary-btn compact-btn">Save</button></div>'''
t=replace_once(t,old,new,'store dialog')
t=t.replace('assets/management.js?v=20260902ds117','assets/management.js?v=20260902ds130')
t=t.replace('assets/directory.js?v=20260902ds97','assets/directory.js?v=20260902ds130')
write(p,t)

# 5) Timing editor: checkbox-only Closed control, no separate save, expose one-save schedule collector.
p=PORTAL/'assets'/'timings.js'; t=read(p)
t=t.replace('''      <div id="timingRows"></div>\n      <div class="timing-footer"><span id="timingStatus" class="muted"></span><button type="button" id="saveTimingsBtn" class="primary-btn compact-btn">Save timings</button></div>`;''','''      <div id="timingRows"></div>\n      <div class="timing-footer"><span id="timingStatus" class="muted"></span></div>`;''')
t=t.replace("    document.getElementById('saveTimingsBtn')?.addEventListener('click',saveTimings);\n",'')
t=t.replace('''        <label class="timing-closed"><input type="checkbox" class="timing-closed-input" ${closed?'checked':''}><span>Closed</span></label>''','''        <label class="timing-closed"><input type="checkbox" class="timing-closed-input" aria-label="Closed ${esc(label)}" ${closed?'checked':''}></label>''')
pattern=r"\n  async function saveTimings\(\)\{.*?\n  \}\n\n  function openStore\(storeId\)\{"
repl='''\n  async function collectForSave(){\n    if(readyPromise)await readyPromise;\n    const rows=document.querySelectorAll('#storeTimingsSection .timing-row');\n    if(!rows.length){\n      if(currentStoreId&&state)renderSelectedSchedule();\n      else renderRows(days.map(([day])=>blankDay(day)));\n    }\n    const daysPayload=collectDays();\n    for(const row of daysPayload){\n      if(!row.is_closed&&(!row.start_time||!row.end_time)){\n        setStatus('Every open day needs both a start time and an end time.',true);\n        throw new Error('Every open day needs both a start time and an end time.');\n      }\n    }\n    setStatus('');\n    return {week_start_day:weekStartDay(),days:daysPayload};\n  }\n\n  function openStore(storeId){'''
t=regex_once(t,pattern,repl,'timings unified collector')
old_open="""    const section=document.getElementById('storeTimingsSection'),save=document.getElementById('saveTimingsBtn'),copy=document.getElementById('copyFirstDayBtn');\n    if(!section)return;\n    const note=document.getElementById('timingScopeNote');\n    if(!currentStoreId){if(note)note.textContent='Save the store first, then reopen it to configure weekly timings.';document.getElementById('timingRows').innerHTML='';if(save)save.disabled=true;if(copy)copy.disabled=true;return;}\n    if(save)save.disabled=false;if(copy)copy.disabled=false;"""
new_open="""    const section=document.getElementById('storeTimingsSection'),copy=document.getElementById('copyFirstDayBtn');\n    if(!section)return;\n    const note=document.getElementById('timingScopeNote');\n    if(!currentStoreId){if(note)note.textContent='Configure weekly timings here; the Store Save button saves the store and this schedule together.';renderRows(days.map(([day])=>blankDay(day)));if(copy)copy.disabled=false;setStatus('');return;}\n    if(copy)copy.disabled=false;"""
t=replace_once(t,old_open,new_open,'timings new-store state')
t=t.replace("  let state=null,currentStoreId=null;","  let state=null,currentStoreId=null,readyPromise=null;")
t=t.replace("  window.MERDPOSStoreTimings=Object.freeze({openStore,refresh:load});\n  load();","  readyPromise=load();\n  window.MERDPOSStoreTimings=Object.freeze({openStore,collectForSave,refresh:()=>{readyPromise=load();return readyPromise;}});")
if 'saveTimingsBtn' in t: raise SystemExit('timings still contains saveTimingsBtn')
write(p,t)

# 6) Directory runtime: render safe schema fields and merge schedule into the single store Save request.
p=PORTAL/'assets'/'directory.js'; t=read(p)
t=t.replace("  let directory = null;","  let directory = null;\n  let timingsModuleReady = Promise.resolve();")
old='''  function ensureTimingsModule() {\n    if (!can('stores.timings.manage') || document.querySelector('script[data-store-timings-module]')) return;\n    const script = document.createElement('script');\n    script.src = 'assets/timings.js?v=20260831storeedit1';\n    script.dataset.storeTimingsModule = '1';\n    document.body.appendChild(script);\n  }'''
new='''  function ensureTimingsModule() {\n    if (!can('stores.timings.manage')) return;\n    const existing = document.querySelector('script[data-store-timings-module]');\n    if (existing) return;\n    const script = document.createElement('script');\n    script.src = 'assets/timings.js?v=20260902ds130';\n    script.dataset.storeTimingsModule = '1';\n    timingsModuleReady = new Promise((resolve,reject)=>{script.addEventListener('load',resolve,{once:true});script.addEventListener('error',()=>reject(new Error('Store timings module could not load.')),{once:true});});\n    document.body.appendChild(script);\n  }'''
t=replace_once(t,old,new,'directory timings loader')
anchor='''  function populateEmployeeStoreOptions() {\n    const select = document.getElementById('employeeStore');'''
insert='''  function renderStoreProfileFields(store = null) {\n    const root = document.getElementById('storeProfileFields');\n    if (!root) return;\n    const fields = Array.isArray(directory?.store_edit_fields) ? directory.store_edit_fields : [];\n    root.innerHTML = fields.map(field => {\n      const name=String(field.name||''),label=String(field.label||name),type=['email','tel','text'].includes(String(field.type||''))?String(field.type):'text',max=Math.max(1,Math.min(1000,Number(field.max_length||255)));\n      return `<label class="${field.wide?'full-field':''}">${esc(label)}<input name="${esc(name)}" type="${type}" maxlength="${max}"></label>`;\n    }).join('');\n    fields.forEach(field=>{const input=root.querySelector(`[name="${String(field.name||'').replace(/"/g,'\\\\"')}"]`);if(input)input.value=store?.[field.name]??'';});\n  }\n\n'''
t=replace_once(t,anchor,insert+anchor,'directory store profile renderer')
t=t.replace("      populateEmployeeStoreOptions();\n      hideLegacyStoreFields();","      populateEmployeeStoreOptions();\n      renderStoreProfileFields();\n      hideLegacyStoreFields();")
t=t.replace("    form.elements.store_name.value = store?.store_name || '';\n    form.elements.status.value", "    form.elements.store_name.value = store?.store_name || '';\n    renderStoreProfileFields(store);\n    form.elements.status.value")
t=t.replace("    dialog.showModal();\n    window.MERDPOSStoreTimings?.openStore?.(store?.id || null);","    dialog.showModal();\n    if(window.MERDPOSStoreTimings?.openStore)window.MERDPOSStoreTimings.openStore(store?.id||null);\n    else timingsModuleReady.then(()=>window.MERDPOSStoreTimings?.openStore?.(store?.id||null)).catch(error=>notice(error.message,true));")
old="""    if (action === 'save_store') values.week_start_day = form.elements.week_start_day?.value || '1';"""
new="""    if (action === 'save_store') values.week_start_day = form.elements.week_start_day?.value || '1';"""
t=replace_once(t,old,new,'directory store values anchor')
old_try="""    try {\n      if (button) button.disabled = true;\n      directory = await api('api/admin_directory.php', {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(values)});"""
new_try="""    try {\n      if (button) button.disabled = true;\n      if(action==='save_store'&&can('stores.timings.manage')){\n        await timingsModuleReady;\n        const timingApi=window.MERDPOSStoreTimings;\n        if(!timingApi?.collectForSave)throw new Error('Store timings are not ready.');\n        const schedule=await timingApi.collectForSave();\n        values.week_start_day=String(schedule.week_start_day||values.week_start_day||'1');\n        values.days=schedule.days;\n      }\n      directory = await api('api/admin_directory.php', {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(values)});"""
t=replace_once(t,old_try,new_try,'directory unified store save')
t=t.replace("      populateEmployeeStoreOptions(); hideLegacyStoreFields(); form.closest('dialog')?.close();","      populateEmployeeStoreOptions(); renderStoreProfileFields(); hideLegacyStoreFields(); if(action==='save_store')window.MERDPOSStoreTimings?.refresh?.(); form.closest('dialog')?.close();")
write(p,t)

# 7) Store API: expose and save schema-aware profile fields; save schedule inside the same DB transaction.
p=PORTAL/'api'/'admin_directory.php'; t=read(p)
marker='function directory_generated_code(string $name): string\n'
helpers=r'''function directory_store_edit_fields(PDO $pdo): array
{
    $columns = directory_store_columns($pdo);
    $groups = [
        [['store_code','code'], 'Code', 'text', false],
        [['address','address_line1'], 'Address', 'text', true],
        [['address_line2'], 'Address line 2', 'text', true],
        [['suburb'], 'Suburb', 'text', false],
        [['city'], 'City', 'text', false],
        [['state','region'], 'State / region', 'text', false],
        [['postcode','postal_code'], 'Postcode', 'text', false],
        [['country'], 'Country', 'text', false],
        [['phone','phone_number'], 'Phone', 'tel', false],
        [['email','store_email'], 'Email', 'email', false],
        [['timezone'], 'Timezone', 'text', false],
        [['currency_code'], 'Currency', 'text', false],
        [['tax_number'], 'Tax number', 'text', false],
        [['abn'], 'ABN', 'text', false],
    ];
    $fields = [];
    foreach ($groups as [$names,$label,$type,$wide]) {
        foreach ($names as $name) {
            if (!isset($columns[$name])) continue;
            $column = $columns[$name];
            $max = 255;
            if (preg_match('/varchar\((\d+)\)/i', (string)($column['Type'] ?? ''), $match)) $max = (int)$match[1];
            $fields[] = [
                'name'=>$name,'label'=>$label,'type'=>$type,'wide'=>$wide,'max_length'=>$max,
                'nullable'=>strtoupper((string)($column['Null'] ?? 'NO')) === 'YES',
                'has_default'=>($column['Default'] ?? null) !== null,
            ];
            break;
        }
    }
    return $fields;
}

function directory_store_profile_input(array $input, array $fields, bool $isNew): array
{
    $values = [];
    foreach ($fields as $field) {
        $name = (string)$field['name'];
        $value = trim((string)($input[$name] ?? ''));
        $max = max(1, (int)($field['max_length'] ?? 255));
        if (mb_strlen($value) > $max) throw new MerdWorkforceException('invalid_store_field', (string)$field['label'] . ' is too long.');
        if (($field['type'] ?? '') === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new MerdWorkforceException('invalid_store_email', 'Enter a valid store email address.');
        }
        if ($value === '' && empty($field['nullable']) && empty($field['has_default'])) {
            if ($isNew && in_array($name, ['store_code','code'], true)) $value = directory_generated_code((string)($input['store_name'] ?? 'Store'));
            else throw new MerdWorkforceException('required_store_field', (string)$field['label'] . ' is required.');
        }
        $values[$name] = ($value === '' && !empty($field['nullable'])) ? null : $value;
    }
    return $values;
}

function directory_normalize_store_time(mixed $value): ?string
{
    $text = trim((string)$value);
    if ($text === '') return null;
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $text)) throw new MerdWorkforceException('invalid_time', 'Use a valid 24-hour time.');
    return strlen($text) === 5 ? $text . ':00' : $text;
}

function directory_normalize_store_schedule(array $rawDays): array
{
    $days = [];
    foreach ($rawDays as $rawDay) {
        if (!is_array($rawDay)) continue;
        $day = filter_var($rawDay['day_of_week'] ?? null, FILTER_VALIDATE_INT);
        if ($day === false || $day < 1 || $day > 7 || isset($days[$day])) throw new MerdWorkforceException('invalid_schedule', 'Each weekday must appear once.');
        $closed = !empty($rawDay['is_closed']);
        $start = $closed ? null : directory_normalize_store_time($rawDay['start_time'] ?? null);
        $end = $closed ? null : directory_normalize_store_time($rawDay['end_time'] ?? null);
        if (!$closed && ($start === null || $end === null)) throw new MerdWorkforceException('incomplete_schedule', 'Every open day needs both a start time and an end time.');
        $days[(int)$day] = ['day_of_week'=>(int)$day,'start_time'=>$start,'end_time'=>$end,'is_closed'=>$closed?1:0];
    }
    ksort($days);
    if (count($days) !== 7) throw new MerdWorkforceException('invalid_schedule', 'All seven weekdays are required.');
    return $days;
}

function directory_save_store_schedule(PDO $pdo, int $clientId, int $storeId, string $storeName, int $weekStartDay, array $days, int $actorId): void
{
    $upsert = $pdo->prepare('INSERT INTO store_weekly_hours (client_id,store_id,day_of_week,start_time,end_time,is_closed,updated_by_employee_id) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE start_time=VALUES(start_time),end_time=VALUES(end_time),is_closed=VALUES(is_closed),updated_by_employee_id=VALUES(updated_by_employee_id),updated_at=CURRENT_TIMESTAMP');
    foreach ($days as $day) $upsert->execute([$clientId,$storeId,$day['day_of_week'],$day['start_time'],$day['end_time'],$day['is_closed'],$actorId]);
    $legacyStart = null;
    if (isset($days[1]) && !$days[1]['is_closed']) $legacyStart = $days[1]['start_time'];
    if ($legacyStart === null) foreach ($days as $day) if (!$day['is_closed'] && $day['start_time'] !== null) { $legacyStart = $day['start_time']; break; }
    if ($legacyStart !== null) {
        $legacy = $pdo->prepare('INSERT INTO store_shift_start_times (client_id,store_id,store_name,shift_start_time) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE store_name=VALUES(store_name),shift_start_time=VALUES(shift_start_time),updated_at=CURRENT_TIMESTAMP');
        $legacy->execute([$clientId,$storeId,$storeName,$legacyStart]);
    } else {
        $pdo->prepare('DELETE FROM store_shift_start_times WHERE client_id=? AND store_id=?')->execute([$clientId,$storeId]);
    }
}

'''
t=replace_once(t,marker,helpers+marker,'admin directory store helpers')
pattern=r"    \$stores = \[\];\n    if \(\$canStores\) \{.*?\n    \}\n\n    \$employees = \[\];"
repl=r'''    $stores = [];
    $storeEditFields = $canStores ? directory_store_edit_fields($pdo) : [];
    if ($canStores) {
        $storeSelect = "SELECT s.id,s.store_name,s.status,COALESCE(s.week_start_day,1) AS week_start_day,COALESCE(t.shift_start_time,'') AS shift_start_time";
        foreach ($storeEditFields as $field) $storeSelect .= ',s.`' . $field['name'] . '`';
        $storesStmt = $pdo->prepare($storeSelect . " FROM stores s LEFT JOIN store_shift_start_times t ON t.client_id=s.client_id AND t.store_id=s.id WHERE s.client_id=? ORDER BY s.id ASC");
        $storesStmt->execute([$clientId]);
        $stores = $storesStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $employees = [];'''
t=regex_once(t,pattern,repl,'admin directory dynamic store select')
t=replace_once(t,"        'stores'=>$stores,","        'stores'=>$stores,\n        'store_edit_fields'=>$storeEditFields,",'admin directory return store fields')
pattern=r"    if \(\$action === 'save_store'\) \{.*?\n        json_response\(directory_load_state\(\$pdo,\$actor\)\);\n    \}\n"
repl=r'''    if ($action === 'save_store') {
        beta_require_permission($actor, 'stores.manage', $pdo);
        $id = isset($input['id']) && $input['id'] !== '' ? (int)$input['id'] : null;
        $name = trim((string)($input['store_name'] ?? ''));
        $status = strtolower(trim((string)($input['status'] ?? 'active')));
        $weekStartDay = (int)($input['week_start_day'] ?? 1);
        if ($name === '' || mb_strlen($name) > 150) throw new MerdWorkforceException('invalid_store_name', 'Enter a store name.');
        if (!in_array($status, ['active','inactive'], true)) throw new MerdWorkforceException('invalid_status', 'Choose active or inactive.');
        if ($weekStartDay < 1 || $weekStartDay > 7) throw new MerdWorkforceException('invalid_week_start', 'Choose a valid week start day.');

        $dup = $pdo->prepare('SELECT id FROM stores WHERE client_id=? AND LOWER(TRIM(store_name))=LOWER(TRIM(?)) AND (? IS NULL OR id<>?) LIMIT 1');
        $dup->execute([(int)$actor['client_id'],$name,$id,$id]);
        if ($dup->fetchColumn()) throw new MerdWorkforceException('duplicate_store', 'A store with that name already exists.');

        $columns = directory_store_columns($pdo);
        $storeEditFields = directory_store_edit_fields($pdo);
        $profileValues = directory_store_profile_input($input, $storeEditFields, $id === null);
        $scheduleDays = null;
        if (array_key_exists('days', $input)) {
            beta_require_permission($actor, 'stores.timings.manage', $pdo);
            if (!is_array($input['days'])) throw new MerdWorkforceException('invalid_schedule', 'A seven-day schedule is required.');
            $scheduleDays = directory_normalize_store_schedule($input['days']);
        }

        $pdo->beginTransaction();
        try {
            if ($id === null) {
                $values = ['client_id'=>(int)$actor['client_id'],'store_name'=>$name,'status'=>$status,'week_start_day'=>$weekStartDay] + $profileValues;
                if (isset($columns['name'])) $values['name'] = $name;
                if (isset($columns['store_code']) && !array_key_exists('store_code',$values)) $values['store_code'] = directory_generated_code($name);
                if (isset($columns['code']) && !array_key_exists('code',$values)) $values['code'] = directory_generated_code($name);
                if (isset($columns['slug'])) $values['slug'] = strtolower(directory_generated_code($name));
                foreach ($columns as $field=>$meta) {
                    if (array_key_exists($field,$values) || str_contains(strtolower((string)$meta['Extra']),'auto_increment')) continue;
                    if ($meta['Default'] !== null || strtoupper((string)$meta['Null']) === 'YES') continue;
                    if (in_array($field,['created_at','updated_at'],true)) continue;
                    throw new MerdWorkforceException('store_schema_unsupported','The store schema requires an additional field: '.$field.'.');
                }
                $fieldSql = implode(',',array_map(fn(string $f):string=>'`'.$f.'`',array_keys($values)));
                $placeholders = implode(',',array_fill(0,count($values),'?'));
                $stmt = $pdo->prepare('INSERT INTO stores ('.$fieldSql.') VALUES ('.$placeholders.')');
                $stmt->execute(array_values($values));
                $id = (int)$pdo->lastInsertId(); $auditAction = 'store.create';
            } else {
                $check = $pdo->prepare('SELECT id FROM stores WHERE id=? AND client_id=? LIMIT 1');
                $check->execute([$id,(int)$actor['client_id']]);
                if (!$check->fetchColumn()) throw new MerdWorkforceException('store_not_found','Store not found.');
                $assign = ['store_name=?','status=?','week_start_day=?']; $args = [$name,$status,$weekStartDay];
                if (isset($columns['name'])) { $assign[]='`name`=?'; $args[]=$name; }
                foreach ($profileValues as $field=>$value) { $assign[]='`'.$field.'`=?'; $args[]=$value; }
                $args[]=$id; $args[]=(int)$actor['client_id'];
                $pdo->prepare('UPDATE stores SET '.implode(',',$assign).' WHERE id=? AND client_id=?')->execute($args);
                $pdo->prepare('UPDATE store_shift_start_times SET store_name=? WHERE client_id=? AND store_id=?')->execute([$name,(int)$actor['client_id'],$id]);
                $auditAction = 'store.update';
            }
            if ($scheduleDays !== null) directory_save_store_schedule($pdo,(int)$actor['client_id'],(int)$id,$name,$weekStartDay,$scheduleDays,(int)$actor['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        directory_audit($pdo,$actor,$auditAction,'store',(string)$id,['store_name'=>$name,'status'=>$status,'week_start_day'=>$weekStartDay,'profile_fields'=>array_keys($profileValues),'schedule_updated'=>$scheduleDays!==null]);
        json_response(directory_load_state($pdo,$actor));
    }
'''
t=regex_once(t,pattern,repl,'admin directory unified store save block')
write(p,t)

# 8) Desktop bottom navigation is shorter, while mobile touch geometry remains unchanged.
p=PORTAL/'assets'/'shell.css'; t=read(p)
t=replace_once(t,":root { --shell-desktop-nav-h:5.25rem; --shell-desktop-subnav-h:3.75rem; }",":root { --shell-desktop-nav-h:4.5rem; --shell-desktop-subnav-h:3.5rem; }",'desktop nav height')
t=replace_once(t,"gap:.35rem; padding:.45rem 1rem calc(.45rem + env(safe-area-inset-bottom));","gap:.3rem; padding:.3rem 1rem calc(.3rem + env(safe-area-inset-bottom));",'desktop rail padding')
t=replace_once(t,"position:relative; width:100%; min-height:4.2rem; display:flex; flex-direction:column;","position:relative; width:100%; min-height:3.45rem; display:flex; flex-direction:column;",'desktop button height')
t=replace_once(t,".rail-group-btn .ui-icon { width:1.55rem; height:1.55rem; }",".rail-group-btn .ui-icon { width:1.35rem; height:1.35rem; }",'desktop nav icon size')
t=replace_once(t,".rail-account-dock { flex:0 0 4.5rem; display:flex; align-items:center; justify-content:center; }",".rail-account-dock { flex:0 0 4rem; display:flex; align-items:center; justify-content:center; }",'desktop account dock width')
t=replace_once(t,"width:3.15rem; height:3.15rem; display:grid; place-items:center; padding:0;","width:2.85rem; height:2.85rem; display:grid; place-items:center; padding:0;",'desktop account trigger size')
t=replace_once(t,".merd-shell-account-trigger .rail-user-avatar { width:2.25rem; height:2.25rem; font-size:.8rem; }",".merd-shell-account-trigger .rail-user-avatar { width:2rem; height:2rem; font-size:.78rem; }",'desktop account avatar size')
write(p,t)

# 9) Account/app-settings trigger gets durable open state, cross-window sync and stable identity.
p=PORTAL/'assets'/'account-menu.js'; t=read(p)
t=replace_once(t,"  const ACCOUNT_CONTEXT_STATE_KEY='merdpos-account-context-sections-v1';","  const ACCOUNT_CONTEXT_STATE_KEY='merdpos-account-context-sections-v1';\n  const ACCOUNT_UI_STATE_KEY='merdpos-account-tools-ui-v1';",'account ui state key')
t=replace_once(t,"  function writeContextSectionState(state){try{localStorage.setItem(ACCOUNT_CONTEXT_STATE_KEY,JSON.stringify(state));}catch(_){}}","  function writeContextSectionState(state){try{localStorage.setItem(ACCOUNT_CONTEXT_STATE_KEY,JSON.stringify(state));}catch(_){}}\n  function readAccountUiState(){try{const saved=JSON.parse(localStorage.getItem(ACCOUNT_UI_STATE_KEY)||'{}');return saved&&typeof saved==='object'?saved:{};}catch(_){return {};}}\n  function writeAccountUiState(open){try{localStorage.setItem(ACCOUNT_UI_STATE_KEY,JSON.stringify({open:!!open}));}catch(_){}}",'account ui state helpers')
t=replace_once(t,"  function openMobileTools(trigger) {","  function openMobileTools(trigger, options = {}) {")
t=replace_once(t,"    if (utilityBackdrop) utilityBackdrop.hidden = false;\n    window.setTimeout", "    if (utilityBackdrop) utilityBackdrop.hidden = false;\n    if(options.persist!==false)writeAccountUiState(true);\n    window.setTimeout",'account open persistence')
t=replace_once(t,"    if (utilityBackdrop) utilityBackdrop.hidden = true;\n    if (wasOpen && options.restoreFocus !== false)","    if (utilityBackdrop) utilityBackdrop.hidden = true;\n    if(options.persist!==false)writeAccountUiState(false);\n    if (wasOpen && options.restoreFocus !== false)",'account close persistence')
t=replace_once(t,"    accountTrigger.className = 'merd-shell-account-trigger';","    accountTrigger.className = 'merd-shell-account-trigger';\n    accountTrigger.dataset.accountToolsTrigger = '1';",'account stable trigger')
anchor="""    utilityBackdrop.addEventListener('click', () => closeMobileTools());\n    document.body.appendChild(utilityBackdrop);\n\n    wireContextSections(utilities);"""
new="""    utilityBackdrop.addEventListener('click', () => closeMobileTools());\n    document.body.appendChild(utilityBackdrop);\n\n    wireContextSections(utilities);\n    if(readAccountUiState().open===true)window.setTimeout(()=>openMobileTools(accountTrigger,{persist:false}),0);\n    window.addEventListener('storage',event=>{if(event.key!==ACCOUNT_UI_STATE_KEY)return;const open=readAccountUiState().open===true;if(open)openMobileTools(accountTrigger,{persist:false});else closeMobileTools({persist:false,restoreFocus:false});});"""
t=replace_once(t,anchor,new,'account restore/sync')
write(p,t)

# 10) Asset cache versions ensure exact runtime reaches live browsers.
p=PORTAL/'assets'/'management.js'; t=read(p)
for old,new in [
('design-tokens.css?v=20260828palette1','design-tokens.css?v=20260902ds130'),
('shell.css?v=20260902ds117','shell.css?v=20260902ds130'),
('dashboard-builder.css?v=20260902ds97','dashboard-builder.css?v=20260902ds130'),
('dashboard-builder.js?v=20260902ds97','dashboard-builder.js?v=20260902ds130'),
('navigation.js?v=20260902ds97','navigation.js?v=20260902ds130'),
('account-menu.js?v=20260902ds117','account-menu.js?v=20260902ds130'),
('design-system.css?v=20260902ds117','design-system.css?v=20260902ds130'),
]: t=t.replace(old,new)
write(p,t)
p=PORTAL/'assets'/'navigation.js'; t=read(p)
t=t.replace('dashboard-builder.css?v=20260828mobile1','dashboard-builder.css?v=20260902ds130').replace('dashboard-builder.js?v=20260825a','dashboard-builder.js?v=20260902ds130')
write(p,t)

# 11) Update the older embedded-timings regression to the single-save architecture.
p=ROOT/'namecheap_beta_live'/'browser_tests'/'implementation-patches-runtime.spec.js'; t=read(p)
pattern=r"test\('Store Edit embeds weekly timings and persists week-start day without changing day keys', async \(\{ page \}\) => \{.*?\n\}\);"
repl=r'''test('Store Edit embeds weekly timings and exposes the seven-day schedule to the single Store Save path', async ({ page }) => {
  const timings = Array.from({length:7},(_,i)=>({store_id:7,day_of_week:i+1,start_time:'09:00:00',end_time:'17:00:00',is_closed:0}));
  const payload = () => ({success:true,csrf:'timing-csrf',stores:[{id:7,store_name:'Demo Store',status:'active',week_start_day:7}],timings});
  await page.route('https://merdpos-smoke.invalid/api/store_timings.php*', route => route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(payload())}));
  await page.route('https://merdpos-smoke.invalid/assets/timings.css*', route => route.fulfill({status:200,contentType:'text/css',body:''}));
  await page.setContent(`<!doctype html><html><head><base href="https://merdpos-smoke.invalid/"></head><body>
    <dialog id="storeDialog" open><form id="storeAdminForm"><div class="admin-dialog-body">
      <div class="admin-form-grid"><input name="id" value="7"><select name="week_start_day" id="storeWeekStartDay">
        <option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option><option value="4">Thursday</option><option value="5">Friday</option><option value="6">Saturday</option><option value="7" selected>Sunday</option>
      </select></div><div class="admin-dialog-footer"><button type="submit">Save</button></div></div></form></dialog>
  </body></html>`);
  await page.addScriptTag({path:portal('assets/timings.js')});
  await expect(page.locator('#storeDialog #storeTimingsSection')).toHaveCount(1);
  await expect(page.locator('#saveTimingsBtn')).toHaveCount(0);
  await expect(page.locator('#timingRows .timing-row').first()).toHaveAttribute('data-day','7');
  await page.selectOption('#storeWeekStartDay','2');
  await expect(page.locator('#timingRows .timing-row').first()).toHaveAttribute('data-day','2');
  const schedule=await page.evaluate(()=>window.MERDPOSStoreTimings.collectForSave());
  expect(schedule.week_start_day).toBe(2);
  expect(schedule.days.map(row=>row.day_of_week)).toEqual([1,2,3,4,5,6,7]);
});'''
t=regex_once(t,pattern,repl,'old timings regression')
write(p,t)

# 12) Add focused revision-130 regression coverage.
p=ROOT/'namecheap_beta_live'/'browser_tests'/'revision130-runtime.spec.js'
p.write_text(r'''const { test, expect } = require('@playwright/test');
const fs=require('fs'),path=require('path');
test.use({channel:'chrome'});
const repoRoot=process.env.GITHUB_WORKSPACE||path.resolve(__dirname,'..','..');
const portal=rel=>path.join(repoRoot,'namecheap_beta_live','timesheet_portal',rel);
const source=rel=>fs.readFileSync(portal(rel),'utf8');

test('revision 130 restores cyan as the semantic MERDPOS product accent without changing five masters',()=>{
  const tokens=source('assets/design-tokens.css');
  for(const literal of ['#FFFFFF','#F5F7FC','#031B4B','#12BDF3','#8B2EFF'])expect(tokens).toContain(literal);
  expect(tokens).toContain('--color-brand-primary: var(--color-brand-cyan);');
  expect(tokens).toContain('--color-focus: var(--color-brand-cyan);');
  expect(tokens).toContain('--color-nav-active: var(--color-brand-cyan);');
});

test('revision 130 dashboard and shared heading alignment are canonical',()=>{
  const builder=source('assets/dashboard-builder.js'),design=source('assets/design-system.css');
  expect(builder).toContain('<header class="dashboard-page-head"><h2 class="ui-page-title">Dashboard</h2></header>');
  expect(design).toContain('DS130 recurrent header-alignment guard');
  expect(design).toContain('.merd-shell :where(.dialog-heading, .dialog-head, .admin-dialog-header) {\n  align-items: center;');
  expect(design).toContain('min-height: var(--size-icon-action);');
});

test('revision 130 store dialog has schema-aware profile fields and one Save control',()=>{
  const dashboard=source('dashboard.php'),directory=source('assets/directory.js'),api=source('api/admin_directory.php');
  expect(dashboard).toContain('id="storeProfileFields"');
  expect(dashboard).not.toContain('Stores are inactivated rather than deleted');
  expect(dashboard).toContain('<div class="admin-dialog-footer"><button type="submit" class="primary-btn compact-btn">Save</button></div>');
  expect(directory).toContain('renderStoreProfileFields(store)');
  expect(directory).toContain('values.days=schedule.days');
  expect(api).toContain('function directory_store_edit_fields');
  expect(api).toContain("[['store_code','code'], 'Code'");
  expect(api).toContain("[['address','address_line1'], 'Address'");
  expect(api).toContain('directory_save_store_schedule($pdo');
});

test('revision 130 timing Closed control is checkbox-only and separate save is retired',()=>{
  const timings=source('assets/timings.js');
  expect(timings).toContain('aria-label="Closed ${esc(label)}"');
  expect(timings).not.toContain('<span>Closed</span>');
  expect(timings).not.toContain('saveTimingsBtn');
  expect(timings).toContain('collectForSave');
});

test('revision 130 desktop bottom dock is shorter while retaining icon-left label-right layout',()=>{
  const shell=source('assets/shell.css');
  expect(shell).toContain('--shell-desktop-nav-h:4.5rem');
  expect(shell).toContain('min-height:3.45rem');
  expect(shell).toContain('.app-frame.nav-bottom .rail-group-btn {');
  expect(shell).toContain('flex-direction: row;');
});

test('revision 130 account tools trigger has durable and cross-window UI state',()=>{
  const account=source('assets/account-menu.js');
  expect(account).toContain("ACCOUNT_UI_STATE_KEY='merdpos-account-tools-ui-v1'");
  expect(account).toContain("accountTrigger.dataset.accountToolsTrigger = '1'");
  expect(account).toContain('writeAccountUiState(true)');
  expect(account).toContain('writeAccountUiState(false)');
  expect(account).toContain('if(event.key!==ACCOUNT_UI_STATE_KEY)return;');
});
''',encoding='utf-8')

# 13) Runtime validator binds the repeat-failure owners.
p=ROOT/'namecheap_beta_live'/'backend'/'cli'/'validate_beta_runtime_contract.php'; t=read(p)
t=t.replace("$accountMenuCss = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/account-menu.css', $errors);","$accountMenuCss = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/account-menu.css', $errors);\n$accountMenuJs = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/account-menu.js', $errors);\n$adminDirectoryApi = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/api/admin_directory.php', $errors);")
anchor="// Implemented DevStudio patch requests are canonical source, not permanent Studio overlays.\n"
checks=r'''// Revision 130 repeat-failure owners are canonical and guarded.
beta_contract_require_contains($tokens, '--color-brand-primary: var(--color-brand-cyan);', 'cyan product accent owner', $errors);
beta_contract_require_contains($dashboardBuilderJs, '<header class="dashboard-page-head"><h2 class="ui-page-title">Dashboard</h2></header>', 'Dashboard page heading', $errors);
beta_contract_require_contains($designSystem, 'DS130 recurrent header-alignment guard', 'shared header alignment guard', $errors);
beta_contract_require_contains($dashboard, 'id="storeProfileFields"', 'schema-aware store profile mount', $errors);
beta_contract_require_absent($dashboard, 'Stores are inactivated rather than deleted', 'retired Store form hint', $errors);
beta_contract_require_contains($dashboard, '<button type="submit" class="primary-btn compact-btn">Save</button>', 'single Store Save control', $errors);
beta_contract_require_absent($timingsJs, 'saveTimingsBtn', 'retired separate timings save control', $errors);
beta_contract_require_contains($timingsJs, 'collectForSave', 'Store schedule single-save handoff', $errors);
beta_contract_require_contains($directoryJs, 'values.days=schedule.days', 'Store Save schedule merge', $errors);
beta_contract_require_contains($adminDirectoryApi, 'function directory_store_edit_fields', 'schema-aware store profile API', $errors);
beta_contract_require_contains($adminDirectoryApi, 'directory_save_store_schedule($pdo', 'atomic Store profile/schedule save', $errors);
beta_contract_require_contains($shellCss, '--shell-desktop-nav-h:4.5rem', 'shorter desktop bottom dock', $errors);
beta_contract_require_contains($accountMenuJs, "ACCOUNT_UI_STATE_KEY='merdpos-account-tools-ui-v1'", 'account tools UI persistence', $errors);
beta_contract_require_contains($accountMenuJs, 'if(event.key!==ACCOUNT_UI_STATE_KEY)return;', 'account tools cross-window state synchronization', $errors);

'''
t=replace_once(t,anchor,checks+anchor,'validator revision130 checks')
write(p,t)

# Script is disposable; remove itself after successful transform.
Path(__file__).unlink()
