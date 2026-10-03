<?php
require __DIR__.'/auth.php';
require_once __DIR__.'/owncloud-storage.php'; require_auth();
require_once __DIR__.'/request-workflow.php';
$db=app_db(); workflow_schema($db);
$db->exec("CREATE TABLE IF NOT EXISTS service_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, client TEXT NOT NULL, phone TEXT NOT NULL DEFAULT '', address TEXT NOT NULL DEFAULT '', service TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '', colleague TEXT NOT NULL DEFAULT '', visit_at TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'Приета', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$columns=array_column($db->query('PRAGMA table_info(service_requests)')->fetchAll(),'name');
foreach (['request_type'=>"TEXT NOT NULL DEFAULT 'Оглед'",'warehouse_status'=>"TEXT NOT NULL DEFAULT 'Чака'",'debt_status'=>"TEXT NOT NULL DEFAULT 'Чака'",'return_status'=>"TEXT NOT NULL DEFAULT 'Чака'",'stage'=>"TEXT NOT NULL DEFAULT 'Приета'",'offer_ref'=>"TEXT NOT NULL DEFAULT ''",'offer_amount'=>"TEXT NOT NULL DEFAULT ''",'offer_equipment'=>"TEXT NOT NULL DEFAULT ''",'montage_job_id'=>"TEXT NOT NULL DEFAULT ''",'object_number'=>"TEXT NOT NULL DEFAULT ''",'company_id'=>"TEXT NOT NULL DEFAULT ''",'authorized_persons'=>"TEXT NOT NULL DEFAULT ''",
'offer_pdf'=>"TEXT NOT NULL DEFAULT ''",'client_email'=>"TEXT NOT NULL DEFAULT ''",'technical_opinion'=>"TEXT NOT NULL DEFAULT ''",'move_from_address'=>"TEXT NOT NULL DEFAULT ''",'move_to_address'=>"TEXT NOT NULL DEFAULT ''"] as $column=>$definition) if (!in_array($column,$columns,true)) $db->exec("ALTER TABLE service_requests ADD COLUMN $column $definition");
$db->exec("UPDATE service_requests SET stage=status WHERE request_type='Оглед' AND stage='Приета' AND status<>'Приета'");
$db->exec("UPDATE service_requests SET stage='Направен оглед',status='Направен оглед' WHERE request_type='Оглед' AND stage='Оглед извършен'");
$db->exec("UPDATE service_requests SET stage='Насрочен оглед',status='Насрочен оглед' WHERE request_type='Оглед' AND stage='Оглед насрочен'");
$db->exec("CREATE TABLE IF NOT EXISTS request_events (id INTEGER PRIMARY KEY AUTOINCREMENT, request_id INTEGER NOT NULL, actor TEXT NOT NULL, description TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
foreach (['submitted_by_user_id'=>"INTEGER NOT NULL DEFAULT 0",'submitted_by'=>"TEXT NOT NULL DEFAULT ''"] as $column=>$definition) { $cols=$db->query('PRAGMA table_info(service_requests)')->fetchAll(PDO::FETCH_COLUMN,1); if(!in_array($column,$cols,true))$db->exec("ALTER TABLE service_requests ADD COLUMN $column $definition"); }
$types=['Ремонт/Обслужване','Оглед','Изключване','Преместване']; $db->exec("UPDATE service_requests SET request_type='Ремонт/Обслужване' WHERE request_type='Ремонт'");
$inspectionStages=['Приета','Приета за оглед','Направен оглед','Направена оферта','Приета оферта','Неприета оферта','Проверка в склада','Предадена за изпълнение','Уговорена','Изпълнена'];
$serviceStages=['Приета','Проверка','Готова за изпълнение','В изпълнение','Приключена','Отказана'];
function e($value): string {return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function queue_for(array $r): string {
    if (in_array($r['stage'],['Приключена','Отказана','Неприета оферта','Изпълнена','Отразена в настъпили промени'],true)) return 'Приключени';
    if ($r['request_type']==='Оглед') { $st=canonical_inspection($r['stage']); if(in_array($st,['Подадена за оглед','Приета','Направен оглед','Направена оферта','Изпратена на клиента'],true))return 'За колега'; if(in_array($st,['Приета оферта','Данни за договор','Проверка в склада'],true))return 'За проверка'; return 'За изпълнение'; }
    if ($r['warehouse_status']!=='Проверено' || ($r['request_type']==='Ремонт/Обслужване' && $r['debt_status']!=='Проверено') || ($r['request_type']==='Изключване' && $r['return_status']==='Чака')) return 'За проверка';
    return 'За изпълнение';
}
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals(csrf_token(),(string)($_POST['csrf']??''))) {$error='Невалидна заявка. Обнови страницата.';http_response_code(400);}
    else try {
        $action=(string)($_POST['action']??'');
        if ($action==='create') {
            $type=(string)($_POST['request_type']??'');$client=trim((string)($_POST['client']??''));$service=trim((string)($_POST['service']??''));
            $phone=trim((string)($_POST['phone']??''));$clientEmail=trim((string)($_POST['client_email']??''));$address=trim((string)($_POST['address']??''));$moveFrom=trim((string)($_POST['move_from_address']??''));$moveTo=trim((string)($_POST['move_to_address']??''));$notes=trim((string)($_POST['notes']??''));$objectNumber=trim((string)($_POST['object_number']??''));
            if (!in_array($type,$types,true)||$client===''||strlen($client)>240||strlen($phone)>120||strlen($clientEmail)>190||($type==='Оглед'&&$clientEmail!==''&&!filter_var($clientEmail,FILTER_VALIDATE_EMAIL))||strlen($address)>500||strlen($moveFrom)>500||strlen($moveTo)>500||($type==='Преместване'&&($moveFrom===''||$moveTo===''))||strlen($service)>320||strlen($notes)>4000) throw new RuntimeException('Попълни валидни данни за заявката.');
            if(in_array($type,['Ремонт/Обслужване','Преместване','Изключване'],true)&&$objectNumber==='') throw new RuntimeException('Въведи № на обект за този вид заявка.');
            if(strlen($objectNumber)>80) throw new RuntimeException('Невалиден № на обект.');
            $db->beginTransaction();
            $submitterId=(int)($_SESSION['user_id']??0);$submitter=(string)($_SESSION['username']??'');$db->prepare('INSERT INTO service_requests(request_type,client,phone,client_email,address,move_from_address,move_to_address,service,notes,object_number,status,stage,submitted_by_user_id,submitted_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$type,$client,$phone,$clientEmail,$address,$moveFrom,$moveTo,$service,$notes,$objectNumber,$type==='Оглед'?'Подадена за оглед':'Приета',$type==='Оглед'?'Подадена за оглед':'Приета',$submitterId,$submitter]);
            $id=(int)$db->lastInsertId();$db->prepare('INSERT INTO request_events(request_id,actor,description) VALUES(?,?,?)')->execute([$id,$_SESSION['username']??'admin','Заявката е приета: '.$type]);
            $db->commit();
        } elseif ($action==='rollback') {
            if(!is_supervisor())throw new RuntimeException('Само Admin или Супервайзор може да връща стъпки.');
            $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);if(!$id)throw new RuntimeException('Невалидна заявка.');
            $db->beginTransaction();rollback_inspection($db,$id);$db->commit();
        } elseif ($action==='supervisor_delete') {
            if(!is_supervisor())throw new RuntimeException('Само Admin или Супервайзор може да изтрива заявки.');
            $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);if(!$id)throw new RuntimeException('Невалидна заявка.');
            $q=$db->prepare('SELECT * FROM service_requests WHERE id=?');$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);if(!$old)throw new RuntimeException('Заявката не е намерена.');
            $db->beginTransaction();
            $db->prepare('DELETE FROM objects WHERE id=?')->execute(['request-'.$id]);
            $db->prepare("DELETE FROM changes WHERE description LIKE ?")->execute(['Заявка #'.$id.' ·%']);
            if(!empty($old['montage_job_id']))$db->prepare('DELETE FROM jobs WHERE id=?')->execute([$old['montage_job_id']]);
            $db->prepare('DELETE FROM request_events WHERE request_id=?')->execute([$id]);
            $db->prepare('DELETE FROM service_requests WHERE id=?')->execute([$id]);
            $db->commit();
        } elseif ($action==='supervisor_stage') {
            if(!is_supervisor())throw new RuntimeException('Само Admin или Супервайзор може да прескача стъпки.');
            $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);if(!$id)throw new RuntimeException('Невалидна заявка.');
            $q=$db->prepare('SELECT request_type,stage FROM service_requests WHERE id=?');$q->execute([$id]);$req=$q->fetch(PDO::FETCH_ASSOC);if(!$req)throw new RuntimeException('Заявката не е намерена.');
            if($req['request_type']==='Ремонт/Обслужване'){$target=canonical_repair((string)($_POST['target_stage']??''));$valid=repair_flow();}else{$target=canonical_inspection((string)($_POST['target_stage']??''));$valid=inspection_flow();}
            if(!in_array($target,$valid,true))throw new RuntimeException('Избери валидна стъпка.');
            $from=$req['stage'];
            $db->beginTransaction();
            $db->prepare('UPDATE service_requests SET stage=?,status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$target,$target,$id]);
            $db->prepare('INSERT INTO request_events(request_id,actor,description) VALUES(?,?,?)')->execute([$id,$_SESSION['username']??'supervisor','Промяна на етап: '.$from.' → '.$target]);
            $db->commit();
        } elseif ($action==='update') {
            $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);$stage=(string)($_POST['stage']??'');$technicalOpinion=trim((string)($_POST['technical_opinion']??''));$colleague=trim((string)($_POST['colleague']??''));$visit=trim((string)($_POST['visit_at']??''));
            $warehouse=(string)($_POST['warehouse_status']??'Чака');$debt=(string)($_POST['debt_status']??'Чака');$returns=(string)($_POST['return_status']??'Чака');
            if (!$id||strlen($colleague)>240||($visit!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',$visit))||!in_array($warehouse,['Чака','Проверено','Проблем','Има','Поръчана','Налична'],true)||!in_array($debt,['Чака','Проверено','Има задължения'],true)||!in_array($returns,['Чака','Няма техника за връщане','Има техника за връщане','Върната','Проверено'],true)) throw new RuntimeException('Невалидни данни.');
            $db->beginTransaction();$q=$db->prepare('SELECT * FROM service_requests WHERE id=?');$q->execute([$id]);$old=$q->fetch();if(!$old)throw new RuntimeException('Заявката не е намерена.');if($old['request_type']==='Оглед'&&!in_array(canonical_inspection($stage),inspection_options($old['stage']),true))throw new RuntimeException('Не може да се прескача етап.');
            $client=trim((string)($_POST['client']??$old['client']));$phone=trim((string)($_POST['phone']??$old['phone']));$address=trim((string)($_POST['address']??$old['address']));if($client===''||strlen($client)>240||strlen($phone)>120||strlen($address)>500)throw new RuntimeException('Невалидни данни за клиента.');
            $offerRef=trim((string)($_POST['offer_ref']??$old['offer_ref']));
            $offerAmount=$old['offer_amount'];
            $offerPdf=(string)($old['offer_pdf']??'');$correctedOfferPdf=(string)($old['corrected_offer_pdf']??'');
            $offerEquipment=trim((string)($_POST['offer_equipment']??$old['offer_equipment']));
            $objectNumber=trim((string)($_POST['object_number']??$old['object_number']));$companyId=trim((string)($_POST['company_id']??$old['company_id']));$authorizedPersons=trim((string)($_POST['authorized_persons']??$old['authorized_persons']));
            if(strlen($offerRef)>160||strlen($offerEquipment)>2000||strlen($objectNumber)>80||strlen($companyId)>40||strlen($authorizedPersons)>3000)throw new RuntimeException('Твърде дълги данни за офертата.');
            if(isset($_FILES['offer_pdf']) && ($_FILES['offer_pdf']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
                if($_FILES['offer_pdf']['error']!==UPLOAD_ERR_OK) throw new RuntimeException('PDF офертата не можа да бъде качена.');
                if((int)$_FILES['offer_pdf']['size']>10*1024*1024) throw new RuntimeException('PDF офертата трябва да е до 10 MB.');
                $tmp=(string)$_FILES['offer_pdf']['tmp_name'];
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                if($mime!=='application/pdf') throw new RuntimeException('Офертата трябва да бъде PDF файл.');
                $sq=$db->prepare("SELECT value FROM app_settings WHERE key='pdf_storage_path'");$sq->execute();$dir=trim((string)($sq->fetchColumn()?:'/var/www/html/uploads/offers')); if(!is_dir($dir)||!is_writable($dir)) throw new RuntimeException('PDF хранилището не е достъпно. Провери настройката в Администрация.');
                $name='offer-'.$id.'-'.date('YmdHis').'-'.bin2hex(random_bytes(4)).'.pdf'; $dest=$dir.'/'.$name;
                if(!move_uploaded_file($tmp,$dest)) throw new RuntimeException('PDF офертата не можа да бъде записана.');
                $offerPdf=str_starts_with($dir,'/var/www/html/')?substr($dest,strlen('/var/www/html')):$dest;
            }
            if(isset($_FILES['corrected_offer_pdf']) && ($_FILES['corrected_offer_pdf']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
                if($_FILES['corrected_offer_pdf']['error']!==UPLOAD_ERR_OK) throw new RuntimeException('Коригираната PDF оферта не можа да бъде качена.');
                if((int)$_FILES['corrected_offer_pdf']['size']>10*1024*1024) throw new RuntimeException('Коригираната PDF оферта трябва да е до 10 MB.');
                $tmp=(string)$_FILES['corrected_offer_pdf']['tmp_name'];$mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                if($mime!=='application/pdf') throw new RuntimeException('Коригираната оферта трябва да бъде PDF файл.');
                $sq=$db->prepare("SELECT value FROM app_settings WHERE key='pdf_storage_path'");$sq->execute();$dir=trim((string)($sq->fetchColumn()?:'/var/www/html/uploads/offers'));if(!is_dir($dir)||!is_writable($dir)) throw new RuntimeException('PDF хранилището не е достъпно.');
                $name='corrected-offer-'.$id.'-'.date('YmdHis').'-'.bin2hex(random_bytes(4)).'.pdf';$dest=$dir.'/'.$name;
                if(!move_uploaded_file($tmp,$dest)) throw new RuntimeException('Коригираната PDF оферта не можа да бъде записана.');
                $correctedOfferPdf=str_starts_with($dir,'/var/www/html/')?substr($dest,strlen('/var/www/html')):$dest;
            }
            $extra=[];
            foreach(['client_kind','company_legal_name','registered_address','representative','registry_checked_at','offer_sent_to','offer_sent_at','decision_note','execution_date','execution_time','execution_team','equipment_ordered_at'] as $field){$extra[$field]=trim((string)($_POST[$field]??$old[$field]??''));if(strlen($extra[$field])>2000)throw new RuntimeException('Твърде дълги данни.');}
            if($old['request_type']==='Оглед') {
                $stage=canonical_inspection($stage);
                $new=array_merge($old,$extra,['client'=>$client,'phone'=>$phone,'address'=>$address,'stage'=>$stage,'colleague'=>$colleague,'visit_at'=>$visit,'offer_ref'=>$offerRef,'offer_pdf'=>$offerPdf,'corrected_offer_pdf'=>$correctedOfferPdf,'object_number'=>$objectNumber,'company_id'=>$companyId,'authorized_persons'=>$authorizedPersons,'offer_equipment'=>$offerEquipment]);
                validate_inspection($old,$new);
                $debt=$old['debt_status'];$returns=$old['return_status'];
                if(canonical_inspection($old['stage'])!=='Проверка в склада')$warehouse=$old['warehouse_status'];
                if(in_array($stage,['Уговорена','Изпълнена','Отразена в настъпили промени'],true)&&$old['montage_job_id']==='')throw new RuntimeException('Липсва задача за изпълнение.');
            } elseif($old['request_type']==='Ремонт/Обслужване') {
                $stage=canonical_repair($stage);
                if(strlen($technicalOpinion)>3000)throw new RuntimeException('Мнението на техническия отдел е твърде дълго.');
                if($stage==='Проверка в склад'&&canonical_repair($old['stage'])==='Проверка от технически отдел'){
                    if($technicalOpinion==='')throw new RuntimeException('Въведи мнение на техническия отдел.');
                    if(trim($offerEquipment)==='')throw new RuntimeException('Опиши необходимите материали / техника.');
                }
                if($stage==='Готова за изпълнение'&&canonical_repair($old['stage'])==='Проверка в склад'){
                    if(trim($offerEquipment)==='')throw new RuntimeException('Опиши необходимата техника / материали.');
                    if($debt==='Чака')throw new RuntimeException('Направи проверка за задължения.');
                    if($debt==='Има задължения')throw new RuntimeException('Има задължения. Заявката не може да бъде предадена за изпълнение.');
                    if($warehouse!=='Проверено')throw new RuntimeException('Потвърди наличността на необходимата техника / материали.');
                }
                if(!in_array($stage,repair_options($old['stage']),true))throw new RuntimeException('Първо завърши предходния етап на заявката.');
            } elseif(!in_array($stage,service_options($old['stage']),true))throw new RuntimeException('Първо завърши предходния етап на заявката.');
            if($old['request_type']!=='Ремонт/Обслужване')$debt=$old['debt_status'];
            if($old['request_type']!=='Изключване')$returns=$old['return_status'];
            if($stage==='Приключена'&&$old['request_type']==='Изключване'&&$returns==='Има техника за връщане')throw new RuntimeException('Отбележи върната техника преди приключване.');
            if(in_array($stage,['Готова за изпълнение','В изпълнение','Приключена'],true)&&!in_array($old['request_type'],['Оглед','Ремонт/Обслужване'],true)&&($warehouse!=='Проверено'||($old['request_type']==='Изключване'&&$returns==='Чака'))) throw new RuntimeException('Първо завърши нужните проверки.');
            $jobId='';
            if($old['request_type']!=='Оглед' && $stage==='Готова за изпълнение' && $old['montage_job_id']==='') {
                $jobId=(int)floor(microtime(true)*1000); $check=$db->prepare('SELECT 1 FROM jobs WHERE id=?'); do {$check->execute([(string)$jobId]);if(!$check->fetchColumn())break;$jobId++;} while(true);
                $map=['Преместване'=>'Преместване на техника','Изключване'=>'Демонтаж','Ремонт/Обслужване'=>'Заявка']; $equipment=array_values(array_filter(array_map('trim',preg_split('/[,;\n]+/',$offerEquipment)),fn($x)=>$x!=='')); $job=['id'=>$jobId,'no'=>'З'.$id,'name'=>$old['client'].' · '.($old['service']?:$old['request_type']),'type'=>$map[$old['request_type']]??'Заявка','date'=>'','time'=>'','team'=>'','equipment'=>$equipment,'checked'=>array_fill(0,count($equipment),true),'source_request_id'=>$id,'request_type'=>$old['request_type'],'object_number'=>$old['object_number'],'client'=>$old['client'],'phone'=>$old['phone'],'address'=>$old['address'],'problem'=>$old['service'],'description'=>$old['notes'],'technical_opinion'=>$technicalOpinion,'required_materials'=>$offerEquipment]; $db->prepare('INSERT INTO jobs(id,number,data) VALUES(?,?,?)')->execute([(string)$jobId,$job['no'],json_encode($job,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)]);
                if($old['request_type']==='Ремонт/Обслужване')$stage='В график изпълнение';
            }
            if($old['request_type']==='Оглед'&&$stage==='Предадена за изпълнение'&&$old['montage_job_id']==='') {
                $jobId=(int)floor(microtime(true)*1000);
                $check=$db->prepare('SELECT 1 FROM jobs WHERE id=?');
                do {$check->execute([(string)$jobId]);if(!$check->fetchColumn())break;$jobId++;} while(true);
                $equipment=array_values(array_filter(array_map('trim',explode(',',$offerEquipment)),fn($x)=>$x!==''));
                $job=['id'=>$jobId,'no'=>'З'.$id,'name'=>$old['client'].' · '.($old['service']?:'Изпълнение след оглед'),'type'=>'Нови обекти','date'=>'','time'=>'','team'=>'','equipment'=>$equipment,'checked'=>array_fill(0,count($equipment),true),'source_request_id'=>$id,'request_type'=>'Оглед','address'=>$old['address'],'object_number'=>$objectNumber,'company_id'=>$companyId,'authorized_persons'=>$authorizedPersons,'offer_ref'=>$offerRef];
                $db->prepare('INSERT INTO jobs(id,number,data) VALUES(?,?,?)')->execute([(string)$jobId,$job['no'],json_encode($job,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)]);
            }
            $db->prepare('UPDATE service_requests SET stage=?,status=?,colleague=?,visit_at=?,warehouse_status=?,debt_status=?,return_status=?,offer_ref=?,offer_amount=?,offer_equipment=?,offer_pdf=?,corrected_offer_pdf=?,object_number=?,company_id=?,authorized_persons=?,montage_job_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$stage,$stage,$colleague,$visit,$warehouse,$debt,$returns,$offerRef,$offerAmount,$offerEquipment,$offerPdf,$correctedOfferPdf,$objectNumber,$companyId,$authorizedPersons,$jobId!==''?(string)$jobId:$old['montage_job_id'],$id]);
            $db->prepare('UPDATE service_requests SET client=?,phone=?,address=? WHERE id=?')->execute([$client,$phone,$address,$id]);
            if($old['request_type']==='Ремонт/Обслужване'&&$technicalOpinion!=='')$db->prepare('UPDATE service_requests SET technical_opinion=? WHERE id=?')->execute([$technicalOpinion,$id]);
            foreach($extra as $field=>$value)$db->prepare('UPDATE service_requests SET '.$field.'=? WHERE id=?')->execute([$value,$id]);
            if($old['request_type']==='Оглед'&&in_array($stage,['Уговорена','Изпълнена','Отразена в настъпили промени'],true)){
                $job=$db->prepare('SELECT data FROM jobs WHERE id=?');$job->execute([$old['montage_job_id']]);$j=json_decode($job->fetchColumn()?:'{}',true);
                $j['date']=$extra['execution_date'];$j['time']=$extra['execution_time'];$j['team']=$extra['execution_team'];$j['completed']=in_array($stage,['Изпълнена','Отразена в настъпили промени'],true);
                $db->prepare('UPDATE jobs SET data=? WHERE id=?')->execute([json_encode($j,JSON_UNESCAPED_UNICODE),$old['montage_job_id']]);
                $reflectCompleted=$j['completed'];
            }
            $actor=(string)($_SESSION['username']??'unknown');
            $db->prepare('INSERT INTO request_events(request_id,actor,description) VALUES(?,?,?)')->execute([$id,$actor,'Запис на данни по заявката']);
            if($old['request_type']==='Ремонт/Обслужване'&&canonical_repair($old['stage'])==='Проверка от технически отдел'&&$stage==='Проверка в склад')$db->prepare('INSERT INTO request_events(request_id,actor,description) VALUES(?,?,?)')->execute([$id,$actor,'Техническа проверка: '.$technicalOpinion.'; необходими материали/техника — '.$offerEquipment]);
            if($old['request_type']==='Ремонт/Обслужване'&&canonical_repair($old['stage'])==='Проверка в склад'&&$stage==='Готова за изпълнение')$db->prepare('INSERT INTO request_events(request_id,actor,description) VALUES(?,?,?)')->execute([$id,$actor,'Складова проверка: задължения — '.$debt.'; техника/материали — '.$warehouse.'; предадена директно в График изпълнение; материали — '.$offerEquipment]);
            foreach(['stage'=>'Етап','warehouse_status'=>'Склад','debt_status'=>'Задължения','return_status'=>'Връщане на техника','colleague'=>'Извършващ огледа','visit_at'=>'Дата на оглед'] as $key=>$label) { $new=['stage'=>$stage,'warehouse_status'=>$warehouse,'debt_status'=>$debt,'return_status'=>$returns,'colleague'=>$colleague,'visit_at'=>$visit][$key];if($old[$key]!==$new)$db->prepare('INSERT INTO request_events(request_id,actor,description) VALUES(?,?,?)')->execute([$id,$_SESSION['username']??'admin',$label.': '.($old[$key]?:'—').' → '.($new?:'—')]); }
            if($jobId!=='')$db->prepare('INSERT INTO request_events(request_id,actor,description) VALUES(?,?,?)')->execute([$id,$_SESSION['username']??'admin','Заявката е предадена за изпълнение и е добавена в График изпълнение като №З'.$id]);
            if(!empty($reflectCompleted))reflect_inspection($db,array_merge($old,$extra,['stage'=>$stage,'object_number'=>$objectNumber,'company_id'=>$companyId,'authorized_persons'=>$authorizedPersons]));
            $db->commit();
        } else throw new RuntimeException('Непознато действие.');
        header('Location: '.(in_array($action,['update','rollback','supervisor_stage'],true)?'/zayavka.php?id='.$id:'/'));exit;
    } catch(Throwable $ex) {if($db->inTransaction())$db->rollBack();$error=$ex instanceof RuntimeException?$ex->getMessage():'Записът не успя.';if(in_array(($action??''),['update','rollback','supervisor_stage'],true)&&!empty($id)){$_SESSION['request_error']=$error;if(($action??'')==='update'){$_SESSION['request_form_data']=$_POST;}header('Location: /zayavka.php?id='.(int)$id);exit;}}
}
$rows=$db->query('SELECT * FROM service_requests ORDER BY id DESC')->fetchAll();$queues=['За колега'=>[],'За проверка'=>[],'За изпълнение'=>[],'Приключени'=>[]];foreach($rows as $row)$queues[queue_for($row)][]=$row;
?><!doctype html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Заявки · INFO</title><style>
:root{font-family:Inter,Segoe UI,Arial,sans-serif;color:#173447;background:#f1f5f7}*{box-sizing:border-box}body{margin:0}button,input,select,textarea{font:inherit}button{cursor:pointer}.top{background:#183346;color:white;padding:15px max(18px,calc((100vw - 1450px)/2));display:flex;justify-content:space-between}.top b{color:#efb45d;font-size:22px}.top a{color:#d7e7ef;text-decoration:none}.wrap{max-width:1450px;margin:auto;padding:22px 18px}.intro{display:flex;justify-content:space-between;gap:16px;align-items:center}h1{margin:0;font-size:26px}.muted{color:#68818d;font-size:14px}.primary{background:#e9aa4b;border:0;border-radius:8px;color:#173447;font-weight:750;padding:10px 15px}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:11px;margin:18px 0}.metric,.card,.formbox{background:white;border:1px solid #dce6eb;border-radius:11px}.metric{padding:14px}.metric strong{font-size:25px;display:block}.metric span{font-size:13px;color:#637e8c}.board{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.lane{background:#e8eff2;border-radius:11px;padding:12px;min-height:250px}.lane h2{font-size:16px;margin:3px 3px 13px}.card:target{outline:3px solid #e9aa4b;scroll-margin-top:20px}.flow{font-size:12px;color:#547487;background:#edf5f8;padding:8px;border-radius:6px;margin:9px 0}.success{background:#e4f5e9;color:#24754e!important;padding:9px;border-radius:7px}.success a{color:#236f8f;font-weight:750}.card{padding:15px;margin-bottom:10px;box-shadow:0 4px 16px #1833460a}.card h3{margin:5px 0;font-size:17px}.card p{margin:6px 0;font-size:13px;color:#56717f}.tag{display:inline-block;padding:4px 8px;border-radius:5px;background:#e4f2f8;color:#326e89;font-size:12px;font-weight:750}.pending{background:#fff0d8;color:#8a5c13}.ok{background:#ddf2e5;color:#27754e}.bad{background:#fbe3e1;color:#a33e34}.check{display:flex;justify-content:space-between;gap:7px;margin:5px 0;font-size:13px}.card details{border-top:1px solid #e2eaed;margin-top:11px;padding-top:10px}.card summary{cursor:pointer;color:#256b8d;font-weight:700}.card label,.formbox label{display:block;font-size:13px;color:#506d7d;font-weight:700;margin:9px 0}.card input,.card select,.formbox input,.formbox select,.formbox textarea{display:block;width:100%;padding:9px;border:1px solid #cbdbe2;border-radius:7px;margin-top:4px;background:white;color:#173447}.formbox{padding:18px;margin:15px 0}.formbox[hidden]{display:none}.fields{display:grid;grid-template-columns:repeat(3,1fr);gap:0 14px}.formbox textarea{min-height:70px}.error{background:#fce5e2;color:#9d332b;border-radius:8px;padding:10px}.history{font-size:12px;color:#8298a3}.closed{margin-top:14px;min-height:80px}.empty{font-size:13px;color:#80949e}@media(max-width:1000px){.board{grid-template-columns:1fr 1fr}}@media(max-width:650px){.board,.fields{grid-template-columns:1fr}.summary{grid-template-columns:1fr 1fr}.intro{align-items:flex-start}.wrap{padding:16px 12px}}
</style><link rel="stylesheet" href="/assets/tim-theme.css?v=20261003"></head><body><header class="top"><b>INFO</b><nav><a href="/">Начало</a> · <a href="/montaji.php">График изпълнение</a></nav></header><main class="wrap"><div class="intro"><div><h1>Заявки за услуги</h1><p class="muted">Ремонт/Обслужване · Оглед · Изключване · Преместване</p></div></div><?php if($error): ?><p class="error"><?=e($error)?></p><?php endif; ?>
<form class="formbox" id="newForm" method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create"><h2>Нова заявка</h2><div class="fields"><label>Вид *<select name="request_type"><?php foreach($types as $type): ?><option <?=($_POST['request_type']??($_GET['type']??''))===$type?'selected':''?>><?=e($type)?></option><?php endforeach; ?></select></label><label id="objectNumberField">№ на обект *<input name="object_number" maxlength="80" value="<?=e($_POST['object_number']??'')?>" placeholder="Напр. 4830"></label><label>Клиент *<input name="client" required maxlength="120" value="<?=e($_POST['client']??'')?>"></label><label>Телефон<input name="phone" type="tel" maxlength="60" value="<?=e($_POST['phone']??'')?>"></label><label id="clientEmailField">Имейл на клиента<input name="client_email" type="email" maxlength="190" value="<?=e($_POST['client_email']??'')?>" placeholder="client@example.com"></label><label id="addressField">Адрес на обекта<input name="address" maxlength="250" value="<?=e($_POST['address']??'')?>"></label><label id="moveFromField">Адрес ОТ *<input name="move_from_address" maxlength="250" value="<?=e($_POST['move_from_address']??'')?>"></label><label id="moveToField">Адрес НА *<input name="move_to_address" maxlength="250" value="<?=e($_POST['move_to_address']??'')?>"></label><label>Услуга / проблем<input name="service" maxlength="160" value="<?=e($_POST['service']??'')?>"></label></div><label>Описание<textarea name="notes" maxlength="2000"><?=e($_POST['notes']??'')?></textarea></label><button class="primary">Приеми заявката</button></form>
</main><script>
const typeSelect=document.querySelector('[name=request_type]'), objectField=document.getElementById('objectNumberField'), objectInput=document.querySelector('[name=object_number]'), clientEmailField=document.getElementById('clientEmailField'), addressField=document.getElementById('addressField'), moveFromField=document.getElementById('moveFromField'), moveToField=document.getElementById('moveToField');
function syncObjectNumber(){const inspection=typeSelect.value==='Оглед',needed=!inspection;objectField.style.display=needed?'block':'none';objectInput.required=needed;clientEmailField.style.display=inspection?'block':'none';const moving=typeSelect.value==='Преместване';addressField.style.display=moving?'none':'block';moveFromField.style.display=moving?'block':'none';moveToField.style.display=moving?'block':'none';moveFromField.querySelector('input').required=moving;moveToField.querySelector('input').required=moving;}
typeSelect.addEventListener('change',syncObjectNumber);syncObjectNumber();
</script></body></html>
