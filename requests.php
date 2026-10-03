<?php
require __DIR__ . '/auth.php';
require_auth();
$db=app_db();
$active=[];
if($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='service_requests'")->fetchColumn()) {
    $active=$db->query("SELECT id,client,phone,address,service,request_type,stage,colleague,visit_at,warehouse_status,debt_status,return_status,updated_at FROM service_requests WHERE stage NOT IN ('Приключена','Отказана','Неприета оферта','Изпълнена','Отразена в настъпили промени') ORDER BY updated_at DESC,id DESC")->fetchAll(PDO::FETCH_ASSOC);
}
function out($v): string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function local_time(string $utc): string {return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Sofia'))->format('d.m.Y H:i');}
function canonical_stage(string $s): string {return ['Оглед насрочен'=>'Приета','Насрочен оглед'=>'Приета','Приета за оглед'=>'Приета','Оглед извършен'=>'Направен оглед'][$s]??$s;}
function next_step(array $r): string {
    if($r['request_type']==='Оглед') {


        if(in_array($r['stage'],['Направен оглед','Оглед извършен'],true)) return 'Чака изготвяне на оферта';
        return match(canonical_stage($r['stage'])){'Подадена за оглед'=>'Чака приемане','Направена оферта'=>'Чака изпращане на клиента','Изпратена на клиента'=>'Чака решение на клиента','Приета оферта'=>'Чака данни за договор','Данни за договор'=>'Чака предаване в склада','Проверка в склада'=>'Проверка за наличност на техника','Предадена за изпълнение'=>'Чака уговаряне','Уговорена'=>'Чака изпълнение',default=>$r['colleague']!==''?'Извършващ огледа: '.$r['colleague']:'Чака назначаване на извършващ огледа'};
    }
    if($r['warehouse_status']==='Проблем')return 'Проблем с техника в склад';
    if($r['warehouse_status']!=='Проверено')return 'Чака проверка на техника в склад';
    if($r['request_type']==='Ремонт') {
        if($r['debt_status']==='Има задължения')return 'Установени задължения';
        if($r['debt_status']!=='Проверено')return 'Чака проверка на задължения';
    }
    if($r['request_type']==='Изключване') {
        if($r['return_status']==='Чака')return 'Чака проверка на техника за връщане';
        if($r['return_status']==='Има техника за връщане')return 'Има техника за връщане';
    }
    return $r['stage']==='В изпълнение'?'В изпълнение':'Готова за изпълнение';
}

?>
<!doctype html>
<html lang="bg">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Заявки · ТИМ ЕАД</title>
<style>
:root{font-family:Inter,Segoe UI,Arial,sans-serif;background:#eff4f6;color:#173447}*{box-sizing:border-box}body{margin:0}.top{background:#183346;color:#fff;padding:17px max(24px,calc((100vw - 900px)/2));display:flex;justify-content:space-between;align-items:center}.brand{font-size:23px;letter-spacing:.1em;font-weight:800;color:#f2b75e}.top a{color:#dce9ee;text-decoration:none}.wrap{max-width:900px;margin:0 auto;padding:42px 24px}h1{margin:0 0 7px;font-size:29px}.sub{margin:0 0 28px;color:#627a88}.cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.cards.single{grid-template-columns:minmax(0,1fr)}.card{display:flex;flex-direction:column;min-height:210px;padding:26px;background:#fff;border:1px solid #d9e4e9;border-radius:15px;text-decoration:none;color:inherit;box-shadow:0 6px 24px #1938490a;transition:.15s}.card:hover,.card:focus-visible{transform:translateY(-3px);border-color:#8eabb9;box-shadow:0 12px 32px #1938491e}.icon{display:grid;place-items:center;width:46px;height:46px;border-radius:10px;background:#e2f1e9;color:#26734f;font-size:24px}.card:nth-child(2) .icon{background:#fff0d9;color:#a56817}.card h2{margin:18px 0 7px;font-size:21px}.card p{margin:0;color:#607987;line-height:1.5}.open{margin-top:auto;padding-top:20px;font-weight:750;color:#2a6e8c}.active-block{margin-top:22px;padding:24px 26px;background:#fff;border:1px solid #d9e4e9;border-radius:15px}.active-block h2{margin:0 0 7px;font-size:21px}.active-block p{margin:0 0 16px;color:#607987}.active-link{font-weight:750;color:#2a6e8c;text-decoration:none}@media(max-width:650px){.cards{grid-template-columns:1fr}.wrap{padding:28px 17px}.top{padding:15px 17px}}
.active-section{margin-top:30px}.active-head{display:flex;justify-content:space-between;align-items:center;gap:12px}.active-head h2{margin:0 0 13px;font-size:22px}.active-head a{color:#276b8c;font-weight:700;text-decoration:none}.active-table-wrap{overflow-x:auto;background:white;border:1px solid #d9e4e9;border-radius:12px}.active-table{width:100%;border-collapse:collapse;min-width:750px}.active-table th{background:#eef4f7;color:#557080;text-align:left;font-size:12px;padding:12px}.active-table td{padding:13px 12px;border-top:1px solid #e5edf0;font-size:14px;vertical-align:top}.active-table td strong{display:block}.active-table td small{color:#78909c}.active-table a{color:#246b8d;font-weight:750}.type-pill{background:#e2f1f5;color:#316b83;padding:4px 7px;border-radius:5px;font-size:12px;font-weight:750}.stage-pill{background:#fff0d9;color:#895d16;padding:4px 7px;border-radius:5px;font-size:12px;font-weight:750}.active-empty{background:white;border:1px solid #d9e4e9;border-radius:12px;padding:20px;color:#6b8290}@media(max-width:650px){.active-section{margin-top:22px}.active-head h2{font-size:19px}}
.request-row{cursor:pointer}.request-row:hover{background:#f3f8fb}.request-row:focus-within{outline:2px solid #246b8d;outline-offset:-2px}
.request-types{background:#fff;border:1px solid #d9e4e9;border-radius:15px;padding:24px 26px;margin-bottom:26px}.request-types h2{margin:0 0 15px;font-size:21px}.type-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.type-grid a{display:flex;align-items:center;justify-content:center;min-height:48px;padding:10px 12px;border:1px solid #d6e3e8;border-radius:9px;background:#f7fafb;color:#285f79;font-weight:750;text-align:center;text-decoration:none}.type-grid a:hover,.type-grid a:focus-visible{background:#edf5f8;border-color:#91adba}@media(max-width:760px){.type-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:430px){.type-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="top"><div class="brand">ТИМ ЕАД</div><a href="/">← Начало</a></header>
<main class="wrap">
<h1>Заявки</h1>
<p class="sub">Управление и добавяне на заявки за услуги.</p>
<section class="request-types" aria-label="Добавяне на заявка"><h2>Добавяне</h2><div class="type-grid"><a href="/zayavki.php?type=Ремонт%2FОбслужване">Ремонт/Обслужване</a><a href="/zayavki.php?type=Оглед">Оглед</a><a href="/zayavki.php?type=Изключване">Изключване</a><a href="/zayavki.php?type=Преместване">Преместване</a></div></section>
<section class="active-section" id="active-requests" aria-label="Активни заявки"><div class="active-head"><h2>Активни заявки (<?=count($active)?>)</h2></div><?php if(!$active): ?><p class="active-empty">Няма активни заявки.</p><?php else: ?><div class="active-table-wrap"><table class="active-table"><thead><tr><th>Заявка</th><th>Клиент и обект</th><th>Подал</th><th>Статус</th><th>В момента</th><th>Обновена</th></tr></thead><tbody><?php foreach($active as $r): ?><tr class="request-row" data-request-url="/zayavka.php?id=<?=out($r['id'])?>"><td><a href="/zayavka.php?id=<?=out($r['id'])?>">#<?=out($r['id'])?></a><br><span class="type-pill"><?=out($r['request_type'])?></span></td><td><strong><?=out($r['client'])?></strong><small><?=out($r['service']?:$r['address'])?></small></td><td><?=out($r['submitted_by']??'—')?></td><td><a href="/zayavka.php?id=<?=out($r['id'])?>" style="text-decoration:none"><span class="stage-pill"><?=out(canonical_stage($r['stage']))?></span></a></td><td><?=out(next_step($r))?></td><td><?=out(local_time($r['updated_at']))?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</main>
<script>document.querySelectorAll('.request-row').forEach(function(row){row.addEventListener('click',function(event){if(event.target.closest('a,button,input,select,textarea')||window.getSelection().toString())return;window.location.assign(row.dataset.requestUrl);});});</script></body>
</html>
