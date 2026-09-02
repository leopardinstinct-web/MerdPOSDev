(function(){
  'use strict';
  if(window.MERDPOS_AUTH?.is_dev!==true)return;

  const PANEL_ID='merdUiStudioStructure';
  const TYPE_LABELS={page:'Page',section:'Section',container:'Container',text:'Text','metric-card':'Metric Card',chart:'Chart','employee-status':'Employee Status','data-table':'Data Table'};
  const TYPE_DEFAULTS={section:'New section',container:'New container',text:'New text','metric-card':'New metric','chart':'New chart','employee-status':'Employee status','data-table':'Data table'};
  const LEAF_TYPES=new Set(['text','metric-card','chart','employee-status','data-table']);
  const ALLOWED={page:['section'],section:['container','text','metric-card','chart','employee-status','data-table'],container:['container','text','metric-card','chart','employee-status','data-table']};
  const state={open:false,filter:'',collapsed:new Set(),selectedKey:'',openActionsKey:'',dragNode:null,dropHint:null,tree:null,refreshTimer:0,canvasInteraction:false,insertPickerTargetKey:'',insertPickerPosition:'inside'};
  let panel=null,treeRoot=null,searchInput=null,breadcrumb=null,chooser=null,canvasLayer=null,modulePicker=null,canvasRaf=0;

  const studio=()=>window.MERDPOS_UI_STUDIO||null;
  const isStudioNode=el=>!!el?.closest?.('[data-ui-studio]');
  const visiblePanel=()=>document.querySelector('.portal-panel:not([hidden])')||document.querySelector('.portal-panel');
  const directId=el=>String(el?.getAttribute?.('id')||'').trim();
  const cleanText=value=>String(value||'').replace(/\s+/g,' ').trim();
  const nodeKey=el=>el?.dataset?.uiStudioRuntimeKey||el?.dataset?.uiStudioAddedKey||(directId(el)?`id:${directId(el)}`:`node:${elementPath(el)}`);
  const elementPath=el=>{const parts=[];let node=el;while(node&&node!==document.body&&parts.length<6){if(directId(node)){parts.unshift(`#${directId(node)}`);break;}let part=node.tagName.toLowerCase();const siblings=node.parentElement?[...node.parentElement.children].filter(x=>x.tagName===node.tagName):[];if(siblings.length>1)part+=`:nth-of-type(${siblings.indexOf(node)+1})`;parts.unshift(part);node=node.parentElement;}return parts.join('>');};
  const labelFor=(el,type)=>{
    if(type==='page')return cleanText(document.querySelector(`.portal-tab[data-panel="${directId(el)}"] span:last-child`)?.textContent)||cleanText(el.getAttribute('aria-label'))||'Page';
    const explicit=cleanText(el.dataset.uiStudioStructureLabel);if(explicit)return explicit;
    const heading=el.matches('h1,h2,h3,h4,h5,h6')?el:el.querySelector(':scope > h1,:scope > h2,:scope > h3,:scope > h4,:scope > header h1,:scope > header h2,:scope > .ui-page-title');
    const headingText=cleanText(heading?.textContent);if(headingText)return headingText.slice(0,64);
    const aria=cleanText(el.getAttribute('aria-label'));if(aria)return aria.slice(0,64);
    if(type==='data-table'){const caption=cleanText(el.querySelector('caption')?.textContent);return caption||'Data Table';}
    if(type==='chart')return cleanText(el.dataset.chartTitle)||cleanText(el.previousElementSibling?.textContent)||'Chart';
    if(type==='employee-status')return cleanText(el.querySelector('h2,h3,strong')?.textContent)||'Employee Status';
    if(type==='metric-card')return cleanText(el.querySelector('.kpi-label,p,strong')?.textContent)||'Metric Card';
    if(type==='text')return cleanText(el.textContent).slice(0,52)||'Text';
    return TYPE_LABELS[type]||'Component';
  };

  function explicitType(el){const value=String(el?.dataset?.uiStudioStructureType||'').toLowerCase();return TYPE_LABELS[value]?value:'';}
  function componentType(el){
    const explicit=explicitType(el);if(explicit)return explicit;
    if(el.matches('table,.table-scroll')||el.querySelector(':scope > table'))return 'data-table';
    if(el.matches('canvas,[data-chart],.chart,.chart-shell,.analytics-chart,.dashboard-chart')||/chart/i.test(directId(el)))return 'chart';
    if(el.matches('.working-card,.employee-status,.status-card[data-employee]'))return 'employee-status';
    if(el.matches('.mgmt-kpi,.metric-card,.summary-card,.dashboard-metric,.financial-account-card'))return 'metric-card';
    if(el.matches('h1,h2,h3,h4,h5,h6,p,.ui-page-title,.muted')&&!el.querySelector('button,input,select,table'))return 'text';
    return '';
  }
  function isContainerLike(el){
    if(explicitType(el)==='container')return true;
    if(el.matches('.dashboard-summary,.management-kpis,.mgmt-grid,.dashboard-grid,.financial-account-grid,.analytics-grid,.card-grid,.directory-toolbar,.table-tools'))return true;
    const cls=String(el.className||'');
    return /(?:^|\s)(?:grid|row|columns?|container)(?:\s|$|-)/i.test(cls)&&el.children.length>1;
  }
  function isSectionLike(el){
    if(explicitType(el)==='section')return true;
    return el.matches('section,.controls-card,.directory-card,.mgmt-card,.financial-section,.report-launch-card,.hero-panel,.dashboard-section');
  }
  function semanticType(el,parentType){
    if(!(el instanceof Element)||isStudioNode(el)||el.hidden)return '';
    const explicit=explicitType(el);if(explicit)return explicit;
    if(parentType==='page')return 'section';
    const component=componentType(el);if(component)return component;
    if(isSectionLike(el))return 'section';
    if(isContainerLike(el))return 'container';
    return '';
  }
  function meaningfulChildren(el,parentType){
    const result=[];
    const walk=(root,depth=0)=>{
      if(depth>3)return;
      for(const child of root.children){
        if(!(child instanceof Element)||isStudioNode(child)||child.hidden)continue;
        const type=semanticType(child,parentType);
        if(type){result.push({el:child,type});continue;}
        if(!['SCRIPT','STYLE','TEMPLATE','SVG'].includes(child.tagName))walk(child,depth+1);
      }
    };
    walk(el,0);return result;
  }
  function makeNode(el,type,parent=null){
    const node={el,type,label:labelFor(el,type),key:nodeKey(el),parent,children:[]};
    if(!LEAF_TYPES.has(type)){
      const children=meaningfulChildren(el,type);
      const seen=new Set();
      for(const item of children){if(seen.has(item.el))continue;seen.add(item.el);node.children.push(makeNode(item.el,item.type,node));}
    }
    return node;
  }
  function buildTree(){const page=visiblePanel();if(!page)return null;return makeNode(page,'page',null);}
  function flatten(node,out=[]){if(!node)return out;out.push(node);for(const child of node.children)flatten(child,out);return out;}
  function findNodeByElement(el){const nodes=flatten(state.tree);return nodes.find(node=>node.el===el)||nodes.slice().reverse().find(node=>node.el.contains(el))||null;}
  function findNodeByKey(key){return flatten(state.tree).find(node=>node.key===key)||null;}
  function pathFor(node){const parts=[];for(let cursor=node;cursor;cursor=cursor.parent)parts.unshift(TYPE_LABELS[cursor.type]);return parts.join(' / ');}

  function allowedChildren(type){return ALLOWED[type]||[];}
  function canPlace(source,target,position){
    if(!source||!target||source===target||source.el.contains(target.el))return false;
    if(position==='inside')return allowedChildren(target.type).includes(source.type);
    const parent=target.parent;if(!parent)return false;
    return allowedChildren(parent.type).includes(source.type);
  }
  function canAdd(target,type,position){
    if(position==='inside')return allowedChildren(target.type).includes(type);
    return !!target.parent&&allowedChildren(target.parent.type).includes(type);
  }
  function typesForPlacement(target,position){const owner=position==='inside'?target:target.parent;return owner?allowedChildren(owner.type):[];}

  const COMPONENT_TYPES=['text','metric-card','chart','employee-status','data-table'];
  function ensureCanvasUI(){
    if(canvasLayer)return;
    canvasLayer=document.createElement('div');canvasLayer.className='merd-ui-structure-canvas-layer';canvasLayer.dataset.uiStudio='structure-canvas';canvasLayer.hidden=true;
    modulePicker=document.createElement('aside');modulePicker.className='merd-ui-structure-module-picker';modulePicker.dataset.uiStudio='structure-module-picker';modulePicker.hidden=true;
    document.body.append(canvasLayer,modulePicker);
    const arm=()=>{state.canvasInteraction=true;clearTimeout(state.refreshTimer);};
    const release=()=>setTimeout(()=>{state.canvasInteraction=false;},0);
    canvasLayer.addEventListener('pointerdown',event=>{if(event.target.closest('button'))arm();});
    modulePicker.addEventListener('pointerdown',arm);
    document.addEventListener('pointerup',release,true);document.addEventListener('pointercancel',release,true);
    canvasLayer.addEventListener('click',onCanvasClick);modulePicker.addEventListener('click',onModulePickerClick);modulePicker.addEventListener('input',onModulePickerInput);
  }
  function hideModulePicker(){if(!modulePicker)return;modulePicker.hidden=true;modulePicker.innerHTML='';state.insertPickerTargetKey='';state.insertPickerPosition='inside';}
  function canvasNodeVisible(rect){return rect.width>1&&rect.height>1&&rect.bottom>-80&&rect.top<window.innerHeight+80&&rect.right>-80&&rect.left<window.innerWidth+80;}
  function canvasInsertButton(node,kind,position,label,anchorName){return `<button type="button" class="merd-ui-structure-canvas-insert is-${kind}" data-canvas-role="insert" data-canvas-key="${escapeHtml(node.key)}" data-canvas-action="insert" data-insert-kind="${kind}" data-position="${position}" data-anchor="${anchorName}" aria-label="Add ${escapeHtml(label)}"><span aria-hidden="true">+</span>${escapeHtml(label)}</button>`;}
  function renderCanvas(){
    ensureCanvasUI();
    if(!state.open||!state.tree){canvasLayer.hidden=true;canvasLayer.innerHTML='';hideModulePicker();return;}
    canvasLayer.hidden=false;
    const selected=findNodeByKey(state.selectedKey)||state.tree,parts=[];
    for(const node of flatten(state.tree)){
      if(!['section','container'].includes(node.type))continue;
      parts.push(`<div class="merd-ui-structure-canvas-outline is-${node.type}${selected.key===node.key?' is-selected':''}" data-canvas-role="outline" data-canvas-key="${escapeHtml(node.key)}"><span>${TYPE_LABELS[node.type]}</span></div>`);
    }
    if(selected.type!=='page'){
      const removeLabel=selected.el.dataset.uiStudioAddedKey?'Remove':'Hide';
      parts.push(`<div class="merd-ui-structure-canvas-toolbar" data-canvas-role="toolbar" data-canvas-key="${escapeHtml(selected.key)}"><strong>${TYPE_LABELS[selected.type]} · ${escapeHtml(selected.label)}</strong><button type="button" data-canvas-action="edit" aria-label="Edit ${escapeHtml(selected.label)}">Edit</button><button type="button" data-canvas-action="duplicate" aria-label="Duplicate ${escapeHtml(selected.label)}">Duplicate</button><button type="button" data-canvas-action="remove" aria-label="${removeLabel} ${escapeHtml(selected.label)}">${removeLabel}</button><button type="button" data-canvas-action="more" aria-label="More actions for ${escapeHtml(selected.label)}">•••</button></div>`);
    }
    if(selected.type==='page')parts.push(canvasInsertButton(selected,'section','inside','Section','inside-bottom'));
    if(selected.type==='section'){
      parts.push(canvasInsertButton(selected,'container','inside','Container','inside-bottom-left'));
      parts.push(canvasInsertButton(selected,'component','inside','Component','inside-bottom-right'));
      if(selected.parent?.type==='page')parts.push(canvasInsertButton(selected,'section','after','Section','after-bottom'));
    }
    if(selected.type==='container'){
      parts.push(canvasInsertButton(selected,'container','inside','Container','inside-bottom-left'));
      parts.push(canvasInsertButton(selected,'component','inside','Component','inside-bottom-right'));
    }
    if(LEAF_TYPES.has(selected.type)&&selected.parent)parts.push(canvasInsertButton(selected,'component','after','Component','after-bottom'));
    canvasLayer.innerHTML=parts.join('');positionCanvas();
  }
  function positionCanvas(){
    if(!canvasLayer||canvasLayer.hidden||!state.tree)return;
    for(const item of canvasLayer.querySelectorAll('[data-canvas-key]')){
      const node=findNodeByKey(item.dataset.canvasKey);if(!node||!node.el?.isConnected){item.hidden=true;continue;}
      const rect=node.el.getBoundingClientRect();if(!canvasNodeVisible(rect)){item.hidden=true;continue;}item.hidden=false;
      const role=item.dataset.canvasRole;
      if(role==='outline'){item.style.left=`${Math.max(0,rect.left)}px`;item.style.top=`${Math.max(0,rect.top)}px`;item.style.width=`${Math.max(0,Math.min(window.innerWidth,rect.right)-Math.max(0,rect.left))}px`;item.style.height=`${Math.max(0,Math.min(window.innerHeight,rect.bottom)-Math.max(0,rect.top))}px`;continue;}
      if(role==='toolbar'){item.style.left=`${Math.max(8,Math.min(window.innerWidth-8,rect.left+4))}px`;item.style.top=`${Math.max(8,Math.min(window.innerHeight-44,rect.top+4))}px`;continue;}
      if(role==='insert'){
        const anchor=item.dataset.anchor||'inside-bottom',center=rect.left+rect.width/2;
        let x=center,y=rect.bottom;
        if(anchor==='inside-bottom-left'){x=rect.left+rect.width*.36;y=rect.bottom-8;}
        if(anchor==='inside-bottom-right'){x=rect.left+rect.width*.64;y=rect.bottom-8;}
        if(anchor==='inside-bottom'){y=rect.bottom-10;}
        if(anchor==='after-bottom')y=rect.bottom+10;
        item.style.left=`${Math.max(70,Math.min(window.innerWidth-70,x))}px`;item.style.top=`${Math.max(24,Math.min(window.innerHeight-24,y))}px`;
      }
    }
  }
  function scheduleCanvasPosition(){cancelAnimationFrame(canvasRaf);canvasRaf=requestAnimationFrame(positionCanvas);}
  function openModulePicker(target,position){
    ensureCanvasUI();const types=typesForPlacement(target,position).filter(type=>COMPONENT_TYPES.includes(type));if(!types.length)return;
    state.insertPickerTargetKey=target.key;state.insertPickerPosition=position;
    modulePicker.innerHTML=`<header class="merd-ui-structure-module-head"><div><span>DEVSTUDIO</span><h3>Insert Component</h3></div><button type="button" data-module-close aria-label="Close Insert Component">×</button></header><div class="merd-ui-structure-module-tabs"><span class="is-active">New Component</span></div><div class="merd-ui-structure-module-body"><small>Add ${position==='after'?'after':'inside'} ${escapeHtml(target.label)}</small><input type="search" data-module-search placeholder="Search components…" aria-label="Search components"><div class="merd-ui-structure-module-grid">${types.map(type=>`<button type="button" data-module-type="${type}" data-module-label="${escapeHtml(TYPE_LABELS[type].toLowerCase())}"><span aria-hidden="true">${iconFor(type)}</span><strong>${TYPE_LABELS[type]}</strong></button>`).join('')}</div></div>`;
    modulePicker.hidden=false;modulePicker.querySelector('[data-module-search]')?.focus({preventScroll:true});
  }
  function onModulePickerInput(event){if(!event.target.matches('[data-module-search]'))return;const query=cleanText(event.target.value).toLowerCase();for(const card of modulePicker.querySelectorAll('[data-module-type]'))card.hidden=!!query&&!String(card.dataset.moduleLabel||'').includes(query);}
  function onModulePickerClick(event){
    if(event.target.closest('[data-module-close]')){hideModulePicker();state.canvasInteraction=false;return;}
    const button=event.target.closest('[data-module-type]');if(!button)return;const target=findNodeByKey(state.insertPickerTargetKey),type=button.dataset.moduleType,position=state.insertPickerPosition||'inside';
    if(target&&COMPONENT_TYPES.includes(type)&&canAdd(target,type,position))studio()?.addElement?.(target.el,type,position,TYPE_DEFAULTS[type]||TYPE_LABELS[type]);
    hideModulePicker();state.canvasInteraction=false;refresh(true);
  }
  function onCanvasClick(event){
    const button=event.target.closest('[data-canvas-action]');if(!button)return;const node=findNodeByKey(button.dataset.canvasKey);if(!node)return;const action=button.dataset.canvasAction;
    if(action==='edit'){studio()?.selectElement?.(node.el);state.selectedKey=node.key;const rect=node.el.getBoundingClientRect();node.el.dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,cancelable:true,clientX:Math.max(8,rect.left+Math.min(rect.width/2,180)),clientY:Math.max(8,rect.top+20)}));state.canvasInteraction=false;return;}
    if(action==='duplicate'){studio()?.duplicateElement?.(node.el);state.canvasInteraction=false;return refresh();}
    if(action==='remove'){studio()?.removeElement?.(node.el);state.canvasInteraction=false;return refresh();}
    if(action==='more'){state.openActionsKey=node.key;state.canvasInteraction=false;render();requestAnimationFrame(()=>[...treeRoot.querySelectorAll('[data-structure-key]')].find(row=>row.dataset.structureKey===node.key)?.scrollIntoView?.({block:'nearest'}));return;}
    if(action==='insert'){
      const kind=button.dataset.insertKind,position=button.dataset.position||'inside';
      if(kind==='component'){openModulePicker(node,position);return;}
      if(kind==='section'&&canAdd(node,'section',position)){studio()?.addElement?.(node.el,'section',position,TYPE_DEFAULTS.section);state.canvasInteraction=false;return refresh(true);}
      if(kind==='container'&&canAdd(node,'container',position)){studio()?.addElement?.(node.el,'container',position,TYPE_DEFAULTS.container);state.canvasInteraction=false;return refresh(true);}
    }
  }

  function ensureUI(){
    if(panel)return;
    panel=document.createElement('aside');panel.id=PANEL_ID;panel.className='merd-ui-structure-panel';panel.dataset.uiStudio='structure';panel.hidden=true;
    panel.innerHTML=`<header class="merd-ui-structure-head"><div><span class="merd-ui-structure-kicker">DEVSTUDIO</span><h2>Structure</h2></div><button type="button" class="merd-ui-structure-close" aria-label="Close Structure">×</button></header><div class="merd-ui-structure-search"><input type="search" placeholder="Filter structure" aria-label="Filter structure"><span class="merd-ui-structure-breadcrumb">Page</span></div><div class="merd-ui-structure-tree" role="tree" aria-label="Page structure"></div><div class="merd-ui-structure-chooser" hidden></div>`;
    document.body.appendChild(panel);treeRoot=panel.querySelector('.merd-ui-structure-tree');searchInput=panel.querySelector('input');breadcrumb=panel.querySelector('.merd-ui-structure-breadcrumb');chooser=panel.querySelector('.merd-ui-structure-chooser');
    panel.querySelector('.merd-ui-structure-close').addEventListener('click',close);
    searchInput.addEventListener('input',()=>{state.filter=cleanText(searchInput.value).toLowerCase();render();});
    treeRoot.addEventListener('click',onTreeClick);
    treeRoot.addEventListener('dragstart',onDragStart);
    treeRoot.addEventListener('dragover',onDragOver);
    treeRoot.addEventListener('dragleave',onDragLeave);
    treeRoot.addEventListener('drop',onDrop);
    treeRoot.addEventListener('dragend',clearDropHints);
    chooser.addEventListener('click',onChooserClick);
    ensureCanvasUI();
  }
  function open(){ensureUI();studio()?.open?.();state.open=true;panel.hidden=false;document.body.classList.add('merd-ui-structure-open');refresh(true);return true;}
  function close(){if(!panel)return;state.open=false;state.openActionsKey='';state.canvasInteraction=false;panel.hidden=true;chooser.hidden=true;hideModulePicker();if(canvasLayer){canvasLayer.hidden=true;canvasLayer.innerHTML='';}document.body.classList.remove('merd-ui-structure-open');clearDropHints();}
  function toggle(){return state.open?(close(),false):open();}

  function matchesFilter(node){if(!state.filter)return true;return node.label.toLowerCase().includes(state.filter)||(TYPE_LABELS[node.type]||'').toLowerCase().includes(state.filter)||node.children.some(matchesFilter);}
  function rowHtml(node,depth){
    if(!matchesFilter(node))return '';
    const collapsed=state.collapsed.has(node.key)&&!state.filter,hasChildren=node.children.length>0,selected=node.key===state.selectedKey,actionsOpen=node.type==='page'||state.openActionsKey===node.key;
    const canInside=allowedChildren(node.type).length>0;
    const removeLabel=node.el.dataset.uiStudioAddedKey?'Remove':'Hide';
    return `<div class="merd-ui-structure-node${selected?' is-selected':''}" data-structure-key="${escapeHtml(node.key)}" data-structure-type="${node.type}" style="--structure-depth:${depth}" role="treeitem" aria-level="${depth+1}" aria-expanded="${hasChildren?String(!collapsed):'false'}"><div class="merd-ui-structure-row" draggable="${node.type!=='page'}"><button type="button" class="merd-ui-structure-toggle" data-structure-action="toggle" ${hasChildren?'':'disabled'} aria-label="${collapsed?'Expand':'Collapse'} ${escapeHtml(node.label)}">${hasChildren?(collapsed?'▸':'▾'):'·'}</button><button type="button" class="merd-ui-structure-select" data-structure-action="select"><span class="merd-ui-structure-icon" aria-hidden="true">${iconFor(node.type)}</span><span><strong>${escapeHtml(node.label)}</strong><small>${TYPE_LABELS[node.type]}</small></span></button><button type="button" class="merd-ui-structure-more" data-structure-action="more" aria-label="Actions for ${escapeHtml(node.label)}">•••</button></div><div class="merd-ui-structure-actions" ${actionsOpen?'':'hidden'}><button type="button" data-structure-action="add" data-position="above">Add above</button><button type="button" data-structure-action="add" data-position="inside" ${canInside?'':'disabled'}>Add inside</button><button type="button" data-structure-action="add" data-position="below">Add below</button><button type="button" data-structure-action="duplicate" ${node.type==='page'?'disabled':''}>Duplicate</button><button type="button" data-structure-action="remove" ${node.type==='page'?'disabled':''}>${removeLabel}</button></div></div>${!collapsed?node.children.map(child=>rowHtml(child,depth+1)).join(''):''}`;
  }
  function render(){
    if(!state.open||!treeRoot)return;state.tree=buildTree();
    if(!state.tree){state.openActionsKey='';treeRoot.innerHTML='<p class="merd-ui-structure-empty">No editable page is visible.</p>';renderCanvas();return;}
    if(state.openActionsKey&&!findNodeByKey(state.openActionsKey))state.openActionsKey='';
    treeRoot.innerHTML=rowHtml(state.tree,0)||'<p class="merd-ui-structure-empty">No matching structure.</p>';
    const selected=findNodeByKey(state.selectedKey);breadcrumb.textContent=selected?pathFor(selected):`Page / ${state.tree.label}`;renderCanvas();
  }
  const interactionActive=()=>!!(state.openActionsKey||(chooser&&!chooser.hidden)||(modulePicker&&!modulePicker.hidden)||state.canvasInteraction);
  function refresh(immediate=false){clearTimeout(state.refreshTimer);const run=()=>{if(!state.open)return;if(interactionActive()){state.refreshTimer=setTimeout(run,80);return;}render();};if(immediate&&!interactionActive())run();else state.refreshTimer=setTimeout(run,80);}

  function onTreeClick(event){
    const button=event.target.closest('[data-structure-action]');if(!button)return;const row=button.closest('[data-structure-key]'),node=findNodeByKey(row?.dataset.structureKey);if(!node)return;
    const action=button.dataset.structureAction;
    if(action==='toggle'){if(state.collapsed.has(node.key))state.collapsed.delete(node.key);else state.collapsed.add(node.key);return render();}
    if(action==='select'){selectNode(node);return;}
    if(action==='more'){state.openActionsKey=state.openActionsKey===node.key?'':node.key;chooser.hidden=true;return render();}
    if(action==='add')return openChooser(node,button.dataset.position||'inside');
    if(action==='duplicate'){studio()?.duplicateElement?.(node.el);return refresh();}
    if(action==='remove'){studio()?.removeElement?.(node.el);return refresh();}
  }
  function selectNode(node){state.selectedKey=node.key;studio()?.selectElement?.(node.el);breadcrumb.textContent=pathFor(node);render();node.el.scrollIntoView?.({block:'nearest',behavior:'smooth'});}

  function openChooser(target,position){
    const types=typesForPlacement(target,position);if(!types.length)return;
    if(types.length===1){const type=types[0];studio()?.addElement?.(target.el,type,position,TYPE_DEFAULTS[type]||TYPE_LABELS[type]);state.openActionsKey='';refresh(true);return;}
    chooser.dataset.targetKey=target.key;chooser.dataset.position=position;
    chooser.innerHTML=`<div class="merd-ui-structure-chooser-head"><strong>Add ${position}</strong><button type="button" data-chooser-close aria-label="Close">×</button></div><div class="merd-ui-structure-type-grid">${types.map(type=>`<button type="button" data-add-type="${type}"><span>${iconFor(type)}</span><strong>${TYPE_LABELS[type]}</strong></button>`).join('')}</div>`;
    chooser.hidden=false;
  }
  function onChooserClick(event){
    if(event.target.closest('[data-chooser-close]')){chooser.hidden=true;state.openActionsKey='';refresh(true);return;}
    const button=event.target.closest('[data-add-type]');if(!button)return;const target=findNodeByKey(chooser.dataset.targetKey),type=button.dataset.addType,position=chooser.dataset.position||'inside';if(!target||!canAdd(target,type,position))return;
    studio()?.addElement?.(target.el,type,position,TYPE_DEFAULTS[type]||TYPE_LABELS[type]);chooser.hidden=true;state.openActionsKey='';refresh(true);
  }

  function onDragStart(event){const row=event.target.closest('[data-structure-key]'),node=findNodeByKey(row?.dataset.structureKey);if(!node||node.type==='page'){event.preventDefault();return;}state.dragNode=node;event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',node.key);}
  function dropPosition(event,row,node){const rect=row.getBoundingClientRect(),ratio=(event.clientY-rect.top)/Math.max(1,rect.height);if(ratio<.28)return 'before';if(ratio>.72)return 'after';return allowedChildren(node.type).length?'inside':(ratio<.5?'before':'after');}
  function onDragOver(event){if(!state.dragNode)return;const row=event.target.closest('[data-structure-key]');if(!row)return;const target=findNodeByKey(row.dataset.structureKey);if(!target)return;const position=dropPosition(event,row,target);if(!canPlace(state.dragNode,target,position))return;event.preventDefault();event.dataTransfer.dropEffect='move';clearDropHints();row.classList.add(`is-drop-${position}`);state.dropHint={target,position,row};}
  function onDragLeave(event){const row=event.target.closest('[data-structure-key]');if(row&&!row.contains(event.relatedTarget))row.classList.remove('is-drop-before','is-drop-inside','is-drop-after');}
  function onDrop(event){if(!state.dragNode||!state.dropHint)return;event.preventDefault();const {target,position}=state.dropHint,source=state.dragNode;clearDropHints();if(!canPlace(source,target,position))return;studio()?.moveElement?.(source.el,target.el,position);state.selectedKey=source.key;refresh();}
  function clearDropHints(){treeRoot?.querySelectorAll('.is-drop-before,.is-drop-inside,.is-drop-after').forEach(row=>row.classList.remove('is-drop-before','is-drop-inside','is-drop-after'));state.dragNode=null;state.dropHint=null;}

  function escapeHtml(value){return String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));}
  function iconFor(type){return {page:'▤',section:'▱',container:'▦',text:'T','metric-card':'◫',chart:'⌁','employee-status':'●','data-table':'▥'}[type]||'◇';}

  window.addEventListener('merdpos-uistudio-structure',event=>{if(event.detail?.open===false)close();else open();});
  window.addEventListener('merdpos-uistudio-selection',event=>{const el=event.detail?.element instanceof Element?event.detail.element:null;if(!el){state.selectedKey='';if(state.open)render();return;}if(!state.open)return;state.tree=buildTree();const node=findNodeByElement(el);state.selectedKey=node?.key||'';render();});
  window.addEventListener('merdpos-uistudio-state',event=>{if(event.detail?.enabled===false)close();});
  document.addEventListener('click',event=>{if(event.target.closest('.portal-tab'))setTimeout(()=>refresh(),0);},true);
  window.addEventListener('resize',scheduleCanvasPosition);window.addEventListener('scroll',scheduleCanvasPosition,true);
  // Live portal widgets mutate class/hidden state frequently. Never replace an active Structure action, chooser, or canvas control mid-interaction.
  const mutationTouchesPortal=record=>{if(isStudioNode(record.target))return false;if(record.type==='childList'){const changed=[...record.addedNodes,...record.removedNodes].filter(node=>node instanceof Element);if(changed.length&&changed.every(node=>isStudioNode(node)))return false;}return true;};
  const observer=new MutationObserver(records=>{if(!state.open||state.openActionsKey||(chooser&&!chooser.hidden)||(modulePicker&&!modulePicker.hidden)||state.canvasInteraction)return;const relevant=records.some(mutationTouchesPortal);if(relevant)refresh();});
  observer.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['hidden','class']});

  window.MERDPOS_UI_STUDIO_STRUCTURE=Object.freeze({open,close,toggle,refresh:()=>refresh(true),getTree:()=>state.tree});
})();
