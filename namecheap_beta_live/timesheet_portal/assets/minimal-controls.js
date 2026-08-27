(function(){
  'use strict';

  const plusSvg='<svg data-merd-add-icon="1" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
  const labels={
    addEmployeeBtn:'Add employee',
    addStoreBtn:'Add store',
    addClientBtn:'Add client',
    addRoleBtn:'Add role'
  };

  function ensureControlStyle(){
    if(document.getElementById('merdMinimalControlStyle'))return;
    const style=document.createElement('style');
    style.id='merdMinimalControlStyle';
    style.textContent='.dashboard-role-controls .dashboard-edit-toggle{flex:0 0 auto;width:max-content;min-width:max-content;max-width:none;white-space:nowrap}';
    document.head.appendChild(style);
  }

  function makeAddButton(button,label){
    if(!button)return;
    const text=String(label||button.getAttribute('aria-label')||button.textContent||'Add').replace(/^\s*\+\s*/,'').trim()||'Add';
    button.dataset.minimalAdd='1';
    button.dataset.merdActionPrimitive='add';
    button.classList.add('merd-icon-action','merd-add-action');
    button.setAttribute('aria-label',text);
    button.setAttribute('title',text);

    // This function is called from a childList MutationObserver. Replacing an
    // already-normalized SVG would make the observer trigger itself forever and
    // can invalidate the pointer target between press and release.
    const icon=button.querySelector(':scope > svg[data-merd-add-icon="1"]');
    const iconIsStable=!!icon&&button.children.length===1&&String(button.textContent||'').trim()==='';
    if(!iconIsStable)button.innerHTML=plusSvg;
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
    if(!wrapper)return;
    const input=wrapper.querySelector('input[type="search"]');
    if(!input)return;
    wrapper.dataset.minimalSearch='1';
    wrapper.dataset.merdActionPrimitive='search';
    wrapper.dataset.searchOpen=String(input.value||'').trim()!==''?'1':'0';
    wrapper.classList.add('merd-collapsible-search');
    if(wrapper.dataset.searchOpen==='1')wrapper.classList.add('is-open');
    const label=wrapper.getAttribute('aria-label')||input.getAttribute('aria-label')||input.placeholder||'Search';
    wrapper.setAttribute('aria-label',label);
    wrapper.setAttribute('title',label);

    if(wrapper.dataset.minimalSearchBound==='1')return;
    wrapper.dataset.minimalSearchBound='1';

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

  function clusterSearchAndAdd(input){
    const wrapper=searchWrapperFromInput(input);
    if(!wrapper)return;
    let parent=wrapper.parentElement;
    if(!parent)return;
    let addButton=parent.querySelector(':scope > .merd-add-action');

    // Stores had a DEV-only runtime that historically moved Search into the title
    // wrapper. The canonical action primitive owns Search + Add composition, so
    // repair that stale DOM state by returning Search to .directory-actions.
    if(!addButton&&input.id==='storeSearch'){
      const actions=document.querySelector('#storesPanel .directory-actions');
      const storeAdd=actions?.querySelector(':scope > .merd-add-action');
      if(actions&&storeAdd){
        actions.insertBefore(wrapper,storeAdd);
        parent=actions;
        addButton=storeAdd;
      }
    }

    if(!addButton)return;
    parent.classList.add('merd-action-cluster');
    parent.dataset.merdActionCluster='search-add';

    // Canonical order is Search then Add. Current MERDPOS toolbars already use
    // this order, but enforce it for future modules without creating new nodes.
    if(wrapper.nextElementSibling!==addButton){
      parent.insertBefore(wrapper,addButton);
    }
  }

  function normalizeDashboardAdd(root=document){
    const buttons=[];
    if(root.matches?.('.dashboard-add-button'))buttons.push(root);
    root.querySelectorAll?.('.dashboard-add-button').forEach(button=>buttons.push(button));
    buttons.forEach(button=>makeAddButton(button,'Add widget'));
  }

  function apply(root=document){
    ensureControlStyle();
    Object.entries(labels).forEach(([id,label])=>{
      const button=(root.getElementById?root.getElementById(id):null)||document.getElementById(id);
      makeAddButton(button,label);
    });

    normalizeDashboardAdd(root);

    const inputs=[];
    if(root.matches?.('input[type="search"]'))inputs.push(root);
    root.querySelectorAll?.('input[type="search"]').forEach(input=>inputs.push(input));
    inputs.forEach(input=>makeSearch(searchWrapperFromInput(input)));
    inputs.forEach(clusterSearchAndAdd);
  }

  let scheduled=false;
  function scheduleApply(){
    if(scheduled)return;
    scheduled=true;
    window.requestAnimationFrame(()=>{scheduled=false;apply(document);});
  }

  apply(document);
  document.addEventListener('DOMContentLoaded',()=>apply(document),{once:true});

  // Dynamic Client/Role/Dashboard controls are normalized idempotently. The
  // observer never fabricates feature UI; it only applies the shared primitive.
  const observer=new MutationObserver(mutations=>{
    if(mutations.some(m=>m.addedNodes&&m.addedNodes.length))scheduleApply();
  });
  observer.observe(document.body,{childList:true,subtree:true});

  window.MERDPOSMinimalControls={apply:()=>apply(document)};
})();
