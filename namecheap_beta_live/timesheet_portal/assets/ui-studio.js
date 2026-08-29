(function(){
  'use strict';
  if(window.MERDPOS_AUTH?.is_dev!==true)return;

  const STORAGE_KEY='merdpos-ui-studio-draft-v1';
  const HUB_KEY='merdpos-ui-studio-hub-v1';
  const PREVIEW_STYLE_ID='merdUiStudioPreviewStyle';
  const MENU_ID='merdUiStudioMenu';
  const SAFETY_LABEL='DEV - PREVIEW ONLY';
  const SCOPES={element:'This element',component:'This component type',matching:'All matching elements',pages:'All pages'};
  const PALETTE=[
    {label:'White',token:'var(--color-brand-white)',hex:'#FFFFFF',fg:'#031B4B'},
    {label:'Canvas',token:'var(--color-brand-background)',hex:'#F5F7FC',fg:'#031B4B'},
    {label:'Navy',token:'var(--color-brand-navy)',hex:'#031B4B',fg:'#FFFFFF'},
    {label:'Cyan',token:'var(--color-brand-cyan)',hex:'#12BDF3',fg:'#031B4B'},
    {label:'Violet',token:'var(--color-brand-violet)',hex:'#8B2EFF',fg:'#FFFFFF'},
  ];
  const state={patches:[],selected:null,hovered:null,selectedElementSelector:'',selectedRuntimeKey:'',selectMode:false,moveMode:null,textEdit:null,revealHidden:false,applying:false,scope:'element',active:false,layer:'main',colorTarget:'background-color'};
  let previewStyle=null,host=null,hub=null,menu=null,toast=null,countBadge=null;
  let runtimeCounter=0,drag=null,suppressClick=false,hubPointerMenuOpen=false,toastTimer=0;

  const cssEscape=value=>window.CSS?.escape?CSS.escape(String(value)):String(value).replace(/[^a-zA-Z0-9_-]/g,'\\$&');
  const attrEscape=value=>String(value).replace(/\\/g,'\\\\').replace(/"/g,'\\"');
  const isStudioNode=node=>!!node?.closest?.('[data-ui-studio]');
  function isEditable(node){
    if(!(node instanceof Element)||isStudioNode(node))return false;
    if(['HTML','BODY','SCRIPT','STYLE','LINK','META'].includes(node.tagName))return false;
    return !!node.closest('.merd-page-shell, dialog.portal-dialog, .merd-about-dialog');
  }
  function uniqueAttributeSelector(el){
    for(const name of ['data-panel','data-report-target','data-finance-panel','data-dashboard-action','name','aria-label']){
      const value=el.getAttribute(name);if(!value)continue;
      const candidate=`${el.tagName.toLowerCase()}[${name}="${attrEscape(value)}"]`;
      try{if(document.querySelectorAll(candidate).length===1)return candidate;}catch(_){}
    }
    return '';
  }
  function selectorFor(el){
    if(el.id)return `#${cssEscape(el.id)}`;
    const unique=uniqueAttributeSelector(el);if(unique)return unique;
    const parts=[];let node=el;
    while(node&&node!==document.body&&parts.length<6){
      if(node.id){parts.unshift(`#${cssEscape(node.id)}`);break;}
      let part=node.tagName.toLowerCase();
      const classes=[...node.classList].filter(c=>!c.startsWith('merd-ui-studio')&&!['active','open','selected','hidden'].includes(c)).slice(0,2);
      if(classes.length)part+=classes.map(c=>`.${cssEscape(c)}`).join('');
      const parent=node.parentElement;
      if(parent){const peers=[...parent.children].filter(child=>child.tagName===node.tagName);if(peers.length>1)part+=`:nth-of-type(${peers.indexOf(node)+1})`;}
      parts.unshift(part);node=parent;
    }
    return parts.join(' > ');
  }
  const stableClasses=el=>[...el.classList].filter(c=>!c.startsWith('merd-ui-studio')&&!['active','open','selected','hidden'].includes(c));
  function matchSelectorFor(el){
    const classes=stableClasses(el).slice(0,3);
    if(classes.length)return `${el.tagName.toLowerCase()}${classes.map(c=>`.${cssEscape(c)}`).join('')}`;
    return uniqueAttributeSelector(el)||el.tagName.toLowerCase();
  }
  function panelFor(el){return el.closest?.('.portal-panel')||null;}
  function componentRootFor(el){return el.closest?.('.merd-mobile-page-head,.controls-card,.mgmt-card,.directory-card,.report-launch-card,.hero-panel,.status-card,dialog.portal-dialog')||null;}
  function classSelectorFor(el){
    if(!el)return '';
    const classes=stableClasses(el).slice(0,2);
    return classes.length?`${el.tagName.toLowerCase()}${classes.map(c=>`.${cssEscape(c)}`).join('')}`:el.tagName.toLowerCase();
  }
  function scopeSelectorFor(el,scope=state.scope){
    if(!el)return '';
    if(scope==='element')return state.selectedElementSelector||selectorFor(el);
    const target=matchSelectorFor(el),panel=panelFor(el),panelPrefix=panel?.id?`#${cssEscape(panel.id)} `:'';
    if(scope==='matching')return target;
    const component=componentRootFor(el);
    if(scope==='component'&&component&&component!==el)return `${panelPrefix}${classSelectorFor(component)} ${target}`.trim();
    if(scope==='pages'&&component?.classList?.contains('merd-mobile-page-head'))return `.merd-mobile-page-head ${target}`;
    if(scope==='pages')return target;
    return `${panelPrefix}${target}`.trim();
  }
  function currentSelector(){return state.selected?scopeSelectorFor(state.selected,state.scope):'';}
  function scopeMatchCount(selector){try{return document.querySelectorAll(selector).length;}catch(_){return 0;}}

  function persist(){try{localStorage.setItem(STORAGE_KEY,JSON.stringify({version:1,patches:state.patches}));}catch(_){}}
  function loadDraft(){try{const parsed=JSON.parse(localStorage.getItem(STORAGE_KEY)||'null');state.patches=Array.isArray(parsed?.patches)?parsed.patches.filter(Boolean):[];}catch(_){state.patches=[];}}
  function ensurePreviewStyle(){
    previewStyle=document.getElementById(PREVIEW_STYLE_ID);if(previewStyle)return previewStyle;
    previewStyle=document.createElement('style');previewStyle.id=PREVIEW_STYLE_ID;previewStyle.dataset.uiStudio='preview';document.head.appendChild(previewStyle);return previewStyle;
  }
  function find(selector){try{return document.querySelector(selector);}catch(_){return null;}}
  function runtimeKeyFor(el,preferred=''){
    if(!el)return '';if(el.dataset.uiStudioRuntimeKey)return el.dataset.uiStudioRuntimeKey;
    const key=preferred||`us-${Date.now().toString(36)}-${(++runtimeCounter).toString(36)}`;el.dataset.uiStudioRuntimeKey=key;return key;
  }
  function runtimeSelector(key){return `[data-ui-studio-runtime-key="${attrEscape(key)}"]`;}
  function primeRuntimeKeys(){
    for(const patch of state.patches){
      if(patch.runtimeKey){const node=find(runtimeSelector(patch.runtimeKey))||find(patch.selector);if(node)runtimeKeyFor(node,patch.runtimeKey);}
      if(patch.kind==='move'&&patch.targetKey){const target=find(runtimeSelector(patch.targetKey))||find(patch.target);if(target)runtimeKeyFor(target,patch.targetKey);}
    }
  }
  function styleRules(){
    const bySelector=new Map();
    for(const patch of state.patches){
      if(patch.kind!=='style'||!patch.selector||!patch.property)continue;
      if(state.revealHidden&&patch.property==='display'&&patch.value==='none')continue;
      const selector=patch.scope&&patch.scope!=='element'?patch.selector:(patch.runtimeKey?runtimeSelector(patch.runtimeKey):patch.selector);
      if(!bySelector.has(selector))bySelector.set(selector,[]);
      bySelector.get(selector).push(`${patch.property}:${patch.value} !important`);
    }
    return [...bySelector].map(([selector,declarations])=>`${selector}{${declarations.join(';')}}`).join('\n');
  }
  function applyMove(patch){
    const source=(patch.runtimeKey&&find(runtimeSelector(patch.runtimeKey)))||find(patch.selector);
    const target=(patch.targetKey&&find(runtimeSelector(patch.targetKey)))||find(patch.target);
    if(!source||!target||source===target||source.contains(target))return;
    if(patch.position==='before')target.parentElement?.insertBefore(source,target);
    else if(patch.position==='after')target.parentElement?.insertBefore(source,target.nextSibling);
    else if(patch.position==='inside')target.appendChild(source);
  }
  function applyText(patch){
    const selector=patch.runtimeKey?runtimeSelector(patch.runtimeKey):patch.selector,node=find(selector);
    if(node&&node.textContent!==patch.value)node.textContent=patch.value;
  }
  function applyAll(){
    if(state.applying)return;
    state.applying=true;primeRuntimeKeys();ensurePreviewStyle().textContent=styleRules();
    for(const patch of state.patches)if(patch.kind==='move')applyMove(patch);
    for(const patch of state.patches)if(patch.kind==='text')applyText(patch);
    state.applying=false;updateHubState();
  }
  function exportPayload(){
    return {version:1,page:location.pathname,theme:document.documentElement.dataset.theme||'light',viewport:{width:window.innerWidth,height:window.innerHeight},patches:state.patches.map(({runtimeKey,targetKey,...patch})=>patch)};
  }
  function patchSummary(patch){
    if(patch.kind==='move')return `${patch.selector} -> move ${patch.position} ${patch.target}`;
    if(patch.kind==='text')return `[This element] ${patch.selector} -> text: ${JSON.stringify(patch.value)}`;
    return `[${SCOPES[patch.scope||'element']||patch.scope}] ${patch.selector} -> ${patch.property}: ${patch.value}`;
  }
  function upsertStyle(property,value){
    if(!state.selected)return showToast('Select an element first');
    const selector=currentSelector(),scope=state.scope;
    const existing=state.patches.find(p=>p.kind==='style'&&p.selector===selector&&p.property===property&&((p.scope||'element')===scope));
    const runtimeKey=scope==='element'?(state.selectedRuntimeKey||existing?.runtimeKey||runtimeKeyFor(state.selected)):'';
    state.patches=state.patches.filter(p=>!(p.kind==='style'&&p.selector===selector&&p.property===property&&((p.scope||'element')===scope)));
    if(value!==null&&value!=='')state.patches.push({kind:'style',scope,selector,runtimeKey,property,value});
    persist();applyAll();renderMenu();showToast(value===null?`${property} reset`:`${property} updated`);
  }
  function restoreTextPatch(patch){const node=(patch.runtimeKey&&find(runtimeSelector(patch.runtimeKey)))||find(patch.selector);if(node&&typeof patch.original==='string')node.textContent=patch.original;}
  function recordText(value,original){
    if(!state.selected)return;
    const selector=state.selectedElementSelector,runtimeKey=state.selectedRuntimeKey||runtimeKeyFor(state.selected);
    const existing=state.patches.find(p=>p.kind==='text'&&p.selector===selector);
    state.patches=state.patches.filter(p=>!(p.kind==='text'&&p.selector===selector));
    state.patches.push({kind:'text',scope:'element',selector,runtimeKey,original:existing?.original??original,value});
    persist();applyAll();renderMenu();showToast('Text updated');
  }
  function finishTextEdit(commit){
    const edit=state.textEdit;if(!edit)return;state.textEdit=null;
    const value=edit.el.textContent??'';edit.el.classList.remove('merd-ui-studio-text-editing');
    if(edit.prior===null)edit.el.removeAttribute('contenteditable');else edit.el.setAttribute('contenteditable',edit.prior);
    if(!commit){edit.el.textContent=edit.original;showToast('Text edit cancelled');return;}
    if(value===edit.original){showToast('Text unchanged');return;}recordText(value,edit.original);
  }
  function startTextEdit(){
    if(!state.selected)return showToast('Select text first');
    if(state.scope!=='element')return showToast('Text edit requires This element scope');
    if(state.selected.children.length)return showToast('Select the text itself');
    hideMenu();const el=state.selected;state.textEdit={el,original:el.textContent??'',prior:el.getAttribute('contenteditable')};
    el.setAttribute('contenteditable','plaintext-only');el.classList.add('merd-ui-studio-text-editing');el.focus();
    const range=document.createRange();range.selectNodeContents(el);const sel=window.getSelection();sel.removeAllRanges();sel.addRange(range);showToast('Edit text - Enter saves - Esc cancels');
  }
  function toggleRevealHidden(){state.revealHidden=!state.revealHidden;applyAll();renderMenu();showToast(state.revealHidden?'Hidden Studio items revealed temporarily':'Hidden preview restored');}
  function recordMove(target,position){
    const selector=state.selectedElementSelector,source=state.selected,targetSelector=selectorFor(target);
    const runtimeKey=source?runtimeKeyFor(source):'',targetKey=target?runtimeKeyFor(target):'';
    state.patches=state.patches.filter(p=>!(p.kind==='move'&&p.selector===selector));
    state.patches.push({kind:'move',selector,runtimeKey,target:targetSelector,targetKey,position});
    persist();applyAll();showToast(`Moved ${position}`);
  }
  function clearHover(){state.hovered?.classList?.remove('merd-ui-studio-hover');state.hovered=null;}
  function clearSelected(){
    state.selected?.classList?.remove('merd-ui-studio-selected');state.selected=null;state.selectedElementSelector='';state.selectedRuntimeKey='';updateHubState();
  }
  function selectElement(el,selectorOverride=''){
    clearSelected();clearHover();state.selected=el;el.classList.add('merd-ui-studio-selected');
    state.selectedElementSelector=selectorOverride||selectorFor(el);state.selectedRuntimeKey=runtimeKeyFor(el);updateHubState();showToast(`Selected ${currentSelector()}`);
  }
  function applyScope(scope){
    if(!SCOPES[scope])return;
    state.scope=scope;updateHubState();renderMenu();
    const selector=currentSelector(),count=selector?scopeMatchCount(selector):0;
    showToast(state.selected?`${SCOPES[scope]} Â· ${count} match${count===1?'':'es'}`:SCOPES[scope]);
  }
  function startSelect(){
    hideMenu();state.selectMode=true;state.moveMode=null;clearHover();
    document.body.classList.add('merd-ui-studio-selecting');document.body.classList.remove('merd-ui-studio-moving');showToast('Tap or click an element');
  }
  function startMove(position){
    if(!state.selected)return showToast('Select an element first');
    if(state.scope!=='element')return showToast('Move requires This element scope');
    hideMenu();state.moveMode={position};state.selectMode=false;clearHover();
    document.body.classList.add('merd-ui-studio-moving');document.body.classList.remove('merd-ui-studio-selecting');showToast(`Choose destination Â· ${position}`);
  }
  function toggleHidden(){
    if(!state.selected)return showToast('Select an element first');
    const selector=currentSelector();
    const hidden=state.patches.some(p=>p.kind==='style'&&p.selector===selector&&p.property==='display'&&p.value==='none'&&((p.scope||'element')===state.scope));
    upsertStyle('display',hidden?null:'none');
  }
  function resetSelected(){
    if(!state.selected)return showToast('Select an element first');
    const selectors=new Set(Object.keys(SCOPES).map(scope=>scopeSelectorFor(state.selected,scope)));
    const removedMove=state.patches.some(p=>p.kind==='move'&&p.selector===state.selectedElementSelector);
    const removedText=state.patches.filter(p=>p.kind==='text'&&selectors.has(p.selector));
    state.patches=state.patches.filter(p=>!selectors.has(p.selector)&&!(p.kind==='move'&&p.selector===state.selectedElementSelector));
    removedText.forEach(restoreTextPatch);persist();if(removedMove){location.reload();return;}applyAll();renderMenu();showToast('Selected element reset');
  }
  function reloadWith(patches){state.patches=patches;persist();location.reload();}
  function undoLast(){
    if(!state.patches.length)return showToast('Nothing to undo');
    const last=state.patches[state.patches.length-1],next=state.patches.slice(0,-1);
    if(last.kind==='move'){reloadWith(next);return;}
    if(last.kind==='text')restoreTextPatch(last);
    state.patches=next;persist();applyAll();renderMenu();showToast('Last change removed');
  }
  function clearDraft(){try{localStorage.removeItem(STORAGE_KEY);}catch(_){}location.reload();}
  async function copyText(text){
    try{await navigator.clipboard.writeText(text);return true;}catch(_){}
    const field=document.createElement('textarea');field.value=text;field.setAttribute('readonly','');field.style.cssText='position:fixed;opacity:0;pointer-events:none;left:-9999px;top:0';
    document.body.appendChild(field);field.select();let ok=false;try{ok=document.execCommand('copy');}catch(_){}field.remove();return ok;
  }
  async function copyJson(){
    const ok=await copyText(JSON.stringify(exportPayload(),null,2));showToast(ok?`Copied ${state.patches.length} change${state.patches.length===1?'':'s'}`:'Clipboard blocked');
  }
  async function copyChat(){
    const payload=exportPayload();
    const summary=state.patches.map((patch,index)=>`${index+1}. ${patchSummary(patch)}`).join('\n')||'No preview changes.';
    const text=`Apply these MERDPOS UI Studio preview changes to the canonical Beta source, then run the normal checks, deployment, and runtime verification gates.\n\n${summary}\n\nChange-set JSON:\n${JSON.stringify(payload,null,2)}`;
    const ok=await copyText(text);showToast(ok?'Chat handoff copied':'Clipboard blocked');
  }

  const ICON_FILES={
    select:'ads_click_48px.svg',textEdit:'edit_48px.svg',color:'palette_48px.svg',layout:'tune_48px.svg',scope:'filter_center_focus_48px.svg',
    move:'open_with_48px.svg',eye:'visibility_48px.svg',eyeOff:'visibility_off_48px.svg',changes:'edit_note_48px.svg',exit:'close_48px.svg',
    back:'arrow_back_48px.svg',undo:'undo_48px.svg',copy:'content_copy_48px.svg',chat:'chat_48px.svg',reset:'restart_alt_48px.svg',trash:'delete_48px.svg',
    bg:'format_color_fill_48px.svg',text:'format_color_text_48px.svg',before:'arrow_back_48px.svg',after:'arrow_forward_48px.svg',inside:'input_48px.svg',
  };
  function iconMarkup(name){
    const file=ICON_FILES[name];if(!file)return '<span class="merd-ui-menu-icon" aria-hidden="true"></span>';
    return `<span class="merd-ui-menu-icon" aria-hidden="true" style="--studio-icon:url(assets/vendor/google-material-symbols/${file})"></span>`;
  }
  function toneFor(action){
    if(['select','text','color-target'].includes(action))return 'cyan';
    if(['color','scope','changes'].includes(action))return 'violet';
    if(['move','exit'].includes(action))return 'navy';
    if(action==='clear')return 'danger';
    if(['back','undo','reset','color-reset','reveal'].includes(action))return 'light';
    return 'soft';
  }
  function menuDefinitions(){
    const hidden=!!state.selected&&state.patches.some(p=>p.kind==='style'&&p.selector===currentSelector()&&p.property==='display'&&p.value==='none'&&((p.scope||'element')===state.scope));
    if(state.layer==='color'){
      return [
        {label:state.colorTarget==='background-color'?'BG':'Text',action:'color-target',icon:state.colorTarget==='background-color'?'bg':'text'},
        ...PALETTE.map(c=>({label:c.label,action:'swatch',value:c.token,swatch:c.hex,fg:c.fg})),
        {label:'Reset',action:'color-reset',icon:'reset'},{label:'Back',action:'back',icon:'back'}
      ];
    }
    if(state.layer==='layout')return [
      {label:'Padding',action:'layout-prop',value:'padding'},{label:'Margin',action:'layout-prop',value:'margin'},
      {label:'Gap',action:'layout-prop',value:'gap'},{label:'Radius',action:'layout-prop',value:'border-radius'},
      {label:'Width',action:'layout-prop',value:'width'},{label:'Font',action:'layout-prop',value:'font-size'},
      {label:'Back',action:'back',icon:'back'}
    ];
    if(state.layer.startsWith('values:')){
      const prop=state.layer.slice(7);
      const presets={
        padding:['0px','4px','8px','12px','16px','24px','32px'],
        margin:['0px','4px','8px','12px','16px','24px','32px'],
        gap:['0px','4px','8px','12px','16px','24px','32px'],
        'border-radius':['0px','6px','10px','14px','18px','24px','999px'],
        width:['auto','25%','50%','75%','100%','fit-content'],
        'font-size':['12px','14px','16px','18px','20px','24px','32px'],
      }[prop]||[];
      return [...presets.map(value=>({label:value.replace('px',''),action:'style-value',value,property:prop})),{label:'Back',action:'layout',icon:'back'}];
    }
    if(state.layer==='scope')return [
      {label:'Element',action:'scope-value',value:'element',current:state.scope==='element'},
      {label:'Component',action:'scope-value',value:'component',current:state.scope==='component'},
      {label:'Matching',action:'scope-value',value:'matching',current:state.scope==='matching'},
      {label:'All pages',action:'scope-value',value:'pages',current:state.scope==='pages'},
      {label:'Back',action:'back',icon:'back'}
    ];
    if(state.layer==='move')return [
      {label:'Before',action:'move-value',value:'before',icon:'before',disabled:state.scope!=='element'||!state.selected},
      {label:'After',action:'move-value',value:'after',icon:'after',disabled:state.scope!=='element'||!state.selected},
      {label:'Inside',action:'move-value',value:'inside',icon:'inside',disabled:state.scope!=='element'||!state.selected},
      {label:'Back',action:'back',icon:'back'}
    ];
    if(state.layer==='changes')return [
      {label:'Copy',action:'copy',icon:'copy',disabled:!state.patches.length},
      {label:'Chat',action:'chat',icon:'chat',disabled:!state.patches.length},
      {label:'Undo',action:'undo',icon:'undo',disabled:!state.patches.length},
      {label:'Reset',action:'reset',icon:'reset',disabled:!state.selected},
      {label:'Clear',action:'clear',icon:'trash',disabled:!state.patches.length},
      {label:'Back',action:'back',icon:'back'}
    ];
    return [
      {label:'Select',action:'select',icon:'select'},
      {label:'Text',action:'text',icon:'textEdit',disabled:!state.selected||state.scope!=='element'},
      {label:'Color',action:'color',icon:'color',disabled:!state.selected},
      {label:'Layout',action:'layout',icon:'layout',disabled:!state.selected},
      {label:'Scope',action:'scope',icon:'scope'},
      {label:'Move',action:'move',icon:'move',disabled:state.scope!=='element'||!state.selected},
      {label:hidden?'Show':'Hide',action:'hide',icon:hidden?'eye':'eyeOff',disabled:!state.selected},
      {label:state.revealHidden?'Restore hidden':'Reveal',action:'reveal',icon:state.revealHidden?'eyeOff':'eye',disabled:!state.patches.some(p=>p.kind==='style'&&p.property==='display'&&p.value==='none')},
      {label:`Changes${state.patches.length?` ${state.patches.length}`:''}`,action:'changes',icon:'changes'},
      {label:'Exit',action:'exit',icon:'exit'}
    ];
  }
  function renderMenu(){
    if(!menu)return;
    const defs=menuDefinitions();menu.classList.remove('is-ready');
    menu.innerHTML=defs.map((item,index)=>{
      const classes=['merd-ui-menu-button',`tone-${toneFor(item.action)}`];
      if(item.swatch)classes.push('is-swatch');if(item.current)classes.push('is-current');
      const style=item.swatch?` style="--studio-swatch:${item.swatch};--studio-swatch-fg:${item.fg}"`:'';
      const icon=item.icon?iconMarkup(item.icon):'';
      const value=item.value!==undefined?` data-value="${String(item.value).replace(/"/g,'&quot;')}"`:'';
      const property=item.property?` data-property="${item.property}"`:'';
      return `<li role="none" class="merd-ui-menu-item" style="--delay:${index*28}ms"><button type="button" role="menuitem" class="${classes.join(' ')}" data-action="${item.action}"${value}${property}${item.disabled?' disabled aria-disabled="true"':''}${style} aria-label="${item.label}">${icon}<span>${item.label}</span></button></li>`;
    }).join('');
    layoutMenu();requestAnimationFrame(()=>menu?.classList.add('is-ready'));
  }
  function cssLengthPx(value,fallback=0){
    const raw=String(value||'').trim(),number=parseFloat(raw);if(!Number.isFinite(number))return fallback;
    if(raw.endsWith('rem'))return number*(parseFloat(getComputedStyle(document.documentElement).fontSize)||16);
    if(raw.endsWith('vh'))return number*window.innerHeight/100;
    if(raw.endsWith('vw'))return number*window.innerWidth/100;
    return number;
  }
  function bottomReserve(){return window.innerWidth>820?0:cssLengthPx(getComputedStyle(document.documentElement).getPropertyValue('--shell-mobile-nav-h'),76);}
  function clampHub(point){
    const half=30,edge=8,reserve=bottomReserve();
    return {x:Math.min(window.innerWidth-half-edge,Math.max(half+edge,point.x)),y:Math.min(window.innerHeight-reserve-half-edge,Math.max(half+edge,point.y))};
  }
  function positionMode(){return window.innerWidth<=820?'mobile':'desktop';}
  function loadHubPosition(){
    let saved={};try{saved=JSON.parse(localStorage.getItem(HUB_KEY)||'{}')||{};}catch(_){}
    const mode=positionMode(),reserve=bottomReserve(),fallback={x:window.innerWidth-58,y:window.innerHeight-reserve-62};
    return clampHub(saved[mode]||fallback);
  }
  function saveHubPosition(point){
    let saved={};try{saved=JSON.parse(localStorage.getItem(HUB_KEY)||'{}')||{};}catch(_){}
    saved[positionMode()]={x:Math.round(point.x),y:Math.round(point.y)};try{localStorage.setItem(HUB_KEY,JSON.stringify(saved));}catch(_){}
  }
  function setHubPosition(point,save=false){
    const next=clampHub(point);hub.style.left=`${next.x}px`;hub.style.top=`${next.y}px`;menu.style.left=`${next.x}px`;menu.style.top=`${next.y}px`;
    if(save)saveHubPosition(next);if(isMenuOpen())layoutMenu();return next;
  }
  function hubPoint(){const rect=hub.getBoundingClientRect();return {x:rect.left+rect.width/2,y:rect.top+rect.height/2};}
  function menuAngles(count,radius,point=hubPoint()){
    const itemRadius=28,edge=7,reserve=bottomReserve();
    const safe={left:edge+itemRadius,right:window.innerWidth-edge-itemRadius,top:edge+itemRadius,bottom:window.innerHeight-reserve-edge-itemRadius};
    const fullFits=point.x-radius>=safe.left&&point.x+radius<=safe.right&&point.y-radius>=safe.top&&point.y+radius<=safe.bottom;
    if(fullFits)return {angles:Array.from({length:count},(_,i)=>-90+(360/count)*i),radius};
    const span=Math.min(280,Math.max(170,(count-1)*36)),centers=[0,45,90,135,180,225,270,315];
    let best=null;
    for(const r of [radius,radius-10,radius-20,Math.max(68,radius-28)]){
      for(const center of centers){
        const angles=count===1?[center]:Array.from({length:count},(_,i)=>center-span/2+(span/(count-1))*i);
        let overflow=0,minClear=Infinity;
        for(const angle of angles){
          const rad=angle*Math.PI/180,x=point.x+Math.cos(rad)*r,y=point.y+Math.sin(rad)*r;
          const dl=x-safe.left,dr=safe.right-x,dt=y-safe.top,db=safe.bottom-y;
          overflow+=Math.max(0,-dl)+Math.max(0,-dr)+Math.max(0,-dt)+Math.max(0,-db);minClear=Math.min(minClear,dl,dr,dt,db);
        }
        const score=overflow*10000-r*10-minClear;
        if(!best||score<best.score)best={angles,radius:r,score,overflow};
      }
      if(best?.overflow===0)break;
    }
    return best;
  }
  function layoutMenu(){
    if(!menu||!hub)return;
    const items=[...menu.querySelectorAll('.merd-ui-menu-item')],hubCenter=hubPoint(),radius=window.innerWidth<=820?98:108,itemRadius=28,edge=7,reserve=bottomReserve();
    const minX=edge+itemRadius+radius,maxX=window.innerWidth-edge-itemRadius-radius,minY=edge+itemRadius+radius,maxY=window.innerHeight-reserve-edge-itemRadius-radius;
    const point={x:minX<=maxX?Math.min(maxX,Math.max(minX,hubCenter.x)):hubCenter.x,y:minY<=maxY?Math.min(maxY,Math.max(minY,hubCenter.y)):hubCenter.y};
    menu.style.left=`${point.x}px`;menu.style.top=`${point.y}px`;
    const layout=menuAngles(items.length,radius,point);
    items.forEach((item,index)=>{
      const angle=layout.angles[index]||0,rad=angle*Math.PI/180;
      item.style.setProperty('--dx',`${Math.cos(rad)*layout.radius}px`);item.style.setProperty('--dy',`${Math.sin(rad)*layout.radius}px`);
    });
  }
  function isMenuOpen(){return menu?.dataset.open==='1'&&!menu.hidden;}
  function showMenu(){
    if(!state.active)return;renderMenu();layoutMenu();menu.hidden=false;menu.dataset.open='1';hub.setAttribute('aria-expanded','true');
  }
  function hideMenu(){
    if(!menu)return;menu.dataset.open='';menu.hidden=true;hub?.setAttribute('aria-expanded','false');
  }
  function toggleMenu(){isMenuOpen()?hideMenu():showMenu();}
  function navigate(layer){state.layer=layer;renderMenu();if(!isMenuOpen())showMenu();}
  function showToast(message){
    if(!toast||!message)return;
    clearTimeout(toastTimer);toast.textContent=message;toast.hidden=false;
    const point=hubPoint();toast.style.left=`${point.x}px`;toast.style.top=`${point.y}px`;toast.classList.toggle('below',point.y<90);toast.classList.toggle('above',point.y>=90);
    toastTimer=setTimeout(()=>{if(toast)toast.hidden=true;},1700);
  }
  function updateHubState(){
    if(!hub)return;
    countBadge.textContent=String(state.patches.length);countBadge.hidden=!state.patches.length;hub.classList.toggle('has-selection',!!state.selected);
    hub.setAttribute('aria-label',`MERDPOS UI Studio${state.selected?' Â· element selected':''}${state.patches.length?` Â· ${state.patches.length} changes`:''}`);
  }
  function onMenuAction(event){
    const button=event.target.closest('button[data-action]');if(!button||button.disabled)return;
    const action=button.dataset.action,value=button.dataset.value,property=button.dataset.property;
    if(action==='select')return startSelect();
    if(action==='text')return startTextEdit();
    if(action==='color')return navigate('color');
    if(action==='layout')return navigate('layout');
    if(action==='scope')return navigate('scope');
    if(action==='move')return navigate('move');
    if(action==='changes')return navigate('changes');
    if(action==='back')return navigate('main');
    if(action==='color-target'){state.colorTarget=state.colorTarget==='background-color'?'color':'background-color';renderMenu();showToast(state.colorTarget==='color'?'Text color':'Background color');return;}
    if(action==='swatch')return upsertStyle(state.colorTarget,value);
    if(action==='color-reset')return upsertStyle(state.colorTarget,null);
    if(action==='layout-prop')return navigate(`values:${value}`);
    if(action==='style-value')return upsertStyle(property,value);
    if(action==='scope-value')return applyScope(value);
    if(action==='move-value')return startMove(value);
    if(action==='hide')return toggleHidden();
    if(action==='reveal')return toggleRevealHidden();
    if(action==='copy')return copyJson();
    if(action==='chat')return copyChat();
    if(action==='undo')return undoLast();
    if(action==='reset')return resetSelected();
    if(action==='clear')return clearDraft();
    if(action==='exit')return closeStudio();
  }
  function ensureUI(){
    if(host)return;
    host=document.createElement('div');host.className='merd-ui-studio-host';host.dataset.uiStudio='host';host.setAttribute('popover','manual');host.hidden=true;
    hub=document.createElement('button');hub.type='button';hub.className='merd-ui-hub';hub.dataset.uiStudio='hub';hub.setAttribute('aria-controls',MENU_ID);hub.setAttribute('aria-expanded','false');
    hub.innerHTML='<strong>UI</strong><small>DEV</small><span class="merd-ui-hub-count" hidden>0</span>';
    menu=document.createElement('ul');menu.id=MENU_ID;menu.className='merd-ui-menu';menu.dataset.uiStudio='menu';menu.setAttribute('role','menu');menu.hidden=true;
    toast=document.createElement('div');toast.className='merd-ui-toast above';toast.dataset.uiStudio='toast';toast.setAttribute('role','status');toast.setAttribute('aria-live','polite');toast.hidden=true;
    countBadge=hub.querySelector('.merd-ui-hub-count');host.append(hub,menu,toast);document.body.appendChild(host);setHubPosition(loadHubPosition());
    menu.addEventListener('click',event=>{event.stopPropagation();onMenuAction(event);});
    host.addEventListener('click',event=>event.stopPropagation());
    hub.addEventListener('click',event=>{if(suppressClick){suppressClick=false;event.preventDefault();return;}if(hubPointerMenuOpen){hubPointerMenuOpen=false;hideMenu();return;}toggleMenu();});
    hub.addEventListener('pointerdown',event=>{
      if(event.button!==0)return;
      hubPointerMenuOpen=isMenuOpen();drag={id:event.pointerId,startX:event.clientX,startY:event.clientY,lastX:event.clientX,lastY:event.clientY,moved:false};
      try{hub.setPointerCapture(event.pointerId);}catch(_){}if(isMenuOpen())hideMenu();
    });
    hub.addEventListener('pointermove',event=>{
      if(!drag||event.pointerId!==drag.id)return;
      const distance=Math.hypot(event.clientX-drag.startX,event.clientY-drag.startY);if(distance>5)drag.moved=true;if(!drag.moved)return;
      event.preventDefault();drag.lastX=event.clientX;drag.lastY=event.clientY;setHubPosition({x:event.clientX,y:event.clientY});
    });
    hub.addEventListener('pointerup',event=>{
      if(!drag||event.pointerId!==drag.id)return;
      if(drag.moved){suppressClick=true;setHubPosition({x:drag.lastX,y:drag.lastY},true);}
      try{hub.releasePointerCapture(event.pointerId);}catch(_){}drag=null;
    });
    window.addEventListener('resize',()=>setHubPosition(hubPoint(),true));updateHubState();
  }
  function openStudio(){ensureUI();state.active=true;host.hidden=false;try{host.showPopover();}catch(_){}document.body.classList.add('merd-ui-studio-open');updateHubState();showToast(SAFETY_LABEL);}
  function closeStudio(){
    if(state.textEdit)finishTextEdit(true);hideMenu();state.active=false;state.selectMode=false;state.moveMode=null;const wasRevealing=state.revealHidden;state.revealHidden=false;clearHover();if(wasRevealing)applyAll();
    try{if(host.matches(':popover-open'))host.hidePopover();}catch(_){}host.hidden=true;document.body.classList.remove('merd-ui-studio-open','merd-ui-studio-selecting','merd-ui-studio-moving');
  }

  document.addEventListener('mouseover',event=>{
    if(!(state.selectMode||state.moveMode))return;
    const el=event.target instanceof Element?event.target:null;if(!isEditable(el)||state.hovered===el)return;
    clearHover();state.hovered=el;el.classList.add('merd-ui-studio-hover');
  },true);
  document.addEventListener('mouseout',event=>{if((state.selectMode||state.moveMode)&&event.target===state.hovered)clearHover();},true);
  document.addEventListener('click',event=>{
    if(!(state.selectMode||state.moveMode))return;
    const el=event.target instanceof Element?event.target:null;if(!isEditable(el))return;
    event.preventDefault();event.stopImmediatePropagation();
    if(state.moveMode){
      if(!state.selected||state.selected===el||state.selected.contains(el))return showToast('Choose a different destination');
      const position=state.moveMode.position;state.moveMode=null;document.body.classList.remove('merd-ui-studio-moving');recordMove(el,position);return;
    }
    state.selectMode=false;document.body.classList.remove('merd-ui-studio-selecting');selectElement(el);
  },true);

  document.addEventListener('focusout',event=>{if(state.textEdit&&event.target===state.textEdit.el)finishTextEdit(true);},true);

  let applyQueued=false;
  const observer=new MutationObserver(()=>{
    if(state.applying||applyQueued||!state.patches.length)return;
    applyQueued=true;requestAnimationFrame(()=>{applyQueued=false;applyAll();});
  });
  function init(){
    loadDraft();ensureUI();applyAll();observer.observe(document.body,{subtree:true,childList:true});
    document.getElementById('openUiStudioBtn')?.addEventListener('click',openStudio);
    document.addEventListener('keydown',event=>{
      if(state.textEdit){
        if(event.key==='Escape'){event.preventDefault();finishTextEdit(false);return;}
        if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();finishTextEdit(true);state.selected?.blur?.();return;}
      }
      if(event.altKey&&event.shiftKey&&event.key.toLowerCase()==='e'){event.preventDefault();state.active?closeStudio():openStudio();}
      if(event.key==='Escape'&&(state.selectMode||state.moveMode)){
        state.selectMode=false;state.moveMode=null;clearHover();document.body.classList.remove('merd-ui-studio-selecting','merd-ui-studio-moving');showToast('Selection cancelled');
      }
    });
    window.MERDPOS_UI_STUDIO=Object.freeze({
      open:openStudio,close:closeStudio,getChangeSet:()=>JSON.parse(JSON.stringify(exportPayload())),
      getChanges:()=>JSON.parse(JSON.stringify(state.patches)),copyForChat:copyChat,clear:clearDraft,
    });
  }
  init();
})();
