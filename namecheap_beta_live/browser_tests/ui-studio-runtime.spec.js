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
async function openRoot(page){for(let i=0;i<6;i++){if(await item(page,'Select').isVisible().catch(()=>false))return;await hub(page).click();await page.waitForTimeout(20)}await expect(item(page,'Select')).toBeVisible()}
async function selectTarget(page,selector){await openRoot(page);await choose(page,'Select');await page.locator(selector).click()}
async function openEdit(page){await openRoot(page);await choose(page,'Edit');await expect(item(page,'Color')).toBeVisible()}
test('UI Studio is unavailable without actual DEV identity',async({page})=>{
  await mount(page,false);await expect(page.locator('.merd-ui-hub')).toHaveCount(0);expect(await page.evaluate(()=>typeof window.MERDPOS_UI_STUDIO)).toBe('undefined');
});

test('DEV hub is visible by default and root has Select without Exit',async({page})=>{
  await mount(page,true);await expect(hub(page)).toBeVisible();await openRoot(page);await expect(item(page,'Select')).toBeVisible();await expect(item(page,'Exit')).toHaveCount(0);await expect(item(page,'Undo')).toBeVisible();
});

test('one selection can be replaced by another and selected root exposes the requested actions',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await expect(page.locator('#cardA .merd-ui-studio-selected, #cardA.merd-ui-studio-selected')).toHaveCount(1);await expect(item(page,'Add')).toBeVisible();await expect(item(page,'Edit')).toBeVisible();await expect(item(page,'Move')).toBeVisible();await expect(item(page,'Comment')).toBeVisible();await expect(item(page,'Hide')).toBeVisible();
  await choose(page,'Select');await page.locator('#cardB').click();await expect(page.locator('#cardA .merd-ui-studio-selected, #cardA.merd-ui-studio-selected')).toHaveCount(0);await expect(page.locator('#cardB .merd-ui-studio-selected, #cardB.merd-ui-studio-selected')).toHaveCount(1);expect(await page.locator('.merd-ui-studio-selected').count()).toBe(1);
});

test('Edit drills into Color Palette and Layout only',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Edit');await expect(item(page,'Color')).toBeVisible();await expect(item(page,'Layout')).toBeVisible();await expect(item(page,'Comment')).toHaveCount(0);await choose(page,'Color');await expect(item(page,'Palette')).toBeVisible();await choose(page,'Palette');await expect(item(page,'Navy')).toBeVisible();await expect(item(page,'Background')).toBeVisible();await expect(item(page,'Text')).toBeVisible();await hub(page).click();await expect(item(page,'Palette')).toBeVisible();await hub(page).click();await expect(item(page,'Color')).toBeVisible();
});
test('Add chooses an element type then Above Below Left or Right placement',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Add');await expect(item(page,'Text')).toBeVisible();await expect(item(page,'Button')).toBeVisible();await choose(page,'Button');await expect(item(page,'Above')).toBeVisible();await expect(item(page,'Below')).toBeVisible();await expect(item(page,'Left')).toBeVisible();await expect(item(page,'Right')).toBeVisible();page.once('dialog',d=>d.accept('Prototype action'));await choose(page,'Right');await expect(page.locator('[data-ui-studio-added-key]')).toContainText('Prototype action');const payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.patches).toEqual(expect.arrayContaining([expect.objectContaining({kind:'add',elementType:'button',position:'right'})]));
});

test('Move selects a destination first then Top Bottom Left or Right',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Move');await expect(item(page,'Select Destination')).toBeVisible();await choose(page,'Select Destination');await page.locator('#cardB').click();await expect(item(page,'Top')).toBeVisible();await expect(item(page,'Bottom')).toBeVisible();await expect(item(page,'Left')).toBeVisible();await expect(item(page,'Right')).toBeVisible();await choose(page,'Bottom');const order=await page.locator('#demoPanel > article').evaluateAll(nodes=>nodes.map(n=>n.id));expect(order.slice(0,2)).toEqual(['cardB','cardA']);const payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.patches).toEqual(expect.arrayContaining([expect.objectContaining({kind:'move',position:'bottom'})]));
});

test('Comment and Hide Show are direct selected-item actions',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');page.once('dialog',d=>d.accept('Review spacing'));await choose(page,'Comment');let payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.patches).toEqual(expect.arrayContaining([expect.objectContaining({kind:'comment',comment:'Review spacing'})]));await openRoot(page);await choose(page,'Hide');await expect(page.locator('#cardA')).toBeHidden();await expect(item(page,'Show')).toBeVisible();await choose(page,'Show');await expect(page.locator('#cardA')).toBeVisible();
});

test('change-count badge opens History and a history step can be deleted',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Hide');await expect(page.locator('.merd-ui-hub-count')).toBeVisible();const before=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getHistory().length);await page.locator('.merd-ui-hub-count').click();await expect(page.locator('.merd-ui-history')).toBeVisible();await expect(page.locator('.merd-ui-history-delete').first()).toBeVisible();await page.locator('.merd-ui-history-delete').first().click();await expect.poll(()=>page.evaluate(()=>window.MERDPOS_UI_STUDIO.getHistory().length)).toBe(before-1);
});
test('icon-only prototype geometry remains concentric with 48-unit icons',async({page})=>{
  await mount(page,true,360);await openRoot(page);const g=await page.evaluate(()=>{const h=document.querySelector('.merd-ui-hub').getBoundingClientRect(),m=document.querySelector('.merd-ui-menu').getBoundingClientRect(),svg=document.querySelector('.merd-ui-menu svg'),ring=document.querySelector('.merd-ui-sector-ring'),e=document.querySelector('[aria-label="Select"]'),i=e.querySelector('.merd-ui-sector-icon');return {dx:Math.abs(h.left+h.width/2-(m.left+m.width/2)),dy:Math.abs(h.top+h.height/2-(m.top+m.height/2)),viewBox:svg.getAttribute('viewBox'),inner:ring.dataset.innerRadius,outer:ring.dataset.outerRadius,gap:ring.dataset.sliceGap,icon:Number(i.getAttribute('width')),labels:document.querySelectorAll('.merd-ui-sector-label').length,bg:getComputedStyle(document.querySelector('.merd-ui-hub')).backgroundImage}});expect(g.dx).toBeLessThan(1);expect(g.dy).toBeLessThan(1);expect(g.viewBox).toBe('0 0 760 760');expect(g.inner).toBe('84');expect(g.outer).toBe('220');expect(g.gap).toBe('0');expect(g.icon).toBe(48);expect(g.labels).toBe(0);expect(g.bg).toContain('rgb(245, 158, 11)');
});

test('numeric Layout values keep arrow-stepper behavior',async({page})=>{
  await mount(page,true);await selectTarget(page,'#cardA');await choose(page,'Edit');await choose(page,'Layout');await choose(page,'Padding');await expect(item(page,'Increase')).toBeVisible();await expect(item(page,'Decrease')).toBeVisible();const before=await hub(page).locator('small').textContent();await choose(page,'Increase');const after=await hub(page).locator('small').textContent();expect(after).not.toBe(before);const payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.patches).toEqual(expect.arrayContaining([expect.objectContaining({kind:'style',property:'padding'})]));
});

test('hub hover opens, wheel arms an action, center click selects it on fine pointers',async({page})=>{
  await mount(page,true,1280);const box=await hub(page).boundingBox();await page.mouse.move(box.x+box.width/2,box.y+box.height/2);await expect(item(page,'Select')).toBeVisible();await page.mouse.wheel(0,120);await expect(hub(page).locator('strong')).toHaveText('Select');await hub(page).click();await expect(page.locator('body')).toHaveClass(/merd-ui-studio-selecting/);
});

test('Undo remains available on every visible drill-down level',async({page})=>{
  await mount(page,true);await openRoot(page);await expect(item(page,'Undo')).toBeVisible();await selectTarget(page,'#cardA');await expect(item(page,'Undo')).toBeVisible();await choose(page,'Edit');await expect(item(page,'Undo')).toBeVisible();await choose(page,'Layout');await expect(item(page,'Undo')).toBeVisible();await choose(page,'Padding');await expect(item(page,'Undo')).toBeVisible();
});
test('Select intercepts existing controls without activating them',async({page})=>{
  await mount(page,true);await selectTarget(page,'#railHome span');expect(await page.evaluate(()=>window.__railClicks)).toBe(0);await expect(page.locator('#railHome')).toHaveClass(/merd-ui-studio-selected/);await choose(page,'Select');await page.locator('#utilityAction span').click();expect(await page.evaluate(()=>window.__utilityClicks)).toBe(0);await expect(page.locator('#utilityAction')).toHaveClass(/merd-ui-studio-selected/);
});

test('touch hub stays concentric and synthetic hover does not open it',async({browser})=>{
  const context=await browser.newContext({viewport:{width:394,height:512},hasTouch:true,isMobile:true});const page=await context.newPage();await mount(page,true,394);await hub(page).dispatchEvent('mouseenter');await expect(page.locator('.merd-ui-menu')).toBeHidden();const h=await hub(page).boundingBox();await page.touchscreen.tap(h.x+h.width/2,h.y+h.height/2);await expect(item(page,'Select')).toBeVisible();const origin=await page.evaluate(()=>{const h=getComputedStyle(document.querySelector('.merd-ui-hub')),m=getComputedStyle(document.querySelector('.merd-ui-menu'));return {hl:h.left,ht:h.top,ml:m.left,mt:m.top}});expect(origin).toEqual({hl:'0px',ht:'0px',ml:'0px',mt:'0px'});await context.close();
});

test('touch drag preserves finger-to-hub offset',async({browser})=>{
  const context=await browser.newContext({viewport:{width:394,height:512},hasTouch:true,isMobile:true});const page=await context.newPage();await mount(page,true,394);const before=await hub(page).boundingBox(),cx=before.x+before.width/2,cy=before.y+before.height/2,startX=cx+18,startY=cy+9,dx=-34,dy=-27;const cdp=await context.newCDPSession(page);await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:startX,y:startY,radiusX:4,radiusY:4}]});await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:startX+dx,y:startY+dy,radiusX:4,radiusY:4}]});await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});await page.waitForTimeout(60);const after=await hub(page).boundingBox(),acx=after.x+after.width/2,acy=after.y+after.height/2;expect(Math.abs((acx-cx)-dx)).toBeLessThan(2);expect(Math.abs((acy-cy)-dy)).toBeLessThan(2);await context.close();
});
