const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
test.use({ channel: 'chrome' });
const repoRoot = process.env.GITHUB_WORKSPACE || path.resolve(__dirname, '..', '..');
const portalRoot = path.join(repoRoot, 'namecheap_beta_live', 'timesheet_portal');
const tokensPath = path.join(portalRoot, 'assets', 'design-tokens.css');
const studioCssPath = path.join(portalRoot, 'assets', 'ui-studio.css');
const studioJsPath = path.join(portalRoot, 'assets', 'ui-studio.js');
const iconRoot = path.join(portalRoot, 'assets', 'vendor', 'google-material-symbols');
const fixture = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>:root{--shell-mobile-nav-h:4.75rem}.app-frame{display:flex}.app-rail{width:220px}.portal-panel[hidden]{display:none}</style></head><body>
<div class="app-frame nav-expanded"><aside class="app-rail"><section class="rail-section" data-nav-section="home"><button id="railHome" class="rail-group-btn" aria-label="Home"><span>Home</span></button><div class="sidebar-group" data-sidebar-group="home"><button class="portal-tab active" data-panel="demoPanel"><span>Dashboard</span></button><button class="portal-tab" data-panel="secondPanel"><span>Second</span></button></div></section><div class="rail-shell-utilities"><button id="utilityAction"><span>Utility action</span></button></div><section class="rail-account-dock"><button class="merd-shell-account-trigger" aria-label="Account">I</button></section></aside>
<main class="merd-page-shell"><section id="demoPanel" class="portal-panel"><header class="merd-mobile-page-head"><span class="merd-mobile-context">Demo client</span></header><article id="cardA" class="controls-card"><strong>Card A</strong></article><article id="cardB" class="controls-card"><strong>Card B</strong></article></section><section id="secondPanel" class="portal-panel" hidden><article id="cardC" class="controls-card"><strong>Card C</strong></article></section></main></div>
<script>window.__railClicks=0;window.__utilityClicks=0;window.__dashboardEditCalls=0;window.__dashboardEditing=false;window.MERDPOSDashboardBuilder={toggleStudioEdit:async()=>{window.__dashboardEditCalls++;window.__dashboardEditing=!window.__dashboardEditing;return window.__dashboardEditing;},isEditing:()=>window.__dashboardEditing};document.getElementById('railHome').addEventListener('click',()=>window.__railClicks++);document.getElementById('utilityAction').addEventListener('click',()=>window.__utilityClicks++);document.addEventListener('click',e=>{const tab=e.target.closest('.portal-tab');if(!tab)return;document.querySelectorAll('.portal-tab').forEach(x=>x.classList.toggle('active',x===tab));document.querySelectorAll('.portal-panel').forEach(p=>p.hidden=p.id!==tab.dataset.panel);});</script>
</body></html>`;
function createStudioServer(){
  const state={revision:0,patches:[],history:[],nextId:1};
  const payload=()=>({success:true,csrf:'studio-csrf',revision:state.revision,patches:structuredClone(state.patches),audit_retained:true,generated_at:new Date().toISOString()});
  return {state,async handle(route){
    const request=route.request();
    if(request.method()==='GET')return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(payload())});
    const body=request.postDataJSON()||{};
    if(body.action!=='bootstrap'&&Number(body.base_revision)!==state.revision)return route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({...payload(),success:false,error_code:'revision_conflict',error:'Studio changed elsewhere'})});
    if(body.action==='bootstrap'){
      if(state.revision===0&&!state.history.length){state.patches=structuredClone(body.patches||[]);state.revision=1;}
      return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(payload())});
    }
    if(body.action==='mutate'){
      state.patches=structuredClone(body.patches||[]);state.revision++;
      state.history.push({...body.entry,id:`global-${state.nextId++}`,revision:state.revision});
      return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(payload())});
    }
    if(body.action==='receipt'){
      const receipt=body.receipt||{},updates=Array.isArray(receipt.updates)?receipt.updates:[];
      for(const update of updates){const index=state.patches.findIndex(p=>p.patchId===update.patchId);if(index<0)continue;if(update.status==='confirmed_applied')state.patches.splice(index,1);else state.patches[index].status=update.status;}
      state.revision++;state.history.push({action:'llm_receipt',receipt,id:`global-${state.nextId++}`,revision:state.revision});
      return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({...payload(),receipt_summary:'Receipt applied'})});
    }
    return route.fulfill({status:400,contentType:'application/json',body:JSON.stringify({success:false,error:'Bad Studio action'})});
  }};
}
async function mount(page,isDev=true,width=1280,enabled=true,studioServer=null){
  await page.setViewportSize({width,height:width<=820?844:900});
  await page.addInitScript(()=>{window.__studioCopied='';const clipboard={writeText:async text=>{window.__studioCopied=String(text)}};try{Object.defineProperty(navigator,'clipboard',{configurable:true,value:clipboard})}catch(_){}});
  studioServer=studioServer||createStudioServer();
  await page.route('https://merdpos-smoke.invalid/ui-studio',r=>r.fulfill({status:200,contentType:'text/html',body:fixture}));
  await page.route('https://merdpos-smoke.invalid/api/ui_studio_history.php*',r=>studioServer.handle(r));
  await page.route('https://merdpos-smoke.invalid/api/ui_studio_asset.php*',r=>{const asset={token:'a'.repeat(64),name:'studio-context.svg',mime:'image/svg+xml',size:128,sha256:'b'.repeat(64),url:'https://app.merdpos.com/beta/timesheet_portal/studio_context_asset.php?t='+('a'.repeat(64))};studioServer.state.assets=studioServer.state.assets||[];studioServer.state.assets.push(asset);r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,csrf:'studio-csrf',asset})});});
  await page.route('https://merdpos-smoke.invalid/assets/vendor/google-material-symbols/*',r=>{const name=new URL(r.request().url()).pathname.split('/').pop();r.fulfill({status:200,contentType:'image/svg+xml',body:fs.readFileSync(path.join(iconRoot,name))})});
  await page.goto('https://merdpos-smoke.invalid/ui-studio');await page.evaluate(value=>localStorage.setItem('merdpos-ui-studio-settings-v1',JSON.stringify({enabled:value,accent:'#F59E0B',fontScale:1,iconScale:1,radialScale:1})),enabled);await page.addStyleTag({path:tokensPath});await page.addStyleTag({path:studioCssPath});await page.addScriptTag({content:`window.MERDPOS_AUTH={is_dev:${isDev?'true':'false'},role_key:${isDev?'"DEV"':'"ADMIN"'}};`});await page.addScriptTag({path:studioJsPath});if(isDev)await expect.poll(()=>page.evaluate(()=>window.MERDPOS_UI_STUDIO?.getChangeSet().global===true)).toBe(true);return studioServer;
}
async function reloadRuntime(page){await page.reload();await page.addStyleTag({path:tokensPath});await page.addStyleTag({path:studioCssPath});await page.addScriptTag({content:'window.MERDPOS_AUTH={is_dev:true,role_key:"DEV"};'});await page.addScriptTag({path:studioJsPath});}
const hub=page=>page.locator('.merd-ui-hub');
const item=(page,name)=>page.getByRole('menuitem',{name,exact:true});
async function choose(page,name){await item(page,name).evaluate(el=>el.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true})))}
async function openRoot(page){if(await item(page,'Settings').isVisible().catch(()=>false))return;await page.locator('#cardA').click({button:'right'});if(await item(page,'Unselect').isVisible().catch(()=>false))await choose(page,'Unselect');await expect(item(page,'Settings')).toBeVisible()}
async function selectTarget(page,selector){if(await item(page,'Settings').isVisible().catch(()=>false))await page.mouse.click(5,5);const target=page.locator(selector);await target.dispatchEvent('contextmenu',{button:2,clientX:160,clientY:180});await expect(page.locator('.merd-ui-studio-selected')).toHaveCount(1);await expect(item(page,'Unselect')).toBeVisible()}
async function openEdit(page){await openRoot(page);await choose(page,'Edit');await expect(item(page,'Color')).toBeVisible()}
async function submitComment(page,text,files=[]){const overlay=page.locator('.merd-ui-comment-overlay');await expect(overlay).toBeVisible();await overlay.locator('.merd-ui-comment-textarea').fill(text);if(files.length)await overlay.locator('input[type=file]').setInputFiles(files);await overlay.locator('.merd-ui-comment-save').click();await expect(overlay).toBeHidden();}
test('UI Studio is unavailable without actual DEV identity',async({page})=>{
  await mount(page,false);await expect(page.locator('.merd-ui-hub')).toHaveCount(0);expect(await page.evaluate(()=>typeof window.MERDPOS_UI_STUDIO)).toBe('undefined');
});

test('DEV Studio is toggle-controlled and root no longer has Select',async({page})=>{
  await mount(page,true,1280,false);await expect(hub(page)).toBeHidden();expect(await page.evaluate(()=>window.MERDPOS_UI_STUDIO.isEnabled())).toBe(false);await page.evaluate(()=>window.MERDPOS_UI_STUDIO.setEnabled(true));await expect(hub(page)).toBeHidden();await openRoot(page);await expect(hub(page)).toBeVisible();await expect(item(page,'Minimize')).toBeVisible();await expect(item(page,'Select')).toHaveCount(0);await expect(item(page,'Edit Dashboard')).toBeVisible();await expect(item(page,'Settings')).toBeVisible();await expect(item(page,'Exit')).toHaveCount(0);await expect(item(page,'Undo')).toBeVisible();
});

test('Changes is an unresolved inbox and Copy emits the receipt contract',async({page})=>{
  const server=await mount(page,true,1280);await openRoot(page);await choose(page,'Changes');await expect(item(page,'History')).toHaveCount(0);await expect(item(page,'Copy for ChatGPT')).toBeVisible();await expect(item(page,'Paste LLM Receipt')).toBeVisible();await page.mouse.click(5,5);await selectTarget(page,'#cardA');await choose(page,'Hide');await expect.poll(()=>server.state.patches.length).toBe(1);const payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.version).toBe(6);expect(payload.global).toBe(true);expect(payload.workflow).toBe('unresolved-patches');expect(payload.history).toBeUndefined();expect(payload.patches[0].patchId).toMatch(/^patch-/);expect(payload.patches[0].status).toBe('pending');await page.evaluate(()=>window.MERDPOS_UI_STUDIO.copyForChat());const copied=await page.evaluate(()=>window.__studioCopied);expect(copied).toContain('merdposDevStudioReceipt');expect(copied).toContain(payload.patches[0].patchId);
});

test('changed element dot marks unresolved work without exposing audit history',async({page})=>{
  const server=await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Comment');await submitComment(page,'Unresolved marker');await expect.poll(()=>server.state.patches.length).toBe(1);await page.mouse.click(5,5);const dot=page.locator('.merd-ui-change-dot');await expect(dot).toHaveCount(1);await expect(dot).toBeVisible();await expect(page.locator('[data-ui-studio="history"],.merd-ui-element-history')).toHaveCount(0);await expect(dot).toHaveAttribute('aria-label','Unresolved Studio patch');
});

test('root Edit Dashboard delegates to the shared dashboard builder',async({page})=>{
  await mount(page,true,1280);await openRoot(page);await choose(page,'Edit Dashboard');await expect.poll(()=>page.evaluate(()=>window.__dashboardEditCalls)).toBe(1);await openRoot(page);await expect(item(page,'Done Dashboard')).toBeVisible();
});

test('Studio separates button geometry size from icon size',async({page})=>{
  await mount(page,true,1280);await openRoot(page);await choose(page,'Settings');await expect(item(page,'Color')).toBeVisible();await expect(item(page,'Size')).toBeVisible();await choose(page,'Color');await choose(page,'Navy');expect(await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getSettings().accent)).toBe('#031B4B');expect(await page.evaluate(()=>getComputedStyle(document.documentElement).getPropertyValue('--merd-ui-accent-ink').trim())).toBe('#FFFFFF');await hub(page).click();await choose(page,'Size');await expect(item(page,'Button Size')).toBeVisible();await expect(item(page,'Icon Size')).toBeVisible();await choose(page,'Button Size');const before=await page.evaluate(()=>({button:window.MERDPOS_UI_STUDIO.getSettings().radialScale,icon:window.MERDPOS_UI_STUDIO.getSettings().iconScale,hub:document.querySelector('.merd-ui-hub').getBoundingClientRect().width,path:document.querySelector('.merd-ui-sector').getAttribute('d')}));await choose(page,'Increase');const afterButton=await page.evaluate(()=>({button:window.MERDPOS_UI_STUDIO.getSettings().radialScale,icon:window.MERDPOS_UI_STUDIO.getSettings().iconScale,hub:document.querySelector('.merd-ui-hub').getBoundingClientRect().width,path:document.querySelector('.merd-ui-sector').getAttribute('d')}));expect(afterButton.button).toBeGreaterThan(before.button);expect(afterButton.icon).toBe(before.icon);expect(afterButton.hub).toBeGreaterThan(before.hub);expect(afterButton.path).not.toBe(before.path);await hub(page).click();await choose(page,'Icon Size');const pathBeforeIcon=await page.locator('.merd-ui-sector').first().getAttribute('d');await choose(page,'Increase');const afterIcon=await page.evaluate(()=>({button:window.MERDPOS_UI_STUDIO.getSettings().radialScale,icon:window.MERDPOS_UI_STUDIO.getSettings().iconScale}));expect(afterIcon.button).toBe(afterButton.button);expect(afterIcon.icon).toBeGreaterThan(afterButton.icon);await expect(page.locator('.merd-ui-sector').first()).toHaveAttribute('d',pathBeforeIcon);
});


test('legacy DEV-only stamps normalize to the Developer master across every preview role',async({page})=>{
  const server=createStudioServer();server.state.revision=1;server.state.patches=[{kind:'style',scope:'element',selector:'#cardA',property:'display',value:'none',roleScope:'DEV',roleTargets:['DEV']}];await mount(page,true,1280,true,server);const patch=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet().patches[0]);expect(patch).toMatchObject({roleScope:'DEV',roleTargets:['DEV','ADMIN','SUPER','USER']});for(const role of ['ADMIN','SUPER','USER']){await page.evaluate(r=>{window.MERDPOS_AUTH.view_role_key=r;window.MERDPOS_UI_STUDIO.refreshPreview();},role);await expect(page.locator('#cardA')).toBeHidden();}
});

test('Developer and Admin implemented changes carry canonical downward inheritance',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Hide');let patch=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet().patches.find(p=>p.property==='display'));expect(patch).toMatchObject({roleScope:'DEV',roleTargets:['DEV','ADMIN','SUPER','USER']});await page.evaluate(()=>{window.MERDPOS_AUTH.view_role_key='ADMIN';window.MERDPOS_UI_STUDIO.refreshPreview();});await selectTarget(page,'#cardB');await choose(page,'Hide');patch=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet().patches.find(p=>p.selector==='#cardB'&&p.property==='display'));expect(patch).toMatchObject({roleScope:'ADMIN',roleTargets:['ADMIN','SUPER','USER']});
});

test('background palette automatically chooses readable text ink',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await openEdit(page);await choose(page,'Color');await choose(page,'Palette');await choose(page,'Navy');let patches=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet().patches);expect(patches).toEqual(expect.arrayContaining([expect.objectContaining({property:'background-color',value:'var(--color-brand-navy)'}),expect.objectContaining({property:'color',value:'#FFFFFF'})]));
});

test('global wheel skips disabled radial actions while radial is open',async({page})=>{
  await mount(page,true,390);await openRoot(page);await page.mouse.move(8,8);await page.mouse.wheel(0,120);await expect(hub(page).locator('strong')).not.toHaveText('Minimize');
});
test('Minimize disables Studio without creating a restore icon',async({page})=>{
  await mount(page,true,1280);await openRoot(page);await choose(page,'Minimize');await expect(hub(page)).toBeHidden();await expect(page.locator('.merd-ui-restore-trigger')).toHaveCount(0);expect(await page.evaluate(()=>window.MERDPOS_UI_STUDIO.isEnabled())).toBe(false);
});

test('enabled Studio shows dashed hover target while radial stays hidden',async({page})=>{
  await mount(page,true);await page.locator('#cardA').dispatchEvent('mouseover');await expect(page.locator('#cardA')).toHaveClass(/merd-ui-studio-hover/);await expect(hub(page)).toBeHidden();await expect(page.locator('.merd-ui-hover-select')).toHaveCount(0);
});

test('selected item shows a cursor-follow action pill and wheel focus supplies icon and label',async({page})=>{
  await mount(page,true,1280);await page.mouse.move(420,260);await selectTarget(page,'#cardA');const pill=page.locator('.merd-ui-cursor-pill');await expect(pill).toBeVisible();await expect(pill).toContainText('Select action…');const before=await pill.boundingBox();await page.mouse.move(690,410);await expect.poll(async()=>{const box=await pill.boundingBox();return Math.round(box.x)}).not.toBe(Math.round(before.x));await page.mouse.wheel(0,120);await expect(pill).toContainText('Minimize');await expect(pill.locator('.merd-ui-cursor-pill-icon')).toBeVisible();const iconMask=await pill.locator('.merd-ui-cursor-pill-icon').evaluate(el=>getComputedStyle(el).webkitMaskImage||getComputedStyle(el).maskImage);expect(iconMask).toContain('arrow_downward_48px.svg');
});

test('existing change dots follow the newly selected Studio accent',async({page})=>{
  await mount(page,true,1280);await selectTarget(page,'#cardA');await choose(page,'Comment');await submitComment(page,'Accent marker');await expect.poll(()=>page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet().patches.some(p=>p.kind==='comment'))).toBe(true);await page.mouse.click(5,5);const dot=page.locator('.merd-ui-change-dot');await expect(dot).toBeVisible();await expect.poll(()=>dot.evaluate(el=>getComputedStyle(el).backgroundColor)).toBe('rgb(245, 158, 11)');await page.locator('#cardA').click({button:'right'});await choose(page,'Settings');await choose(page,'Color');await choose(page,'Navy');await page.mouse.click(5,5);await expect(dot).toBeVisible();await expect.poll(()=>dot.evaluate(el=>getComputedStyle(el).backgroundColor)).toBe('rgb(3, 27, 75)');
});

test('Studio appearance settings synchronize across open windows on the same browser profile',async({browser})=>{
  const context=await browser.newContext(),server=createStudioServer(),pageA=await context.newPage(),pageB=await context.newPage();try{await mount(pageA,true,1280,true,server);await mount(pageB,true,1280,true,server);await openRoot(pageA);await choose(pageA,'Settings');await choose(pageA,'Color');await choose(pageA,'Navy');await expect.poll(()=>pageB.evaluate(()=>window.MERDPOS_UI_STUDIO.getSettings().accent)).toBe('#031B4B');await expect.poll(()=>pageB.evaluate(()=>getComputedStyle(document.documentElement).getPropertyValue('--merd-ui-accent').trim())).toBe('#031B4B');expect(await pageB.evaluate(()=>window.MERDPOS_UI_STUDIO.isEnabled())).toBe(true);}finally{await context.close();}
});

test('right click docks radial away from selected control and page clicks are consumed until dismissed',async({page})=>{
  await mount(page,true,1280);const target=page.locator('#railHome');const box=await target.boundingBox();await target.click({button:'right'});const h=await hub(page).boundingBox(),hc={x:h.x+h.width/2,y:h.y+h.height/2},tc={x:box.x+box.width/2,y:box.y+box.height/2};expect(Math.hypot(hc.x-tc.x,hc.y-tc.y)).toBeGreaterThan(300);await page.locator('#utilityAction').click();await expect(hub(page)).toBeHidden();expect(await page.evaluate(()=>window.__utilityClicks)).toBe(0);await page.locator('#utilityAction').click();expect(await page.evaluate(()=>window.__utilityClicks)).toBe(1);
});

test('Ctrl+D toggles Studio state without opening the radial',async({page})=>{
  await mount(page,true,1280,false);await page.evaluate(()=>{window.__studioStates=[];window.addEventListener('merdpos-uistudio-state',event=>window.__studioStates.push(!!event.detail?.enabled));});await page.keyboard.press('Control+d');expect(await page.evaluate(()=>window.MERDPOS_UI_STUDIO.isEnabled())).toBe(true);await expect(page.locator('body')).toHaveClass(/merd-ui-studio-enabled/);await expect(hub(page)).toBeHidden();await page.keyboard.press('Control+d');expect(await page.evaluate(()=>window.MERDPOS_UI_STUDIO.isEnabled())).toBe(false);await expect(page.locator('body')).not.toHaveClass(/merd-ui-studio-enabled/);expect(await page.evaluate(()=>window.__studioStates.slice(-2))).toEqual([true,false]);
});

test('right click selects an item and selected root exposes Unselect',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await expect(item(page,'Unselect')).toBeVisible();await expect(item(page,'Add')).toBeVisible();await expect(item(page,'Edit')).toBeVisible();await expect(item(page,'Move')).toBeVisible();await expect(item(page,'Comment')).toBeVisible();await expect(item(page,'Hide')).toBeVisible();
  await selectTarget(page,'#cardB');await expect(page.locator('#cardA')).not.toHaveClass(/merd-ui-studio-selected/);await expect(page.locator('#cardB')).toHaveClass(/merd-ui-studio-selected/);await choose(page,'Unselect');await expect(page.locator('.merd-ui-studio-selected')).toHaveCount(0);await expect(item(page,'Select')).toHaveCount(0);
});

test('Edit drills into Color Palette and Layout only',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Edit');await expect(item(page,'Color')).toBeVisible();await expect(item(page,'Layout')).toBeVisible();await expect(item(page,'Comment')).toHaveCount(0);await choose(page,'Color');await expect(item(page,'Palette')).toBeVisible();await choose(page,'Palette');await expect(item(page,'Navy')).toBeVisible();await expect(item(page,'Background')).toBeVisible();await expect(item(page,'Text')).toBeVisible();await hub(page).click({button:'right'});await expect(item(page,'Palette')).toBeVisible();await hub(page).click({button:'right'});await expect(item(page,'Color')).toBeVisible();
});
test('Add chooses an element type then Above Below Left or Right placement',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Add');await expect(item(page,'Text')).toBeVisible();await expect(item(page,'Button')).toBeVisible();await choose(page,'Button');await expect(item(page,'Above')).toBeVisible();await expect(item(page,'Below')).toBeVisible();await expect(item(page,'Left')).toBeVisible();await expect(item(page,'Right')).toBeVisible();page.once('dialog',d=>d.accept('Prototype action'));await choose(page,'Right');await expect(page.locator('[data-ui-studio-added-key]')).toContainText('Prototype action');const payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.patches).toEqual(expect.arrayContaining([expect.objectContaining({kind:'add',elementType:'button',position:'right'})]));
});

test('lower-role Add becomes a visual proposal that must originate from Developer master',async({page})=>{
  await mount(page,true);await page.evaluate(()=>{window.MERDPOS_AUTH.view_role_key='USER';window.MERDPOS_UI_STUDIO.refreshPreview();});await selectTarget(page,'#cardA');await expect(item(page,'Request')).toBeVisible();await expect(item(page,'Add')).toHaveCount(0);await choose(page,'Request');await choose(page,'Card');page.once('dialog',d=>d.accept('Requested sales widget'));await choose(page,'Below');const proposal=page.locator('[data-ui-studio-request-key]');await expect(proposal).toBeVisible();await expect(proposal).toContainText('REQUEST');await expect(proposal).toContainText('USER');await expect(proposal).toContainText('Requested sales widget');await expect(page.locator('[data-ui-studio-added-key]')).toHaveCount(0);const patch=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet().patches.find(p=>p.kind==='request'&&p.requestType==='add'));expect(patch).toMatchObject({requestedFromPreview:'USER',implementationOrigin:'DEV',status:'pending',roleScope:'USER',roleTargets:['DEV','USER'],elementType:'card',position:'below'});await page.evaluate(()=>{window.MERDPOS_AUTH.view_role_key='SUPER';window.MERDPOS_UI_STUDIO.refreshPreview();});await expect(proposal).toHaveCount(0);await page.evaluate(()=>{window.MERDPOS_AUTH.view_role_key='DEV';window.MERDPOS_UI_STUDIO.refreshPreview();});await expect(page.locator('[data-ui-studio-request-key]')).toBeVisible();
});

test('lower-role Comment is recorded as a Developer-master request, not an implemented comment',async({page})=>{
  await mount(page,true);await page.evaluate(()=>{window.MERDPOS_AUTH.view_role_key='SUPER';window.MERDPOS_UI_STUDIO.refreshPreview();});await selectTarget(page,'#cardA');await expect(item(page,'Request Note')).toBeVisible();await choose(page,'Request Note');await submitComment(page,'Need a compact summary widget here');const payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.patches).toEqual(expect.arrayContaining([expect.objectContaining({kind:'request',requestType:'comment',requestedFromPreview:'SUPER',implementationOrigin:'DEV',status:'pending',roleTargets:['DEV','SUPER'],comment:'Need a compact summary widget here'})]));expect(payload.patches.some(p=>p.kind==='comment'&&p.roleScope==='SUPER')).toBe(false);await expect(page.locator('#cardA')).toHaveClass(/merd-ui-studio-has-request/);
});

test('Studio comment composer accepts multiline text and image context for ChatGPT handoff',async({page})=>{
  const server=await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Comment');const svg='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10H0z"/></svg>';await submitComment(page,'First line\nSecond line',[{name:'icon.svg',mimeType:'image/svg+xml',buffer:Buffer.from(svg)}]);const patch=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet().patches.find(p=>p.kind==='comment'));expect(patch.comment).toBe('First line\nSecond line');expect(patch.attachments).toHaveLength(1);expect(patch.attachments[0]).toMatchObject({mime:'image/svg+xml',url:expect.stringContaining('studio_context_asset.php?t=')});expect(server.state.assets).toHaveLength(1);await page.evaluate(()=>window.MERDPOS_UI_STUDIO.copyForChat());const copied=await page.evaluate(()=>window.__studioCopied);expect(copied).toContain('First line');expect(copied).toContain('studio_context_asset.php?t=');expect(copied).toContain('Image context URLs');
});

test('Move selects a destination first then Top Bottom Left or Right',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Move');await expect(item(page,'Select Destination')).toBeVisible();await choose(page,'Select Destination');await page.locator('#cardB').click();await expect(item(page,'Top')).toBeVisible();await expect(item(page,'Bottom')).toBeVisible();await expect(item(page,'Left')).toBeVisible();await expect(item(page,'Right')).toBeVisible();await choose(page,'Bottom');const order=await page.locator('#demoPanel > article').evaluateAll(nodes=>nodes.map(n=>n.id));expect(order.slice(0,2)).toEqual(['cardB','cardA']);const payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.patches).toEqual(expect.arrayContaining([expect.objectContaining({kind:'move',position:'bottom'})]));
});

test('Comment and Hide Show are direct selected-item actions',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Comment');await submitComment(page,'Review spacing');let payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.patches).toEqual(expect.arrayContaining([expect.objectContaining({kind:'comment',comment:'Review spacing'})]));await selectTarget(page,'#cardA');await choose(page,'Hide');await expect(page.locator('#cardA')).toBeHidden();await expect(item(page,'Show')).toBeVisible();await choose(page,'Show');await expect(page.locator('#cardA')).toBeVisible();
});

test('lower-role Undo removes only its own layer and cannot undo the Developer master',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Hide');await page.evaluate(()=>{window.MERDPOS_AUTH.view_role_key='USER';window.MERDPOS_UI_STUDIO.refreshPreview();});await selectTarget(page,'#cardB');await choose(page,'Hide');let patches=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet().patches.filter(p=>p.property==='display'));expect(patches).toEqual(expect.arrayContaining([expect.objectContaining({selector:'#cardA',roleScope:'DEV'}),expect.objectContaining({selector:'#cardB',roleScope:'USER'})]));await choose(page,'Undo');patches=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet().patches.filter(p=>p.property==='display'));expect(patches.some(p=>p.selector==='#cardA'&&p.roleScope==='DEV')).toBe(true);expect(patches.some(p=>p.selector==='#cardB'&&p.roleScope==='USER')).toBe(false);await page.evaluate(()=>window.MERDPOS_UI_STUDIO.refreshPreview());await expect(page.locator('#cardA')).toBeHidden();await expect(page.locator('#cardB')).toBeVisible();
});

test('DEV master Undo absorbs stale duplicate child hide and restores every inherited preview',async({page})=>{
  const server=createStudioServer();server.state.revision=7;server.state.patches=[{kind:'style',scope:'element',selector:'#cardA',property:'display',value:'none',roleScope:'DEV',roleTargets:['DEV','ADMIN','SUPER','USER']},{kind:'style',scope:'element',selector:'#cardA',property:'display',value:'none',roleScope:'USER',roleTargets:['USER']}];await mount(page,true,1280,true,server);expect(await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet().patches.filter(p=>p.selector==='#cardA'&&p.property==='display').length)).toBe(1);await page.locator('#cardB').click({button:'right'});await choose(page,'Unselect');await choose(page,'Undo');await expect.poll(()=>server.state.patches.filter(p=>p.selector==='#cardA'&&p.property==='display').length).toBe(0);for(const role of ['DEV','ADMIN','SUPER','USER']){await page.evaluate(r=>{window.MERDPOS_AUTH.view_role_key=r;window.MERDPOS_UI_STUDIO.refreshPreview();},role);await expect(page.locator('#cardA')).toBeVisible();}
});

test('explicit lower-role override survives a later DEV master hide Undo',async({page})=>{
  await mount(page,true);await page.evaluate(()=>{window.MERDPOS_AUTH.view_role_key='USER';window.MERDPOS_UI_STUDIO.refreshPreview();});await selectTarget(page,'#cardA');await choose(page,'Hide');let userPatch=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet().patches.find(p=>p.selector==='#cardA'&&p.roleScope==='USER'));expect(userPatch.explicitOverride).toBe(true);await page.evaluate(()=>{window.MERDPOS_AUTH.view_role_key='DEV';window.MERDPOS_UI_STUDIO.refreshPreview();});await selectTarget(page,'#cardA');await choose(page,'Hide');await choose(page,'Undo');await page.evaluate(()=>{window.MERDPOS_AUTH.view_role_key='USER';window.MERDPOS_UI_STUDIO.refreshPreview();});await expect(page.locator('#cardA')).toBeHidden();userPatch=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet().patches.find(p=>p.selector==='#cardA'&&p.roleScope==='USER'));expect(userPatch.explicitOverride).toBe(true);
});

test('change-count badge opens unresolved patch actions, not history',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Hide');await expect(page.locator('.merd-ui-hub-count')).toBeVisible();await page.locator('.merd-ui-hub-count').click();await expect(item(page,'Copy for ChatGPT')).toBeVisible();await expect(item(page,'Paste LLM Receipt')).toBeVisible();await expect(page.locator('[data-ui-studio="history"]')).toHaveCount(0);
});
test('icon-only prototype geometry remains concentric with 48-unit icons',async({page})=>{
  await mount(page,true,360);await openRoot(page);const g=await page.evaluate(()=>{const h=document.querySelector('.merd-ui-hub').getBoundingClientRect(),m=document.querySelector('.merd-ui-menu').getBoundingClientRect(),svg=document.querySelector('.merd-ui-menu svg'),ring=document.querySelector('.merd-ui-sector-ring'),e=document.querySelector('[aria-label="Settings"]'),i=e.querySelector('.merd-ui-sector-icon');return {dx:Math.abs(h.left+h.width/2-(m.left+m.width/2)),dy:Math.abs(h.top+h.height/2-(m.top+m.height/2)),viewBox:svg.getAttribute('viewBox'),inner:ring.dataset.innerRadius,outer:ring.dataset.outerRadius,gap:ring.dataset.sliceGap,icon:Number(i.getAttribute('width')),labels:document.querySelectorAll('.merd-ui-sector-label').length,bg:getComputedStyle(document.querySelector('.merd-ui-hub')).backgroundImage}});expect(g.dx).toBeLessThan(1);expect(g.dy).toBeLessThan(1);expect(g.viewBox).toBe('0 0 760 760');expect(g.inner).toBe('84');expect(g.outer).toBe('220');expect(g.gap).toBe('0');expect(g.icon).toBe(48);expect(g.labels).toBe(0);expect(g.bg).toContain('rgb(245, 158, 11)');
});

test('numeric Layout values keep arrow-stepper behavior',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Edit');await choose(page,'Layout');await choose(page,'Padding');await expect(item(page,'Increase')).toBeVisible();await expect(item(page,'Decrease')).toBeVisible();const before=await hub(page).locator('small').textContent();await choose(page,'Increase');const after=await hub(page).locator('small').textContent();expect(after).not.toBe(before);const payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.patches).toEqual(expect.arrayContaining([expect.objectContaining({kind:'style',property:'padding'})]));
});

test('wheel anywhere arms slices and middle click activates the armed action',async({page})=>{
  await mount(page,true,1280);await openRoot(page);await page.mouse.move(8,8);await page.mouse.wheel(0,120);await expect(hub(page).locator('strong')).toHaveText('Minimize');const iconUrl=await hub(page).locator('.merd-ui-hub-context-icon').evaluate(el=>el.style.getPropertyValue('--merd-ui-hub-icon'));expect(iconUrl).toContain('https://merdpos-smoke.invalid/assets/vendor/google-material-symbols/');expect(iconUrl).not.toContain('/assets/assets/');await page.mouse.wheel(0,120);await expect(hub(page).locator('strong')).toHaveText('Edit Dashboard');await page.mouse.down({button:'middle'});await page.mouse.up({button:'middle'});await expect.poll(()=>page.evaluate(()=>window.__dashboardEditCalls)).toBe(1);
});

test('right click is Back while open and left click outside hides the radial',async({page})=>{
  await mount(page,true);await openRoot(page);await choose(page,'Settings');await expect(item(page,'Color')).toBeVisible();await hub(page).click({button:'right'});await expect(item(page,'Settings')).toBeVisible();await hub(page).click({button:'right'});await expect(hub(page)).toBeHidden();await page.locator('#cardB').click({button:'right'});await expect(hub(page)).toBeVisible();await page.mouse.click(5,5);await expect(hub(page)).toBeHidden();expect(await page.evaluate(()=>window.MERDPOS_UI_STUDIO.isEnabled())).toBe(true);
});

test('Undo remains available on every visible drill-down level',async({page})=>{
  await mount(page,true);await openRoot(page);await expect(item(page,'Undo')).toBeVisible();await selectTarget(page,'#cardA');await expect(item(page,'Undo')).toBeVisible();await choose(page,'Edit');await expect(item(page,'Undo')).toBeVisible();await choose(page,'Layout');await expect(item(page,'Undo')).toBeVisible();await choose(page,'Padding');await expect(item(page,'Undo')).toBeVisible();
});
test('right click selects existing controls without activating them',async({page})=>{
  await mount(page,true);await selectTarget(page,'#railHome span');expect(await page.evaluate(()=>window.__railClicks)).toBe(0);await expect(page.locator('#railHome')).toHaveClass(/merd-ui-studio-selected/);await selectTarget(page,'#utilityAction span');expect(await page.evaluate(()=>window.__utilityClicks)).toBe(0);await expect(page.locator('#utilityAction')).toHaveClass(/merd-ui-studio-selected/);
});

test('global unresolved patches synchronize without exposing backend audit history',async({browser})=>{
  const server=createStudioServer(),contextA=await browser.newContext(),contextB=await browser.newContext(),pageA=await contextA.newPage(),pageB=await contextB.newPage();try{await mount(pageA,true,1280,true,server);await mount(pageB,true,1280,true,server);await selectTarget(pageA,'#cardA');await choose(pageA,'Hide');await expect.poll(()=>server.state.patches.length).toBe(1);expect(server.state.history.length).toBe(1);await pageB.waitForTimeout(4300);await reloadRuntime(pageB);expect(await pageB.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet().revision)).toBe(server.state.revision);expect(await pageB.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet().history)).toBeUndefined();await expect(pageB.locator('#cardA')).toBeHidden();}finally{await contextA.close();await contextB.close();}
});


test('Paste LLM Receipt updates statuses and confirmed patches leave the global inbox',async({page})=>{
  const server=await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Comment');await submitComment(page,'Implement me');await expect.poll(()=>server.state.patches.length).toBe(1);const payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet()),patch=payload.patches[0];await page.evaluate(()=>window.MERDPOS_UI_STUDIO.openReceipt());const overlay=page.locator('.merd-ui-receipt-overlay');await expect(overlay).toBeVisible();const receipt={merdposDevStudioReceipt:1,sourceRevision:payload.revision,updates:[{patchId:patch.patchId,status:'confirmed_applied',commit:'abc123',verification:'passed',note:'Live verified'}]};await overlay.locator('.merd-ui-receipt-textarea').fill(JSON.stringify(receipt));await overlay.getByRole('button',{name:'Apply receipt'}).click();await expect(overlay).toBeHidden();await expect.poll(()=>page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet().patches.length)).toBe(0);expect(server.state.history.at(-1).action).toBe('llm_receipt');expect(server.state.history.at(-1).receipt.updates[0].patchId).toBe(patch.patchId);
});


test('MERDPOS Palette radial editor previews edit reorder add delete and copies a palette patch',async({page})=>{
  await mount(page,true,1280);await openRoot(page);await choose(page,'Settings');await choose(page,'MERDPOS Palette');await expect(item(page,'Navy #031B4B')).toBeVisible();await choose(page,'Navy #031B4B');await expect(item(page,'View')).toBeVisible();await expect(item(page,'Edit')).toBeVisible();await expect(item(page,'Move Up')).toBeVisible();await expect(item(page,'Move Down')).toBeVisible();await expect(item(page,'Delete')).toBeVisible();
  let answers=['Navy Preview','#123456'];const editDialogs=d=>d.accept(answers.shift());page.on('dialog',editDialogs);await choose(page,'Edit');page.off('dialog',editDialogs);let palette=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getPalette());expect(palette.find(item=>item.id==='navy')).toMatchObject({label:'Navy Preview',value:'#123456'});expect(await page.evaluate(()=>getComputedStyle(document.documentElement).getPropertyValue('--color-brand-navy').trim())).toBe('#123456');
  await choose(page,'Move Down');palette=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getPalette());expect(palette.findIndex(item=>item.id==='navy')).toBe(3);await hub(page).click({button:'right'});await expect(item(page,'Add color')).toBeVisible();answers=['Ocean','#123ABC'];const addDialogs=d=>d.accept(answers.shift());page.on('dialog',addDialogs);await choose(page,'Add color');page.off('dialog',addDialogs);palette=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getPalette());expect(palette.some(item=>item.label==='Ocean'&&item.value==='#123ABC')).toBe(true);await choose(page,'Ocean #123ABC');page.once('dialog',d=>d.accept());await choose(page,'Delete');palette=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getPalette());expect(palette.some(item=>item.label==='Ocean')).toBe(false);
  const payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());const patch=payload.patches.find(p=>p.kind==='palette');expect(patch).toBeTruthy();expect(patch).toMatchObject({scope:'global',paletteKey:'brand-master',roleScope:'DEV',status:'pending'});await page.evaluate(()=>window.MERDPOS_UI_STUDIO.copyForChat());const copied=await page.evaluate(()=>window.__studioCopied);expect(copied).toContain('[MERDPOS Palette]');expect(copied).toContain('"kind": "palette"');expect(copied).not.toContain('"history"');
});

test('dismissing or disabling the radial deselects the previously selected element',async({page})=>{
  await mount(page,true,1280);await selectTarget(page,'#cardA');await expect(page.locator('.merd-ui-studio-selected')).toHaveCount(1);await hub(page).click({button:'right'});await expect(hub(page)).toBeHidden();await expect(page.locator('.merd-ui-studio-selected')).toHaveCount(0);await selectTarget(page,'#cardA');await page.keyboard.press('Control+d');expect(await page.evaluate(()=>window.MERDPOS_UI_STUDIO.isEnabled())).toBe(false);await expect(page.locator('.merd-ui-studio-selected')).toHaveCount(0);await page.keyboard.press('Control+d');expect(await page.evaluate(()=>window.MERDPOS_UI_STUDIO.isEnabled())).toBe(true);await expect(page.locator('.merd-ui-studio-selected')).toHaveCount(0);
});

test('touch uses direct selection while synthetic hover stays inactive',async({browser})=>{
  const context=await browser.newContext({viewport:{width:394,height:512},hasTouch:true,isMobile:true});const page=await context.newPage();await mount(page,true,394);await hub(page).dispatchEvent('mouseenter');await expect(page.locator('.merd-ui-menu')).toBeHidden();const card=await page.locator('#cardA').boundingBox();await page.touchscreen.tap(card.x+card.width/2,card.y+card.height/2);await expect(page.locator('.merd-ui-studio-selected')).toHaveCount(1);await expect(item(page,'Unselect')).toBeVisible();const origin=await page.evaluate(()=>{const h=getComputedStyle(document.querySelector('.merd-ui-hub')),m=getComputedStyle(document.querySelector('.merd-ui-menu'));return {hl:h.left,ht:h.top,ml:m.left,mt:m.top}});expect(origin).toEqual({hl:'0px',ht:'0px',ml:'0px',mt:'0px'});await context.close();
});

test('touch drag preserves finger-to-hub offset',async({browser})=>{
  const context=await browser.newContext({viewport:{width:394,height:512},hasTouch:true,isMobile:true});const page=await context.newPage();await mount(page,true,394);const card=await page.locator('#cardA').boundingBox();await page.touchscreen.tap(card.x+card.width/2,card.y+card.height/2);const before=await hub(page).boundingBox(),cx=before.x+before.width/2,cy=before.y+before.height/2,startX=cx+18,startY=cy+9,dx=-34,dy=-27;const cdp=await context.newCDPSession(page);await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:startX,y:startY,radiusX:4,radiusY:4}]});await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:startX+dx,y:startY+dy,radiusX:4,radiusY:4}]});await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});await page.waitForTimeout(60);const after=await hub(page).boundingBox(),acx=after.x+after.width/2,acy=after.y+after.height/2;expect(Math.abs((acx-cx)-dx)).toBeLessThan(2);expect(Math.abs((acy-cy)-dy)).toBeLessThan(2);await context.close();
});
