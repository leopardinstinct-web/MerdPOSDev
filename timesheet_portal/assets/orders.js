/* MERDPOS Portal (Alpha) — Inventory DBA stock counting, session draft only. */
(() => {
  'use strict';
  const root = document.getElementById('countWorkspace');
  if (!root) return;
  const $ = id => document.getElementById(id);
  const state = {products: [], type: 'AUTO', csrf: root.dataset.csrf, queue: Promise.resolve(), scanner: null, scanning: false, cameraStarting: false, cameraStopping: false, cameraCancelled: false, lastId: null};
  const types = {AUTO: 'Cigarettes', MANUAL: 'Accessories'};
  const badge = (text, cls) => {
    const e = document.createElement('span');
    e.className = cls;
    e.textContent = text;
    return e;
  };
  const info = (message, error = false) => {
    const el = $('countNotice');
    el.textContent = message;
    el.className = 'order-notice ' + (error ? 'order-error' : 'order-good');
    el.hidden = false;
  };
  async function request(data = null) {
    const options = {credentials:'same-origin',cache:'no-store'};
    if (data) {
      options.method = 'POST';
      options.body = new URLSearchParams({...data, csrf_token:state.csrf, type:data.type || state.type});
    }
    const response = await fetch('api/order_count.php', options);
    let payload;
    try { payload = await response.json(); } catch (_) { throw new Error('Catalogue response could not be read.'); }
    if (!response.ok || !payload.success) throw new Error(payload.error || 'The server rejected this request.');
    state.products = payload.products;
    state.csrf = payload.csrf_token;
    return payload;
  }
  const active = () => state.products.filter(p => p.type === state.type);
  const units = n => n === null ? '—' : String(n);
  function render() {
    const matching = active();
    const q = $('productSearch').value.trim().toLowerCase();
    $('typeAuto').setAttribute('aria-pressed', String(state.type === 'AUTO'));
    $('typeManual').setAttribute('aria-pressed', String(state.type === 'MANUAL'));
    $('productSearch').placeholder = state.type === 'AUTO' ? 'Search cigarette name or barcode' : 'Search paper, lighter, filter or barcode';
    $('countHead').textContent = state.type === 'AUTO' ? 'Cigarette physical stock' : 'Tobacco accessories to order';
    $('countHelp').textContent = state.type === 'AUTO'
      ? 'Scan a pack to count +1, or a box to count its Packaging quantity. Unscanned cigarettes remain at 0.'
      : 'Scan an accessory or select it from the list to add 1 to the order. Tap its quantity to correct it.';
    const auto = state.products.filter(p => p.type === 'AUTO');
    const counted = auto.filter(p => p.count > 0);
    const valid = auto.filter(p => p.ceiling !== null);
    const missing = auto.length - valid.length;
    const total = valid.reduce((sum,p) => sum + p.order_quantity, 0);
    $('countedProducts').textContent = String(counted.length);
    $('orderTotal').textContent = String(total);
    $('ceilingProblems').textContent = missing ? missing + ' cigarettes have no valid ceiling and cannot be calculated.' : 'All listed cigarette ceilings are configured.';
    const accessories = state.products.filter(p => p.type === 'MANUAL' && p.count > 0);
    $('accessoryTotal').textContent = String(accessories.reduce((sum,p) => sum + p.count, 0));
    $('countedLabel').textContent = state.type === 'AUTO' ? 'Counted cigarettes' : 'Selected accessories';
    $('countQtyLabel').textContent = state.type === 'AUTO' ? 'On hand' : 'Order qty';
    $('countOrderHeading').hidden = state.type !== 'AUTO';
    $('countCeilingHeading').hidden = state.type !== 'AUTO';
    $('showAllCigarettes').parentElement.hidden = state.type !== 'AUTO';
    const list = $('productSuggestions');
    list.replaceChildren();
    if (q.length >= 2) {
      const hits = matching.filter(p => p.name.toLowerCase().includes(q) ||
        [...p.unit_barcodes,...p.box_barcodes].some(c => c.toLowerCase().includes(q))).slice(0,12);
      hits.forEach(p => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'order-suggestion';
        b.textContent = p.name + (p.type === 'AUTO' ? ' · ceiling ' + units(p.ceiling) : '');
        b.addEventListener('click', () => addProduct(p.id));
        list.appendChild(b);
      });
      list.hidden = hits.length === 0;
    } else list.hidden = true;
    const tbody = $('countedBody');
    tbody.replaceChildren();
    const rows = matching.filter(p => p.count > 0 || (state.type === 'AUTO' && $('showAllCigarettes').checked))
      .filter(p => !q || p.name.toLowerCase().includes(q) || [...p.unit_barcodes,...p.box_barcodes].some(c=>c.toLowerCase().includes(q)))
      .sort((a,b)=>a.name.localeCompare(b.name));
    rows.forEach(p => {
      const tr = document.createElement('tr');
      if (p.id === state.lastId) tr.className = 'order-highlight';
      const name = document.createElement('td');
      name.append(badge(p.name,'order-row-name'));
      const sub = document.createElement('small');
      sub.textContent = p.category + ' · ' + (p.unit_barcodes[0] || 'No barcode');
      name.append(sub);
      tr.append(name);
      if (state.type === 'AUTO') {
        const ceiling = document.createElement('td');
        ceiling.textContent = units(p.ceiling);
        if (p.ceiling === null) ceiling.title = 'No configured ceiling — order unavailable';
        tr.append(ceiling);
      }
      const count = document.createElement('td');
      const edit = document.createElement('button');
      edit.type = 'button';
      edit.className = 'order-qty';
      edit.textContent = String(p.count);
      edit.setAttribute('aria-label','Edit quantity for ' + p.name);
      edit.addEventListener('click',()=>editCount(p));
      count.append(edit);
      tr.append(count);
      if (state.type === 'AUTO') {
        const order = document.createElement('td');
        order.textContent = units(p.order_quantity);
        order.className = 'order-calculated';
        tr.append(order);
      }
      const actions = document.createElement('td');
      const plus = document.createElement('button');
      plus.type='button';plus.className='order-row-action';plus.textContent='+1';plus.setAttribute('aria-label','Add one '+p.name);
      plus.addEventListener('click',()=>addProduct(p.id));
      const remove=document.createElement('button');
      remove.type='button';remove.className='order-row-action order-remove';remove.textContent='Reset';remove.setAttribute('aria-label','Reset '+p.name);
      remove.addEventListener('click',()=>mutate({action:'remove',product_id:p.id},p.name+' reset to 0.'));
      actions.append(plus,remove);tr.append(actions);tbody.append(tr);
    });
    $('emptyCounts').hidden = rows.length > 0;
    $('countTable').hidden = rows.length === 0;
  }
  function mutate(data, notice) {
    // Queue mutations in arrival order: a fast second scan must never disappear.
    const queued = {...data, type:state.type};
    state.queue = state.queue.then(async () => {
      try {
        const out = await request(queued);
        state.lastId = queued.product_id || null;
        if (queued.action === 'scan') {
          const match = out.products.find(p => [...p.unit_barcodes,...p.box_barcodes].includes(queued.barcode) && p.type === queued.type);
          if (match) {
            state.lastId=match.id;
            const qty=match.box_barcodes.includes(queued.barcode) ? match.packaging : 1;
            notice=match.name+' · +'+qty+' units ('+match.count+' total)';
          }
        }
        $('productSearch').value = '';
        $('productSuggestions').hidden = true;
        render();
        if (notice) info(notice);
      } catch (error) {
        info(error.message || 'Unable to update draft', true);
      }
    });
    return state.queue;
  }
  async function addProduct(id) {await mutate({action:'select',product_id:id},'Product quantity increased by 1.');}
  async function editCount(product) {
    const value = window.prompt('Exact '+(state.type==='AUTO'?'physical units on hand':'accessories to order')+' for\n'+product.name, String(product.count));
    if (value === null) return;
    if (!/^\d{1,5}$/.test(value.trim())) { info('Enter a whole number from 0 to 99999.',true);return; }
    await mutate({action:'set',product_id:product.id,quantity:value.trim()},product.name+' quantity updated.');
  }
  async function enterBarcode(value) {
    const code=String(value||'').trim();
    if (!code) return;
    $('barcodeEntry').value = '';
    await mutate({action:'scan',barcode:code});
  }
  async function stopCamera() {
    if (state.cameraStarting) {
      // start() may still resolve after Stop is pressed. Defer cleanup until then.
      state.cameraCancelled = true;
      $('startCamera').textContent = 'Stopping camera…';
      return;
    }
    if (state.cameraStopping) return;
    state.cameraStopping = true;
    const scanner = state.scanner;
    const wasScanning = state.scanning;
    state.scanner = null;
    state.scanning = false;
    try {
      if (scanner) {
        try {if (wasScanning) await scanner.stop();} catch (_) {}
        try {scanner.clear();} catch (_) {}
      }
    } finally {
      $('cameraPanel').hidden = true;
      $('startCamera').textContent = 'Scan with camera';
      state.cameraStopping = false;
    }
  }
  async function startCamera() {
    if (state.cameraStarting || state.cameraStopping) return;
    if (state.scanning) {await stopCamera();return;}
    if (typeof Html5Qrcode === 'undefined') {
      info('Camera scanner not available. Enter barcode or use product search.',true);return;
    }
    state.cameraCancelled = false;
    state.cameraStarting = true;
    $('cameraPanel').hidden=false;
    $('startCamera').textContent='Starting camera…';
    try {
      const opts={formatsToSupport:[
        Html5QrcodeSupportedFormats.EAN_13,Html5QrcodeSupportedFormats.EAN_8,
        Html5QrcodeSupportedFormats.UPC_A,Html5QrcodeSupportedFormats.UPC_E,
        Html5QrcodeSupportedFormats.CODE_128,Html5QrcodeSupportedFormats.CODE_39
      ],verbose:false};
      const scanner=new Html5Qrcode('cameraReader',opts);
      state.scanner=scanner;
      let captured=false;
      await scanner.start({facingMode:'environment'},{fps:10,qrbox:{width:250,height:145}},
        async value=>{
          if (captured) return;
          captured=true;
          await stopCamera();
          await enterBarcode(value);
        },()=>{});
      state.cameraStarting=false;
      state.scanning=true;
      if (state.cameraCancelled) {await stopCamera();return;}
      $('startCamera').textContent='Stop camera';
      info('Point the camera at a barcode. One recognized scan is counted at a time.');
    } catch (e) {
      state.cameraStarting=false;
      await stopCamera();
      info('Camera permission/format unavailable. Use barcode entry or search. '+(e.message||''),true);
    }
  }
  $('typeAuto').addEventListener('click',async()=>{await stopCamera();state.type='AUTO';$('productSearch').value='';render();});
  $('typeManual').addEventListener('click',async()=>{await stopCamera();state.type='MANUAL';$('productSearch').value='';render();});
  $('productSearch').addEventListener('input',render);
  $('showAllCigarettes').addEventListener('change',render);
  $('barcodeForm').addEventListener('submit',e=>{e.preventDefault();enterBarcode($('barcodeEntry').value);});
  $('startCamera').addEventListener('click',startCamera);
  $('stopCamera').addEventListener('click',stopCamera);
  window.addEventListener('pagehide',()=>{if(state.scanning || state.cameraStarting)stopCamera();});
  request().then(()=>{render();$('countLoading').hidden=true;root.hidden=false;}).catch(e=>{
    $('countLoading').textContent='Cannot load Inventory DBA: '+(e.message||'Unknown error');
    $('countLoading').className='order-notice order-error';
    root.hidden=true;
  });
})();
