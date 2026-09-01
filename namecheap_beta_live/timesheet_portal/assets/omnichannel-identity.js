(function(){
  'use strict';

  if(window.MERDPOSOmnichannelIdentity)return;
  const permissions=window.MERDPOS_AUTH?.permissions||{};
  const brandAssets=window.MERDPOSBrandAssets||Object.freeze({lockup:'assets/brand/merdpos-logo-approved.png?v=20260827brand4',mark:'assets/brand/merdpos-mark.png?v=20260827brand4',wordmark:'assets/brand/merdpos-wordmark.png?v=20260827brand4',tagline:'assets/brand/merdpos-tagline.png?v=20260827brand4'});
  if(!permissions['dashboard.view'])return;

  let state=null;
  let fetchedAt=0;
  let staleTimer=null;
  const auth=window.MERDPOS_AUTH||{};
  const PREVIEW_ACTION_KEY='merdpos-preview-role-action-v1';

  function ensureBrandAssets(){
    if(!document.querySelector('link[data-merd-brand-css]')){
      const link=document.createElement('link');
      link.rel='stylesheet';
      link.href='assets/brand/brand.css?v=20260828palette1';
      link.dataset.merdBrandCss='1';
      document.head.appendChild(link);
    }
    if(!document.querySelector('link[data-merd-brand-icon]')){
      const icon=document.createElement('link');
      icon.rel='icon';
      icon.type='image/png';
      icon.href=brandAssets.mark;
      icon.dataset.merdBrandIcon='1';
      document.head.appendChild(icon);
    }
  }

  function patchProductBrand(){
    ensureBrandAssets();
    const root=document.querySelector('.merd-topbar .merd-logo-lockup');
    if(!root)return;
    const approved=brandAssets.lockup;
    const current=root.querySelector('.merd-brand__lockup-image');
    if(root.dataset.merdBrandPatched==='approved-v4'&&current?.getAttribute('src')===approved)return;
    root.className='merd-logo-lockup merd-brand merd-brand--header merd-brand--approved-lockup';
    root.setAttribute('aria-label','MERDPOS');
    root.innerHTML=`<img class="merd-brand__lockup-image" src="${approved}" alt="MERDPOS - Smarter Faster Together">`;
    root.dataset.merdBrandPatched='approved-v4';
  }

  async function json(url){
    const response=await fetch(url,{cache:'no-store',headers:{'Accept':'application/json'}});
    const text=await response.text();
    let data=null;
    try{data=text?JSON.parse(text):null;}catch(_){throw new Error(`Identity context returned invalid data (${response.status}).`);}
    if(!data)throw new Error(`Identity context returned an empty response (${response.status}).`);
    if(!data.success)throw new Error(data.error||'Identity context could not be loaded.');
    return data;
  }

  function identityStores(){
    return Array.isArray(state?.store_identity)&&state.store_identity.length?state.store_identity:(state?.stores||[]);
  }

  function storeMap(){
    const byId=new Map(),byName=new Map();
    identityStores().forEach(store=>{
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
      const fallback=avatar.innerHTML;
      const img=logoImg(store,'');
      if(!img)return;
      img.addEventListener('error',()=>{
        avatar.innerHTML=fallback;
        avatar.classList.remove('omni-has-logo');
        delete avatar.dataset.omniLogo;
      },{once:true});
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

  function relativeAction(label,at,fallback=`${label} now`){
    const stamp=typeof at==='number'?at:Date.parse(String(at||''));
    if(!Number.isFinite(stamp))return fallback;
    const seconds=Math.max(0,Math.round((Date.now()-stamp)/1000));
    if(seconds<45)return `${label} now`;
    const minutes=Math.floor(seconds/60);
    if(minutes<60)return `${label} ${minutes}m ago`;
    const hours=Math.floor(minutes/60);
    return `${label} ${hours}h ago`;
  }

  function relativeFreshness(){return relativeAction('Updated',fetchedAt,'Updated now');}

  function ensureStatusPills(rolebar){
    let root=document.getElementById('dashboardStatusPills');
    if(!root){root=document.createElement('div');root.id='dashboardStatusPills';root.className='omni-status-pills';root.setAttribute('role','group');root.setAttribute('aria-label','Current MERDPOS status');}
    const controls=rolebar.querySelector('.dashboard-role-controls');
    const editToggle=document.getElementById('dashboardEditToggle');
    if(controls&&editToggle?.parentElement===controls)editToggle.insertAdjacentElement('afterend',root);
    else (controls||rolebar).appendChild(root);
    return root;
  }

  function ensureStatusPill(root,id){
    let pill=document.getElementById(id);
    if(!pill){pill=document.createElement('span');pill.id=id;pill.className='omni-status-pill';pill.innerHTML='<span class="omni-status-pill-dot"></span><strong></strong><span class="omni-status-pill-action"></span>';root.appendChild(pill);}
    return pill;
  }

  function renderStatusPill(pill,{name,action,title='',stale=false}){
    pill.querySelector('strong').textContent=name;
    pill.querySelector('.omni-status-pill-action').textContent=action;
    pill.classList.toggle('is-stale',!!stale);
    pill.title=title;
  }

  function currentUserStatus(){
    const user=state?.current_user||{},shopActive=!!user.shop_active,loginAt=Date.parse(String(user.portal_login_at||'')),shopAt=Date.parse(String(user.shop_clock_in_at||''));
    let action='Active now';
    if(Number.isFinite(shopAt)&&(!Number.isFinite(loginAt)||shopAt>=loginAt))action=relativeAction('Clocked in',shopAt);
    else if(Number.isFinite(loginAt))action=relativeAction('Logged in',loginAt);
    const channel=shopActive?'Portal + Shop':'Portal';
    return {name:String(user.name||state?.current_user_id||'User'),action:`${channel} · ${action}`,title:`Current authenticated user. Portal session active.${shopActive?` Shop: ${user.shop_name||'active'}.`:''} ${action}.`};
  }

  function previewRoleStatus(){
    const key=String(auth.view_role_key||auth.role_key||'DEV').trim().toUpperCase(),name=String(auth.role_label||key||'Developer').trim(),prefix=auth.is_role_preview?'Preview':'Actual role';
    let record=null;try{record=JSON.parse(localStorage.getItem(PREVIEW_ACTION_KEY)||'null');}catch(_){}
    const switched=record&&String(record.key||'').toUpperCase()===key?relativeAction('Switched',Number(record.at),''):'';
    const action=`${prefix} · ${switched||'Active now'}`;
    return {name,action,title:`Current DEV presentation role: ${name}. Actual authenticated identity remains ${auth.actual_role_label||'Developer'}.`};
  }

  function patchFreshness(){
    const rolebar=document.getElementById('dashboardRolebar');
    if(rolebar&&state){
      const root=ensureStatusPills(rolebar),userPill=ensureStatusPill(root,'omniCurrentUser');
      renderStatusPill(userPill,currentUserStatus());
      if(auth.is_dev===true){const rolePill=ensureStatusPill(root,'omniPreviewRole');renderStatusPill(rolePill,previewRoleStatus());}
      const badge=ensureStatusPill(root,'omniFreshness');badge.classList.add('omni-freshness');badge.querySelector('.omni-status-pill-dot').classList.add('omni-freshness-dot');
      const clientCode=String(state.client?.client_code||'').trim(),fresh=relativeFreshness();
      renderStatusPill(badge,{name:clientCode||'Live',action:fresh,stale:Date.now()-fetchedAt>5*60*1000,title:`Data context: ${state.client?.name||'MERDPOS'}${clientCode?` (${clientCode})`:''}. ${fresh}.`});
    }

    const meta=document.getElementById('accountContextMeta');
    if(meta&&state){let status=meta.querySelector('.omni-context-status');if(!status){status=document.createElement('span');status.className='omni-context-status';meta.appendChild(status);}status.textContent=`Live context · ${relativeFreshness()}`;}
  }

  function patchDocumentIdentity(){
    if(!state)return;
    const name=String(state.client?.name||'').trim();
    const code=String(state.client?.client_code||'').trim();
    if(name)document.title=`MERDPOS · ${name}`;
    document.documentElement.dataset.merdClient=code||String(state.client?.id||'');
  }

  function patchAll(){
    patchProductBrand();
    patchDocumentIdentity();
    patchStoreDirectory();
    patchNamedStoreLabels();
    patchFreshness();
  }

  function queuePatches(){
    [0,80,220,520,1000,1800].forEach(delay=>window.setTimeout(patchAll,delay));
  }

  async function load(){
    patchProductBrand();
    try{
      state=await json('api/beta_state.php?_='+Date.now());
      const serverTime=Date.parse(state.generated_at||'');
      fetchedAt=Number.isFinite(serverTime)?serverTime:Date.now();
      patchAll();
      queuePatches();
      window.dispatchEvent(new CustomEvent('merdpos:identity-ready',{detail:{client:state.client,stores:identityStores(),generated_at:state.generated_at}}));
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
