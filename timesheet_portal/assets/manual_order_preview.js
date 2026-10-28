/* MERDPOS Alpha: workbook-style, read-only order sheet shared by draft and SUPER review.
   Order quantities remain server-calculated. Never send edits through this preview. */
(() => {
  'use strict';
  const storeInfo = {
    MX:{name:'Marrickville Xpress',address:'375 Enmore Rd, Marrickville NSW 2204, Australia'},
    ET:{name:'Enmore Xpress',address:'87 Enmore Rd, Newtown NSW 2042, Australia'},
    DT:{name:'Double Bay Xpress',address:'Shop 4/401-407 New South Head Rd, Double Bay NSW 2028, Australia'},
    RT:{name:'Rosebay Xpress',address:'10C Dover Rd, Rose Bay NSW 2029, Australia'}
  };
  const node = (tag, cls, value) => {
    const out=document.createElement(tag);
    if(cls)out.className=cls;
    if(value !== undefined)out.textContent=String(value);
    return out;
  };
  const auDate=(value)=>{
    const parsed=value?new Date(value):new Date();
    if(Number.isNaN(parsed.getTime()))return 'Date unavailable';
    return new Intl.DateTimeFormat('en-AU',{
      timeZone:'Australia/Sydney',month:'long',day:'numeric',year:'numeric'
    }).format(parsed);
  };
  function draftRows(products){
    return products.filter(p=>(p.type==='AUTO' ? p.order_quantity : p.count)>0
        &&(p.type!=='AUTO'||p.ceiling!==null))
      .map(p=>({
        category:p.category||'',
        barcodes:(p.unit_barcodes||[]).join(', '),
        name:p.name,
        order_quantity:p.type==='AUTO'?p.order_quantity:p.count,
        type:p.type
      }))
      .sort((a,b)=>a.type.localeCompare(b.type)||a.name.localeCompare(b.name));
  }
  function render(host,{store,rows=[],date=null,orderId='',testOrder=false,phase='Draft'}){
    if(!host)return;
    host.replaceChildren();
    const storeData=storeInfo[store]||{name:store||'Unknown store',address:''};
    const paper=node('div','manual-order-sheet');
    const title=node('header','manual-order-sheet-header');
    const dateRow=node('div','manual-order-date',auDate(date));
    const storeRow=node('div','manual-order-store',storeData.name);
    const addressRow=node('div','manual-order-address','Address: '+storeData.address);
    title.append(dateRow,storeRow,addressRow);
    if(testOrder)title.append(node('div','manual-order-test','QA TEST ORDER — NOT FOR SUPPLIER'));
    const meta=node('div','manual-order-meta',orderId?('Order '+orderId+' · '+phase):'Live order preview · '+phase);
    title.append(meta);
    paper.append(title);
    const scroll=node('div','manual-order-scroll');
    const table=node('table','manual-order-table');
    table.setAttribute('aria-label',storeData.name+' order lines');
    const head=node('thead');
    const tr=node('tr');
    ['Category','Bar Code','Product Name','Order Quantity'].forEach(t=>{
      tr.append(node('th','',t));
    });
    head.append(tr);table.append(head);
    const body=node('tbody');
    rows.forEach(p=>{
      const row=node('tr');
      [p.category,p.barcodes,p.name,p.order_quantity].forEach((v,i)=>{
        const cell=node('td',i===3?'manual-order-quantity':'',v??'');
        row.append(cell);
      });
      body.append(row);
    });
    if(rows.length===0){
      const empty=node('tr');
      const td=node('td','manual-order-empty','No order lines at the moment. Scan stock or add accessories to update the sheet.');
      td.colSpan=4;empty.append(td);body.append(empty);
    }
    table.append(body);scroll.append(table);paper.append(scroll);
    const foot=node('div','manual-order-foot');
    foot.append(node('span','',rows.length+' order lines'));
    foot.append(node('span','','Quantities are calculated from the selected store’s stock ceilings and accessories.'));
    paper.append(foot);host.append(paper);
  }
  window.MerdManualOrder={render,draftRows,storeInfo};
})();
