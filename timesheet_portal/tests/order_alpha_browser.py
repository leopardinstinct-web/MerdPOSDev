"""Local read-only Inventory DBA browser smoke. Requires Python Playwright/Chromium."""
from pathlib import Path
import os, shutil, subprocess, tempfile, time, socket, certifi
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
def run():
    with tempfile.TemporaryDirectory(prefix='merd-alpha-browser-') as tmp:
        dst=Path(tmp)
        for directory in ('includes','api','assets'):shutil.copytree(ROOT/directory,dst/directory)
        shutil.copy(ROOT/'orders.php',dst/'orders.php')
        (dst/'includes'/'config.php').write_text("<?php define('SESSION_NAME','MERD_ALPHA_BROWSER');",encoding='ascii')
        (dst/'qa-session.php').write_text("<?php require_once __DIR__.'/includes/auth.php'; start_app_session(); $_SESSION['user']=['user_id'=>'111','name'=>'Alpha Browser QA','is_super'=>false]; echo 'READY';",encoding='ascii')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        php=os.getenv('MERD_PHP84') or shutil.which('php84') or next((str(p) for p in Path.home().glob('AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.4_*/php.exe')),None)
        cmd=[php,'-d','curl.cainfo='+certifi.where(),'-d','openssl.cafile='+certifi.where(),'-S',f'127.0.0.1:{port}','-t',str(dst)]
        env=os.environ.copy();env['MERD_ALPHA_ORDER_DATA_DIR']=str(dst/'private_order_data')
        server=subprocess.Popen(cmd,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env=env)
        try:
            with sync_playwright() as p:
                browser=p.chromium.launch(channel='chrome',headless=True)
                ctx=browser.new_context(viewport={'width':390,'height':844})
                page=ctx.new_page()
                errors=[]
                page.on('pageerror',lambda err:errors.append(str(err)))
                base=f'http://127.0.0.1:{port}/'
                page.goto(base+'qa-session.php')
                page.goto(base+'orders.php')
                page.get_by_role('button',name='MX Marrickville Xpress Select store').click()
                page.locator('#countWorkspace').wait_for(state='visible',timeout=30000)
                assert page.locator('#orderTotal').inner_text().isdigit()
                assert page.locator('#manualOrderPreview .manual-order-store').inner_text()=='Marrickville Xpress'
                assert page.evaluate("""() => {const host=document.createElement('div');
                  MerdManualOrder.render(host,{store:'MX',date:'2026-10-28 23:30:00',rows:[]});
                  return host.querySelector('.manual-order-date').textContent;
                }""") == '29 October 2026'
                assert page.evaluate("""() => {const host=document.createElement('div');
                  MerdManualOrder.render(host,{store:'MX',date:'2026-10-28T23:30:00.123',rows:[]});
                  return host.querySelector('.manual-order-date').textContent;
                }""") == '29 October 2026'
                assert page.locator('#manualOrderPreview .manual-order-table th').all_inner_texts()==['Category','Bar Code','Product Name','Order Quantity']
                assert page.locator('h2:has-text("Generate order for SUPER review")').count()==0
                page.locator('#productSearch').fill('B & H 20')
                page.locator('.order-suggestion').first.click()
                page.wait_for_function("() => document.querySelector('#countedProducts').textContent === '1'")
                assert page.locator('#countedBody').inner_text().find("B & H")>=0
                # Deliver 2 clicks in one event loop while first API request is pending.
                assert page.locator('#countTable thead').inner_text().find('Actions')<0
                page.evaluate("() => {const b=document.querySelector('#countedBody .order-stepper-btn:last-child'); b.click(); b.click();}")
                page.wait_for_function("() => document.querySelector('#countedBody .order-stepper-input').value === '3'",timeout=30000)
                page.locator('#countedBody .order-stepper-input').first.fill('2')
                page.locator('#countedBody .order-stepper-input').first.press('Enter')
                page.wait_for_function("() => document.querySelector('#countedBody .order-stepper-input').value === '2'")
                page.locator('#typeManual').click()
                page.locator('#productSearch').fill('BIC Plain Big')
                page.locator('.order-suggestion').first.click()
                page.wait_for_function("() => document.querySelector('#accessoryTotal').textContent === '1'")
                assert page.locator('#manualOrderPreview .manual-order-table tbody').inner_text().find('BIC Plain Big')>=0
                assert page.locator('#manualOrderPreview .manual-order-table tbody').inner_text().find('B & H')>=0

                # Camera cancellation during async startup must stop once start resolves.
                page.evaluate("""() => { window.__cameraStopped = false;
                  window.Html5Qrcode = class { constructor(){} start(){return new Promise(resolve=>setTimeout(resolve,250));} stop(){window.__cameraStopped=true;return Promise.resolve();} clear(){} };
                }""")
                page.locator('#startCamera').click()
                page.locator('#stopCamera').click()
                page.wait_for_function('() => window.__cameraStopped === true',timeout=5000)
                page.evaluate("""() => {window.__starts=0;window.__stops=0;
                  window.Html5Qrcode = class { constructor(){} start(){window.__starts++;return Promise.resolve();} stop(){return new Promise(resolve=>setTimeout(()=>{window.__stops++;resolve();},300));} clear(){} };
                }""")
                page.locator('#startCamera').click()
                page.wait_for_function("() => document.querySelector('#startCamera').textContent === 'Stop camera'")
                page.evaluate("() => {document.querySelector('#stopCamera').click();document.querySelector('#startCamera').click();}")
                page.wait_for_timeout(500)
                assert page.evaluate('() => window.__starts===1 && window.__stops===1 && document.querySelector("#cameraPanel").hidden')
                assert not errors, errors
                # Synthetic USER submits one order; pending remains editable.
                page.on('dialog',lambda dialog:dialog.accept())
                page.locator('#submitOrder').click()
                page.wait_for_url('**/orders.php?submitted=*',timeout=30000)
                page.locator('.order-status-pending').first.wait_for(timeout=15000)
                page.locator('.order-details summary').first.click()
                assert page.locator('.manual-order-history-sheet .manual-order-store').first.inner_text()=='Marrickville Xpress'
                assert page.locator('.manual-order-history-sheet .manual-order-table th').count()==4
                page.locator('button:has-text("Edit pending order")').first.click()
                page.wait_for_url('**/orders.php?editing=*',timeout=15000)
                page.locator('#countWorkspace').wait_for(state='visible',timeout=30000)
                assert page.locator('#submitOrder').inner_text().find('Save changes')>=0
                assert not errors, errors
                page.screenshot(path=str(dst/'order-alpha-mobile.png'),full_page=True)
                print('PASS mobile Chromium 390px integrated manual-order sheet, live preview, stock/accessories, history, edit, zero JS errors',flush=True)
                browser.close()
        finally:
            server.terminate()
            try:server.wait(timeout=5)
            except subprocess.TimeoutExpired:server.kill()
if __name__=='__main__':run()
