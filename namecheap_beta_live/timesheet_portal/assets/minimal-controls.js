(function(){
  'use strict';

  const plusSvg='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
  const labels={
    addEmployeeBtn:'Add employee',
    addStoreBtn:'Add store',
    addClientBtn:'Add client',
    addRoleBtn:'Add role'
  };

  function makeAddButton(button,label){
    if(!button||button.dataset.minimalAdd==='1')return;
    const text=String(label||button.textContent||'Add').replace(/^\s*\+\s*/,'').trim()||'Add';
    button.dataset.minimalAdd='1';
    button.classList.add('merd-icon-action','merd-add-action');
    button.setAttribute('aria-label',text);
    button.setAttribute('title',text);
    button.innerHTML=plusSvg;
  }

  function searchWrapperFromInput(input){
    if(!input)return null;
    return input.closest('.search-box,.clients-admin-search,[data-collapsible-search]');
  }

  function openSearch(wrapper,input){
    wrapper.classList.add('is-open');
    wrapper.dataset.searchOpen='1';
    window.requestAnimationFrame(()=>input.focus({preventScroll:true}));
  }

  function closeSearch(wrapper,input){
    if(String(input.value||'').trim()!=='')return;
    wrapper.classList.remove('is-open');
    wrapper.dataset.searchOpen='0';
    input.blur();
  }

  function makeSearch(wrapper){
    if(!wrapper||wrapper.dataset.minimalSearch==='1')return;
    const input=wrapper.querySelector('input[type="search"]');
    if(!input)return;
    wrapper.dataset.minimalSearch='1';
    wrapper.dataset.searchOpen=String(input.value||'').trim()!==''?'1':'0';
    wrapper.classList.add('merd-collapsible-search');
    if(wrapper.dataset.searchOpen==='1')wrapper.classList.add('is-open');
    wrapper.setAttribute('title',wrapper.getAttribute('aria-label')||'Search');

    wrapper.addEventListener('click',event=>{
      if(event.target===input)return;
      if(!wrapper.classList.contains('is-open')){
        event.preventDefault();
        openSearch(wrapper,input);
      }
    });
    input.addEventListener('focus',()=>wrapper.classList.add('is-open'));
    input.addEventListener('input',()=>{
      if(String(input.value||'').trim()!=='')wrapper.classList.add('is-open');
    });
    input.addEventListener('keydown',event=>{
      if(event.key!=='Escape')return;
      event.preventDefault();
      if(String(input.value||'').trim()!==''){
        input.value='';
        input.dispatchEvent(new Event('input',{bubbles:true}));
      }
      closeSearch(wrapper,input);
    });
    input.addEventListener('blur',()=>window.setTimeout(()=>closeSearch(wrapper,input),120));
  }

  function apply(root=document){
    Object.entries(labels).forEach(([id,label])=>{
      const button=(root.getElementById?root.getElementById(id):null)||document.getElementById(id);
      makeAddButton(button,label);
    });

    const inputs=[];
    if(root.matches?.('input[type="search"]'))inputs.push(root);
    root.querySelectorAll?.('input[type="search"]').forEach(input=>inputs.push(input));
    inputs.forEach(input=>makeSearch(searchWrapperFromInput(input)));
  }

  let scheduled=false;
  function scheduleApply(){
    if(scheduled)return;
    scheduled=true;
    window.requestAnimationFrame(()=>{scheduled=false;apply(document);});
  }

  apply(document);
  document.addEventListener('DOMContentLoaded',()=>apply(document),{once:true});

  // New Client/Role controls are rendered dynamically. This observer never
  // reparents or appends observed nodes; it only applies idempotent classes and
  // accessibility attributes, avoiding the recursive mutation bug seen before.
  const observer=new MutationObserver(mutations=>{
    if(mutations.some(m=>m.addedNodes&&m.addedNodes.length))scheduleApply();
  });
  observer.observe(document.body,{childList:true,subtree:true});

  window.MERDPOSMinimalControls={apply:()=>apply(document)};
})();
