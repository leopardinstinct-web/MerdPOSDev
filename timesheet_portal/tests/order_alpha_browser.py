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
        (dst/'qa-session.php').write_text("<?php require_once __DIR__.'/includes/auth.php'; start_app_session(); $_SESSION['user']=['name'=>'Alpha Browser QA','is_super'=>false]; echo 'READY';",encoding='ascii')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        php=os.getenv('MERD_PHP84') or shutil.which('php84') or next((str(p) for p in Path.home().glob('AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.4_*/php.exe')),None)
        cmd=[php,'-d','curl.cainfo='+certifi.where(),'-d','openssl.cafile='+certifi.where(),'-S',f'127.0.0.1:{port}','-t',str(dst)]
        server=subprocess.Popen(cmd,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
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
                page.locator('#productSearch').fill('B & H 20')
                page.locator('.order-suggestion').first.click()
                page.wait_for_function("() => document.querySelector('#countedProducts').textContent === '1'")
                assert page.locator('#countedBody').inner_text().find("B & H")>=0
                # Deliver 2 clicks in one event loop while first API request is pending.
                page.evaluate("() => {const b=document.querySelector('#countedBody .order-row-action'); b.click(); b.click();}")
                page.wait_for_function("() => document.querySelector('#countedBody .order-qty').textContent === '3'",timeout=30000)
                page.locator('#typeManual').click()
                page.locator('#productSearch').fill('BIC Plain Big')
                page.locator('.order-suggestion').first.click()
                page.wait_for_function("() => document.querySelector('#accessoryTotal').textContent === '1'")
                # Camera cancellation during async startup must stop once start resolves.
                page.evaluate("""() => { window.__cameraStopped = false;
                  window.Html5Qrcode = class { constructor(){} start(){return new Promise(resolve=>setTimeout(resolve,250));} stop(){window.__cameraStopped=true;return Promise.resolve();} clear(){} };
                }""")
                page.locator('#startCamera').click()
                page.locator('#stopCamera').click()
                page.wait_for_function('() => window.__cameraStopped === true',timeout=5000)
                assert not errors, errors
                page.screenshot(path=str(dst/'order-alpha-mobile.png'),full_page=True)
                print('PASS mobile Chromium 390px UI, store selection, Inventory CSV load, search, AUTO/MANUAL increments and zero JS page errors',flush=True)
                browser.close()
        finally:
            server.terminate()
            try:server.wait(timeout=5)
            except subprocess.TimeoutExpired:server.kill()
if __name__=='__main__':run()
