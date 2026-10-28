"""Isolated Alpha order approval / multi-order test. Does not modify production or Google."""
from pathlib import Path
from http.cookiejar import CookieJar
import json,os,shutil,socket,subprocess,tempfile,time,urllib.parse,urllib.request,urllib.error,certifi,re,zipfile,io

ROOT=Path(__file__).resolve().parents[1]
def ok(check,label):
 if not check:raise AssertionError(label)
 print('PASS',label,flush=True)
def main():
 with tempfile.TemporaryDirectory(prefix='merd-alpha-approval-') as tmp:
  dst=Path(tmp)/'portal';dst.mkdir()
  for folder in ('includes','api','assets'):shutil.copytree(ROOT/folder,dst/folder)
  shutil.copy(ROOT/'orders.php',dst/'orders.php')
  (dst/'includes'/'config.php').write_text("<?php define('SESSION_NAME','MERD_ALPHA_APPROVAL_SMOKE');",encoding='ascii')
  (dst/'qa-session.php').write_text("<?php require_once __DIR__.'/includes/auth.php'; start_app_session(); $_SESSION['user']=['user_id'=>(string)($_GET['uid']??''),'name'=>(string)($_GET['name']??''),'is_super'=>(bool)($_GET['super']??0)]; echo 'READY';",encoding='ascii')
  with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
  php=os.getenv('MERD_PHP84') or shutil.which('php84') or next((str(p) for p in Path.home().glob('AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.4_*/php.exe')),None)
  env=os.environ.copy();env['MERD_ALPHA_ORDER_DATA_DIR']=str(Path(tmp)/'private')
  server=subprocess.Popen([php,'-d','display_errors=0','-d','curl.cainfo='+certifi.where(),'-d','openssl.cafile='+certifi.where(),'-S',f'127.0.0.1:{port}','-t',str(dst)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env=env)
  base=f'http://127.0.0.1:{port}/'
  class Client:
   def __init__(self):
    self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))
   def get(self,path):
    try:
     with self.opener.open(base+path,timeout=42) as response:return response.status,response.read()
    except urllib.error.HTTPError as e:return e.code,e.read()
   def post(self,path,data):
    request=urllib.request.Request(base+path,data=urllib.parse.urlencode(data).encode())
    try:
     with self.opener.open(request,timeout=42) as response:return response.status,response.read()
    except urllib.error.HTTPError as e:return e.code,e.read()
  try:
   time.sleep(1)
   user=Client();superuser=Client();other=Client()
   code,_=user.get('api/order_workflow.php');ok(code==401,'anonymous order list denied')
   for client,uid,name,superflag in [(user,'111','User A',0),(superuser,'999','Super Admin',1),(other,'222','User B',0)]:
    code,body=client.get(f'qa-session.php?uid={uid}&name={urllib.parse.quote(name)}&super={superflag}')
    ok(code==200 and body==b'READY','session '+name)
   code,html=user.get('orders.php')
   token=re.search(r'name="csrf_token" value="([a-f0-9]{64})"',html.decode()).group(1)
   code,_=user.post('orders.php',{'csrf_token':token,'store_code':'MX'})
   ok(code==200,'user selects MX store')
   status,body=user.get('api/order_count.php')
   ok(status==200,'live Inventory DBA catalogue loaded')
   catalog=json.loads(body)
   print('CEILING_MISSING',sum(1 for p in catalog['products'] if p['type']=='AUTO' and p['ceiling'] is None),flush=True)
   check_auto=next(p for p in catalog['products'] if p['type']=='AUTO' and p['name']=="B & H 20's (Classic, Blue)")
   check_accessory=next(p for p in catalog['products'] if p['type']=='MANUAL' and p['name']=="BIC Plain Big")
   nonce=catalog['draft_nonce']
   def mutation(data):
    status,body=user.post('api/order_count.php',{'csrf_token':token,**data})
    return status,json.loads(body)
   status,out=mutation({'action':'adjust','type':'AUTO','product_id':check_auto['id'],'delta':'1'})
   ok(status==200 and next(p for p in out['products'] if p['id']==check_auto['id'])['count']==1,'on-hand plus 1')
   status,out=mutation({'action':'adjust','type':'AUTO','product_id':check_auto['id'],'delta':'-1'})
   ok(status==200 and next(p for p in out['products'] if p['id']==check_auto['id'])['count']==0,'on-hand minus 1')
   status,out=mutation({'action':'adjust','type':'AUTO','product_id':check_auto['id'],'delta':'-1'})
   ok(status==200 and next(p for p in out['products'] if p['id']==check_auto['id'])['count']==0,'no negative stock')
   status,out=mutation({'action':'set','type':'MANUAL','product_id':check_accessory['id'],'quantity':'3'})
   ok(status==200,'accessory editable field accepts 3')
   status,out=mutation({'action':'adjust','type':'MANUAL','product_id':check_accessory['id'],'delta':'-1'})
   ok(status==200 and next(p for p in out['products'] if p['id']==check_accessory['id'])['count']==2,'accessory decrement')
   code,body=user.post('api/order_workflow.php',{'action':'submit','csrf_token':'wrong','draft_nonce':nonce})
   ok(code==403,'CSRF blocks order submit')
   status,body=user.post('api/order_workflow.php',{'action':'submit','csrf_token':token,'draft_nonce':nonce})
   print('SUBMIT_RESPONSE',status,body.decode()[:270],flush=True)
   ok(status==200,'first independent order submitted')
   one=json.loads(body)['order']['id']
   status,body=user.post('api/order_workflow.php',{'action':'submit','csrf_token':token,'draft_nonce':nonce})
   ok(status==409,'duplicate submitted draft blocked')
   status,body=user.get('api/order_count.php')
   second_nonce=json.loads(body)['draft_nonce']
   ok(second_nonce!=nonce,'second order gets fresh draft nonce in same session')
   status,body=user.post('api/order_workflow.php',{'action':'submit','csrf_token':token,'draft_nonce':second_nonce})
   ok(status==200,'multiple orders from one login session')
   two=json.loads(body)['order']['id']
   code,body=other.post('api/order_workflow.php',{'action':'edit','csrf_token':'bad','order_id':one})
   ok(code==403,'other user forged edit blocked')
   code,body=user.post('api/order_workflow.php',{'action':'edit','csrf_token':token,'order_id':one})
   ok(code==200,'USER can reopen own PENDING order for edits')
   code,body=user.get('api/order_count.php')
   resume=json.loads(body)
   ok(next(p for p in resume['products'] if p['id']==check_accessory['id'])['count']==2,'original counts restored on pending edit')
   revised_nonce=resume['draft_nonce']
   code,body=mutation({'action':'adjust','type':'MANUAL','product_id':check_accessory['id'],'delta':'1'})
   ok(code==200,'pending order draft can be modified')
   code,body=user.post('api/order_workflow.php',{'action':'submit','csrf_token':token,'draft_nonce':revised_nonce})
   ok(code==200 and json.loads(body)['order']['id']==one,'editing pending resubmits same order ID')
   code,body=other.get('api/order_workflow.php')
   ok(code==200 and json.loads(body)['orders']==[],'other USER cannot see orders')
   code,body=user.get('api/order_workflow.php')
   ok(code==200 and len(json.loads(body)['orders'])==2,'USER sees own distinct orders')
   code,body=user.post('api/order_workflow.php',{'action':'approve','csrf_token':token,'order_id':one})
   ok(code==403,'USER cannot approve')
   code,body=user.get(f'api/order_download.php?id={one}&format=pdf')
   ok(code==403,'USER cannot download PDF')
   code,body=superuser.get('api/order_workflow.php')
   ok(code==200 and len(json.loads(body)['orders'])==2,'SUPER sees across user submissions')
   code,html=superuser.get('orders.php')
   stoken=re.search(r'name="csrf_token" value="([a-f0-9]{64})"',html.decode()).group(1)
   code,body=superuser.get(f'api/order_download.php?id={one}&format=pdf')
   ok(code==409,'PDF unavailable before SUPER approval')
   code,body=superuser.post('api/order_workflow.php',{'action':'approve','csrf_token':stoken,'order_id':one,'note':''})
   print('APPROVE_RESPONSE',code,body[:350],flush=True)
   ok(code==200 and json.loads(body)['order']['status']=='APPROVED','SUPER approves and locks')
   code,body=superuser.get(f'api/order_download.php?id={one}&format=pdf')
   ok(code==200 and body.startswith(b'%PDF-') and len(body)>800,'SUPER receives valid PDF')
   code,xlsx=superuser.get(f'api/order_download.php?id={one}&format=xlsx')
   ok(code==200 and zipfile.is_zipfile(io.BytesIO(xlsx)),'SUPER receives XLSX document')
   from openpyxl import load_workbook
   workbook=load_workbook(io.BytesIO(xlsx),read_only=True)
   ok(workbook.active['A1'].value=='MERDPOS Approved Tobacco Order','XLSX opens in openpyxl')
   code,body=superuser.post('api/order_workflow.php',{'action':'approve','csrf_token':stoken,'order_id':one})
   ok(code==409,'already-approved order cannot be re-approved')
   code,body=superuser.post('api/order_workflow.php',{'action':'reject','csrf_token':stoken,'order_id':two,'note':'Please recount'})
   ok(code==200,'SUPER rejects second order')
   code,body=superuser.get(f'api/order_download.php?id={two}&format=pdf')
   ok(code==409,'rejected order cannot download')
   print('RESULT ALL ACCEPTANCE PASS',flush=True)
  finally:
   server.terminate()
   try:server.wait(timeout=8)
   except subprocess.TimeoutExpired:server.kill()
   pass

if __name__=='__main__':main()
