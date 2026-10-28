/* MERDPOS Alpha: permanent submissions, SUPER-only review and approved downloads. */
(() => {
  'use strict';
  const panel=document.getElementById('workflowPanel');
  if(!panel) return;
  const $=id=>document.getElementById(id);
  const isSuper=panel.dataset.super==='1';
  const csrf=panel.dataset.csrf;
  let orders=[];
  let page=1, total=0, pageSize=40;
  let pendingByStore={MX:0,ET:0,DT:0,RT:0};
  let busy=false;
  const notice=(message,error=false)=>{
    const box=$('orderHistoryNotice');
    box.hidden=false;box.className='order-notice '+(error?'order-error':'order-good');
    box.textContent=message;
  };
  const el=(tag,cls,text)=>{
    const e=document.createElement(tag);
    if(cls)e.className=cls;
    if(text!==undefined)e.textContent=String(text);
    return e;
  };
  async function api(data=null) {
    const options={credentials:'same-origin',cache:'no-store'};
    if(data){options.method='POST';options.body=new URLSearchParams({...data,csrf_token:csrf});}
    const query=new URLSearchParams({page:String(page),store:$('historyStore').value,status:$('historyStatus').value});
    const response=await fetch('api/order_workflow.php'+(data?'':'?'+query),options);
    let out;
    try{out=await response.json();}catch(_){throw new Error('Server response could not be read.');}
    if(!response.ok||!out.success)throw new Error(out.error||'Order request failed.');
    return out;
  }
  function render(){
    const wrap=$('orderHistory');
    wrap.replaceChildren();
    const filtered=orders;
    $('historyPrevious').disabled=page<=1;
    $('historyNext').disabled=page*pageSize>=total;
    $('historyPageInfo').textContent='Page '+page+' of '+Math.max(1,Math.ceil(total/pageSize))+' · '+total+' orders';
    if(isSuper){
      const strip=el('div','order-status-strip');
      ['MX','ET','DT','RT'].forEach(store=>{
        const count=pendingByStore[store]||0;
        strip.append(el('span','order-store-stat',store+' · '+count+' pending'));
      });
      wrap.append(strip);
    }
    if(!filtered.length){wrap.append(el('p','order-empty','No orders match the selected filters.'));return;}
    filtered.forEach(o=>{
      const card=el('article','order-history-card');
      const head=el('div','order-history-head');
      const main=el('div','');
      main.append(el('strong','',o.id),el('span','order-history-meta',' · '+o.store+' · '+new Date(o.submitted_at).toLocaleString()));
      head.append(main,el('span','order-status order-status-'+o.status.toLowerCase(),o.status));
      card.append(head);
      card.append(el('p','order-history-meta','Submitted by '+o.author_name+
        ' · Cigarettes '+o.cigarette_units+' units · Accessories '+o.accessory_units+' units'));
      if(o.excluded_missing_ceiling&&o.excluded_missing_ceiling.length){
        card.append(el('p','order-history-meta','Not calculated (no store ceiling): '+o.excluded_missing_ceiling.length+' catalogue products'));
      }
      const details=el('details','order-details');
      details.append(el('summary','','View '+o.rows.length+' order lines'));
      const table=el('table','order-review-table');
      const thead=el('thead','');
      const tr=el('tr','');
      ['Category','Bar code','Product','Quantity'].forEach(h=>tr.append(el('th','',h)));
      thead.append(tr);table.append(thead);
      const tbody=el('tbody','');
      o.rows.forEach(p=>{
        const tr=el('tr','');
        [p.category,p.barcodes,p.name,p.order_quantity].forEach(v=>tr.append(el('td','',v)));
        tbody.append(tr);
      });
      table.append(tbody);
      const scrolling=el('div','order-table-wrap');scrolling.append(table);details.append(scrolling);card.append(details);
      if(o.review_note)card.append(el('p','order-history-meta','Review note: '+o.review_note));
      if(o.status==='APPROVED'){
        card.append(el('p','order-history-meta','Approved by '+o.reviewer_name+' · '+new Date(o.reviewed_at).toLocaleString()));
        if(isSuper){
          const links=el('div','order-history-actions');
          [['pdf','Download PDF'],['xlsx','Download spreadsheet']].forEach(([format,label])=>{
            const a=el('a','secondary-btn',label);
            a.href='api/order_download.php?id='+encodeURIComponent(o.id)+'&format='+format;
            a.rel='noopener';links.append(a);
          });
          card.append(links);
        }
      }
      if(o.status==='PENDING'&&o.editable){
        const editButton=el('button','secondary-btn','Edit pending order');
        editButton.type='button';editButton.addEventListener('click',async()=>{
          if(busy)return;
          if(!window.confirm('Resume editing '+o.id+'? Any unsent stock counts in your current draft may be replaced.'))return;
          busy=true;editButton.disabled=true;
          try{await api({action:'edit',order_id:o.id});window.location.href='orders.php?editing='+encodeURIComponent(o.id);}
          catch(e){notice(e.message,true);busy=false;editButton.disabled=false;}
        });
        card.append(editButton);
      }
      if(o.status==='PENDING'&&isSuper){
        const actions=el('div','order-history-actions');
        for(const [action,label] of [['approve','Approve & lock'],['reject','Reject']]){
          const button=el('button',action==='approve'?'primary-btn':'secondary-btn',label);
          button.type='button';
          button.addEventListener('click',async()=>{
            if(busy)return;
            const message=action==='approve'
              ? 'Approve and permanently lock order '+o.id+'? PDF and spreadsheet will be generated for SUPER download.'
              : 'Reject order '+o.id+'? The submitter will see the rejected status.';
            if(!window.confirm(message))return;
            let note='';
            if(action==='reject'){
              const reply=window.prompt('Reason for rejecting (optional, max 240 characters):','');
              if(reply===null)return;
              note=reply.trim();
              if(note.length>240){notice('Review note cannot exceed 240 characters.',true);return;}
            }
            busy=true;button.disabled=true;
            try{
              await api({action,order_id:o.id,note});
              notice(o.id+' '+(action==='approve'?'approved and locked.':'rejected.'));
              await refresh();
            }catch(e){notice(e.message,true);}
            finally{busy=false;button.disabled=false;}
          });
          actions.append(button);
        }
        card.append(actions);
      }
      wrap.append(card);
    });
  }
  async function refresh(){
    try{
      const out=await api();orders=out.orders;total=out.total;pageSize=out.page_size;pendingByStore=out.pending_by_store||pendingByStore;render();
    }catch(e){notice(e.message,true);$('orderHistory').textContent='Order history temporarily unavailable.';}
  }
  $('historyStore').addEventListener('change',()=>{page=1;refresh();});
  $('historyStatus').addEventListener('change',()=>{page=1;refresh();});
  $('refreshOrders').addEventListener('click',refresh);
  $('historyPrevious').addEventListener('click',()=>{if(page>1){page--;refresh();}});
  $('historyNext').addEventListener('click',()=>{if(page*pageSize<total){page++;refresh();}});
  const submit=$('submitOrder');
  if(submit){
    submit.addEventListener('click',async()=>{
      if(busy)return;
      busy=true;submit.disabled=true;
      try{
        // Wait for all queued scan and quantity operations to persist first.
        if(typeof window.merdAlphaCountReady==='function')await window.merdAlphaCountReady();
        const countResponse=await fetch('api/order_count.php',{credentials:'same-origin',cache:'no-store'});
        const draft=await countResponse.json();
        if(!countResponse.ok||!draft.success)throw new Error(draft.error||'Could not load current draft.');
        const cigarettes=draft.products.filter(x=>x.type==='AUTO'&&x.order_quantity!==null)
          .reduce((total,x)=>total+x.order_quantity,0);
        const accessories=draft.products.filter(x=>x.type==='MANUAL')
          .reduce((total,x)=>total+x.count,0);
        const excluded=draft.products.filter(p=>p.type==='AUTO'&&p.ceiling===null).length;
        if(!window.confirm('Submit '+draft.store+' order for SUPER approval?\n'+cigarettes+
          ' cigarette units; '+accessories+' accessory units.\n'+excluded+' cigarette products have no ceiling and will be excluded.\nYou can start another order after submission.')){
          return;
        }
        const out=await api({action:'submit',draft_nonce:draft.draft_nonce});
        notice(out.message+' Order ID: '+out.order.id);
        window.location.href='orders.php?submitted='+encodeURIComponent(out.order.id);
      }catch(e){
        const box=$('submissionNotice');box.textContent=e.message;box.className='order-notice order-error';box.hidden=false;
      }finally{busy=false;submit.disabled=false;}
    });
  }
  const startNew=$('newOrderDraft');
  if(startNew){startNew.addEventListener('click',async()=>{
    if(busy)return;
    if(!window.confirm('Discard current unsent counts and start a fresh order for this store? Submitted orders will be unaffected.'))return;
    busy=true;startNew.disabled=true;
    try{await api({action:'new'});window.location.href='orders.php?new=1';}
    catch(e){notice(e.message,true);busy=false;startNew.disabled=false;}
  });}
  refresh();
})();
