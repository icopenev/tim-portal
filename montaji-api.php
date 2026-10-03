<?php
require __DIR__ . '/auth.php';
require_auth(true);
try {
    require_once __DIR__.'/request-workflow.php';
    $db = app_db(); workflow_schema($db);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $jobs = [];
        foreach ($db->query('SELECT data FROM jobs ORDER BY rowid') as $row) $jobs[] = json_decode($row['data'],true);
        $changes = [];
        foreach ($db->query('SELECT month,when_text,description FROM changes ORDER BY id') as $row) $changes[] = ['month'=>$row['month'],'when'=>$row['when_text'],'text'=>$row['description']];
        json_response(['jobs'=>$jobs,'changes'=>$changes]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_response(['error'=>'Method not allowed'],405); exit; }
    $raw = file_get_contents('php://input');
    if (strlen($raw)>2*1024*1024) { json_response(['error'=>'Too large'],413); exit; }
    $data = json_decode($raw,true);
    if (!is_array($data) || !isset($data['jobs'],$data['changes']) || !is_array($data['jobs']) || !array_is_list($data['jobs']) || !is_array($data['changes']) || !array_is_list($data['changes'])) {
        json_response(['error'=>'Invalid data'],400); exit;
    }
    foreach ($data['jobs'] as $j) if (!is_array($j) || !isset($j['id'],$j['no'])) { json_response(['error'=>'Invalid job'],400); exit; }
    foreach ($data['changes'] as $c) if (!is_array($c) || !isset($c['month'],$c['when'],$c['text'])) { json_response(['error'=>'Invalid change'],400); exit; }
    $db->beginTransaction();
    // An open calendar may submit an older snapshot after an offer is accepted.
    // Keep newly created ERP jobs that are absent from that snapshot.
    $incomingIds=[];
    foreach($data['jobs'] as $j)$incomingIds[(string)$j['id']]=true;
    foreach($db->query('SELECT id,data FROM jobs') as $row) {
        if(isset($incomingIds[$row['id']]))continue;
        $existing=json_decode($row['data'],true);
        if(is_array($existing)&&isset($existing['source_request_id']))$data['jobs'][]=$existing;
    }
    // Linked inspection jobs follow the same server-side workflow.
    foreach($data['jobs'] as &$j){
        $lookup=$db->prepare('SELECT data FROM jobs WHERE id=?');$lookup->execute([(string)$j['id']]);$stored=json_decode($lookup->fetchColumn()?:'null',true);
        if(!$stored&&(isset($j['source_request_id'])||($j['type']??'')==='Нови обекти'))throw new RuntimeException('Задачата трябва да бъде създадена през заявката.');
        if(!$stored||!isset($stored['source_request_id']))continue;
        $request=$db->prepare('SELECT * FROM service_requests WHERE id=?');$request->execute([$stored['source_request_id']]);$r=$request->fetch(PDO::FETCH_ASSOC);
        if(!$r)continue;
        if($r['request_type']==='Ремонт/Обслужване'){
            $j['source_request_id']=$stored['source_request_id'];
            foreach(['object_number','client','phone','address','problem','description','technical_opinion','required_materials','request_type','name','no','type','equipment'] as $key)if(isset($stored[$key]))$j[$key]=$stored[$key];
            $stage=canonical_repair($r['stage']);
            if(!in_array($stage,['Проверка в склад','В график изпълнение','Проверка на вложени материали','За плащане','Платена / Завършена'],true)){ if(in_array($stage,['Приета','Проверка от технически отдел'],true))continue; throw new RuntimeException('Невалиден етап на ремонтната заявка.'); }
            $changedSchedule=($j['date']??'')!==($stored['date']??'')||($j['time']??'')!==($stored['time']??'')||($j['team']??'')!==($stored['team']??'');
            if(in_array($stage,['Проверка на вложени материали','За плащане','Платена / Завършена'],true)){
                if(empty($j['completed'])||$changedSchedule)throw new RuntimeException('Приключен ремонт не може да се връща или пренасрочва от графика.');
                continue;
            }
            if(!empty($j['completed'])&&empty($stored['completed'])){
                if(empty($j['date'])||empty($j['time'])||empty($j['team']))throw new RuntimeException('Първо насрочи дата, час и екип.');
                $db->prepare("UPDATE service_requests SET stage='Проверка на вложени материали',status='Проверка на вложени материали',execution_date=?,execution_time=?,execution_team=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$j['date'],$j['time'],$j['team'],$r['id']]);
                workflow_event($db,(int)$r['id'],'График изпълнение: техниците приключиха → Проверка на вложени материали');
            }elseif(!empty($j['date'])){
                if(empty($j['time'])||empty($j['team']))throw new RuntimeException('Попълни дата, час и техник.');
                $db->prepare("UPDATE service_requests SET execution_date=?,execution_time=?,execution_team=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$j['date'],$j['time'],$j['team'],$r['id']]);
            }
            continue;
        }
        if($r['request_type']!=='Оглед')continue;
        $j['source_request_id']=$stored['source_request_id'];
        foreach(['object_number','company_id','authorized_persons','address','request_type','offer_ref','name','no','type','equipment'] as $key)if(isset($stored[$key]))$j[$key]=$stored[$key];
        $stage=canonical_inspection($r['stage']);
        if(!in_array($stage,['Предадена за изпълнение','Уговорена','Изпълнена','Отразена в настъпили промени'],true))throw new RuntimeException('Заявката не е предадена за изпълнение.');
        if(!empty($j['date'])){foreach($stored['equipment']??[] as $i=>$item)if(empty($j['checked'][$i]))throw new RuntimeException('Техниката трябва да е налична преди уговаряне или изпълнение.');}
        $changedSchedule=($j['date']??'')!==($stored['date']??'')||($j['time']??'')!==($stored['time']??'')||($j['team']??'')!==($stored['team']??'');
        if(in_array($stage,['Изпълнена','Отразена в настъпили промени'],true)){
            if(empty($j['completed'])||$changedSchedule)throw new RuntimeException('Изпълнена заявка не може да се връща или пренасрочва.');
            continue;
        }
        if(!empty($j['completed'])&&empty($stored['completed'])){
            if($stage!=='Уговорена'||$changedSchedule)throw new RuntimeException('Първо запази уговорената дата, час и екип.');
            $db->prepare("UPDATE service_requests SET stage='Изпълнена',status='Изпълнена',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$r['id']]);
            workflow_event($db,(int)$r['id'],'Уговорена → Изпълнена');
            $r['stage']='Изпълнена';reflect_inspection($db,$r);
        }elseif(!empty($j['date'])){
            $new=array_merge($r,['stage'=>'Уговорена','execution_date'=>$j['date'],'execution_time'=>$j['time']??'','execution_team'=>$j['team']??'']);
            if($stage==='Предадена за изпълнение')validate_inspection($r,$new);
            if(empty($j['time'])||empty($j['team']))throw new RuntimeException('Попълни дата, час и техник.');
            $db->prepare("UPDATE service_requests SET stage='Уговорена',status='Уговорена',execution_date=?,execution_time=?,execution_team=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$j['date'],$j['time'],$j['team'],$r['id']]);
            if($stage!=='Уговорена')workflow_event($db,(int)$r['id'],'Предадена за изпълнение → Уговорена');
        }elseif($stage==='Уговорена')throw new RuntimeException('Не може да се премахва уговорената дата.');
    }
    unset($j);
    $db->exec('DELETE FROM jobs');
    $stmt = $db->prepare('INSERT INTO jobs(id,number,data) VALUES(?,?,?)');
    foreach ($data['jobs'] as $j) $stmt->execute([(string)$j['id'],(string)$j['no'],json_encode($j,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)]);
    $db->exec('DELETE FROM changes');
    $stmt = $db->prepare('INSERT INTO changes(month,when_text,description) VALUES(?,?,?)');
    foreach ($data['changes'] as $c) $stmt->execute([(string)$c['month'],(string)$c['when'],(string)$c['text']]);
    // Completion records are authoritative even when the calendar sent an older change list.
    foreach($db->query("SELECT * FROM service_requests WHERE request_type='Оглед' AND stage='Отразена в настъпили промени'") as $r){
        $text='Заявка #'.$r['id'].' · Изпълнена · Нов обект №'.$r['object_number'];
        $exists=$db->prepare('SELECT 1 FROM changes WHERE description=?');$exists->execute([$text]);
        if(!$exists->fetchColumn())$db->prepare('INSERT INTO changes(month,when_text,description) VALUES(?,?,?)')->execute([substr($r['execution_date']?:date('Y-m-d'),0,7),date('d.m.Y H:i'),$text]);
    }
    $db->commit();
    json_response(['success'=>true]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('montaji API: '.$e->getMessage());
    json_response(['error'=>$e instanceof RuntimeException?$e->getMessage():'Database error'],$e instanceof RuntimeException?422:500);
}
