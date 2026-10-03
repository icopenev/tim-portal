<?php
require __DIR__.'/auth.php'; require_auth();
require_once __DIR__.'/request-workflow.php';
$db=app_db(); workflow_schema($db);
$types=['Ремонт/Обслужване','Оглед','Изключване','Преместване'];
$inspectionStages=['Приета','Приета за оглед','Направен оглед','Направена оферта','Приета оферта','Неприета оферта','Проверка в склада','Предадена за изпълнение','Уговорена','Изпълнена'];
$serviceStages=['Приета','Проверка','Готова за изпълнение','В изпълнение','Приключена','Отказана'];
function e($value): string {return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id){http_response_code(400);exit('Невалидна заявка.');}
$q=$db->prepare('SELECT * FROM service_requests WHERE id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!empty($_SESSION['request_form_data'])&&is_array($_SESSION['request_form_data'])){$posted=$_SESSION['request_form_data'];unset($_SESSION['request_form_data']);foreach($posted as $key=>$value)if(is_string($value)&&array_key_exists($key,$r)&&!in_array($key,['id','request_type','stage','status','created_at','updated_at','montage_job_id'],true))$r[$key]=$value;}
if(!$r){http_response_code(404);exit('Заявката не е намерена.');}
?>
<!doctype html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Заявка #<?=e($r['id'])?></title>
<style>:root{font-family:Inter,Segoe UI,Arial,sans-serif;background:#eff4f6;color:#173447}*{box-sizing:border-box}body{margin:0}.top{background:#183346;color:#fff;padding:16px max(20px,calc((100vw - 900px)/2));display:flex;justify-content:space-between}.top a{color:#fff;text-decoration:none}.wrap{max-width:900px;margin:auto;padding:30px 20px}.card{background:#fff;border:1px solid #d9e4e9;border-radius:14px;padding:24px;box-shadow:0 6px 24px #1938490a}.tag{display:inline-block;padding:4px 8px;border-radius:6px;background:#e2f1f5;color:#316b83;font-size:12px;font-weight:700}.pending{background:#fff0d9;color:#895d16}.check{display:flex;justify-content:space-between;border-top:1px solid #e5edf0;padding:10px 0}.flow{background:#f3f7f9;border-radius:8px;padding:12px;margin:14px 0}.success{background:#e7f5ec;padding:12px;border-radius:8px}label{display:block;margin:12px 0;font-weight:650}input,select,textarea{display:block;width:100%;margin-top:5px;padding:10px;border:1px solid #cbd9df;border-radius:7px;font:inherit}textarea{min-height:90px}.primary{background:#246b8d;color:#fff;border:0;border-radius:7px;padding:11px 17px;font-weight:750;cursor:pointer}.muted,.history{color:#6c8290;font-size:13px}details{margin-top:18px}summary{cursor:pointer;font-weight:750}</style></head><body>
<header class="top"><strong>INFO · Активна заявка</strong><nav><a href="/">Начало</a> · <a href="/requests.php#active-requests">← Активни заявки</a></nav></header><main class="wrap"><div class="muted" style="margin:0 0 12px"><strong>Подал заявката:</strong> <?=e($r['submitted_by']??'—')?> · <strong>Подадена:</strong> <?=e(isset($r['created_at']) ? date('d-m-Y H:i',strtotime($r['created_at'].' UTC')) : '—')?></div>
<?php $request_detail=true; $view=$r['request_type']==='Оглед'?'/request-process-table.php':($r['request_type']==='Ремонт/Обслужване'?'/service-process-table.php':'/zayavki-card.php'); include __DIR__.$view; ?>
</main></body></html>
