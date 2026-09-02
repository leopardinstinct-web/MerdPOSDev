(function(){
  'use strict';
  if(window.MERDPOS_AUTH?.is_dev!==true)return;

  const PANEL_ID='merdUiStudioStructure';
  const TYPE_LABELS={page:'Page',section:'Section',container:'Container',text:'Text','metric-card':'Metric Card',chart:'Chart','employee-status':'Employee Status','data-table':'Data Table'};
  const TYPE_DEFAULTS={section:'New section',container:'New container',text:'New text','metric-card':'New metric','chart':'New chart','employee-status':'Employee status','data-table':'Data table'};
  const LEAF_TYPES=new Set(['text','metric-card','chart','employee-status','data-table']);
  const ALLOWED={page:['section'],section:['container','text','metric-card','chart','employee-status','data-table'],container:['container','text','metric-card','chart','employee-status','data-table']};
  const state={open:false,filter:'',collapsed:new Set(),selectedKey:'',dragNode:null,dropHint:null,tree:null,refreshTimer:0};
  let panel=null,treeRoot=null,searchInput=null,breadcrumb=null,chooser=null;

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
  function findNodeByElement(el){return flatten(state.tree).find(node=>node.el===el||node.el.contains(el))||null;}
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
  }
  function open(){ensureUI();studio()?.open?.();state.open=true;panel.hidden=false;document.body.classList.add('merd-ui-structure-open');refresh(true);return true;}
  function close(){if(!panel)return;state.open=false;panel.hidden=true;chooser.hidden=true;document.body.classList.remove('merd-ui-structure-open');clearDropHints();}
  function toggle(){return state.open?(close(),false):open();}

  function matchesFilter(node){if(!state.filter)return true;return node.label.toLowerCase().includes(state.filter)||(TYPE_LABELS[node.type]||'').toLowerCase().includes(state.filter)||node.children.some(matchesFilter);}
  function rowHtml(node,depth){
    if(!matchesFilter(node))return '';
    const collapsed=state.collapsed.has(node.key)&&!state.filter,hasChildren=node.children.length>0,selected=node.key===state.selectedKey;
    const canInside=allowedChildren(node.type).length>0;
    const removeLabel=node.el.dataset.uiStudioAddedKey?'Remove':'Hide';
    return `<div class="merd-ui-structure-node${selected?' is-selected':''}" data-structure-key="${escapeHtml(node.key)}" data-structure-type="${node.type}" style="--structure-depth:${depth}" role="treeitem" aria-level="${depth+1}" aria-expanded="${hasChildren?String(!collapsed):'false'}"><div class="merd-ui-structure-row" draggable="${node.type!=='page'}"><button type="button" class="merd-ui-structure-toggle" data-structure-action="toggle" ${hasChildren?'':'disabled'} aria-label="${collapsed?'Expand':'Collapse'} ${escapeHtml(node.label)}">${hasChildren?(collapsed?'▸':'▾'):'·'}</button><button type="button" class="merd-ui-structure-select" data-structure-action="select"><span class="merd-ui-structure-icon" aria-hidden="true">${iconFor(node.type)}</span><span><strong>${escapeHtml(node.label)}</strong><small>${TYPE_LABELS[node.type]}</small></span></button><button type="button" class="merd-ui-structure-more" data-structure-action="more" aria-label="Actions for ${escapeHtml(node.label)}">•••</button></div><div class="merd-ui-structure-actions" hidden><button type="button" data-structure-action="add" data-position="above">Add above</button><button type="button" data-structure-action="add" data-position="inside" ${canInside?'':'disabled'}>Add inside</button><button type="button" data-structure-action="add" data-position="below">Add below</button><button type="button" data-structure-action="duplicate" ${node.type==='page'?'disabled':''}>Duplicate</button><button type="button" data-structure-action="remove" ${node.type==='page'?'disabled':''}>${removeLabel}</button></div></div>${!collapsed?node.children.map(child=>rowHtml(child,depth+1)).join(''):''}`;
  }
  function render(){
    if(!state.open||!treeRoot)return;state.tree=buildTree();
    if(!state.tree){treeRoot.innerHTML='<p class="merd-ui-structure-empty">No editable page is visible.</p>';return;}
    treeRoot.innerHTML=rowHtml(state.tree,0)||'<p class="merd-ui-structure-empty">No matching structure.</p>';
    const selected=findNodeByKey(state.selectedKey);breadcrumb.textContent=selected?pathFor(selected):`Page / ${state.tree.label}`;
  }
  function refresh(immediate=false){clearTimeout(state.refreshTimer);const run=()=>{if(state.open)render();};if(immediate)run();else state.refreshTimer=setTimeout(run,80);}

  function onTreeClick(event){
    const button=event.target.closest('[data-structure-action]');if(!button)return;const row=button.closest('[data-structure-key]'),node=findNodeByKey(row?.dataset.structureKey);if(!node)return;
    const action=button.dataset.structureAction;
    if(action==='toggle'){if(state.collapsed.has(node.key))state.collapsed.delete(node.key);else state.collapsed.add(node.key);return render();}
    if(action==='select'){selectNode(node);return;}
    if(action==='more'){const actions=row.querySelector('.merd-ui-structure-actions');actions.hidden=!actions.hidden;return;}
    if(action==='add')return openChooser(node,button.dataset.position||'inside');
    if(action==='duplicate'){studio()?.duplicateElement?.(node.el);return refresh();}
    if(action==='remove'){studio()?.removeElement?.(node.el);return refresh();}
  }
  function selectNode(node){state.selectedKey=node.key;studio()?.selectElement?.(node.el);breadcrumb.textContent=pathFor(node);render();node.el.scrollIntoView?.({block:'nearest',behavior:'smooth'});}

  function openChooser(target,position){
    const types=typesForPlacement(target,position);if(!types.length)return;
    chooser.dataset.targetKey=target.key;chooser.dataset.position=position;
    chooser.innerHTML=`<div class="merd-ui-structure-chooser-head"><strong>Add ${position}</strong><button type="button" data-chooser-close aria-label="Close">×</button></div><div class="merd-ui-structure-type-grid">${types.map(type=>`<button type="button" data-add-type="${type}"><span>${iconFor(type)}</span><strong>${TYPE_LABELS[type]}</strong></button>`).join('')}</div>`;
    chooser.hidden=false;
  }
  function onChooserClick(event){
    if(event.target.closest('[data-chooser-close]')){chooser.hidden=true;return;}
    const button=event.target.closest('[data-add-type]');if(!button)return;const target=findNodeByKey(chooser.dataset.targetKey),type=button.dataset.addType,position=chooser.dataset.position||'inside';if(!target||!canAdd(target,type,position))return;
    studio()?.addElement?.(target.el,type,position,TYPE_DEFAULTS[type]||TYPE_LABELS[type]);chooser.hidden=true;refresh();
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
  const observer=new MutationObserver(records=>{if(!state.open)return;const relevant=records.some(record=>!isStudioNode(record.target));if(relevant)refresh();});
  observer.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['hidden','class']});

  window.MERDPOS_UI_STUDIO_STRUCTURE=Object.freeze({open,close,toggle,refresh:()=>refresh(true),getTree:()=>state.tree});
})();
