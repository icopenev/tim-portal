<?php
function inspection_flow(): array {return ['Подадена за оглед','Приета','Направен оглед','Направена оферта','Изпратена на клиента','Приета оферта','Данни за договор','Проверка в склада','Предадена за изпълнение','Уговорена','Изпълнена','Отразена в настъпили промени','Корекция след монтаж'];}
function canonical_inspection(string $s): string {return ['Оглед насрочен'=>'Приета','Насрочен оглед'=>'Приета','Приета за оглед'=>'Приета','Оглед извършен'=>'Направен оглед'][$s]??$s;}
function inspection_options(string $s): array {$s=canonical_inspection($s);$flow=inspection_flow();$i=array_search($s,$flow,true);$o=[$s];if($i!==false&&isset($flow[$i+1]))$o[]=$flow[$i+1];if($s==='Изпратена на клиента')$o[]='Неприета оферта';return $o;}
function repair_flow(): array {return ['Приета','Проверка от технически отдел','Проверка в склад','Проверка на вложени материали','За плащане','Платена / Завършена'];}
function canonical_repair(string $s): string {return $s==='Проверка'?'Проверка от технически отдел':$s;}
function repair_options(string $s): array {$s=canonical_repair($s);$f=repair_flow();$i=array_search($s,$f,true);$o=[$s];if($i!==false&&isset($f[$i+1]))$o[]=$f[$i+1];if(!in_array($s,['Платена / Завършена','Отказана'],true))$o[]='Отказана';return array_values(array_unique($o));}
function service_options(string $s): array {$f=['Приета','Проверка','Готова за изпълнение','В изпълнение','Приключена'];$i=array_search($s,$f,true);$o=[$s];if($i!==false&&isset($f[$i+1]))$o[]=$f[$i+1];if(!in_array($s,['Приключена','Отказана'],true))$o[]='Отказана';return array_values(array_unique($o));}
function workflow_schema(PDO $db): void {
 $columns=array_column($db->query('PRAGMA table_info(service_requests)')->fetchAll(PDO::FETCH_ASSOC),'name');
 foreach(['client_kind','company_legal_name','registered_address','representative','registry_checked_at','offer_sent_to','offer_sent_at','decision_note','execution_date','execution_time','execution_team','equipment_ordered_at','corrected_offer_pdf'] as $name)if(!in_array($name,$columns,true))$db->exec("ALTER TABLE service_requests ADD COLUMN $name TEXT NOT NULL DEFAULT ''");
}
function validate_inspection_step(array $old,array $new): void {
 $s=canonical_inspection($new['stage']);$before=canonical_inspection($old['stage']);
 if(!in_array($s,inspection_options($before),true))throw new RuntimeException('Не може да се прескача етап. Завърши следващата стъпка.');
 if($s===$before)return;
 $required=match($s){
 'Приета'=>['client'=>'Клиент','phone'=>'Телефон','address'=>'Адрес'],
 'Направен оглед'=>['colleague'=>'Извършващ огледа','visit_at'=>'Дата на оглед'],
 'Направена оферта'=>['offer_ref'=>'Номер/описание на офертата','offer_pdf'=>'PDF оферта'],
 'Изпратена на клиента'=>['offer_sent_to'=>'Получател/начин на изпращане','offer_sent_at'=>'Дата на изпращане'],
 'Приета оферта','Неприета оферта'=>['decision_note'=>'Потвърждение на решението на клиента'],
 'Данни за договор'=>['client_kind'=>'Вид клиент','object_number'=>'№ на обект','authorized_persons'=>'Оторизирани лица','address'=>'Адрес на обекта','phone'=>'Телефон'],
 'Предадена за изпълнение'=>[],
 'Уговорена'=>['execution_date'=>'Дата на изпълнение','execution_time'=>'Час','execution_team'=>'Екип'],
 'Корекция след монтаж'=>[],
 default=>[]};
 if($s==='Данни за договор'&&($new['client_kind']??'')==='company')$required+=['company_id'=>'ЕИК','company_legal_name'=>'Фирма по регистър','representative'=>'Представляващ'];
 $missing=[];foreach($required as $key=>$label)if(trim((string)($new[$key]??''))==='')$missing[]=$label;if($missing)throw new RuntimeException('Липсва: '.implode(', ',$missing).'.');
 if($s==='Данни за договор'&&!in_array($new['client_kind'],['person','company'],true))throw new RuntimeException('Избери физическо лице или фирма.');
 if($s==='Данни за договор'&&$new['client_kind']==='company'&&!preg_match('/^(?:[0-9]{9}|[0-9]{13})$/',$new['company_id']))throw new RuntimeException('ЕИК трябва да е 9 или 13 цифри.');
 if(($new['warehouse_status']??'')==='Поръчана'&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($new['equipment_ordered_at']??'')))throw new RuntimeException('Посочи датата, на която е поръчана техниката.');
 if($s==='Предадена за изпълнение'&&($new['warehouse_status']??'')!=='Налична')throw new RuntimeException('За предаване към изпълнение наличието на техника трябва да е „Налична“.');
 if($s==='Уговорена'&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$new['execution_date'])||!preg_match('/^\d{2}:\d{2}$/',$new['execution_time'])))throw new RuntimeException('Невалидна дата/час за изпълнение.');
}
function workflow_event(PDO $db,int $id,string $text): void {$db->prepare('INSERT INTO request_events(request_id,actor,description) VALUES(?,?,?)')->execute([$id,$_SESSION['username']??'system',$text]);}
function reflect_inspection(PDO $db,array $r): void {
 $id=(int)$r['id'];$date=$r['execution_date']?:date('Y-m-d');
 $cols=array_column($db->query('PRAGMA table_info(objects)')->fetchAll(PDO::FETCH_ASSOC),'name');
 foreach(['client_name','company_id','representative','phone'] as $c)if(!in_array($c,$cols,true))$db->exec("ALTER TABLE objects ADD COLUMN $c TEXT NOT NULL DEFAULT ''");
 $clientName=trim((string)(($r['client_kind']??'')==='company'?($r['company_legal_name']??''):'') )?:trim((string)($r['client']??''));
 $db->prepare('INSERT INTO objects(id,status,number,address,persons,date,client_name,company_id,representative,phone) VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET status=excluded.status,number=excluded.number,address=excluded.address,persons=excluded.persons,date=excluded.date,client_name=excluded.client_name,company_id=excluded.company_id,representative=excluded.representative,phone=excluded.phone')->execute(['request-'.$id,'active',$r['object_number'],$r['address'],$r['authorized_persons'],$date,$clientName,$r['company_id']??'',$r['representative']??'',$r['phone']??'']);
 if($r['stage']!=='Отразена в настъпили промени'){
  $db->prepare('INSERT INTO changes(month,when_text,description) VALUES(?,?,?)')->execute([substr($date,0,7),date('d.m.Y H:i'),'Заявка #'.$id.' · Изпълнена · Нов обект №'.$r['object_number']]);
  $db->prepare("UPDATE service_requests SET stage='Отразена в настъпили промени',status='Отразена в настъпили промени',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
  workflow_event($db,$id,'Изпълнена → Отразена в настъпили промени');
 }
}

function validate_inspection(array $old,array $new): void {
 $s=canonical_inspection($new['stage']);
 if(!in_array($s,inspection_options($old['stage']),true))throw new RuntimeException('Не може да се прескача етап.');
 $flow=inspection_flow();$index=array_search($s==='Неприета оферта'?'Изпратена на клиента':$s,$flow,true);
 if($index===false)throw new RuntimeException('Невалиден етап.');
 for($i=1;$i<=$index;$i++)validate_inspection_step(array_merge($old,['stage'=>$flow[$i-1]]),array_merge($new,['stage'=>$flow[$i]]));
 if($s==='Неприета оферта')validate_inspection_step(array_merge($old,['stage'=>'Изпратена на клиента']),$new);
}

function rollback_inspection(PDO $db,int $id): void {
 $q=$db->prepare('SELECT * FROM service_requests WHERE id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
 if(!$r||$r['request_type']!=='Оглед')throw new RuntimeException('Заявката не е за оглед.');
 $stage=canonical_inspection($r['stage']);$flow=inspection_flow();$i=array_search($stage,$flow,true);
 if($stage==='Неприета оферта'){$target='Изпратена на клиента';$targetIndex=4;}
 elseif($i!==false&&$i>0){$target=$flow[$i-1];$targetIndex=$i-1;}
 else throw new RuntimeException('Това е първата стъпка.');
 $db->exec("CREATE TABLE IF NOT EXISTS workflow_archives (id INTEGER PRIMARY KEY AUTOINCREMENT,request_id INTEGER NOT NULL,kind TEXT NOT NULL,source_id TEXT NOT NULL,payload TEXT NOT NULL,archived_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
 $archive=function(string $kind,string $source,array $data)use($db,$id){$db->prepare('INSERT INTO workflow_archives(request_id,kind,source_id,payload) VALUES(?,?,?,?)')->execute([$id,$kind,$source,json_encode($data,JSON_UNESCAPED_UNICODE)]);};
 if($stage==='Отразена в настъпили промени'){
  $q=$db->prepare('SELECT * FROM objects WHERE id=?');$q->execute(['request-'.$id]);$object=$q->fetch(PDO::FETCH_ASSOC);
  if($object){$archive('object',$object['id'],$object);$db->prepare('DELETE FROM objects WHERE id=?')->execute([$object['id']]);}
  $description='Заявка #'.$id.' · Изпълнена · Нов обект №'.$r['object_number'];
  $q=$db->prepare('SELECT * FROM changes WHERE description=?');$q->execute([$description]);foreach($q as $c)$archive('change',(string)$c['id'],$c);
  $db->prepare('DELETE FROM changes WHERE description=?')->execute([$description]);
 }
 if($r['montage_job_id']!==''){
  $q=$db->prepare('SELECT * FROM jobs WHERE id=?');$q->execute([$r['montage_job_id']]);$job=$q->fetch(PDO::FETCH_ASSOC);
  if($job){
   if($targetIndex<8){$archive('job',$job['id'],$job);$db->prepare('DELETE FROM jobs WHERE id=?')->execute([$job['id']]);}
   else{$j=json_decode($job['data'],true);$archive('job_state',$job['id'],$job);if($targetIndex<10)$j['completed']=false;if($targetIndex<9){$j['date']='';$j['time']='';$j['team']='';}$db->prepare('UPDATE jobs SET data=? WHERE id=?')->execute([json_encode($j,JSON_UNESCAPED_UNICODE),$job['id']]);}
  }
 }
 $db->prepare('UPDATE service_requests SET stage=?,status=?,montage_job_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$target,$target,$targetIndex<8?'':$r['montage_job_id'],$id]);
 workflow_event($db,$id,'Връщане назад: '.$stage.' → '.$target);
}
