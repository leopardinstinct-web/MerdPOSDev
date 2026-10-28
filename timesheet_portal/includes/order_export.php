<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/fpdf.php';

function order_file_dir(): string {
    $dir=order_private_dir().'/approved';
    if (!is_dir($dir)) {
        $old=umask(0077);
        try {if (!@mkdir($dir,0700,true)&&!is_dir($dir)) throw new RuntimeException('Cannot create PDF directory.');}
        finally {umask($old);}
    }
    return $dir;
}
function order_export_path(string $id,string $format): string {
    if (!preg_match('/^ORD-[0-9]{8}-[A-F0-9]{10}$/D',$id) || !in_array($format,['pdf','xlsx'],true)) {
        throw new InvalidArgumentException('Invalid order file.');
    }
    return order_file_dir().'/'.$id.'.'.$format;
}
function order_pdf_text(string $value): string {
    $out=@iconv('UTF-8','windows-1252//TRANSLIT//IGNORE',$value);
    return $out!==false ? $out : preg_replace('/[^\x20-\x7E]/','?',$value);
}
function order_pdf_wrap(FPDF $pdf,string $text,float $width): array {
    // Wrap using the actual current PDF font metrics, not guessed character counts.
    $lines=[];$line='';
    foreach (str_split($text) as $ch) {
        if ($ch==="\n") { $lines[]=rtrim($line);$line='';continue; }
        if ($pdf->GetStringWidth($line.$ch)>$width) {
            if ($line==='') throw new RuntimeException('Unrenderable PDF product text.');
            $lines[]=rtrim($line);
            $line=ltrim($ch);
        } else {$line.=$ch;}
    }
    $lines[]=rtrim($line);
    return $lines;
}
class MerdOrderPdf extends FPDF {
    public string $orderId='';
    public string $storeName='';
    public string $storeAddress='';
    public string $orderDate='';
    public bool $isQaTest=false;
    public function Header() {
        // Matches Manual Order.xlsx: navy date/address strips, blue store title
        // and a four-column order table. Repeated on every page.
        $this->SetXY(13,12);
        $this->SetFillColor(30,64,115);
        $this->SetTextColor(255,255,255);
        $this->SetFont('Arial','',11);
        $this->Cell(184,9,$this->orderDate,0,1,'C',true);
        $this->SetX(13);
        $this->SetFillColor(17,85,204);
        $this->SetFont('Arial','B',15);
        $this->Cell(184,12,order_pdf_text($this->storeName),0,1,'C',true);
        $this->SetX(13);
        $this->SetFillColor(30,64,115);
        $this->SetFont('Arial','',8);
        $this->Cell(184,9,'Address: '.order_pdf_text($this->storeAddress),0,1,'C',true);
        $this->SetTextColor(46,67,92);
        $this->SetFont('Arial','',8);
        $this->Ln(3);
        $this->Cell(0,6,'MERDPOS Portal (Alpha)  |  '.$this->orderId.'  |  SUPER approved',0,1,'C');
        if ($this->isQaTest) {
            $this->SetFillColor(254,235,232);
            $this->SetTextColor(156,27,24);
            $this->SetFont('Arial','B',11);
            $this->Cell(0,9,'QA TEST ORDER - NOT FOR SUPPLIER',0,1,'C',true);
        }
        $this->SetTextColor(27,36,48);
        $this->Ln(4);
    }
    public function Footer() {
        $this->SetY(-18);
        $this->SetFont('Arial','',8);$this->SetTextColor(95,109,128);
        $this->Cell(0,6,'MERDPOS Portal (Alpha)  |  '.$this->orderId.'  |  Page '.$this->PageNo().' / {nb}',0,0,'R');
    }
}
function order_pdf(array $row,string $path): void {
    $data=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
    $names=['MX'=>['Marrickville Xpress','375 Enmore Rd, Marrickville NSW 2204, Australia'],
            'ET'=>['Enmore Tobacco','Enmore, NSW, Australia'],
            'DT'=>['Double Bay Xpress','Shop 4/401-407 New South Head Rd, Double Bay NSW 2028, Australia'],
            'RT'=>['Rosebay Xpress','10C Dover Rd, Rose Bay NSW 2029, Australia']];
    [$storeName,$address]=$names[$row['store']];
    $doc=new MerdOrderPdf('P','mm','A4');
    $doc->orderId=$row['id'];
    $doc->storeName=$storeName;
    $doc->storeAddress=$address;
    // The order ledger stores UTC timestamps with Z. For legacy timestamps
    // missing an offset, interpret the original database value as UTC.
    $submitted=new DateTimeImmutable($row['submitted_at'],new DateTimeZone('UTC'));
    $doc->orderDate=$submitted->setTimezone(new DateTimeZone('Australia/Sydney'))->format('F j, Y');
    $doc->isQaTest=!empty($data['test_order']);
    $doc->AliasNbPages();$doc->SetMargins(13,12,13);$doc->SetAutoPageBreak(true,22);
    $doc->AddPage();
    $widths=[27,40,90,27];
    $drawHeader=static function () use ($doc,$widths): void {
        $doc->SetFont('Arial','B',9);$doc->SetFillColor(58,118,216);
        $doc->SetTextColor(255,255,255);$doc->SetDrawColor(35,66,115);
        foreach (['Category','Bar Code','Product Name','Order Quantity'] as $i=>$label) {
            $doc->Cell($widths[$i],9,$label,1,0,$i===3?'C':'L',true);
        }
        $doc->Ln();
        $doc->SetTextColor(27,36,48);$doc->SetFont('Arial','',8);
    };
    $drawHeader();
    $doc->SetTextColor(27,36,48);$doc->SetFont('Arial','',8);
    foreach ($data['rows'] as $entry) {
        $name=order_pdf_text((string)$entry['name']);
        $barcode=order_pdf_text((string)$entry['barcodes']);
        $cat=order_pdf_text((string)$entry['category']);
        $q=(string)$entry['order_quantity'];
        $columns=[
            order_pdf_wrap($doc,$cat,$widths[0]-2),
            order_pdf_wrap($doc,$barcode,$widths[1]-2),
            order_pdf_wrap($doc,$name,$widths[2]-2)
        ];
        $lines=max(array_map('count',$columns));
        $height=max(8,$lines*5+2);
        if ($doc->GetY()+$height>267) {
            $doc->AddPage();
            $drawHeader();
            // Explicitly restore body metrics after every repeated header.
            // Row wrapping was measured with this exact 8pt regular font.
            $doc->SetFont('Arial','',8);
            $doc->SetTextColor(27,36,48);
        }
        // The branded Header() is taller than the prior design. Measure real
        // remaining body space AFTER a fresh page and table header are drawn.
        // Never let a long row overlap the footer or silently clip PDF text.
        if ($doc->GetY()+$height>267) {
            throw new RuntimeException('Order line is too long to fit the printable PDF body.');
        }
        $y=$doc->GetY();$x=$doc->GetX();
        // Column fills mirror the workbook (neutral category, cream barcode,
        // white product and pale-blue quantity).
        $columnFills=[[246,248,252],[255,244,207],[255,255,255],[205,223,249]];
        $colX=$x;
        foreach ($widths as $col=>$width) {
            $doc->SetFillColor(...$columnFills[$col]);
            $doc->Rect($colX,$y,$width,$height,'F');
            $colX+=$width;
        }
        $doc->SetDrawColor(34,54,87);
        $doc->Rect($x,$y,array_sum($widths),$height);
        $offset=$x;
        for($col=0;$col<3;$col++){ $offset+=$widths[$col];$doc->Line($offset,$y,$offset,$y+$height); }
        $at=$x;
        foreach ($columns as $col=>$fragments) {
            foreach ($fragments as $line=>$fragment) {
                $doc->SetXY($at+1,$y+1+$line*5);
                $doc->Cell($widths[$col]-2,5,$fragment,0,0,'L');
            }
            $at+=$widths[$col];
        }
        $doc->SetXY($at,$y);$doc->Cell($widths[3],$height,$q,0,0,'C');
        $doc->SetXY($x,$y+$height);
    }
    $excluded=count($data['excluded_missing_ceiling']??[]);
    if ($excluded>0) {
        $doc->Ln(5);$doc->SetFont('Arial','',8);
        $doc->Cell(0,5,$excluded.' Inventory DBA products excluded (missing store ceiling).',0,1,'L');
    }
    $doc->Ln(6);$doc->SetFont('Arial','',8);
    $doc->Cell(0,5,'Submitted: '.$row['author_name'].'  |  Approved by: '.$row['reviewer_name'],0,1,'L');
    $doc->Output('F',$path);
}
function order_sheet_xml(array $cells): string {
    $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml.='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($cells as $r=>$values) {
        $xml.='<row r="'.($r+1).'">';
        foreach ($values as $index=>$v) {
            $col='';$i=$index+1;
            while($i>0){$mod=($i-1)%26;$col=chr(65+$mod).$col;$i=intdiv($i-1,26);}
            $ref=$col.($r+1);
            $value=(string)$v;
            // Always strings so leading-zero codes and spreadsheet formula-looking
            // product names cannot be executed when opened in Excel.
            $xml.='<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($value,ENT_XML1,'UTF-8').'</t></is></c>';
        }
        $xml.='</row>';
    }
    return $xml.'</sheetData></worksheet>';
}
function order_xlsx(array $row,string $path): void {
    $payload=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
    $rows=[
        [!empty($payload['test_order'])?'MERDPOS QA TEST - NOT FOR SUPPLIER':'MERDPOS Approved Tobacco Order',$row['id']],
        ['Store',$row['store']],
        ['Submitted by',$row['author_name']],
        ['Submitted at',$row['submitted_at']],
        ['Approved by',$row['reviewer_name']],
        ['Category','Bar Code','Product Name','Order Quantity','On Hand','Ceiling']
    ];
    foreach ($payload['rows'] as $p) {
        $rows[]=[$p['category'],$p['barcodes'],$p['name'],(string)$p['order_quantity'],
            $p['on_hand']===null?'':(string)$p['on_hand'],$p['ceiling']===null?'':(string)$p['ceiling']];
    }
    $zip=new ZipArchive();
    if ($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Cannot prepare order spreadsheet.');
    try {
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Approved Order" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml',order_sheet_xml($rows));
    } finally {$zip->close();}
}
function order_build_files(array $row): array {
    $id=$row['id'];
    $pdf=order_export_path($id,'pdf');
    $xlsx=order_export_path($id,'xlsx');
    // Called only while a PENDING order is held under SQLite BEGIN IMMEDIATE.
    // If PHP crashed after file promotion but before the approval transaction
    // committed, these are orphan outputs: safely replace them on retry.
    foreach([$pdf,$xlsx] as $orphan) {
        if (is_link($orphan)) throw new RuntimeException('Unsafe approved order file.');
        if (is_file($orphan) && !@unlink($orphan)) throw new RuntimeException('Cannot recover incomplete approval file.');
    }
    $pdfTmp=$pdf.'.'.bin2hex(random_bytes(4)).'.tmp';
    $xlsxTmp=$xlsx.'.'.bin2hex(random_bytes(4)).'.tmp';
    try {
        order_pdf($row,$pdfTmp);
        order_xlsx($row,$xlsxTmp);
        if (!rename($pdfTmp,$pdf) || !rename($xlsxTmp,$xlsx)) throw new RuntimeException('Order file promotion failed.');
        @chmod($pdf,0600);@chmod($xlsx,0600);
        return [$pdf,$xlsx];
    } catch(Throwable $e) {
        foreach([$pdfTmp,$xlsxTmp,$pdf,$xlsx] as $file) @unlink($file);
        throw $e;
    }
}
