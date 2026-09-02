from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def read(path): return path.read_text(encoding='utf-8')
def write(path,text): path.write_text(text,encoding='utf-8')
def replace_once(text,old,new,label):
    if old not in text: raise SystemExit(f'missing anchor: {label}')
    if text.count(old)!=1: raise SystemExit(f'non-unique anchor: {label} ({text.count(old)})')
    return text.replace(old,new,1)
def replace_block(text,start_marker,end_marker,replacement,label):
    start=text.find(start_marker)
    if start<0: raise SystemExit(f'missing start: {label}')
    end=text.find(end_marker,start)
    if end<0: raise SystemExit(f'missing end: {label}')
    return text[:start]+replacement+text[end:]

studio_path=ROOT/'namecheap_beta_live/timesheet_portal/assets/ui-studio.js'
studio=read(studio_path)

prime="""  function primeRuntimeKeys(){for(const patch of state.patches){if(patch.runtimeKey){const node=find(runtimeSelector(patch.runtimeKey))||find(patch.selector);if(node)runtimeKeyFor(node,patch.runtimeKey);}if(patch.kind==='move'&&patch.targetKey){const target=find(runtimeSelector(patch.targetKey))||find(patch.target);if(target)runtimeKeyFor(target,patch.targetKey);}if((patch.kind==='add'||(patch.kind==='request'&&patch.requestType==='add'))&&patch.parentKey){const parent=find(runtimeSelector(patch.parentKey))||find(patch.parent);if(parent)runtimeKeyFor(parent,patch.parentKey);}}}
"""
prime_new="""  function primeRuntimeKeys(){for(const patch of state.patches){if(patch.runtimeKey){const node=find(runtimeSelector(patch.runtimeKey))||find(patch.selector);if(node)runtimeKeyFor(node,patch.runtimeKey);}if(patch.kind==='move'&&patch.targetKey){const target=find(runtimeSelector(patch.targetKey))||find(patch.target);if(target)runtimeKeyFor(target,patch.targetKey);}if((patch.kind==='add'||(patch.kind==='request'&&patch.requestType==='add'))&&patch.parentKey){const parent=find(runtimeSelector(patch.parentKey))||find(patch.parent);if(parent)runtimeKeyFor(parent,patch.parentKey);}if(patch.kind==='add'&&patch.sourceKey){const source=find(runtimeSelector(patch.sourceKey))||find(patch.source);if(source)runtimeKeyFor(source,patch.sourceKey);}}}
"""
studio=replace_once(studio,prime,prime_new,'primeRuntimeKeys clone source')

create_added="""  function createAddedNode(patch){
    let node;
    if(patch.elementType==='clone'){
      const source=(patch.sourceKey&&find(runtimeSelector(patch.sourceKey)))||find(patch.source);
      node=source?source.cloneNode(true):document.createElement('div');
      for(const item of [node,...node.querySelectorAll?.('*')||[]]){
        for(const attribute of [...item.attributes]){
          if(attribute.name==='id'||attribute.name==='name'||attribute.name==='for'||attribute.name==='aria-controls'||attribute.name==='aria-labelledby'||attribute.name==='aria-describedby'||attribute.name.startsWith('on'))item.removeAttribute(attribute.name);
        }
        item.removeAttribute?.('data-ui-studio-runtime-key');item.removeAttribute?.('data-ui-studio-added-key');
      }
      node.classList.add('merd-ui-studio-added-preview','merd-ui-studio-added-clone');
    }else if(patch.elementType==='section'){
      node=document.createElement('section');node.className='merd-ui-studio-added-preview merd-ui-studio-added-section';node.dataset.uiStudioStructureType='section';node.dataset.uiStudioStructureLabel=patch.content||'New section';
    }else if(patch.elementType==='container'){
      node=document.createElement('div');node.className='merd-ui-studio-added-preview merd-ui-studio-added-container';node.dataset.uiStudioStructureType='container';node.dataset.uiStudioStructureLabel=patch.content||'New container';
    }else if(patch.elementType==='metric-card'){
      node=document.createElement('article');node.className='controls-card merd-ui-studio-added-preview merd-ui-studio-added-card';node.dataset.uiStudioStructureType='metric-card';node.dataset.uiStudioStructureLabel=patch.content||'New metric';node.innerHTML=`<strong>${patch.content||'New metric'}</strong><p>0</p>`;
    }else if(patch.elementType==='chart'){
      node=document.createElement('figure');node.className='controls-card merd-ui-studio-added-preview merd-ui-studio-added-chart';node.dataset.uiStudioStructureType='chart';node.dataset.uiStudioStructureLabel=patch.content||'New chart';node.innerHTML=`<figcaption>${patch.content||'New chart'}</figcaption><div class=\"merd-ui-studio-added-chart-bars\" aria-hidden=\"true\"><i></i><i></i><i></i><i></i></div>`;
    }else if(patch.elementType==='employee-status'){
      node=document.createElement('article');node.className='working-card merd-ui-studio-added-preview';node.dataset.uiStudioStructureType='employee-status';node.dataset.uiStudioStructureLabel=patch.content||'Employee status';node.innerHTML=`<span class=\"live-dot\"></span><h3>${patch.content||'Employee status'}</h3><p>Status</p>`;
    }else if(patch.elementType==='data-table'){
      node=document.createElement('div');node.className='table-scroll merd-ui-studio-added-preview merd-ui-studio-added-table';node.dataset.uiStudioStructureType='data-table';node.dataset.uiStudioStructureLabel=patch.content||'Data table';node.innerHTML=`<table><thead><tr><th>${patch.content||'Data table'}</th><th>Value</th></tr></thead><tbody><tr><td>Row</td><td>—</td></tr></tbody></table>`;
    }else if(patch.elementType==='button'){
      node=document.createElement('button');node.type='button';node.className='merd-ui-studio-added-preview merd-ui-studio-added-button';node.textContent=patch.content||'New button';
    }else if(patch.elementType==='card'){
      node=document.createElement('article');node.className='controls-card merd-ui-studio-added-preview merd-ui-studio-added-card';const strong=document.createElement('strong');strong.textContent=patch.content||'New card';node.appendChild(strong);
    }else if(patch.elementType==='divider'){
      node=document.createElement('div');node.className='merd-ui-studio-added-preview merd-ui-studio-added-divider';node.setAttribute('aria-hidden','true');
    }else{
      node=document.createElement('p');node.className='merd-ui-studio-added-preview merd-ui-studio-added-text';node.dataset.uiStudioStructureType='text';node.textContent=patch.content||'New text';
    }
    if(!node.dataset.uiStudioStructureType&&patch.elementType!=='clone'&&['section','container','text','metric-card','chart','employee-status','data-table'].includes(patch.elementType))node.dataset.uiStudioStructureType=patch.elementType;
    node.dataset.uiStudioAddedKey=patch.addedKey;runtimeKeyFor(node,patch.runtimeKey||patch.addedKey);return node;
  }
"""
studio=replace_block(studio,'  function createAddedNode(','  function applyAdd(',create_added,'createAddedNode')

apply_add="""  function applyAdd(patch){if(!patch.addedKey||find(addedSelector(patch.addedKey)))return;const target=(patch.parentKey&&find(runtimeSelector(patch.parentKey)))||find(patch.parent);if(!target)return;const node=createAddedNode(patch),before=['above','left','before'].includes(patch.position);if(patch.position==='inside'&&canContain(target))target.appendChild(node);else if(target.parentElement)target.parentElement.insertBefore(node,before?target:target.nextSibling);else target.appendChild(node);}
"""
studio=replace_block(studio,'  function applyAdd(','  function applyMove(',apply_add,'applyAdd')
apply_move="""  function applyMove(patch){const source=(patch.runtimeKey&&find(runtimeSelector(patch.runtimeKey)))||find(patch.selector),target=(patch.targetKey&&find(runtimeSelector(patch.targetKey)))||find(patch.target);if(!source||!target||source===target||source.contains(target))return;if(patch.position==='inside'&&canContain(target)){target.appendChild(source);return;}const before=['top','left','before','above'].includes(patch.position);target.parentElement?.insertBefore(source,before?target:target.nextSibling);}
"""
studio=replace_block(studio,'  function applyMove(','  function applyText(',apply_move,'applyMove')

bridge="""  function addElementDirect(targetInput,elementType,position='inside',content=''){
    if(currentViewRole()!=='DEV')return false;const target=targetInput instanceof Element?targetInput:find(String(targetInput||''));if(!target||isStudioNode(target))return false;
    const parentSelector=selectorFor(target),parentKey=runtimeKeyFor(target),addedKey=`add-${Date.now().toString(36)}-${(++runtimeCounter).toString(36)}`,selector=addedSelector(addedKey);
    state.patches.push(stampRoleScope({kind:'add',scope:'element',selector,runtimeKey:addedKey,parent:parentSelector,parentKey,position,elementType:String(elementType||'text'),content:String(content||''),addedKey}));
    recordHistory('add',`Added ${elementType} ${position} from Structure`,{selector,runtimeKey:addedKey,element:target});persist();applyAll();const added=find(selector);if(added)selectElement(added,selector);renderMenu();showToast(`${elementType} added from Structure`);return added||true;
  }
  function moveElementDirect(sourceInput,targetInput,position='after'){
    if(currentViewRole()!=='DEV')return false;const source=sourceInput instanceof Element?sourceInput:find(String(sourceInput||'')),target=targetInput instanceof Element?targetInput:find(String(targetInput||''));if(!source||!target||source===target||source.contains(target)||isStudioNode(source)||isStudioNode(target))return false;selectElement(source);recordMove(target,position);applyAll();selectElement(source);window.dispatchEvent(new CustomEvent('merdpos-uistudio-structure-change',{detail:{kind:'move',position}}));return true;
  }
  function duplicateElementDirect(targetInput){
    if(currentViewRole()!=='DEV')return false;const target=targetInput instanceof Element?targetInput:find(String(targetInput||''));if(!target||isStudioNode(target))return false;const parentSelector=selectorFor(target),parentKey=runtimeKeyFor(target),sourceSelector=selectorFor(target),sourceKey=runtimeKeyFor(target),addedKey=`add-${Date.now().toString(36)}-${(++runtimeCounter).toString(36)}`,selector=addedSelector(addedKey);
    state.patches.push(stampRoleScope({kind:'add',scope:'element',selector,runtimeKey:addedKey,parent:parentSelector,parentKey,position:'after',elementType:'clone',content:'',source:sourceSelector,sourceKey,addedKey}));recordHistory('add','Duplicated element from Structure',{selector,runtimeKey:addedKey,element:target});persist();applyAll();const added=find(selector);if(added)selectElement(added,selector);renderMenu();showToast('Element duplicated from Structure');return added||true;
  }
  function removeElementDirect(targetInput){const target=targetInput instanceof Element?targetInput:find(String(targetInput||''));if(!target||isStudioNode(target))return false;selectElement(target);if(target.dataset.uiStudioAddedKey)resetSelected();else toggleHidden();window.dispatchEvent(new CustomEvent('merdpos-uistudio-structure-change',{detail:{kind:target.dataset.uiStudioAddedKey?'remove':'hide'}}));return true;}
"""
studio=replace_once(studio,'  function toggleRevealHidden(){',bridge+'  function toggleRevealHidden(){','structure bridge insertion')

clear_sel="""  function clearSelected(){state.selected?.classList?.remove('merd-ui-studio-selected');state.selected=null;state.selectedElementSelector='';state.selectedRuntimeKey='';updateHubState();updateCursorPill();window.dispatchEvent(new CustomEvent('merdpos-uistudio-selection',{detail:{element:null,selector:'',runtimeKey:''}}));}
"""
studio=replace_block(studio,'  function clearSelected(){','  function selectElement(',clear_sel,'clearSelected event')
select_sel="""  function selectElement(el,selectorOverride=''){const target=selectionTarget(el);if(!target)return;clearSelected();clearHover();state.selected=target;target.classList.add('merd-ui-studio-selected');state.selectedElementSelector=selectorOverride||selectorFor(target);state.selectedRuntimeKey=runtimeKeyFor(target);updateHubState();updateCursorPill();window.dispatchEvent(new CustomEvent('merdpos-uistudio-selection',{detail:{element:target,selector:state.selectedElementSelector,runtimeKey:state.selectedRuntimeKey}}));showToast(`Selected ${currentSelector()}`);}
"""
studio=replace_block(studio,'  function selectElement(','  function applyScope(',select_sel,'selectElement event')

root_defs="""  function rootDefinitions(){const role=currentViewRole(),lower=role!=='DEV',base=[{label:'Minimize',action:'minimize',icon:'minimize',accent:studioSettings.accent,disabled:window.innerWidth<=820},{label:window.MERDPOSDashboardBuilder?.isEditing?.()?'Done Dashboard':'Edit Dashboard',action:'edit-dashboard',icon:'dashboard',accent:STUDIO_COLORS.green,disabled:!window.MERDPOSDashboardBuilder?.toggleStudioEdit},{label:'Structure',action:'structure',icon:'dashboard',accent:STUDIO_COLORS.cyan},{label:'Changes',action:'changes',icon:'changes',accent:STUDIO_COLORS.indigo,children:true},{label:'Settings',action:'settings',icon:'settings',accent:studioSettings.accent,children:true}];if(!state.selected)return base;const hides=applicableHidePatches(),ownHide=hides.find(p=>patchOwnedByCurrentRole(p)),hidden=!!hides.length,upstreamHidden=hidden&&!ownHide;return [base[0],{label:'Unselect',action:'unselect',icon:'select',accent:STUDIO_COLORS.cyan},base[1],base[2],{label:lower?'Request':'Add',action:'add',icon:'add',accent:STUDIO_COLORS.pink,children:true},{label:'Edit',action:'edit',icon:'edit',accent:STUDIO_COLORS.amber,children:true},{label:'Move',action:'move',icon:'move',accent:STUDIO_COLORS.lime,children:true},{label:lower?'Request Note':'Comment',action:'comment',icon:'comment',accent:STUDIO_COLORS.gold},{label:upstreamHidden?'Hidden upstream':hidden?'Show':'Hide',action:'hide',icon:hidden?'eye':'eyeOff',accent:STUDIO_COLORS.slate,disabled:upstreamHidden},base[3],base[4]];}

"""
studio=replace_block(studio,'  function rootDefinitions(){','  function editDefinitions()',root_defs,'rootDefinitions structure action')
studio=replace_once(studio,"if(action==='edit-dashboard')return toggleDashboardEditor();","if(action==='edit-dashboard')return toggleDashboardEditor();if(action==='structure'){hideMenu();window.dispatchEvent(new CustomEvent('merdpos-uistudio-structure',{detail:{open:true}}));return;}",'onDefinition structure route')

export_start="    window.MERDPOS_UI_STUDIO=Object.freeze("
start=studio.find(export_start)
if start<0: raise SystemExit('missing studio export')
end=studio.find("\n    if(studioSettings.enabled)",start)
if end<0: raise SystemExit('missing studio export end')
export_line="""    window.MERDPOS_UI_STUDIO=Object.freeze({open:()=>setStudioEnabled(true),close:()=>setStudioEnabled(false),setEnabled:setStudioEnabled,isEnabled:()=>!!studioSettings.enabled,isActive:()=>!!state.active,minimize:minimizeStudio,addContextComment,getChangeSet:()=>JSON.parse(JSON.stringify(exportPayload())),getChanges:()=>JSON.parse(JSON.stringify(state.patches)),getSettings:()=>JSON.parse(JSON.stringify(studioSettings)),getPalette:()=>cloneValue(brandPaletteItems()),refreshPreview:applyAll,copyForChat:copyChat,openChanges:()=>{setStudioEnabled(true);navigate('changes');showMenu();},openReceipt:()=>{setStudioEnabled(true);openReceiptComposer();},applyReceipt,getCounts:()=>patchCounts(),clear:clearDraft,selectElement:(input)=>{const el=input instanceof Element?input:find(String(input||''));if(!el)return false;selectElement(el);return true;},getSelection:()=>({element:state.selected,selector:state.selectedElementSelector,runtimeKey:state.selectedRuntimeKey}),addElement:addElementDirect,moveElement:moveElementDirect,duplicateElement:duplicateElementDirect,removeElement:removeElementDirect});"""
studio=studio[:start]+export_line+studio[end:]
write(studio_path,studio)

management_path=ROOT/'namecheap_beta_live/timesheet_portal/assets/management.js'
management=read(management_path)
management=replace_once(management,"      appendStyle('merd-ui-studio-css','assets/ui-studio.css?v=20260902studio30');\n      appendScript('merd-ui-studio','assets/ui-studio.js?v=20260902ds117');","      appendStyle('merd-ui-studio-css','assets/ui-studio.css?v=20260902studio30');\n      appendStyle('merd-ui-studio-structure-css','assets/ui-studio-structure.css?v=20260902structure1');\n      appendScript('merd-ui-studio','assets/ui-studio.js?v=20260902structure1');\n      appendScript('merd-ui-studio-structure','assets/ui-studio-structure.js?v=20260902structure1');",'management structure loader')
write(management_path,management)

validator_path=ROOT/'namecheap_beta_live/backend/cli/validate_beta_runtime_contract.php'
validator=read(validator_path)
validator=replace_once(validator,"$uiStudioCss = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/ui-studio.css', $errors);","$uiStudioCss = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/ui-studio.css', $errors);\n$uiStudioStructureJs = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/ui-studio-structure.js', $errors);\n$uiStudioStructureCss = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/ui-studio-structure.css', $errors);",'validator structure reads')
structure_checks="""
// DevStudio Structure/Layers editor is a DEV-only view over the canonical patch engine.
beta_contract_require_contains($management, "if(isDev){", 'DevStudio DEV-only loader', $errors);
beta_contract_require_contains($management, 'assets/ui-studio-structure.css?v=20260902structure1', 'Structure editor stylesheet wiring', $errors);
beta_contract_require_contains($management, 'assets/ui-studio-structure.js?v=20260902structure1', 'Structure editor runtime wiring', $errors);
beta_contract_require_contains($uiStudioJs, "{label:'Structure',action:'structure'", 'Structure radial action', $errors);
beta_contract_require_contains($uiStudioJs, "if(patch.position==='inside'&&canContain(target))target.appendChild(node)", 'inside add patch semantics', $errors);
beta_contract_require_contains($uiStudioJs, "if(patch.position==='inside'&&canContain(target)){target.appendChild(source);return;}", 'inside move/reparent patch semantics', $errors);
beta_contract_require_contains($uiStudioJs, 'duplicateElement:duplicateElementDirect', 'Structure duplicate bridge', $errors);
beta_contract_require_contains($uiStudioStructureJs, "page:['section']", 'Page to Section hierarchy', $errors);
beta_contract_require_contains($uiStudioStructureJs, "section:['container','text','metric-card','chart','employee-status','data-table']", 'Section child hierarchy', $errors);
beta_contract_require_contains($uiStudioStructureJs, "container:['container','text','metric-card','chart','employee-status','data-table']", 'Container child hierarchy', $errors);
beta_contract_require_contains($uiStudioStructureJs, 'MERDPOS_UI_STUDIO_STRUCTURE', 'Structure editor public runtime', $errors);
beta_contract_require_contains($uiStudioStructureCss, '@media(max-width:720px)', 'Structure editor mobile sheet', $errors);

"""
marker='// Implemented DevStudio patch requests are canonical source, not permanent Studio overlays.\n'
validator=replace_once(validator,marker,structure_checks+marker,'validator structure checks')
write(validator_path,validator)

doc_path=ROOT/'namecheap_beta_live/timesheet_portal/UI_STUDIO.md'
doc=read(doc_path)
if '## Structure / Layers editor' not in doc:
    doc += """

## Structure / Layers editor

Developer sessions expose a Structure action in the DevStudio radial menu. It opens a persistent layers tree over the currently visible portal page and uses MERDPOS semantic nodes instead of raw DOM noise:

- Page
  - Section
    - Container
      - Text
      - Metric Card
      - Chart
      - Employee Status
      - Data Table

A Section may also own content directly (for example `Section > Data Table`). Page accepts Sections; Sections accept Containers or content modules; Containers accept nested Containers or content modules. Leaf content modules do not accept children.

Tree selection is synchronized with the existing DevStudio live-preview selection. Drag/drop uses before, inside, and after placement and records the same canonical move patches used by radial Move. Add Above / Inside / Below uses the canonical add patch engine. Duplicate creates a sanitized preview clone with duplicate IDs/form ownership attributes stripped. Existing source elements use Hide rather than destructive deletion; elements created by DevStudio can be removed from the current preview layer.

The Structure runtime and stylesheet are loaded only for actual DEV sessions. On narrow screens the layers panel becomes a bottom sheet. The panel is an editor view only: patch history, role scope, copy-for-ChatGPT, receipt status, and deployment truth remain owned by DevStudio's existing canonical state.
"""
    write(doc_path,doc)

Path(__file__).unlink()
