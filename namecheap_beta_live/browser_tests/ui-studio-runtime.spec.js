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
const fixture = `<!doctype html><html><head><style>:root{--shell-mobile-nav-h:4.75rem}.app-frame{display:flex}.app-rail{width:220px}.portal-panel[hidden]{display:none}</style></head><body>
<button id="openUiStudioBtn">Open UI Studio</button>
<div class="app-frame nav-expanded"><aside class="app-rail"><section class="rail-section" data-nav-section="home"><button id="railHome" class="rail-group-btn" aria-label="Home"><span>Home</span></button><div class="sidebar-group" data-sidebar-group="home"><button class="portal-tab active" data-panel="demoPanel"><span>Dashboard</span></button><button class="portal-tab" data-panel="secondPanel"><span>Second</span></button></div></section><div class="rail-shell-utilities"><button id="utilityAction"><span>Utility action</span></button></div></aside>
<main class="merd-page-shell"><section id="demoPanel" class="portal-panel"><header class="merd-mobile-page-head"><span class="merd-mobile-context">Demo client</span></header><article id="cardA" class="controls-card"><strong>Card A</strong></article><article id="cardB" class="controls-card"><strong>Card B</strong></article></section><section id="secondPanel" class="portal-panel" hidden><article id="cardC" class="controls-card"><strong>Card C</strong></article></section></main></div>
<script>window.__railClicks=0;window.__utilityClicks=0;document.getElementById('railHome').addEventListener('click',()=>window.__railClicks++);document.getElementById('utilityAction').addEventListener('click',()=>window.__utilityClicks++);document.addEventListener('click',e=>{const tab=e.target.closest('.portal-tab');if(!tab)return;document.querySelectorAll('.portal-tab').forEach(x=>x.classList.toggle('active',x===tab));document.querySelectorAll('.portal-panel').forEach(p=>p.hidden=p.id!==tab.dataset.panel);});</script>
</body></html>`;
async function mount(page,isDev=true,width=1280){
  await page.setViewportSize({width,height:width<=820?844:900});
  await page.addInitScript(()=>{window.__studioCopied='';const clipboard={writeText:async text=>{window.__studioCopied=String(text)}};try{Object.defineProperty(navigator,'clipboard',{configurable:true,value:clipboard})}catch(_){}});
  await page.route('https://merdpos-smoke.invalid/ui-studio',r=>r.fulfill({status:200,contentType:'text/html',body:fixture}));
  await page.route('https://merdpos-smoke.invalid/assets/vendor/google-material-symbols/*',r=>{const name=new URL(r.request().url()).pathname.split('/').pop();r.fulfill({status:200,contentType:'image/svg+xml',body:fs.readFileSync(path.join(iconRoot,name))})});
  await page.goto('https://merdpos-smoke.invalid/ui-studio');await page.addStyleTag({path:tokensPath});await page.addStyleTag({path:studioCssPath});await page.addScriptTag({content:`window.MERDPOS_AUTH={is_dev:${isDev?'true':'false'},role_key:${isDev?'"DEV"':'"ADMIN"'}};`});await page.addScriptTag({path:studioJsPath});
}
async function reloadRuntime(page){await page.reload();await page.addStyleTag({path:tokensPath});await page.addStyleTag({path:studioCssPath});await page.addScriptTag({content:'window.MERDPOS_AUTH={is_dev:true,role_key:"DEV"};'});await page.addScriptTag({path:studioJsPath});}
const hub=page=>page.locator('.merd-ui-hub');
const item=(page,name)=>page.getByRole('menuitem',{name,exact:true});
async function choose(page,name){await item(page,name).evaluate(el=>el.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true})))}
async function openRoot(page){await hub(page).click();await expect(item(page,'Select')).toBeVisible()}
async function selectTarget(page,selector){await openRoot(page);await choose(page,'Select');await page.locator(selector).click()}
async function openEdit(page){await openRoot(page);await choose(page,'Edit');await expect(item(page,'Color')).toBeVisible()}
test('UI Studio is unavailable without actual DEV identity',async({page})=>{await mount(page,false);await expect(page.locator('.merd-ui-hub')).toHaveCount(0);expect(await page.evaluate(()=>typeof window.MERDPOS_UI_STUDIO)).toBe('undefined')});

test('sector menu keeps parent layers visible and hub navigates back',async({page})=>{
  await mount(page,true);await page.locator('#openUiStudioBtn').click();await openRoot(page);expect(await page.getByRole('menuitem').count()).toBe(4);
  await choose(page,'Edit');await expect(item(page,'Select')).toBeVisible();await expect(item(page,'Color')).toBeVisible();await expect(page.locator('.merd-ui-sector-ring')).toHaveCount(2);await expect(page.locator('.ring-1')).toHaveClass(/is-compact-parent/);await expect(page.locator('.ring-1 .merd-ui-sector-label')).toHaveCount(0);await expect(page.locator('.ring-1 .merd-ui-sector-icon')).toHaveCount(4);
  await choose(page,'Color');await expect(item(page,'White')).toBeVisible();await expect(page.locator('.merd-ui-sector-ring')).toHaveCount(3);await expect(page.locator('.ring-2')).toHaveClass(/is-compact-parent/);await expect(page.locator('.ring-2 .merd-ui-sector-label')).toHaveCount(0);await expect(hub(page).locator('strong')).toHaveText('←');
  await hub(page).click();await expect(item(page,'Color')).toBeVisible();await expect(page.locator('.merd-ui-sector-ring')).toHaveCount(2);await hub(page).click();await expect(item(page,'Select')).toBeVisible();await expect(page.locator('.merd-ui-sector-ring')).toHaveCount(1);
});

test('select intercepts sidebar and transient menu controls without activating them',async({page})=>{
  await mount(page,true);await page.locator('#openUiStudioBtn').click();await selectTarget(page,'#railHome span');expect(await page.evaluate(()=>window.__railClicks)).toBe(0);await expect(page.locator('#railHome')).toHaveClass(/merd-ui-studio-selected/);
  await openRoot(page);await choose(page,'Select');await page.locator('#utilityAction span').click();expect(await page.evaluate(()=>window.__utilityClicks)).toBe(0);await expect(page.locator('#utilityAction')).toHaveClass(/merd-ui-studio-selected/);
});

test('comments and added preview elements persist and appear in history',async({page})=>{
  await mount(page,true);await page.locator('#openUiStudioBtn').click();await selectTarget(page,'#cardA');
  await openEdit(page);page.once('dialog',d=>d.accept('Increase spacing near this card'));await choose(page,'Comment');
  let payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.patches).toEqual(expect.arrayContaining([expect.objectContaining({kind:'comment',comment:'Increase spacing near this card'})]));
  await openEdit(page);await choose(page,'Add');page.once('dialog',d=>d.accept('Prototype action'));await choose(page,'Button');await expect(page.locator('[data-ui-studio-added-key]')).toContainText('Prototype action');
  payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.patches).toEqual(expect.arrayContaining([expect.objectContaining({kind:'add',elementType:'button'})]));expect(payload.history.map(x=>x.action)).toEqual(expect.arrayContaining(['comment','add']));
  await reloadRuntime(page);await expect(page.locator('[data-ui-studio-added-key]')).toContainText('Prototype action');
});

test('history returns to recorded panel and reselects the changed element',async({page})=>{
  await mount(page,true);await page.locator('#openUiStudioBtn').click();await page.locator('.portal-tab[data-panel="secondPanel"]').click();await selectTarget(page,'#cardC');await openEdit(page);await choose(page,'Hide');
  await page.locator('.portal-tab[data-panel="demoPanel"]').click();await openRoot(page);await choose(page,'Changes');await choose(page,'History');await expect(page.locator('.merd-ui-history')).toBeVisible();
  await page.locator('.merd-ui-history-row').first().click();await expect(page.locator('#secondPanel')).not.toHaveAttribute('hidden','');await expect(page.locator('#cardC strong')).toHaveClass(/merd-ui-studio-selected/);
});
test('color ring scrolls and applies preview color while matching scope crosses panels',async({page})=>{
  await mount(page,true);await page.locator('#openUiStudioBtn').click();await selectTarget(page,'#cardA');await openEdit(page);await choose(page,'Scope');await choose(page,'Matching');await hub(page).click();
  await expect(item(page,'Color')).toBeVisible();await choose(page,'Color');const before=await item(page,'White').count();const menu=page.locator('.merd-ui-menu');const box=await menu.boundingBox();await page.mouse.move(box.x+box.width-10,box.y+box.height/2);await page.mouse.wheel(0,100);expect(before).toBe(1);
  const swatches=page.locator('.merd-ui-sector-ring.ring-3 [role="menuitem"]');await expect(swatches.first()).toBeVisible();await swatches.first().click();const payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.patches).toEqual(expect.arrayContaining([expect.objectContaining({kind:'style',scope:'matching',property:'background-color'})]));
});

test('Galaxy-sized touch layout keeps hub concentric and sector content readable',async({page})=>{
  await mount(page,true,360);await page.locator('#openUiStudioBtn').click();await openRoot(page);
  const geometry=await page.evaluate(()=>{const h=document.querySelector('.merd-ui-hub').getBoundingClientRect(),m=document.querySelector('.merd-ui-menu').getBoundingClientRect(),label=document.querySelector('[aria-label=\"Select\"] .merd-ui-sector-label')?.getBoundingClientRect(),icon=document.querySelector('[aria-label=\"Select\"] .merd-ui-sector-icon')?.getBoundingClientRect();return {hub:{x:h.left+h.width/2,y:h.top+h.height/2},menu:{x:m.left+m.width/2,y:m.top+m.height/2},label:{w:label?.width||0,h:label?.height||0},icon:{w:icon?.width||0,h:icon?.height||0}}});
  expect(Math.abs(geometry.hub.x-geometry.menu.x)).toBeLessThan(1);expect(Math.abs(geometry.hub.y-geometry.menu.y)).toBeLessThan(1);expect(geometry.label.h).toBeGreaterThanOrEqual(16);expect(geometry.icon.w).toBeGreaterThanOrEqual(26);
  const labelBox=await item(page,'Edit').locator('.merd-ui-sector-label').boundingBox();await page.mouse.click(labelBox.x+labelBox.width/2,labelBox.y+labelBox.height/2);await expect(item(page,'Color')).toBeVisible();const fit=await page.evaluate(()=>{const e=document.querySelector('[aria-label="Hide"]'),p=e?.querySelector('.merd-ui-sector'),l=e?.querySelector('.merd-ui-sector-label'),i=e?.querySelector('.merd-ui-sector-icon');if(!p||!l||!i)return null;const pb=p.getBoundingClientRect(),lb=l.getBoundingClientRect(),ib=i.getBoundingClientRect();return {labelInside:lb.left>=pb.left-1&&lb.right<=pb.right+1&&lb.top>=pb.top-1&&lb.bottom<=pb.bottom+1,labelH:lb.height,iconW:ib.width,parentLabels:document.querySelectorAll('.ring-1 .merd-ui-sector-label').length}});expect(fit).not.toBeNull();expect(fit.labelInside).toBe(true);expect(fit.labelH).toBeGreaterThanOrEqual(17);expect(fit.iconW).toBeGreaterThanOrEqual(28);expect(fit.parentLabels).toBe(0);
  await page.setViewportSize({width:681,height:598});await page.waitForTimeout(80);const resized=await page.evaluate(()=>{const h=document.querySelector('.merd-ui-hub').getBoundingClientRect(),m=document.querySelector('.merd-ui-menu').getBoundingClientRect(),label=document.querySelector('[aria-label=\"Color\"] .merd-ui-sector-label')?.getBoundingClientRect();return {dx:Math.abs((h.left+h.width/2)-(m.left+m.width/2)),dy:Math.abs((h.top+h.height/2)-(m.top+m.height/2)),menu:m.width,labelH:label?.height||0}});expect(resized.dx).toBeLessThan(1);expect(resized.dy).toBeLessThan(1);expect(resized.menu).toBeGreaterThan(360);expect(resized.labelH).toBeGreaterThanOrEqual(14);
});

test('real touch taps activate sectors at 394x512 and share an explicit origin',async({browser})=>{
  const context=await browser.newContext({viewport:{width:394,height:512},hasTouch:true,isMobile:true});const page=await context.newPage();await mount(page,true,394);await page.setViewportSize({width:394,height:512});await page.locator('#openUiStudioBtn').click();
  const h=await hub(page).boundingBox();await page.touchscreen.tap(h.x+h.width/2,h.y+h.height/2);await expect(item(page,'Select')).toBeVisible();
  const origin=await page.evaluate(()=>{const h=getComputedStyle(document.querySelector('.merd-ui-hub')),m=getComputedStyle(document.querySelector('.merd-ui-menu'));return {hl:h.left,ht:h.top,ml:m.left,mt:m.top}});expect(origin).toEqual({hl:'0px',ht:'0px',ml:'0px',mt:'0px'});
  const editBox=await item(page,'Edit').locator('.merd-ui-sector-label').boundingBox();await page.touchscreen.tap(editBox.x+editBox.width/2,editBox.y+editBox.height/2);await expect(item(page,'Color')).toBeVisible();await context.close();
});

test('touch drag preserves the finger-to-hub offset instead of snapping the hub',async({browser})=>{
  const context=await browser.newContext({viewport:{width:394,height:512},hasTouch:true,isMobile:true});const page=await context.newPage();await mount(page,true,394);await page.setViewportSize({width:394,height:512});await page.locator('#openUiStudioBtn').click();
  const before=await hub(page).boundingBox(),cx=before.x+before.width/2,cy=before.y+before.height/2,startX=cx+18,startY=cy+9,dx=34,dy=27;const cdp=await context.newCDPSession(page);
  await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:startX,y:startY,radiusX:4,radiusY:4}]});await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:startX+dx,y:startY+dy,radiusX:4,radiusY:4}]});await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});await page.waitForTimeout(60);
  const after=await hub(page).boundingBox(),afterCx=after.x+after.width/2,afterCy=after.y+after.height/2;expect(Math.abs((afterCx-cx)-dx)).toBeLessThan(2);expect(Math.abs((afterCy-cy)-dy)).toBeLessThan(2);await page.waitForTimeout(30);await page.touchscreen.tap(afterCx,afterCy);await expect(item(page,'Select')).toBeVisible();await context.close();
});

test('Studio remains draggable and sector menu is viewport safe on mobile',async({page})=>{
  const errors=[];page.on('pageerror',e=>errors.push(String(e.message||e)));await mount(page,true,390);await page.locator('#openUiStudioBtn').click();const before=await hub(page).boundingBox();await page.mouse.move(before.x+before.width/2,before.y+before.height/2);await page.mouse.down();await page.mouse.move(190,220,{steps:8});await page.mouse.up();const after=await hub(page).boundingBox();expect(Math.abs(after.x-before.x)+Math.abs(after.y-before.y)).toBeGreaterThan(20);
  await openRoot(page);await choose(page,'Edit');await choose(page,'Color');const m=await page.locator('.merd-ui-menu').boundingBox();expect(m.x).toBeGreaterThanOrEqual(-1);expect(m.y).toBeGreaterThanOrEqual(-1);expect(m.x+m.width).toBeLessThanOrEqual(391);expect(m.y+m.height).toBeLessThanOrEqual(845-70);expect(errors).toEqual([]);
});

test('Changes exposes copy/chat handoff including comments and history',async({page})=>{
  await mount(page,true);await page.locator('#openUiStudioBtn').click();await selectTarget(page,'#cardA');await openEdit(page);page.once('dialog',d=>d.accept('Review this'));await choose(page,'Comment');await openRoot(page);await choose(page,'Changes');await choose(page,'Chat');await expect.poll(()=>page.evaluate(()=>window.__studioCopied)).toContain('Review this');await expect.poll(()=>page.evaluate(()=>window.__studioCopied)).toContain('history');
});
