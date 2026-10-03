<?php
require __DIR__.'/auth.php';require_auth();require_once __DIR__.'/owncloud-storage.php';
$id=(int)($_GET['id']??0);$db=app_db();$s=$db->prepare('SELECT offer_pdf FROM service_requests WHERE id=?');$s->execute([$id]);$name=(string)$s->fetchColumn();
if($name===''){http_response_code(404);exit('Няма PDF оферта.');}
$c=oc_settings();if(!oc_ready($c)){http_response_code(503);exit('ownCloud не е конфигуриран.');}
$path=$c['root'].'/Оферти/'.ltrim($name,'/');[$code,$data]=oc_req($c,'GET',$path);
if($code!==200){http_response_code(502);exit('PDF офертата не може да бъде заредена.');}
header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="'.basename($name).'"');header('Content-Length: '.strlen($data));header('Cache-Control: private, no-store');echo $data;
