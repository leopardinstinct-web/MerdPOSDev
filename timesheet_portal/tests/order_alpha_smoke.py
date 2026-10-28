"""Read-only Inventory DBA / session-only order draft acceptance smoke.
Runs local PHP server; writes nothing to Google Sheets, Drive or production.
Usage: python tests/order_alpha_smoke.py (from any directory), requires php84 and Internet.
"""
from pathlib import Path
import json, os, shutil, subprocess, tempfile, time, urllib.parse, urllib.request, urllib.error
from http.cookiejar import CookieJar

ROOT = Path(__file__).resolve().parents[1]
def check(ok, label):
    if not ok:
        raise AssertionError(label)
    print("PASS",label,flush=True)

def main():
    with tempfile.TemporaryDirectory(prefix="merd-alpha-qa-") as wd:
        dst=Path(wd)
        for folder in ("includes","api","assets"):
            shutil.copytree(ROOT/folder,dst/folder)
        shutil.copy(ROOT/"orders.php",dst/"orders.php")
        (dst/"includes"/"config.php").write_text("<?php define('SESSION_NAME','MERD_ALPHA_QA_SMOKE');",encoding="ascii")
        (dst/"qa-session.php").write_text("<?php require_once __DIR__.'/includes/auth.php'; start_app_session(); $_SESSION['user']=['name'=>'Alpha Test', 'is_super'=>!empty($_GET['super'])]; echo 'READY';",encoding="ascii")
        import socket
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0))
            port=sock.getsockname()[1]
        php = os.getenv('MERD_PHP84') or shutil.which('php84') or next((str(p) for p in Path.home().glob('AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.4_*/php.exe')),None)
        if not php: raise RuntimeError('PHP 8.4 CLI not found; set MERD_PHP84')
        import certifi
        cert=certifi.where()
        server=subprocess.Popen([php,'-d','curl.cainfo='+cert,'-d','openssl.cafile='+cert,'-S',f'127.0.0.1:{port}','-t',str(dst)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        jar=CookieJar(); opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
        base=f"http://127.0.0.1:{port}/"
        def req(path,data=None):
            payload=urllib.parse.urlencode(data).encode() if data is not None else None
            try:
                with opener.open(base+path,data=payload,timeout=45) as resp:return resp.status,resp.read().decode("utf-8")
            except urllib.error.HTTPError as error:return error.code,error.read().decode("utf-8")
        try:
            time.sleep(2)
            code,_=req("api/order_count.php")
            check(code==401,"anonymous catalogue blocked")
            code,body=req("qa-session.php")
            check((code,body)==(200,"READY"),"USER session bootstrap")
            code,_=req("api/order_count.php")
            check(code==409,"store selection required")
            code,body=req("orders.php")
            import re
            token=re.search('name="csrf_token" value="([a-f0-9]{64})"',body).group(1)
            code,_=req("orders.php",{"csrf_token":token,"store_code":"MX"})
            check(code==200,"store selection redirect followed")
            code,data=req("api/order_count.php")
            check(code==200,"Inventory DBA CSV catalogue loaded")
            payload=json.loads(data); products=payload["products"]
            captured=payload['catalogue_captured_at']
            auto={p["name"]:p for p in products if p["type"]=="AUTO"}
            manual={p["name"]:p for p in products if p["type"]=="MANUAL"}
            check(len(auto)>10 and len(manual)>10,"eligible AUTO / MANUAL products available")
            check(not any(p["name"]=="Kamagra" for p in products),"NO products excluded")
            cigarettes=auto["B & H 20's (Classic, Blue)"]
            check(cigarettes["ceiling"]==4 and cigarettes["count"]==0 and cigarettes["order_quantity"]==4,"MX ceiling and unscanned-as-zero")
            lighter=manual["BIC Plain Big"]
            check("070330600065" in lighter["unit_barcodes"],"leading zero preserved in barcode")
            def post(data):
                c,raw=req("api/order_count.php",{"csrf_token":token,**data})
                try:body=json.loads(raw)
                except Exception:body={"raw":raw[:140]}
                return c,body
            code,_=post({"type":"AUTO","action":"scan","barcode":"93208949","csrf_token":"bad"})
            check(code==403,"CSRF blocked")
            code,p=post({"type":"AUTO","action":"scan","barcode":"93208949"})
            hit=next(x for x in p["products"] if x["id"]==cigarettes["id"])
            check(hit["count"]==1 and hit["order_quantity"]==3,"unit barcode increments and calculates")
            check(p['catalogue_captured_at']==captured, 'inventory reference snapshot pinned through draft')
            code,p=post({"type":"AUTO","action":"scan","barcode":"9310797301190"})
            hit=next(x for x in p["products"] if x["id"]==cigarettes["id"])
            check(hit["count"]==2 and hit["order_quantity"]==2,"barcode alias same product increments")
            code,p=post({"type":"AUTO","action":"set","product_id":cigarettes["id"],"quantity":"8"})
            hit=next(x for x in p["products"] if x["id"]==cigarettes["id"])
            check(hit["count"]==8 and hit["order_quantity"]==0,"above ceiling never creates negative order")
            code,p=post({"type":"AUTO","action":"remove","product_id":cigarettes["id"]})
            hit=next(x for x in p["products"] if x["id"]==cigarettes["id"])
            check(hit["count"]==0 and hit["order_quantity"]==4,"reset reverts to zero unscanned")
            code,p=post({"type":"MANUAL","action":"scan","barcode":"070330600065"})
            code,p=post({"type":"MANUAL","action":"scan","barcode":"070330600065"})
            hit=next(x for x in p["products"] if x["id"]==lighter["id"])
            check(hit["count"]==2 and hit["order_quantity"]==2,"accessory quantity increments")
            code,_=post({"type":"AUTO","action":"scan","barcode":"8901111046234"})
            check(code==422,"NO catalogue barcode rejected")
            code,_=post({"type":"AUTO","action":"set","product_id":lighter["id"],"quantity":"3"})
            check(code==422,"cross-kind product manipulation blocked")
            # Validate the carton matching function with a synthetic record (no live eligible carton exists).
            snippet="""<?php require_once %s; $p=['type'=>'AUTO','id'=>'example','unit_barcodes'=>['00123'],'box_barcodes'=>['00456'],'packaging'=>10]; $m=order_barcode_match(['example'=>$p],'00456','AUTO'); if($m['quantity']!==10)exit(1); $m=order_barcode_match(['example'=>$p],'00123','AUTO'); if($m['quantity']!==1)exit(2); $q=['type'=>'MANUAL','id'=>'other','unit_barcodes'=>['00456'],'box_barcodes'=>[],'packaging'=>null]; try {order_barcode_match(['example'=>$p,'other'=>$q],'00456','AUTO');exit(3);} catch(InvalidArgumentException $e) {echo 'OK';}"""%repr(str(dst/"includes"/"order_inventory.php")).replace("\\\\","/")
            phpfile=dst/"box-test.php";phpfile.write_text(snippet,encoding="utf8")
            output=subprocess.check_output([php,str(phpfile)],text=True,timeout=15).strip()
            check(output=="OK","Packaging multiplier and unit fallback (synthetic eligible product)")
            code,body=req("orders.php")
            token=re.search('name="csrf_token" value="([a-f0-9]{64})"',body).group(1)
            req("orders.php",{"csrf_token":token,"store_code":"RT"})
            code,raw=req("api/order_count.php")
            hit=next(x for x in json.loads(raw)["products"] if x["id"]==cigarettes["id"])
            check(hit["ceiling"]==16 and hit["count"]==0 and hit["order_quantity"]==16,"RT ceiling and isolated store draft")
            print("RESULT ALL PASS",flush=True)
        finally:
            server.terminate()
            try:server.wait(timeout=6)
            except subprocess.TimeoutExpired:server.kill()
            pass

if __name__=="__main__":main()
