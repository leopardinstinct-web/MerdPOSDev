(function(){
  'use strict';

  if(window.MERDPOSOmnichannelIdentity)return;
  const permissions=window.MERDPOS_AUTH?.permissions||{};
  if(!permissions['dashboard.view'])return;

  let state=null;
  let fetchedAt=0;
  let staleTimer=null;
  const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  async function json(url){
    const response=await fetch(url,{cache:'no-store',headers:{'Accept':'application/json'}});
    const text=await response.text();
    let data=null;
    try{data=text?JSON.parse(text):null;}catch(_){throw new Error(`Identity context returned invalid data (${response.status}).`);}
    if(!data)throw new Error(`Identity context returned an empty response (${response.status}).`);
    if(!data.success)throw new Error(data.error||'Identity context could not be loaded.');
    return data;
  }

  function storeMap(){
    const byId=new Map(),byName=new Map();
    (state?.stores||[]).forEach(store=>{
      byId.set(Number(store.id),store);
      byName.set(String(store.store_name||'').trim().toLowerCase(),store);
    });
    return{byId,byName};
  }

  function logoImg(store,cls='omni-inline-store-logo'){
    if(!store?.logo_path)return null;
    const img=document.createElement('img');
    img.className=cls;
    img.src=store.logo_path;
    img.alt=`${store.store_name||'Store'} logo`;
    img.loading='lazy';
    img.decoding='async';
    img.addEventListener('error',()=>img.remove(),{once:true});
    return img;
  }

  function patchStoreDirectory(){
    const root=document.getElementById('storeDirectory');
    if(!root||!state)return;
    const maps=storeMap();
    root.querySelectorAll('.entity-row').forEach(row=>{
      const button=row.querySelector('[data-edit-store]');
      const title=String(row.querySelector('.entity-title-line strong')?.textContent||'').trim().toLowerCase();
      const store=(button?maps.byId.get(Number(button.dataset.editStore)):null)||maps.byName.get(title);
      const avatar=row.querySelector('.entity-avatar.store-avatar');
      if(!store||!avatar||!store.logo_path)return;
      if(avatar.dataset.omniLogo===String(store.logo_path))return;
      const img=logoImg(store,'');
      if(!img)return;
      avatar.replaceChildren(img);
      avatar.classList.add('omni-has-logo');
      avatar.dataset.omniLogo=String(store.logo_path);
    });
  }

  function patchNamedStoreLabels(){
    if(!state)return;
    const maps=storeMap();
    const selectors=['.dashboard-bar-label','.chart-label','.dashboard-mini-table tbody td:nth-child(2)'];
    document.querySelectorAll(selectors.join(',')).forEach(label=>{
      if(label.querySelector('.omni-inline-store-logo'))return;
      const name=String(label.textContent||'').trim().toLowerCase();
      const store=maps.byName.get(name);
      const img=logoImg(store);
      if(img)label.prepend(img);
    });

    document.querySelectorAll('.dashboard-list-row').forEach(row=>{
      const storeLabel=row.querySelector('div span');
      if(!storeLabel||storeLabel.querySelector('.omni-inline-store-logo'))return;
      const store=maps.byName.get(String(storeLabel.textContent||'').trim().toLowerCase());
      const img=logoImg(store);
      if(img)storeLabel.prepend(img);
    });
  }

  function relativeFreshness(){
    if(!fetchedAt)return 'Updated now';
    const seconds=Math.max(0,Math.round((Date.now()-fetchedAt)/1000));
    if(seconds<45)return 'Updated now';
    const minutes=Math.floor(seconds/60);
    if(minutes<60)return `Updated ${minutes}m ago`;
    const hours=Math.floor(minutes/60);
    return `Updated ${hours}h ago`;
  }

  function patchFreshness(){
    const rolebar=document.getElementById('dashboardRolebar');
    if(rolebar&&state){
      let badge=document.getElementById('omniFreshness');
      if(!badge){
        badge=document.createElement('span');
        badge.id='omniFreshness';
        badge.className='omni-freshness';
        badge.innerHTML='<span class="omni-freshness-dot"></span><strong></strong><span></span>';
        rolebar.appendChild(badge);
      }
      const clientCode=String(state.client?.client_code||'').trim();
      badge.querySelector('strong').textContent=clientCode||'Live';
      badge.querySelector('span:last-child').textContent=relativeFreshness();
      badge.classList.toggle('is-stale',Date.now()-fetchedAt>5*60*1000);
      badge.title=`Data context: ${state.client?.name||'MERDPOS'}${clientCode?` (${clientCode})`:''}. ${relativeFreshness()}.`;
    }

    const meta=document.getElementById('accountContextMeta');
    if(meta&&state){
      let status=meta.querySelector('.omni-context-status');
      if(!status){
        status=document.createElement('span');
        status.className='omni-context-status';
        meta.appendChild(status);
      }
      status.textContent=`Live context · ${relativeFreshness()}`;
    }
  }

  function patchDocumentIdentity(){
    if(!state)return;
    const name=String(state.client?.name||'').trim();
    const code=String(state.client?.client_code||'').trim();
    if(name)document.title=`MERDPOS · ${name}`;
    document.documentElement.dataset.merdClient=code||String(state.client?.id||'');
  }

  function patchAll(){
    patchDocumentIdentity();
    patchStoreDirectory();
    patchNamedStoreLabels();
    patchFreshness();
  }

  function queuePatches(){
    [0,80,220,520,1000,1800].forEach(delay=>window.setTimeout(patchAll,delay));
  }

  async function load(){
    try{
      state=await json('api/beta_state.php?_='+Date.now());
      const serverTime=Date.parse(state.generated_at||'');
      fetchedAt=Number.isFinite(serverTime)?serverTime:Date.now();
      patchAll();
      queuePatches();
      window.dispatchEvent(new CustomEvent('merdpos:identity-ready',{detail:{client:state.client,stores:state.stores,generated_at:state.generated_at}}));
    }catch(error){
      console.error('MERDPOS omnichannel identity:',error);
    }
  }

  document.addEventListener('click',event=>{
    if(event.target.closest('[data-panel="storesPanel"],#refreshBetaBtn,.dashboard-rolebar,[data-panel="dashboardPanel"]'))queuePatches();
  });
  document.getElementById('storeSearch')?.addEventListener('input',()=>window.setTimeout(patchStoreDirectory,40));
  document.addEventListener('visibilitychange',()=>{
    if(document.visibilityState==='visible'&&Date.now()-fetchedAt>2*60*1000)load();
  });

  staleTimer=window.setInterval(patchFreshness,30000);
  window.addEventListener('beforeunload',()=>window.clearInterval(staleTimer),{once:true});

  window.MERDPOSOmnichannelIdentity={refresh:load,get:()=>state,patch:patchAll};
  load();
})();
