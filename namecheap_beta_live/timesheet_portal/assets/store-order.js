(function(){
  const num=value=>{const n=Number(value);return Number.isFinite(n)?n:Number.MAX_SAFE_INTEGER;};
  const byId=(a,b)=>num(a?.id ?? a?.store_id)-num(b?.id ?? b?.store_id);

  function sortSelect(select){
    if(!select)return;
    const options=Array.from(select.options||[]);
    const fixed=options.filter(o=>!/^\d+$/.test(String(o.value||'')));
    const numeric=options.filter(o=>/^\d+$/.test(String(o.value||''))).sort((a,b)=>num(a.value)-num(b.value));
    if(numeric.length<2)return;
    const selected=select.value;
    [...fixed,...numeric].forEach(o=>select.appendChild(o));
    select.value=selected;
  }

  function sortStoreRows(){
    const root=document.getElementById('storeDirectory');
    if(!root)return;
    Array.from(root.querySelectorAll(':scope > .entity-row'))
      .sort((a,b)=>num(a.querySelector('[data-edit-store]')?.dataset.editStore)-num(b.querySelector('[data-edit-store]')?.dataset.editStore))
      .forEach(row=>root.appendChild(row));
  }

  function sortStoreChoices(){
    const root=document.getElementById('employeeStoreChoices');
    if(!root)return;
    Array.from(root.querySelectorAll(':scope > .store-choice'))
      .sort((a,b)=>num(a.querySelector('input[name="store_ids"]')?.value)-num(b.querySelector('input[name="store_ids"]')?.value))
      .forEach(row=>root.appendChild(row));
  }

  function run(){
    ['financialStore','proposedStore','employeeStore','timingTarget'].forEach(id=>sortSelect(document.getElementById(id)));
    document.querySelectorAll('select[data-store-select]').forEach(sortSelect);
    sortStoreRows();
    sortStoreChoices();
  }

  let timer=null;
  const schedule=()=>{clearTimeout(timer);timer=setTimeout(run,25);};
  window.MERDPOSStoreOrder={sortStores:list=>Array.isArray(list)?list.slice().sort(byId):[],run};
  new MutationObserver(schedule).observe(document.body,{childList:true,subtree:true});
  document.addEventListener('change',event=>{if(event.target?.matches?.('select'))schedule();});
  run();
})();
