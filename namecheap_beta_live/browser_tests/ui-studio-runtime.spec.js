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
  await choose(page,'Edit');await expect(item(page,'Select')).toBeVisible();await expect(item(page,'Color')).toBeVisible();await expect(page.locator('.merd-ui-sector-ring')).toHaveCount(2);
  await choose(page,'Color');await expect(item(page,'White')).toBeVisible();await expect(page.locator('.merd-ui-sector-ring')).toHaveCount(3);await expect(hub(page).locator('strong')).toHaveText('←');
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

test('Studio remains draggable and sector menu is viewport safe on mobile',async({page})=>{
  const errors=[];page.on('pageerror',e=>errors.push(String(e.message||e)));await mount(page,true,390);await page.locator('#openUiStudioBtn').click();const before=await hub(page).boundingBox();await page.mouse.move(before.x+before.width/2,before.y+before.height/2);await page.mouse.down();await page.mouse.move(190,220,{steps:8});await page.mouse.up();const after=await hub(page).boundingBox();expect(Math.abs(after.x-before.x)+Math.abs(after.y-before.y)).toBeGreaterThan(20);
  await openRoot(page);await choose(page,'Edit');await choose(page,'Color');const m=await page.locator('.merd-ui-menu').boundingBox();expect(m.x).toBeGreaterThanOrEqual(-1);expect(m.y).toBeGreaterThanOrEqual(-1);expect(m.x+m.width).toBeLessThanOrEqual(391);expect(m.y+m.height).toBeLessThanOrEqual(845-70);expect(errors).toEqual([]);
});

test('Changes exposes copy/chat handoff including comments and history',async({page})=>{
  await mount(page,true);await page.locator('#openUiStudioBtn').click();await selectTarget(page,'#cardA');await openEdit(page);page.once('dialog',d=>d.accept('Review this'));await choose(page,'Comment');await openRoot(page);await choose(page,'Changes');await choose(page,'Chat');await expect.poll(()=>page.evaluate(()=>window.__studioCopied)).toContain('Review this');await expect.poll(()=>page.evaluate(()=>window.__studioCopied)).toContain('history');
});
