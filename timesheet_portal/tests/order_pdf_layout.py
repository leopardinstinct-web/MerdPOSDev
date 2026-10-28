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
   rows.append({'type':'AUTO','category':'TobaccoLongCategory '+('A'*110),
                'name':'SKU%03d '%i+('Very long protected product name with wrapped descriptive details '*3),
                'barcodes':'00012345678901234567890123456789,0009876543210987654321',
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
  extracted=' '.join(page.get_text() for page in doc)
  for i in range(65):
   assert extracted.count('SKU%03d'%i)==1,('PDF dropped/duplicated product',i)
  for page in doc:
   for block in page.get_text('blocks'):
    assert block[3]<=page.rect.height-10,('text overflowed page',block)
  print('PASS long category/barcode/product wrapping,',len(doc),'PDF pages, every SKU once, no page overflow',flush=True)
  doc.close()
  # Fail closed rather than accepting a single row too tall for page.
  payload['rows'][0]['category']='X'*9000
  jsonpath.write_text(json.dumps(payload),encoding='utf8')
  pdf.unlink()
  error=subprocess.run([command,str(php)],capture_output=True,text=True,timeout=30)
  assert error.returncode!=0 and not pdf.exists(),'Excessive row must fail rather than corrupt approved PDF'
  print('PASS pathologically long row fails closed',flush=True)
if __name__=='__main__':main()
