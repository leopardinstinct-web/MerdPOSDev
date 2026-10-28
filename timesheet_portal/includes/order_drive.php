<?php
declare(strict_types=1);
/**
 * Optional server-to-server Google Drive publishing for approved Alpha orders.
 * Uses a private service-account JSON and a folder shared with that account.
 * Never accepts browser-supplied tokens, destination IDs or file paths.
 */
function order_drive_config(): ?array {
    $keyPath=getenv('MERD_ALPHA_DRIVE_SERVICE_ACCOUNT_JSON') ?: '';
    $folder=getenv('MERD_ALPHA_DRIVE_FOLDER_ID') ?: '';
    if ($keyPath==='' || $folder==='') return null;
    if (!preg_match('/^[A-Za-z0-9_-]{10,}$/D',$folder) || !is_file($keyPath) || is_link($keyPath)) {
        throw new RuntimeException('Drive configuration is invalid.');
    }
    $key=json_decode((string)file_get_contents($keyPath),true,512,JSON_THROW_ON_ERROR);
    if (!isset($key['client_email'],$key['private_key']) ||
        !str_ends_with((string)$key['client_email'],'.gserviceaccount.com')) {
        throw new RuntimeException('Drive service account configuration invalid.');
    }
    return ['key'=>$key,'folder'=>$folder];
}
function order_drive_http(string $url,string $method,array $headers,string $body=''): array {
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL unavailable.');
    $curl=curl_init($url);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,
      CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$body,
      CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>false]);
    $data=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    $error=curl_error($curl);curl_close($curl);
    if ($data===false || $status<200 || $status>=300) {
        throw new RuntimeException('Google Drive request failed (HTTP '.$status.'): '.substr($error,0,150));
    }
    return ['body'=>(string)$data,'status'=>$status];
}
function order_drive_token(array $key): string {
    $enc=static fn(array $x):string=>rtrim(strtr(base64_encode(json_encode($x,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'+/','-_'),'=');
    $now=time();
    $header=$enc(['alg'=>'RS256','typ'=>'JWT']);
    $claims=$enc(['iss'=>$key['client_email'],'scope'=>'https://www.googleapis.com/auth/drive.file',
      'aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3500]);
    $input=$header.'.'.$claims;
    if (!openssl_sign($input,$sig,$key['private_key'],OPENSSL_ALGO_SHA256)) throw new RuntimeException('Drive signing failed.');
    $jwt=$input.'.'.rtrim(strtr(base64_encode($sig),'+/','-_'),'=');
    $token=order_drive_http('https://oauth2.googleapis.com/token','POST',
      ['Content-Type: application/x-www-form-urlencoded'],
      http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]));
    $json=json_decode($token['body'],true,512,JSON_THROW_ON_ERROR);
    if (empty($json['access_token'])) throw new RuntimeException('Drive token not returned.');
    return (string)$json['access_token'];
}
function order_drive_publish(array $row): array {
    if (($row['status']??'')!=='APPROVED' || !empty($row['deleted_at'])) throw new DomainException('Only approved orders can be published.');
    $config=order_drive_config();
    if ($config===null) return ['state'=>'NOT_CONFIGURED'];
    require_once __DIR__.'/order_export.php';
    $id=(string)$row['id'];
    $receiptDir=order_private_dir().'/drive_receipts';
    if (!is_dir($receiptDir) && !mkdir($receiptDir,0700,true) && !is_dir($receiptDir)) throw new RuntimeException('Drive receipt directory unavailable.');
    $lock=fopen($receiptDir.'/'.$id.'.lock','c');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Drive order lock unavailable.');
    try {
        $receipt=$receiptDir.'/'.$id.'.json';
        $state=is_file($receipt)?json_decode((string)file_get_contents($receipt),true):[];
        if (!is_array($state)) $state=[];
        $token=order_drive_token($config['key']);
        foreach (['pdf'=>'application/pdf','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'] as $extension=>$mime) {
            if (!empty($state[$extension]['id'])) continue;
            $local=order_export_path($id,$extension);
            if (!is_file($local) || is_link($local)) throw new RuntimeException('Approved order file missing.');
            $name=$id.'.'.$extension;
            // Query first for crash recovery: idempotent even if the upload succeeded
            // but PHP terminated before writing the local receipt.
            $filter="name = '".str_replace("'","\\'",$name)."' and '". $config['folder']."' in parents and trashed = false";
            $query='https://www.googleapis.com/drive/v3/files?'.http_build_query(['q'=>$filter,'fields'=>'files(id,name,mimeType),nextPageToken','pageSize'=>100]);
            $found=json_decode(order_drive_http($query,'GET',['Authorization: Bearer '.$token])['body'],true);
            $matches=array_values(array_filter($found['files']??[],static fn($f)=>($f['name']??'')===$name && ($f['mimeType']??'')===$mime));
            if (count($matches)>1) throw new RuntimeException('Duplicate Drive file requires reconciliation.');
            if ($matches) $fileId=$matches[0]['id'];
            else {
                $boundary='merd'.bin2hex(random_bytes(12));
                $meta=json_encode(['name'=>$name,'parents'=>[$config['folder']],'mimeType'=>$mime],JSON_THROW_ON_ERROR);
                $raw=(string)file_get_contents($local);
                $body='--'.$boundary."\r\n".'Content-Type: application/json; charset=UTF-8'."\r\n\r\n".$meta."\r\n".
                  '--'.$boundary."\r\n".'Content-Type: '.$mime."\r\n\r\n".$raw."\r\n".'--'.$boundary.'--'."\r\n";
                $url='https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,mimeType';
                $response=json_decode(order_drive_http($url,'POST',['Authorization: Bearer '.$token,'Content-Type: multipart/related; boundary='.$boundary],$body)['body'],true);
                $fileId=$response['id']??null;
            }
            if (!is_string($fileId) || $fileId==='') throw new RuntimeException('Drive file ID missing.');
            $state[$extension]=['id'=>$fileId,'name'=>$name,'sha256'=>hash_file('sha256',$local)];
            $tmp=$receipt.'.tmp.'.bin2hex(random_bytes(4));
            file_put_contents($tmp,json_encode($state,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX);
            chmod($tmp,0600);
            if (!rename($tmp,$receipt)) throw new RuntimeException('Drive receipt persistence failed.');
        }
        return ['state'=>'SAVED','files'=>$state];
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
