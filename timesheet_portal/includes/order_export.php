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
    $doc->AliasNbPages();$doc->SetMargins(13,14,13);$doc->SetAutoPageBreak(true,22);
    $doc->AddPage();
    $doc->SetFont('Arial','',12);$doc->SetTextColor(35,47,65);
    $doc->Cell(0,8,gmdate('F j, Y',strtotime($row['submitted_at'])),0,1,'C');
    $doc->SetFont('Arial','B',14);
    $doc->Cell(0,9,order_pdf_text($storeName),0,1,'C');
    $doc->SetFont('Arial','',9);
    $doc->Cell(0,6,'Address: '.order_pdf_text($address),0,1,'C');
    $doc->SetFont('Arial','',8);
    $doc->Cell(0,5,'Approved Order  |  '.$row['id'],0,1,'C');
    if (!empty($data['test_order'])) {
        $doc->SetTextColor(165,33,27);$doc->SetFont('Arial','B',13);
        $doc->Cell(0,9,'QA TEST ORDER - NOT FOR SUPPLIER',0,1,'C');
        $doc->SetTextColor(27,36,48);
    }
    $doc->Ln(7);
    $widths=[27,40,90,27];
    $drawHeader=static function () use ($doc,$widths): void {
        $doc->SetFont('Arial','B',9);$doc->SetFillColor(229,239,250);$doc->SetTextColor(22,45,74);
        foreach (['Category','Bar Code','Product Name','Order Quantity'] as $i=>$label) {
            $doc->Cell($widths[$i],9,$label,1,0,$i===3?'C':'L',true);
        }$doc->Ln();
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
        if ($height>210) throw new RuntimeException('Order line is too long to fit a PDF page.');
        if ($doc->GetY()+$height>267) {$doc->AddPage();$drawHeader();$doc->SetFont('Arial','',8);$doc->SetTextColor(27,36,48);}
        $y=$doc->GetY();$x=$doc->GetX();
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
