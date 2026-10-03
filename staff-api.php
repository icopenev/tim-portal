<?php
require __DIR__.'/auth.php'; require_auth(true); $db=app_db();
function body(){return json_decode(file_get_contents('php://input'),true)?:$_POST;}
function actor(){return (int)($_SESSION['user_id']??0);}
function adminish(){return in_array(current_user_role(),['admin','supervisor'],true);}
$action=$_GET['action']??'bootstrap'; $method=$_SERVER['REQUEST_METHOD'];
try{
 if($action==='bootstrap'){
  $objects=$db->query("SELECT * FROM staff_objects WHERE active=1 ORDER BY name")->fetchAll();
  $employees=$db->query("SELECT e.*,o.name object_name FROM staff_employees e JOIN staff_objects o ON o.id=e.object_id WHERE e.active=1 ORDER BY o.name,e.name")->fetchAll();
  $shifts=$db->query("SELECT * FROM staff_shifts WHERE active=1 ORDER BY object_id,id")->fetchAll();
  json_response(['objects'=>$objects,'employees'=>$employees,'shifts'=>$shifts,'role'=>current_user_role(),'csrf'=>csrf_token()]);exit;
 }
 if($method!=='GET'){ $b=body(); if(!hash_equals(csrf_token(),(string)($b['csrf']??''))) throw new Exception('Невалиден CSRF token'); }
 if($action==='object'&&$method==='POST'){
  if(!adminish()) {json_response(['error'=>'Нямате права'],403);exit;} $b=body();$name=trim($b['name']??'');if($name==='')throw new Exception('Въведете име на обект');
  $s=$db->prepare("INSERT INTO staff_objects(name,description) VALUES(?,?)");$s->execute([$name,trim($b['description']??'')]);json_response(['ok'=>true,'id'=>$db->lastInsertId()]);exit;
 }
 if($action==='employee'&&$method==='POST'){
  if(!adminish()){json_response(['error'=>'Нямате права'],403);exit;} $b=body();$oid=(int)($b['object_id']??0);$name=trim($b['name']??'');$code=trim($b['employee_code']??'');if(!$oid||$name==='')throw new Exception('Липсва обект или служител');
  $db->beginTransaction();$rid=null;if($code!==''){$s=$db->prepare("INSERT INTO staff_employee_registry(employee_code,name,position_name) VALUES(?,?,?) ON CONFLICT(employee_code) DO UPDATE SET name=excluded.name,position_name=excluded.position_name,active=1");$s->execute([$code,$name,trim($b['position_name']??'')]);$s=$db->prepare("SELECT id FROM staff_employee_registry WHERE employee_code=?");$s->execute([$code]);$rid=$s->fetchColumn();}
  $s=$db->prepare("INSERT INTO staff_employees(registry_id,employee_code,object_id,name,position_name) VALUES(?,?,?,?,?)");$s->execute([$rid,$code?:null,$oid,$name,trim($b['position_name']??'')]);$db->commit();json_response(['ok'=>true]);exit;
 }
 if($action==='shift'&&$method==='POST'){
  if(!adminish()){json_response(['error'=>'Нямате права'],403);exit;} $b=body();$oid=(int)($b['object_id']??0);$code=trim($b['code']??'');$name=trim($b['name']??'');$type=$b['type']??'work';if(!$oid||$code===''||$name==='')throw new Exception('Попълнете смяната');
  $s=$db->prepare("INSERT INTO staff_shifts(object_id,code,name,type,time_from,time_to,counts_as_work_day) VALUES(?,?,?,?,?,?,?)");$s->execute([$oid,$code,$name,$type,$b['time_from']?:null,$b['time_to']?:null,in_array($type,['work','duty'],true)?1:0]);json_response(['ok'=>true]);exit;
 }
 if($action==='month'&&$method==='GET'){
  $oid=(int)($_GET['object_id']??0);$y=(int)($_GET['year']??0);$m=(int)($_GET['month']??0);if(!$oid||$m<1||$m>12)throw new Exception('Невалиден период');$start=sprintf('%04d-%02d-01',$y,$m);$end=(new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
  $s=$db->prepare("SELECT e.id,e.name,e.employee_code,e.position_name FROM staff_employees e WHERE e.object_id=? AND e.active=1 ORDER BY e.id");$s->execute([$oid]);$emps=$s->fetchAll();
  $s=$db->prepare("SELECT * FROM staff_shifts WHERE object_id=? AND active=1 ORDER BY id");$s->execute([$oid]);$sh=$s->fetchAll();
  $s=$db->prepare("SELECT se.employee_id,se.work_date,se.shift_id,se.note FROM staff_schedule_entries se WHERE se.object_id=? AND se.work_date BETWEEN ? AND ?");$s->execute([$oid,$start,$end]);$entries=$s->fetchAll();
  json_response(['employees'=>$emps,'shifts'=>$sh,'entries'=>$entries,'days'=>(int)(new DateTimeImmutable($start))->format('t')]);exit;
 }
 if($action==='cell'&&$method==='POST'){
  $b=body();$oid=(int)$b['object_id'];$eid=(int)$b['employee_id'];$date=$b['work_date'];$sid=($b['shift_id']??'')===''?null:(int)$b['shift_id'];
  if($sid===null){$s=$db->prepare("DELETE FROM staff_schedule_entries WHERE object_id=? AND employee_id=? AND work_date=?");$s->execute([$oid,$eid,$date]);}
  else{$s=$db->prepare("INSERT INTO staff_schedule_entries(object_id,employee_id,work_date,shift_id,note) VALUES(?,?,?,?,?) ON CONFLICT(employee_id,work_date) DO UPDATE SET object_id=excluded.object_id,shift_id=excluded.shift_id,note=excluded.note,updated_at=CURRENT_TIMESTAMP");$s->execute([$oid,$eid,$date,$sid,trim($b['note']??'')]);}
  json_response(['ok'=>true]);exit;
 }
 json_response(['error'=>'Непозната операция'],404);
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();json_response(['error'=>$e->getMessage()],400);}
