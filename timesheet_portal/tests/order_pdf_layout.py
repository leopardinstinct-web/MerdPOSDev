"""PDF overflow/page-safety regression for approved Alpha PDFs, isolated PHP+PyMuPDF."""
from pathlib import Path
import tempfile,os,shutil,subprocess,json,importlib.util
ROOT=Path(__file__).resolve().parents[1]
def main():
 import fitz
 with tempfile.TemporaryDirectory(prefix='merd-alpha-pdf-') as tmp:
  t=Path(tmp);include=t/'includes'
  shutil.copytree(ROOT/'includes',include)
  (include/'config.php').write_text("<?php define('SESSION_NAME','PDF_TEST');",encoding='ascii')
  rows=[]
  for i in range(65):
   rows.append({'type':'AUTO','category':'Tobacco',
                'name':'SKU%03d '%i+('Very long protected product name with wrapped descriptive details '*3),
                'barcodes':'9310797251655,9310797301671',
                'on_hand':1,'ceiling':3,'order_quantity':2})
  payload={'rows':rows,'excluded_missing_ceiling':[]}
  jsonpath=t/'payload.json';jsonpath.write_text(json.dumps(payload),encoding='utf8')
  php=t/'pdf-layout.php'
  pdf=t/'layout.pdf'
  php.write_text("""<?php require_once __DIR__.'/includes/order_export.php';
$row=['id'=>'ORD-20261028-AAAAAAAAAA','store'=>'MX','submitted_at'=>'2026-10-28T00:00:00Z','author_name'=>'User A','reviewer_name'=>'Super B','payload'=>file_get_contents(__DIR__.'/payload.json')];
order_pdf($row,__DIR__.'/layout.pdf');echo 'OK';""",encoding='utf8')
  command=os.getenv('MERD_PHP84') or shutil.which('php84') or next((str(x) for x in Path.home().glob('AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.4_*/php.exe')),None)
  out=subprocess.check_output([command,str(php)],text=True,timeout=30).strip()
  assert out=='OK' and pdf.exists()
  doc=fitz.open(pdf)
  assert len(doc)>=4, 'long rows should paginate into multiple pages'
  for pg in doc:
   content=pg.get_text()
   assert 'Marrickville Xpress' in content and '375 Enmore Rd' in content, 'branded store and address must repeat on each PDF page'
   assert 'Category' in content and 'Bar Code' in content and 'Order Quantity' in content, 'workbook columns must repeat on each PDF page'
  fills=[item.get('fill') for item in doc[0].get_drawings() if item.get('fill') is not None]
  assert any(abs(fill[0]-17/255)<0.02 and abs(fill[1]-85/255)<0.02 for fill in fills), 'PDF store banner must use workbook-style blue'
  extracted=' '.join(page.get_text() for page in doc)
  for i in range(65):
   assert extracted.count('SKU%03d'%i)==1,('PDF dropped/duplicated product',i)
  sku_spans=[]
  for page in doc:
   for block in page.get_text('dict')['blocks']:
    for line in block.get('lines',[]):
     for span in line['spans']:
      if 'SKU' in span['text']:
       sku_spans.append(span)
   for block in page.get_text('blocks'):
    assert block[3]<=page.rect.height-10,('text overflowed page',block)
  assert len(sku_spans)==65 and all(abs(span['size']-8)<0.2 and 'Bold' not in span['font'] for span in sku_spans), 'body font must remain 8pt regular across every repeated header'
  # Every barcode and category stays on exactly one PDF text line.
  expected_barcode='9310797251655,9310797301671'
  for page in doc:
   for block in page.get_text('dict')['blocks']:
    for line in block.get('lines',[]):
     content=''.join(span['text'] for span in line['spans'])
     if '9310797251655' in content:
      assert expected_barcode in content, 'barcode was wrapped or truncated'
     if 'Tobacco' in content:
      assert content.strip()=='Tobacco', 'Category text had an unexpected wrap'
  print('PASS 65 product rows, narrow product wraps, category/barcode single line, header repeats, body font consistent',flush=True)
  doc.close()
  # An impossible single-line barcode must fail rather than wrap/clip.
  payload['rows'][0]['barcodes']='9'*800
  jsonpath.write_text(json.dumps(payload),encoding='utf8')
  pdf.unlink()
  error=subprocess.run([command,str(php)],capture_output=True,text=True,timeout=30)
  assert error.returncode!=0 and not pdf.exists(),'Unrenderable barcode must fail closed'
  payload['rows'][0]['barcodes']='9310797251655,9310797301671'
  payload['rows'][0]['category']='A'*300
  jsonpath.write_text(json.dumps(payload),encoding='utf8')
  error=subprocess.run([command,str(php)],capture_output=True,text=True,timeout=30)
  assert error.returncode!=0 and not pdf.exists(),'Unrenderable category must fail closed'
  print('PASS pathological barcode/category fail closed instead of wrapping',flush=True)

  # Test status remains in the immutable order record but no visible QA banner
  # is shown in PDFs, as requested by the owner.
  payload['test_order']=True
  payload['rows'][0]['category']='Tobacco'
  payload['rows'][0]['barcodes']='9310797251655,9310797301671'
  jsonpath.write_text(json.dumps(payload),encoding='utf8')
  output=subprocess.run([command,str(php)],capture_output=True,text=True,timeout=30)
  assert output.returncode==0,output.stderr
  doc=fitz.open(pdf)
  assert all('QA TEST ORDER - NOT FOR SUPPLIER' not in pg.get_text() for pg in doc)
  assert all('Tobacco' in pg.get_text() and 'Bar Code' in pg.get_text() for pg in doc)
  doc.close()
  print('PASS QA classified record PDF without visual QA warning',flush=True)
