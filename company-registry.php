<?php
require __DIR__.'/auth.php'; require_auth();
header('Content-Type: application/json; charset=utf-8');
$uic=preg_replace('/\D+/','',(string)($_GET['uic']??''));
if(!preg_match('/^(\d{9}|\d{13})$/',$uic)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Невалиден ЕИК / БУЛСТАТ.'],JSON_UNESCAPED_UNICODE);exit;}
$url='https://portal.registryagency.bg/CR/api/Deeds/'.rawurlencode($uic);
$ctx=stream_context_create(['http'=>['timeout'=>10,'header'=>"Accept: application/json\r\nUser-Agent: INFO-ServiceRequests/1.0\r\n",'ignore_errors'=>true]]);
$raw=@file_get_contents($url,false,$ctx);
$status=0;if(isset($http_response_header[0])&&preg_match('/\s(\d{3})\s/',$http_response_header[0],$m))$status=(int)$m[1];
if($raw===false||$status!==200){http_response_code(502);echo json_encode(['ok'=>false,'error'=>'Търговският регистър не отговори.'],JSON_UNESCAPED_UNICODE);exit;}
$data=json_decode($raw,true);
if(!is_array($data)||empty($data['uic'])){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Фирмата не е намерена.'],JSON_UNESCAPED_UNICODE);exit;}
$strip=function($html){$s=html_entity_decode(strip_tags((string)$html),ENT_QUOTES|ENT_HTML5,'UTF-8');return trim(preg_replace('/\s+/u',' ',$s));};
$fields=[];
foreach(($data['sections']??[]) as $section)foreach(($section['subDeeds']??[]) as $sub)foreach(($sub['groups']??[]) as $group)foreach(($group['fields']??[]) as $f){$id=(string)($f['fieldIdent']??'');$txt=$strip($f['htmlData']??'');if($id!==''&&$txt!=='')$fields[$id][]=$txt;}
$pick=function(array $ids)use($fields){foreach($ids as $id)if(!empty($fields[$id]))return implode('; ',array_values(array_unique($fields[$id])));return '';};
$legalForms=[11=>'ЕАД',10=>'АД',5=>'ООД',4=>'ЕООД',3=>'ЕТ',12=>'КДА',13=>'КД'];
$name=trim((string)($data['companyName']??''));
$legal=$legalForms[(int)($data['legalForm']??0)]??('Код '.(string)($data['legalForm']??''));
$full=trim($name.' '.$legal);
echo json_encode(['ok'=>true,'uic'=>$data['uic'],'companyName'=>$full,'companyBaseName'=>$name,'legalForm'=>$legal,'status'=>(int)($data['deedStatus']??0)===1?'Активна':'Статус '.(string)($data['deedStatus']??''),'address'=>$pick(['00050']),'representative'=>$pick(['00100']),'checkedAt'=>date('Y-m-d')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
