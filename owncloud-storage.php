<?php
require_once __DIR__.'/db.php';
function oc_settings(): array {
 $db=app_db(); $db->exec('CREATE TABLE IF NOT EXISTS app_settings (key TEXT PRIMARY KEY,value TEXT NOT NULL)');
 $s=$db->query("SELECT key,value FROM app_settings WHERE key LIKE 'owncloud_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
 $pass='';
 if(!empty($s['owncloud_password'])){
  $key=@file_get_contents('/var/lib/montaji-app/settings.key');
  $raw=base64_decode($s['owncloud_password'],true);
  if($raw!==false&&strlen($key)===SODIUM_CRYPTO_SECRETBOX_KEYBYTES&&strlen($raw)>SODIUM_CRYPTO_SECRETBOX_NONCEBYTES){
   $nonce=substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
   $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$key);
   if($plain!==false)$pass=$plain;
  }
 }
 return ['url'=>rtrim($s['owncloud_url']??'','/'),'user'=>$s['owncloud_user']??'','password'=>$pass,'root'=>trim($s['owncloud_root']??'INFO','/')];
}
function oc_ready(array $c): bool { return $c['url']!==''&&$c['user']!==''&&$c['password']!==''; }
function oc_base(array $c): string { return $c['url'].'/remote.php/dav/files/'.rawurlencode($c['user']); }
function oc_req(array $c,string $method,string $path,$body=null): array {
 $url=oc_base($c).'/'.implode('/',array_map('rawurlencode',array_filter(explode('/',trim($path,'/')),'strlen')));
 $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_USERPWD=>$c['user'].':'.$c['password'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>false,CURLOPT_TIMEOUT=>30]);
 if($body!==null){curl_setopt($ch,CURLOPT_POSTFIELDS,$body);curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/pdf']);}
 $data=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);return[$code,$data,$err];
}
function oc_mkdir(array $c,string $path): void {
 $cur=''; foreach(array_filter(explode('/',trim($path,'/')),'strlen') as $part){$cur.=($cur?'/':'').$part;[$code]=oc_req($c,'MKCOL',$cur);if(!in_array($code,[201,405],true))throw new RuntimeException('ownCloud: не може да се създаде папка (HTTP '.$code.').');}
}
function oc_upload_offer(int $id,string $tmp): string {
 $c=oc_settings();if(!oc_ready($c))throw new RuntimeException('ownCloud хранилището не е конфигурирано.');
 $folder=$c['root'].'/Оферти/'.date('Y').'/'.date('m').'/Z'.$id;oc_mkdir($c,$folder);
 $name='offer-Z'.$id.'-'.date('YmdHis').'.pdf';$body=file_get_contents($tmp);[$code,,$err]=oc_req($c,'PUT',$folder.'/'.$name,$body);
 if(!in_array($code,[200,201,204],true))throw new RuntimeException('ownCloud: качването е неуспешно (HTTP '.$code.($err?' · '.$err:'').').');
 return date('Y').'/'.date('m').'/Z'.$id.'/'.$name;
}
