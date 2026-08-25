(function(){
  'use strict';

  const num=value=>{
    const n=Number(value);
    return Number.isFinite(n)?n:Number.MAX_SAFE_INTEGER;
  };
  const byId=(a,b)=>num(a?.id ?? a?.store_id)-num(b?.id ?? b?.store_id);

  function sameOrder(a,b){
    return a.length===b.length && a.every((item,index)=>item===b[index]);
  }

  function sortSelect(select){
    if(!select)return;
    const options=Array.from(select.options||[]);
    if(options.length<2)return;
    const fixed=options.filter(option=>!/^\d+$/.test(String(option.value||'')));
    const numeric=options.filter(option=>/^\d+$/.test(String(option.value||'')))
      .slice().sort((a,b)=>num(a.value)-num(b.value));
    const sorted=[...fixed,...numeric];
    if(sameOrder(options,sorted))return;
    const selected=select.value;
    sorted.forEach(option=>select.appendChild(option));
    select.value=selected;
  }

  function sortStoreRows(){
    const root=document.getElementById('storeDirectory');
    if(!root)return;
    const rows=Array.from(root.querySelectorAll(':scope > .entity-row'));
    if(rows.length<2)return;
    const sorted=rows.slice().sort((a,b)=>
      num(a.querySelector('[data-edit-store]')?.dataset.editStore)-
      num(b.querySelector('[data-edit-store]')?.dataset.editStore)
    );
    if(sameOrder(rows,sorted))return;
    sorted.forEach(row=>root.appendChild(row));
  }

  function sortStoreChoices(){
    const root=document.getElementById('employeeStoreChoices');
    if(!root)return;
    const rows=Array.from(root.querySelectorAll(':scope > .store-choice'));
    if(rows.length<2)return;
    const sorted=rows.slice().sort((a,b)=>
      num(a.querySelector('input[name="store_ids"]')?.value)-
      num(b.querySelector('input[name="store_ids"]')?.value)
    );
    if(sameOrder(rows,sorted))return;
    sorted.forEach(row=>root.appendChild(row));
  }

  function run(){
    ['financialStore','proposedStore','employeeStore','timingTarget'].forEach(id=>sortSelect(document.getElementById(id)));
    document.querySelectorAll('select[data-store-select]').forEach(sortSelect);
    sortStoreRows();
    sortStoreChoices();
  }

  let timers=[];
  function schedule(){
    timers.forEach(clearTimeout);
    timers=[0,50,150,400].map(delay=>setTimeout(run,delay));
  }

  window.MERDPOSStoreOrder={
    sortStores:list=>Array.isArray(list)?list.slice().sort(byId):[],
    run,
    schedule
  };

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',schedule,{once:true});
  }else{
    schedule();
  }

  // Deliberately no MutationObserver here. Re-appending Store rows from an
  // observer can observe its own mutations and create an endless render loop.
  document.addEventListener('click',event=>{
    if(event.target?.closest?.('[data-panel],[data-edit-store],[data-edit-employee],#addStoreBtn,#addEmployeeBtn'))schedule();
  });
  document.addEventListener('change',event=>{
    if(event.target?.matches?.('select'))schedule();
  });
  document.addEventListener('merdpos:stores-updated',schedule);
})();
