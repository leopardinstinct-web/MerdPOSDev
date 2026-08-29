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
test('UI Studio is unavailable without actual DEV identity',async({page})=>{await mount(page,false);await expect(page.locator('.merd-ui-hub')).toHaveCount(0);expect(await page.evaluate(()=>typeof window.MERDPOS_UI_STUDIO)).toBe('undefined')});

test('single-ring drill-down replaces parent items and hub navigates back',async({page})=>{
  await mount(page,true);await page.locator('#openUiStudioBtn').click();await openRoot(page);expect(await page.getByRole('menuitem').count()).toBe(5);await expect(page.locator('.merd-ui-sector-ring')).toHaveCount(1);
  await choose(page,'Edit');await expect(item(page,'Select')).toHaveCount(0);await expect(item(page,'Color')).toBeVisible();await expect(page.getByRole('menuitem')).toHaveCount(9);await expect(page.locator('.merd-ui-sector-ring')).toHaveCount(1);await expect(hub(page).locator('strong')).toHaveText('EDIT');await expect(hub(page).locator('small')).toHaveText('BACK');
  await choose(page,'Color');await expect(item(page,'Color')).toHaveCount(0);await expect(item(page,'White')).toBeVisible();await expect(item(page,'Background')).toBeVisible();await expect(item(page,'Text')).toBeVisible();await expect(item(page,'More')).toBeVisible();await expect(page.locator('.merd-ui-sector-ring')).toHaveCount(1);await expect(hub(page).locator('strong')).toHaveText('COLOR');
  await hub(page).click();await expect(item(page,'Color')).toBeVisible();await expect(item(page,'White')).toHaveCount(0);await hub(page).click();await expect(item(page,'Select')).toBeVisible();await expect(page.getByRole('menuitem')).toHaveCount(5);
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
test('Color exposes MERDPOS palette first, toggles target, and More colors scrolls in one ring',async({page})=>{
  await mount(page,true);await page.locator('#openUiStudioBtn').click();await selectTarget(page,'#cardA');await openEdit(page);await choose(page,'Scope');await choose(page,'Matching');await hub(page).click();
  await expect(item(page,'Color')).toBeVisible();await choose(page,'Color');await expect(item(page,'Navy')).toBeVisible();await expect(item(page,'Cyan')).toBeVisible();await expect(item(page,'Violet')).toBeVisible();await expect(item(page,'Canvas')).toBeVisible();await expect(item(page,'White')).toBeVisible();await choose(page,'Text');await choose(page,'Background');
  await choose(page,'More');await expect(item(page,'Navy')).toHaveCount(0);const before=await page.getByRole('menuitem').evaluateAll(nodes=>nodes.map(n=>n.getAttribute('aria-label'))),hit=await page.locator('.merd-ui-sector-ring.ring-1 [role="menuitem"]').first().locator('.merd-ui-sector').boundingBox();await page.mouse.move(hit.x+hit.width/2,hit.y+hit.height/2);await page.mouse.wheel(0,100);const after=await page.getByRole('menuitem').evaluateAll(nodes=>nodes.map(n=>n.getAttribute('aria-label')));expect(after).not.toEqual(before);const swatches=page.locator('.merd-ui-sector-ring.ring-1 [role="menuitem"]');await swatches.first().click();const payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.patches).toEqual(expect.arrayContaining([expect.objectContaining({kind:'style',scope:'matching',property:'background-color'})]));
});

test('Galaxy-sized single ring keeps prototype geometry with larger icon-only sectors',async({page})=>{
  await mount(page,true,360);await page.locator('#openUiStudioBtn').click();await openRoot(page);
  const g=await page.evaluate(()=>{const h=document.querySelector('.merd-ui-hub').getBoundingClientRect(),m=document.querySelector('.merd-ui-menu').getBoundingClientRect(),svg=document.querySelector('.merd-ui-menu svg'),ring=document.querySelector('.merd-ui-sector-ring'),e=document.querySelector('[aria-label="Select"]'),p=e.querySelector('.merd-ui-sector'),i=e.querySelector('.merd-ui-sector-icon'),ib=i.getBoundingClientRect();return {dx:Math.abs(h.left+h.width/2-(m.left+m.width/2)),dy:Math.abs(h.top+h.height/2-(m.top+m.height/2)),menuW:m.width,hubW:h.width,viewBox:svg.getAttribute('viewBox'),inner:ring.dataset.innerRadius,outer:ring.dataset.outerRadius,gap:ring.dataset.sliceGap,fill:p.getAttribute('fill'),accent:e.dataset.accent,iconAttr:Number(i.getAttribute('width')),iconW:ib.width,iconHref:i.getAttribute('href'),labels:document.querySelectorAll('.merd-ui-sector-label').length,backplates:document.querySelectorAll('.merd-ui-icon-accent').length,hubBg:getComputedStyle(document.querySelector('.merd-ui-hub')).backgroundImage}});
  expect(g.dx).toBeLessThan(1);expect(g.dy).toBeLessThan(1);expect(g.viewBox).toBe('0 0 760 760');expect(g.inner).toBe('84');expect(g.outer).toBe('220');expect(g.gap).toBe('0');expect(g.fill).toBe('#25253D');expect(g.accent.toLowerCase()).toBe('#22d3ee');expect(g.iconAttr).toBe(48);expect(g.iconHref).toContain('gesture_select_48px.svg');expect(g.labels).toBe(0);expect(g.backplates).toBe(0);expect(g.hubBg).toContain('rgb(245, 158, 11)');expect(Math.abs(g.hubW/g.menuW-(144/760))).toBeLessThan(.01);expect(Math.abs(g.iconW-g.menuW*(48/760))).toBeLessThan(1);
  await item(page,'Layout').count().catch(()=>0);await choose(page,'Edit');await expect(item(page,'Layout').locator('.merd-ui-sector-icon')).toHaveAttribute('href',/dashboard_48px\.svg/);
  await page.setViewportSize({width:681,height:598});await page.waitForTimeout(80);const resized=await page.evaluate(()=>{const h=document.querySelector('.merd-ui-hub').getBoundingClientRect(),m=document.querySelector('.merd-ui-menu').getBoundingClientRect();return {dx:Math.abs(h.left+h.width/2-(m.left+m.width/2)),dy:Math.abs(h.top+h.height/2-(m.top+m.height/2)),menuW:m.width,hubW:h.width}});expect(resized.dx).toBeLessThan(1);expect(resized.dy).toBeLessThan(1);expect(resized.menuW).toBeGreaterThan(500);expect(Math.abs(resized.hubW/resized.menuW-(144/760))).toBeLessThan(.01);
});

test('numeric layout values use prototype arrow slices and show value in center hub',async({page})=>{
  await mount(page,true);await page.locator('#openUiStudioBtn').click();await selectTarget(page,'#cardA');await openEdit(page);await choose(page,'Layout');await choose(page,'Padding');await expect(page.getByRole('menuitem')).toHaveCount(3);await expect(item(page,'Increase')).toBeVisible();await expect(item(page,'Decrease')).toBeVisible();await expect(hub(page).locator('strong')).toHaveText('PADDING');const before=await hub(page).locator('small').textContent();await expect(item(page,'Increase').locator('.merd-ui-sector-icon')).toHaveAttribute('href',/arrow_upward_48px\.svg/);await choose(page,'Increase');const after=await hub(page).locator('small').textContent();expect(after).not.toBe(before);expect(after).toContain('BACK');const payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.patches).toEqual(expect.arrayContaining([expect.objectContaining({kind:'style',property:'padding'})]));
});

test('real touch taps activate sectors at 394x512 and share an explicit origin',async({browser})=>{
  const context=await browser.newContext({viewport:{width:394,height:512},hasTouch:true,isMobile:true});const page=await context.newPage();await mount(page,true,394);await page.setViewportSize({width:394,height:512});await page.locator('#openUiStudioBtn').click();
  const h=await hub(page).boundingBox();await page.touchscreen.tap(h.x+h.width/2,h.y+h.height/2);await expect(item(page,'Select')).toBeVisible();
  const origin=await page.evaluate(()=>{const h=getComputedStyle(document.querySelector('.merd-ui-hub')),m=getComputedStyle(document.querySelector('.merd-ui-menu'));return {hl:h.left,ht:h.top,ml:m.left,mt:m.top}});expect(origin).toEqual({hl:'0px',ht:'0px',ml:'0px',mt:'0px'});
  const editBox=await item(page,'Edit').locator('.merd-ui-sector-icon').boundingBox();await page.touchscreen.tap(editBox.x+editBox.width/2,editBox.y+editBox.height/2);await expect(item(page,'Color')).toBeVisible();await context.close();
});

test('touch drag preserves the finger-to-hub offset instead of snapping the hub',async({browser})=>{
  const context=await browser.newContext({viewport:{width:394,height:512},hasTouch:true,isMobile:true});const page=await context.newPage();await mount(page,true,394);await page.setViewportSize({width:394,height:512});await page.locator('#openUiStudioBtn').click();
  await hub(page).dispatchEvent('mouseenter');await expect(page.locator('.merd-ui-menu')).toBeHidden();const before=await hub(page).boundingBox(),cx=before.x+before.width/2,cy=before.y+before.height/2,startX=cx+18,startY=cy+9,dx=34,dy=27;const cdp=await context.newCDPSession(page);
  await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:startX,y:startY,radiusX:4,radiusY:4}]});await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:startX+dx,y:startY+dy,radiusX:4,radiusY:4}]});await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});await page.waitForTimeout(60);
  const after=await hub(page).boundingBox(),afterCx=after.x+after.width/2,afterCy=after.y+after.height/2;expect(Math.abs((afterCx-cx)-dx)).toBeLessThan(2);expect(Math.abs((afterCy-cy)-dy)).toBeLessThan(2);await page.waitForTimeout(30);await page.touchscreen.tap(afterCx,afterCy);await expect(item(page,'Select')).toBeVisible();await context.close();
});

test('Studio remains draggable and sector menu is viewport safe on mobile',async({page})=>{
  const errors=[];page.on('pageerror',e=>errors.push(String(e.message||e)));await mount(page,true,390);await page.locator('#openUiStudioBtn').click();const before=await hub(page).boundingBox();await page.mouse.move(before.x+before.width/2,before.y+before.height/2);await page.mouse.down();await page.mouse.move(190,220,{steps:8});await page.mouse.up();const after=await hub(page).boundingBox();expect(Math.abs(after.x-before.x)+Math.abs(after.y-before.y)).toBeGreaterThan(20);
  await openRoot(page);await choose(page,'Edit');await choose(page,'Color');const visible=await page.evaluate(()=>{const rects=[...document.querySelectorAll('.merd-ui-sector')].map(el=>el.getBoundingClientRect());return {left:Math.min(...rects.map(r=>r.left)),top:Math.min(...rects.map(r=>r.top)),right:Math.max(...rects.map(r=>r.right)),bottom:Math.max(...rects.map(r=>r.bottom)),menuPointer:getComputedStyle(document.querySelector('.merd-ui-menu')).pointerEvents,svgPointer:getComputedStyle(document.querySelector('.merd-ui-menu svg')).pointerEvents}});expect(visible.left).toBeGreaterThanOrEqual(-1.5);expect(visible.top).toBeGreaterThanOrEqual(-1.5);expect(visible.right).toBeLessThanOrEqual(391.5);expect(visible.bottom).toBeLessThanOrEqual(845-70);expect(visible.menuPointer).toBe('none');expect(visible.svgPointer).toBe('none');expect(errors).toEqual([]);
});

test('Changes exposes copy/chat handoff including comments and history',async({page})=>{
  await mount(page,true);await page.locator('#openUiStudioBtn').click();await selectTarget(page,'#cardA');await openEdit(page);page.once('dialog',d=>d.accept('Review this'));await choose(page,'Comment');await openRoot(page);await choose(page,'Changes');await choose(page,'Chat');await expect.poll(()=>page.evaluate(()=>window.__studioCopied)).toContain('Review this');await expect.poll(()=>page.evaluate(()=>window.__studioCopied)).toContain('history');
});


test('hub hover opens the ring and wheel selection executes through the center',async({page})=>{
  await mount(page,true,1280);await page.locator('#openUiStudioBtn').click();const box=await hub(page).boundingBox();await page.mouse.move(box.x+box.width/2,box.y+box.height/2);await expect(item(page,'Select')).toBeVisible();await page.mouse.wheel(0,120);await expect(hub(page).locator('strong')).toHaveText('Select');await expect(hub(page).locator('small')).toHaveText('SELECT');await expect(item(page,'Select')).toHaveClass(/is-hub-candidate/);await hub(page).click();await expect(page.locator('body')).toHaveClass(/merd-ui-studio-selecting/);await expect(page.locator('.merd-ui-menu')).toBeHidden();
});

test('center click is Back when no wheel action is armed',async({page})=>{
  await mount(page,true,1280);await page.locator('#openUiStudioBtn').click();await openRoot(page);await choose(page,'Edit');await expect(item(page,'Color')).toBeVisible();const box=await hub(page).boundingBox();await page.mouse.move(box.x+box.width/2,box.y+box.height/2);await hub(page).click();await expect(item(page,'Select')).toBeVisible();await expect(hub(page).locator('strong')).toHaveText('UI / DEV');
});

test('Undo is available on every Studio drill-down level',async({page})=>{
  await mount(page,true);await page.locator('#openUiStudioBtn').click();await openRoot(page);await expect(item(page,'Undo')).toBeVisible();await choose(page,'Edit');await expect(item(page,'Undo')).toBeVisible();await choose(page,'Layout');await expect(item(page,'Undo')).toBeVisible();await choose(page,'Padding');await expect(item(page,'Undo')).toBeVisible();
});


test('Move reorders the selected element and records a move patch',async({page})=>{
  await mount(page,true);await page.locator('#openUiStudioBtn').click();await selectTarget(page,'#cardA');await openEdit(page);await choose(page,'Move');await choose(page,'After');await page.locator('#cardB').click();const order=await page.locator('#demoPanel > article').evaluateAll(nodes=>nodes.map(n=>n.id));expect(order.slice(0,2)).toEqual(['cardB','cardA']);const payload=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getChangeSet());expect(payload.patches).toEqual(expect.arrayContaining([expect.objectContaining({kind:'move',position:'after'})]));
});

test('Hide becomes Show and History can delete one step',async({page})=>{
  await mount(page,true);await page.locator('#openUiStudioBtn').click();await selectTarget(page,'#cardA');await openEdit(page);await choose(page,'Hide');await expect(item(page,'Show')).toBeVisible();await expect(page.locator('#cardA')).toBeHidden();await choose(page,'Show');await expect(page.locator('#cardA')).toBeVisible();await openRoot(page);await choose(page,'Changes');await choose(page,'History');const before=await page.evaluate(()=>window.MERDPOS_UI_STUDIO.getHistory().length);expect(before).toBeGreaterThan(0);await expect(page.locator('.merd-ui-history-delete').first()).toBeVisible();await page.locator('.merd-ui-history-delete').first().click();await expect.poll(()=>page.evaluate(()=>window.MERDPOS_UI_STUDIO.getHistory().length)).toBe(before-1);
});
