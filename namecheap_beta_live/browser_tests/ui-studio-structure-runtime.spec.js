const {test,expect}=require('@playwright/test');
const fs=require('fs');
const path=require('path');
const root=path.join(__dirname,'..','timesheet_portal');
const source=file=>fs.readFileSync(path.join(root,file),'utf8');
test.use({channel:'chrome'});

test('DevStudio structure bridge persists inside add/move through canonical patch engine',()=>{
  const studio=source('assets/ui-studio.js');
  expect(studio).toContain("{label:'Structure',action:'structure'");
  expect(studio).toContain("if(patch.position==='inside'&&canContain(target))target.appendChild(node)");
  expect(studio).toContain("if(patch.position==='inside'&&canContain(target)){target.appendChild(source);return;}");
  expect(studio).toContain('function addElementDirect(targetInput,elementType,position=\'inside\',content=\'\')');
  expect(studio).toContain('function moveElementDirect(sourceInput,targetInput,position=\'after\')');
  expect(studio).toContain('duplicateElement:duplicateElementDirect');
  expect(studio).toContain("new CustomEvent('merdpos-uistudio-selection'");
});

test('Structure editor exposes MERDPOS hierarchy vocabulary and placement rules',()=>{
  const structure=source('assets/ui-studio-structure.js');
  for(const label of ['Page','Section','Container','Text','Metric Card','Chart','Employee Status','Data Table'])expect(structure).toContain(label);
  expect(structure).toContain("page:['section']");
  expect(structure.indexOf("if(parentType==='page')return 'section';")).toBeLessThan(structure.indexOf('const component=componentType(el);'));
  expect(structure).toContain("section:['container','text','metric-card','chart','employee-status','data-table']");
  expect(structure).toContain("container:['container','text','metric-card','chart','employee-status','data-table']");
  expect(structure).toContain("position==='inside'");
  expect(structure).toContain("studio()?.moveElement?.(source.el,target.el,position)");
  expect(structure).toContain("studio()?.duplicateElement?.(node.el)");
  expect(structure).toContain("studio()?.removeElement?.(node.el)");
});

test('Structure panel renders Page > Section > Container components and direct Section > Data Table',async({page})=>{
  await page.setContent(`<!doctype html><body><main class="page-shell"><section id="dashboardPanel" class="portal-panel"><section id="overview" data-ui-studio-structure-type="section" data-ui-studio-structure-label="Overview"><div id="mainContainer" data-ui-studio-structure-type="container" data-ui-studio-structure-label="Main container"><p data-ui-studio-structure-type="text">Welcome</p><article data-ui-studio-structure-type="metric-card" data-ui-studio-structure-label="Sales metric"></article><figure data-ui-studio-structure-type="chart" data-ui-studio-structure-label="Sales chart"></figure></div><div id="statusContainer" data-ui-studio-structure-type="container" data-ui-studio-structure-label="Status container"><article data-ui-studio-structure-type="employee-status" data-ui-studio-structure-label="Employee Status"></article></div></section><section id="records" data-ui-studio-structure-type="section" data-ui-studio-structure-label="Records"><div data-ui-studio-structure-type="data-table" data-ui-studio-structure-label="Employees table"><table><tbody><tr><td>A</td></tr></tbody></table></div></section></section></main></body>`);
  await page.addScriptTag({content:`window.MERDPOS_AUTH={is_dev:true};window.__structureCalls=[];window.MERDPOS_UI_STUDIO={open(){window.__structureCalls.push(['open']);},selectElement(el){window.__structureCalls.push(['select',el.id||el.dataset.uiStudioStructureType]);},addElement(el,type,position,content){window.__structureCalls.push(['add',el.id||'',type,position,content]);},moveElement(a,b,p){window.__structureCalls.push(['move',a.id||'',b.id||'',p]);},duplicateElement(el){window.__structureCalls.push(['duplicate',el.id||'']);},removeElement(el){window.__structureCalls.push(['remove',el.id||'']);}};`});
  await page.addScriptTag({content:source('assets/ui-studio-structure.js')});
  await page.evaluate(()=>window.MERDPOS_UI_STUDIO_STRUCTURE.open());
  const tree=page.locator('.merd-ui-structure-tree');
  await expect(tree).toContainText('Page');
  await expect(tree).toContainText('Overview');
  await expect(tree).toContainText('Main container');
  await expect(tree).toContainText('Text');
  await expect(tree).toContainText('Metric Card');
  await expect(tree).toContainText('Chart');
  await expect(tree).toContainText('Employee Status');
  await expect(tree).toContainText('Records');
  await expect(tree).toContainText('Data Table');
  const records=tree.locator('[data-structure-key]').filter({hasText:'Records'}).first();
  const dataTable=tree.locator('[data-structure-key]').filter({hasText:'Employees table'}).first();
  await expect(records).toHaveAttribute('data-structure-type','section');
  await expect(dataTable).toHaveAttribute('data-structure-type','data-table');
});

test('Structure actions select live DOM and offer allowed inside modules',async({page})=>{
  await page.setContent(`<!doctype html><body><section id="dashboardPanel" class="portal-panel"><section id="sectionOne" data-ui-studio-structure-type="section" data-ui-studio-structure-label="Section One"><div id="containerOne" data-ui-studio-structure-type="container" data-ui-studio-structure-label="Container One"><p id="textOne" data-ui-studio-structure-type="text">Hello</p></div></section></section></body>`);
  await page.addScriptTag({content:`window.MERDPOS_AUTH={is_dev:true};window.__structureCalls=[];window.MERDPOS_UI_STUDIO={open(){},selectElement(el){window.__structureCalls.push(['select',el.id]);},addElement(el,type,position,content){window.__structureCalls.push(['add',el.id,type,position,content]);},moveElement(){},duplicateElement(){},removeElement(){}};`});
  await page.addScriptTag({content:source('assets/ui-studio-structure.js')});
  await page.evaluate(()=>window.MERDPOS_UI_STUDIO_STRUCTURE.open());
  const container=page.locator('[data-structure-key]').filter({hasText:'Container One'}).first();
  await container.locator('[data-structure-action="select"]').click();
  expect(await page.evaluate(()=>window.__structureCalls.some(call=>call[0]==='select'&&call[1]==='containerOne'))).toBeTruthy();
  await container.locator('[data-structure-action="more"]').click();
  await container.locator('[data-structure-action="add"][data-position="inside"]').click();
  const chooser=page.locator('.merd-ui-structure-chooser');
  await expect(chooser).toContainText('Text');
  await expect(chooser).toContainText('Metric Card');
  await expect(chooser).toContainText('Chart');
  await expect(chooser).toContainText('Employee Status');
  await expect(chooser).toContainText('Data Table');
  await chooser.locator('[data-add-type="data-table"]').click();
  expect(await page.evaluate(()=>window.__structureCalls.some(call=>call[0]==='add'&&call[1]==='containerOne'&&call[2]==='data-table'&&call[3]==='inside'))).toBeTruthy();
});

test('Structure editor has desktop layers panel and mobile bottom-sheet contract',()=>{
  const css=source('assets/ui-studio-structure.css');
  expect(css).toContain('.merd-ui-structure-panel{position:fixed');
  expect(css).toContain('width:min(370px,calc(100vw - 32px))');
  expect(css).toContain('@media(max-width:720px)');
  expect(css).toContain('height:min(68vh,640px)');
});