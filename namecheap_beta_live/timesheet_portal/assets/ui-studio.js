(function(){
  'use strict';
  if(window.MERDPOS_AUTH?.is_dev!==true)return;

  const STORAGE_KEY='merdpos-ui-studio-draft-v1';
  const PREVIEW_STYLE_ID='merdUiStudioPreviewStyle';
  const PALETTE=[
    ['White','var(--color-brand-white)','#FFFFFF'],
    ['App Background','var(--color-brand-background)','#F5F7FC'],
    ['Brand Navy','var(--color-brand-navy)','#031B4B'],
    ['Brand Cyan','var(--color-brand-cyan)','#12BDF3'],
    ['Violet','var(--color-brand-violet)','#8B2EFF'],
  ];
  const cssEscape=value=>window.CSS?.escape?CSS.escape(String(value)):String(value).replace(/[^a-zA-Z0-9_-]/g,'\\$&');
  const attrEscape=value=>String(value).replace(/\\/g,'\\\\').replace(/"/g,'\\"');
  const state={patches:[],selected:null,hovered:null,selectMode:false,moveMode:null,applying:false};
  let studioRoot=null;
  let previewBadge=null;
  let previewStyle=null;

  function isStudioNode(node){return !!node?.closest?.('[data-ui-studio]');}
  function isEditable(node){
    if(!(node instanceof Element)||isStudioNode(node))return false;
    if(['HTML','BODY','SCRIPT','STYLE','LINK','META'].includes(node.tagName))return false;
    return !!node.closest('.merd-page-shell, dialog.portal-dialog, .merd-about-dialog');
  }
  function uniqueAttributeSelector(el){
    for(const name of ['data-panel','data-report-target','data-finance-panel','data-dashboard-action','name','aria-label']){
      const value=el.getAttribute(name);
      if(!value)continue;
      const candidate=`${el.tagName.toLowerCase()}[${name}="${attrEscape(value)}"]`;
      try{if(document.querySelectorAll(candidate).length===1)return candidate;}catch(_){}
    }
    return '';
  }

  function selectorFor(el){
    if(el.id)return `#${cssEscape(el.id)}`;
    const unique=uniqueAttributeSelector(el);
    if(unique)return unique;
    const parts=[];
    let node=el;
    while(node&&node!==document.body&&parts.length<6){
      if(node.id){parts.unshift(`#${cssEscape(node.id)}`);break;}
      let part=node.tagName.toLowerCase();
      const classes=[...node.classList].filter(c=>!c.startsWith('merd-ui-studio')&&!['active','open','selected'].includes(c)).slice(0,2);
      if(classes.length)part+=classes.map(c=>`.${cssEscape(c)}`).join('');
      const parent=node.parentElement;
      if(parent){
        const peers=[...parent.children].filter(child=>child.tagName===node.tagName);
        if(peers.length>1)part+=`:nth-of-type(${peers.indexOf(node)+1})`;
      }
      parts.unshift(part);
      node=parent;
    }
    return parts.join(' > ');
  }
  function persist(){
    try{localStorage.setItem(STORAGE_KEY,JSON.stringify({version:1,patches:state.patches}));}catch(_){}
  }
  function loadDraft(){
    try{
      const parsed=JSON.parse(localStorage.getItem(STORAGE_KEY)||'null');
      state.patches=Array.isArray(parsed?.patches)?parsed.patches.filter(Boolean):[];
    }catch(_){state.patches=[];}
  }
  function ensurePreviewStyle(){
    previewStyle=document.getElementById(PREVIEW_STYLE_ID);
    if(previewStyle)return previewStyle;
    previewStyle=document.createElement('style');
    previewStyle.id=PREVIEW_STYLE_ID;
    previewStyle.dataset.uiStudio='preview';
    document.head.appendChild(previewStyle);
    return previewStyle;
  }
  function styleRules(){
    const bySelector=new Map();
    for(const patch of state.patches){
      if(patch.kind!=='style'||!patch.selector||!patch.property)continue;
      const selector=patch.runtimeKey?runtimeSelector(patch.runtimeKey):patch.selector;
      if(!bySelector.has(selector))bySelector.set(selector,[]);
      bySelector.get(selector).push(`${patch.property}:${patch.value} !important`);
    }
    return [...bySelector].map(([selector,declarations])=>`${selector}{${declarations.join(';')}}`).join('\n');
  }
  function find(selector){try{return document.querySelector(selector);}catch(_){return null;}}
  let runtimeCounter=0;
  function runtimeKeyFor(el,preferred=''){
    if(!el)return '';
    if(el.dataset.uiStudioRuntimeKey)return el.dataset.uiStudioRuntimeKey;
    const key=preferred||`us-${Date.now().toString(36)}-${(++runtimeCounter).toString(36)}`;
    el.dataset.uiStudioRuntimeKey=key;
    return key;
  }
  function runtimeSelector(key){return `[data-ui-studio-runtime-key="${attrEscape(key)}"]`;}
  function primeRuntimeKeys(){
    for(const patch of state.patches){
      if(patch.runtimeKey){const node=find(runtimeSelector(patch.runtimeKey))||find(patch.selector);if(node)runtimeKeyFor(node,patch.runtimeKey);}
      if(patch.kind==='move'&&patch.targetKey){const target=find(runtimeSelector(patch.targetKey))||find(patch.target);if(target)runtimeKeyFor(target,patch.targetKey);}
    }
  }
  function applyMove(patch){
    const source=(patch.runtimeKey&&find(runtimeSelector(patch.runtimeKey)))||find(patch.selector),target=(patch.targetKey&&find(runtimeSelector(patch.targetKey)))||find(patch.target);
    if(!source||!target||source===target||source.contains(target))return;
    if(patch.position==='before'){
      if(source.nextSibling===target&&source.parentElement===target.parentElement)return;
      target.parentElement?.insertBefore(source,target);
    }else if(patch.position==='after'){
      if(target.nextSibling===source&&source.parentElement===target.parentElement)return;
      target.parentElement?.insertBefore(source,target.nextSibling);
    }else if(patch.position==='inside'){
      if(source.parentElement===target&&target.lastElementChild===source)return;
      target.appendChild(source);
    }
  }
  function applyAll(){
    if(state.applying)return;
    state.applying=true;
    primeRuntimeKeys();
    ensurePreviewStyle().textContent=styleRules();
    for(const patch of state.patches)if(patch.kind==='move')applyMove(patch);
    state.applying=false;
    renderChanges();
    updateBadge();
  }
  function upsertStyle(selector,property,value){
    const existing=state.patches.find(p=>p.kind==='style'&&p.selector===selector&&p.property===property);
    const node=state.selected||find(selector);
    const runtimeKey=currentRuntimeKey()||existing?.runtimeKey||(node?runtimeKeyFor(node):'');
    state.patches=state.patches.filter(p=>!(p.kind==='style'&&p.selector===selector&&p.property===property));
    if(value!==null&&value!=='')state.patches.push({kind:'style',selector,runtimeKey,property,value});
    persist();applyAll();
  }
  function recordMove(selector,target,position){
    const source=state.selected||find(selector),targetNode=find(target);
    const runtimeKey=source?runtimeKeyFor(source):'';
    const targetKey=targetNode?runtimeKeyFor(targetNode):'';
    state.patches=state.patches.filter(p=>!(p.kind==='move'&&p.selector===selector));
    state.patches.push({kind:'move',selector,runtimeKey,target,targetKey,position});
    persist();applyAll();
  }
  function patchSummary(patch){
    if(patch.kind==='move')return `${patch.selector} → move ${patch.position} ${patch.target}`;
    return `${patch.selector} → ${patch.property}: ${patch.value}`;
  }
  function exportPayload(){
    return {
      version:1,
      page:location.pathname,
      theme:document.documentElement.dataset.theme||'light',
      viewport:{width:window.innerWidth,height:window.innerHeight},
      patches:state.patches.map(({runtimeKey,targetKey,...patch})=>patch),
    };
  }
  function renderChanges(){
    if(!studioRoot)return;
    const list=studioRoot.querySelector('[data-studio-changes]');
    const output=studioRoot.querySelector('[data-studio-output]');
    const count=studioRoot.querySelector('[data-studio-count]');
    if(count)count.textContent=String(state.patches.length);
    if(list)list.innerHTML=state.patches.length?state.patches.map((p,i)=>`<li><span>${escapeHtml(patchSummary(p))}</span><button type="button" data-remove-patch="${i}" aria-label="Remove change ${i+1}">×</button></li>`).join(''):'<li class="ui-studio-empty">No preview changes yet.</li>';
    if(output)output.value=JSON.stringify(exportPayload(),null,2);
  }
  function escapeHtml(value){
    return String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  }
  function updateBadge(){
    if(!previewBadge)return;
    previewBadge.hidden=state.patches.length===0;
    previewBadge.textContent=`UI STUDIO PREVIEW · ${state.patches.length} change${state.patches.length===1?'':'s'}`;
  }
  function paletteOptions(label){
    return `<label>${label}<select data-studio-style-select><option value="">No preview change</option><option value="transparent">Transparent</option>${PALETTE.map(([name,value,hex])=>`<option value="${escapeHtml(value)}">${escapeHtml(name)} · ${hex}</option>`).join('')}</select></label>`;
  }
  function ensureChrome(){
    if(studioRoot)return;
    previewBadge=document.createElement('button');
    previewBadge.type='button';
    previewBadge.className='merd-ui-studio-badge';
    previewBadge.dataset.uiStudio='badge';
    previewBadge.hidden=true;
    previewBadge.addEventListener('click',openStudio);
    document.body.appendChild(previewBadge);

    studioRoot=document.createElement('aside');
    studioRoot.className='merd-ui-studio';
    studioRoot.dataset.uiStudio='panel';
    studioRoot.hidden=true;
    studioRoot.innerHTML=`
      <header class="ui-studio-head">
        <div><strong>MERDPOS UI Studio</strong><span>DEV · PREVIEW ONLY</span></div>
        <button type="button" data-studio-close aria-label="Close UI Studio">×</button>
      </header>
      <div class="ui-studio-body">
        <section class="ui-studio-section ui-studio-intro">
          <p>Edit this browser's rendered UI. Nothing here writes source, APIs or data.</p>
          <button type="button" class="ui-studio-primary" data-studio-select>Select element</button>
          <p data-studio-instruction>Choose Select element, then click anything in the app.</p>
        </section>
        <section class="ui-studio-section" data-studio-inspector hidden>
          <div class="ui-studio-section-head"><strong>Selected element</strong><button type="button" data-studio-reset>Reset element</button></div>
          <code data-studio-selector></code>
          <div class="ui-studio-grid">
            ${paletteOptions('Background')}
            ${paletteOptions('Text color')}
            <label>Padding<input data-style-prop="padding" type="text" placeholder="e.g. 16px"></label>
            <label>Margin<input data-style-prop="margin" type="text" placeholder="e.g. 8px"></label>
            <label>Gap<input data-style-prop="gap" type="text" placeholder="e.g. 12px"></label>
            <label>Radius<input data-style-prop="border-radius" type="text" placeholder="e.g. 14px"></label>
            <label>Width<input data-style-prop="width" type="text" placeholder="e.g. 100%"></label>
            <label>Font size<input data-style-prop="font-size" type="text" placeholder="e.g. 18px"></label>
          </div>
          <div class="ui-studio-actions">
            <button type="button" data-studio-hide>Hide</button>
            <button type="button" data-studio-move="before">Move before…</button>
            <button type="button" data-studio-move="after">Move after…</button>
            <button type="button" data-studio-move="inside">Move inside…</button>
          </div>
        </section>
        <section class="ui-studio-section">
          <div class="ui-studio-section-head"><strong>Change-set</strong><span><b data-studio-count>0</b> changes</span></div>
          <ol class="ui-studio-changes" data-studio-changes></ol>
          <textarea data-studio-output readonly aria-label="UI Studio change-set JSON"></textarea>
          <div class="ui-studio-actions">
            <button type="button" data-studio-copy>Copy change-set</button>
            <button type="button" data-studio-undo>Undo last</button>
            <button type="button" class="ui-studio-danger" data-studio-clear>Clear + reload</button>
          </div>
          <p class="ui-studio-status" data-studio-status aria-live="polite"></p>
        </section>
      </div>`;
    document.body.appendChild(studioRoot);
    bindChrome();
    renderChanges();
    updateBadge();
  }

  function setInstruction(text){
    const node=studioRoot?.querySelector('[data-studio-instruction]');
    if(node)node.textContent=text;
  }
  function setStatus(text){
    const node=studioRoot?.querySelector('[data-studio-status]');
    if(node)node.textContent=text;
  }
  function openStudio(){
    ensureChrome();
    studioRoot.hidden=false;
    document.body.classList.add('merd-ui-studio-open');
    setStatus(state.patches.length?'Draft preview is active.':'Preview only — no server changes.');
  }
  function closeStudio(){
    if(!studioRoot)return;
    studioRoot.hidden=true;
    document.body.classList.remove('merd-ui-studio-open','merd-ui-studio-selecting','merd-ui-studio-moving');
    state.selectMode=false;state.moveMode=null;clearHover();
  }
  function clearHover(){
    state.hovered?.classList?.remove('merd-ui-studio-hover');
    state.hovered=null;
  }
  function clearSelected(){
    state.selected?.classList?.remove('merd-ui-studio-selected');
    state.selected=null;
  }
  function selectElement(el,selectorOverride=''){
    clearSelected();clearHover();
    state.selected=el;
    el.classList.add('merd-ui-studio-selected');
    const selector=selectorOverride||selectorFor(el);
    const runtimeKey=runtimeKeyFor(el);
    const inspector=studioRoot.querySelector('[data-studio-inspector]');
    inspector.hidden=false;
    inspector.dataset.selector=selector;
    inspector.dataset.runtimeKey=runtimeKey;
    studioRoot.querySelector('[data-studio-selector]').textContent=selector;
    const style=getComputedStyle(el);
    for(const input of inspector.querySelectorAll('[data-style-prop]')){
      input.value='';
      input.placeholder=style.getPropertyValue(input.dataset.styleProp).trim()||input.placeholder;
    }
    const selects=inspector.querySelectorAll('[data-studio-style-select]');
    selects.forEach(select=>select.value='');
    setInstruction('Selected. Adjust values below or choose a move command.');
  }
  function currentSelector(){return studioRoot?.querySelector('[data-studio-inspector]')?.dataset.selector||'';}
  function currentRuntimeKey(){return studioRoot?.querySelector('[data-studio-inspector]')?.dataset.runtimeKey||'';}
  function startSelect(){
    state.selectMode=true;state.moveMode=null;clearHover();
    document.body.classList.add('merd-ui-studio-selecting');
    document.body.classList.remove('merd-ui-studio-moving');
    setInstruction('Click an element in the MERDPOS interface to edit it.');
  }
  function startMove(position){
    const selector=currentSelector();
    if(!selector)return;
    state.moveMode={selector,position};state.selectMode=false;clearHover();
    document.body.classList.add('merd-ui-studio-moving');
    document.body.classList.remove('merd-ui-studio-selecting');
    setInstruction(`Move mode: click the destination element (${position}).`);
  }
  function reloadWith(patches){
    state.patches=patches;
    persist();
    location.reload();
  }
  function resetSelected(){
    const selector=currentSelector();
    if(!selector)return;
    const removedMove=state.patches.some(p=>p.kind==='move'&&p.selector===selector);
    state.patches=state.patches.filter(p=>p.selector!==selector);
    persist();
    if(removedMove){location.reload();return;}
    applyAll();
    selectElement(state.selected);
  }
  function toggleHidden(){
    const selector=currentSelector();
    if(!selector)return;
    const hidden=state.patches.some(p=>p.kind==='style'&&p.selector===selector&&p.property==='display'&&p.value==='none');
    upsertStyle(selector,'display',hidden?null:'none');
    updateHideButton();
  }
  function updateHideButton(){
    const button=studioRoot?.querySelector('[data-studio-hide]');
    const selector=currentSelector();
    if(!button||!selector)return;
    const hidden=state.patches.some(p=>p.kind==='style'&&p.selector===selector&&p.property==='display'&&p.value==='none');
    button.textContent=hidden?'Show':'Hide';
  }
  async function copyChanges(){
    const text=JSON.stringify(exportPayload(),null,2);
    try{await navigator.clipboard.writeText(text);setStatus('Change-set copied. Paste it into chat, or tell me to read the open UI Studio draft.');}
    catch(_){const output=studioRoot.querySelector('[data-studio-output]');output.focus();output.select();setStatus('Clipboard was blocked. The change-set is selected for manual copy.');}
  }
  function bindChrome(){
    studioRoot.querySelector('[data-studio-close]').addEventListener('click',closeStudio);
    studioRoot.querySelector('[data-studio-select]').addEventListener('click',startSelect);
    studioRoot.querySelector('[data-studio-reset]').addEventListener('click',resetSelected);
    studioRoot.querySelector('[data-studio-hide]').addEventListener('click',toggleHidden);
    studioRoot.querySelectorAll('[data-studio-move]').forEach(button=>button.addEventListener('click',()=>startMove(button.dataset.studioMove)));
    studioRoot.querySelectorAll('[data-style-prop]').forEach(input=>input.addEventListener('change',()=>{
      const selector=currentSelector();if(!selector)return;
      upsertStyle(selector,input.dataset.styleProp,input.value.trim()||null);
    }));
    const colorSelects=studioRoot.querySelectorAll('[data-studio-style-select]');
    colorSelects.forEach((select,index)=>select.addEventListener('change',()=>{
      const selector=currentSelector();if(!selector)return;
      upsertStyle(selector,index===0?'background-color':'color',select.value||null);
    }));
    studioRoot.querySelector('[data-studio-copy]').addEventListener('click',copyChanges);
    studioRoot.querySelector('[data-studio-undo]').addEventListener('click',()=>{
      if(!state.patches.length)return;
      const last=state.patches[state.patches.length-1];
      const next=state.patches.slice(0,-1);
      if(last.kind==='move'){reloadWith(next);return;}
      state.patches=next;persist();applyAll();
    });
    studioRoot.querySelector('[data-studio-clear]').addEventListener('click',()=>{
      try{localStorage.removeItem(STORAGE_KEY);}catch(_){}
      location.reload();
    });
    studioRoot.addEventListener('click',event=>{
      const remove=event.target.closest('[data-remove-patch]');
      if(!remove)return;
      const index=Number(remove.dataset.removePatch);const patch=state.patches[index];
      const next=state.patches.filter((_,i)=>i!==index);
      if(patch?.kind==='move'){reloadWith(next);return;}
      state.patches=next;persist();applyAll();
    });
  }
  document.addEventListener('mouseover',event=>{
    if(!(state.selectMode||state.moveMode))return;
    const el=event.target instanceof Element?event.target:null;
    if(!isEditable(el))return;
    if(state.hovered===el)return;
    clearHover();state.hovered=el;el.classList.add('merd-ui-studio-hover');
  },true);
  document.addEventListener('mouseout',event=>{
    if(!(state.selectMode||state.moveMode))return;
    if(event.target===state.hovered)clearHover();
  },true);
  document.addEventListener('click',event=>{
    if(!(state.selectMode||state.moveMode))return;
    const el=event.target instanceof Element?event.target:null;
    if(!isEditable(el))return;
    event.preventDefault();event.stopImmediatePropagation();
    if(state.moveMode){
      const {selector,position}=state.moveMode;
      const source=find(selector);
      if(!source||source===el||source.contains(el)){
        setInstruction('Choose a different destination element.');return;
      }
      const target=selectorFor(el);
      state.moveMode=null;document.body.classList.remove('merd-ui-studio-moving');
      recordMove(selector,target,position);
      const moved=(state.patches.find(p=>p.kind==='move'&&p.selector===selector)?.runtimeKey&&find(runtimeSelector(state.patches.find(p=>p.kind==='move'&&p.selector===selector).runtimeKey)))||find(selector);if(moved)selectElement(moved,selector);
      setStatus('Move preview recorded.');
      return;
    }
    state.selectMode=false;document.body.classList.remove('merd-ui-studio-selecting');
    selectElement(el);updateHideButton();
  },true);

  let applyQueued=false;
  const observer=new MutationObserver(()=>{
    if(state.applying||applyQueued||!state.patches.length)return;
    applyQueued=true;requestAnimationFrame(()=>{applyQueued=false;applyAll();});
  });
  function init(){
    loadDraft();ensureChrome();applyAll();
    observer.observe(document.body,{subtree:true,childList:true});
    document.getElementById('openUiStudioBtn')?.addEventListener('click',openStudio);
    document.addEventListener('keydown',event=>{
      if(event.altKey&&event.shiftKey&&event.key.toLowerCase()==='e'){
        event.preventDefault();studioRoot.hidden?openStudio():closeStudio();
      }
      if(event.key==='Escape'&&(state.selectMode||state.moveMode)){
        state.selectMode=false;state.moveMode=null;clearHover();
        document.body.classList.remove('merd-ui-studio-selecting','merd-ui-studio-moving');
        setInstruction('Selection cancelled.');
      }
    });
    window.MERDPOS_UI_STUDIO=Object.freeze({
      open:openStudio,
      close:closeStudio,
      getChangeSet:()=>JSON.parse(JSON.stringify(exportPayload())),
      getChanges:()=>JSON.parse(JSON.stringify(state.patches)),
      clear:()=>{try{localStorage.removeItem(STORAGE_KEY);}catch(_){}location.reload();},
    });
  }
  init();
})();
