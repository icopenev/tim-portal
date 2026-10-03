<article class="card" id="request-<?=e($r['id'])?>">
<?php if(is_supervisor()): ?><div style="display:flex;justify-content:flex-end;margin-bottom:8px"><form method="post" action="/zayavki.php" style="margin:0" onsubmit="return confirm('Сигурни ли сте, че искате да изтриете заявка #<?=e($r['id'])?>?');"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="supervisor_delete"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button type="submit" style="border:1px solid #d9a3a0;background:#fff1f0;color:#9d332b;border-radius:7px;padding:8px 12px;font-weight:700">Изтрий заявката</button></form></div><?php endif; ?>
<span class="history">#<?=e($r['id'])?> · <?=e(substr($r['created_at'],0,16))?></span>
<h3><?=e($r['client'])?></h3>
<span class="tag"><?=e($r['request_type'])?></span> <span class="tag pending"><?=e($r['stage'])?></span>
<p><?=e($r['service'])?></p>
<?php if($r['address']): ?><p>📍 <?=e($r['address'])?></p><?php endif; ?>
<?php if($r['phone']): ?><p>☎ <?=e($r['phone'])?></p><?php endif; ?>
<?php if($r['notes']): ?><p><?=nl2br(e($r['notes']))?></p><?php endif; ?>
<?php if($r['request_type']==='Оглед'): ?>
<div class="check"><span>Извършващ огледа</span><b><?=e($r['colleague']?:'Не е назначен')?></b></div>
<div class="check"><span>Дата на оглед</span><b><?=e($r['visit_at']?str_replace('T',' ',$r['visit_at']):'Не е насрочен')?></b></div>
<ol class="workflow-steps" style="display:flex;flex-wrap:wrap;gap:8px;list-style:none;padding:0"><?php $position=array_search(canonical_inspection($r['stage']),inspection_flow(),true);foreach(inspection_flow() as $step=>$label): ?><li style="border:1px solid #cbd9df;border-radius:7px;padding:6px 9px;background:<?=$label===canonical_inspection($r['stage'])?'#fff0d9':($position!==false&&$step<$position?'#e7f5ec':'#f3f7f9')?>;font-size:12px"><span><?=$step+1?>.</span> <?=e($label)?></li><?php endforeach; ?></ol>
<?php if($r['offer_ref']): ?><p>Оферта: <strong><?=e($r['offer_ref'])?></strong></p><?php endif; ?>
<?php if($r['montage_job_id']): ?><p class="success">✓ Предадена за изпълнение. Заявката е в <a href="/montaji.php">График изпълнение</a> като №З<?=e($r['id'])?>.</p><?php endif; ?>
<?php else: ?>
<div class="check"><span>Склад · техника</span><b class="tag <?=$r['warehouse_status']==='Проверено'?'ok':'pending'?>"><?=e($r['warehouse_status'])?></b></div>
<?php if($r['request_type']==='Ремонт/Обслужване'): ?><div class="check"><span>Задължения</span><b class="tag <?=$r['debt_status']==='Проверено'?'ok':'pending'?>"><?=e($r['debt_status'])?></b></div><?php endif; ?>
<?php if($r['request_type']==='Изключване'): ?><div class="check"><span>Техника за връщане</span><b class="tag <?=$r['return_status']==='Проверено'?'ok':'pending'?>"><?=e($r['return_status'])?></b></div><?php endif; ?>
<?php endif; ?>
<?php if(!$r['montage_job_id'] || ($r['request_type']==='Оглед' && in_array(canonical_inspection($r['stage']),['Предадена за изпълнение','Уговорена'],true))): ?>
<details <?=!empty($request_detail)?'open':''?>><summary><?=!empty($request_detail)?'Текущо състояние и данни':'Отвори заявката'?></summary><form method="post" action="/zayavki.php" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=e($r['id'])?>">
<label>Етап<select name="stage"><?php foreach($r['request_type']==='Оглед'?inspection_options($r['stage']):service_options($r['stage']) as $s): ?><option <?=$s===canonical_inspection($r['stage'])?'selected':''?>><?=e($s)?></option><?php endforeach; ?></select></label>
<?php if($r['request_type']==='Оглед'): ?>
<label>Извършващ огледа<input name="colleague" maxlength="120" value="<?=e($r['colleague'])?>"></label>
<label>Дата и час на оглед<input name="visit_at" type="datetime-local" value="<?=e($r['visit_at'])?>"></label>
<label>Номер или описание на офертата<input name="offer_ref" maxlength="160" value="<?=e($r['offer_ref'])?>" placeholder="Напр. ОФ-2026-42"></label>
<label>PDF оферта<input name="offer_pdf" type="file" accept="application/pdf,.pdf"></label>
<?php if(!empty($r['offer_pdf'])): ?><p><a href="/offer-file.php?id=<?=e($r['id'])?>" target="_blank" rel="noopener">Отвори качената PDF оферта</a></p><?php endif; ?>
<?php if(in_array(canonical_inspection($r['stage']),['Направена оферта','Изпратена на клиента'],true)): ?>
<label>Изпратена до / начин на изпращане<input name="offer_sent_to" maxlength="500" value="<?=e($r['offer_sent_to']??'')?>" placeholder="Имейл или друг използван канал"></label>
<label>Дата и час на изпращане<input name="offer_sent_at" type="datetime-local" value="<?=e($r['offer_sent_at']??'')?>"></label><p class="muted">Отбелязва се вече изпратена оферта.</p>
<?php endif; ?>
<?php if(in_array(canonical_inspection($r['stage']),['Изпратена на клиента','Приета оферта'],true)): ?><label>Решение на клиента · потвърждение<textarea name="decision_note" maxlength="2000" placeholder="Дата, контакт и как е потвърдено решението"><?=e($r['decision_note']??'')?></textarea></label><?php endif; ?>
<label>Техника за монтаж (разделена със запетая)<input name="offer_equipment" maxlength="2000" value="<?=e($r['offer_equipment'])?>" placeholder="Камери, NVR, PoE суич"></label>
<?php if(in_array(canonical_inspection($r['stage']),['Приета оферта','Данни за договор','Проверка в склада','Предадена за изпълнение','Уговорена','Изпълнена'],true)): ?>
<div class="flow"><strong>Данни за договор, клиент и обект</strong></div>
<label>Вид клиент<select name="client_kind"><option value="">Избери</option><option value="person" <?=($r['client_kind']??'')==='person'?'selected':''?>>Физическо лице</option><option value="company" <?=($r['client_kind']??'')==='company'?'selected':''?>>Фирма</option></select></label>
<fieldset class="company-data" style="border:1px solid #d9e4e9;border-radius:8px;padding:12px"><legend>За фирми · данни от Търговския регистър</legend>
<p class="muted">Въведи ЕИК и зареди актуалните данни от Търговския регистър.</p>
<label>ЕИК / БУЛСТАТ<div style="display:flex;gap:8px"><input class="registry-uic" name="company_id" maxlength="40" value="<?=e($r['company_id'])?>" placeholder="Напр. 202317193"></div></label>
<p class="registry-message muted" aria-live="polite"></p>
<label>Наименование по регистър<input name="company_legal_name" maxlength="500" value="<?=e($r['company_legal_name']??'')?>"></label>
<label>Седалище и адрес на управление<input name="registered_address" maxlength="1000" value="<?=e($r['registered_address']??'')?>"></label>
<label>Представляващ<input name="representative" maxlength="500" value="<?=e($r['representative']??'')?>"></label>
<label>Дата на проверка в Търговския регистър<input name="registry_checked_at" type="date" value="<?=e($r['registry_checked_at']??'')?>"></label></fieldset>
<label>№ на обект<input name="object_number" maxlength="80" value="<?=e($r['object_number'])?>" placeholder="Напр. 4830"></label>
<label>Оторизирани лица<textarea name="authorized_persons" maxlength="3000" placeholder="Име, телефон; по едно лице на ред"><?=e($r['authorized_persons'])?></textarea></label>
<label>Адрес на обекта<input name="address_display" value="<?=e($r['address'])?>" disabled></label>
<?php endif; ?>
<?php if(in_array(canonical_inspection($r['stage']),['Проверка в склада','Предадена за изпълнение','Уговорена'],true)): ?><label>Наличие на техника<select name="warehouse_status"><?php foreach(['Има','Поръчана','Налична'] as $v): ?><option <?=$v===$r['warehouse_status']?'selected':''?>><?=e($v)?></option><?php endforeach; ?></select></label><p class="muted">След приета оферта се отбелязва наличието на техника. Само „Налична“ позволява предаване за изпълнение. При „Предадена за изпълнение“ заявката влиза в „График изпълнение“.</p>
<?php endif; ?>
<?php if(in_array(canonical_inspection($r['stage']),['Предадена за изпълнение','Уговорена'],true)): ?>
<label>Уговорена дата<input name="execution_date" type="date" value="<?=e($r['execution_date']??'')?>"></label>
<label>Час<input name="execution_time" type="time" value="<?=e($r['execution_time']??'')?>"></label>
<label>Екип<input name="execution_team" maxlength="120" value="<?=e($r['execution_team']??'')?>"></label>
<?php endif; ?>
<?php else: ?>
<label>Проверка в склад<select name="warehouse_status"><?php foreach(['Чака','Проверено','Проблем'] as $v): ?><option <?=$v===$r['warehouse_status']?'selected':''?>><?=e($v)?></option><?php endforeach; ?></select></label>
<?php if($r['request_type']==='Ремонт/Обслужване'): ?><label>Проверка на задължения<select name="debt_status"><?php foreach(['Чака','Проверено','Има задължения'] as $v): ?><option <?=$v===$r['debt_status']?'selected':''?>><?=e($v)?></option><?php endforeach; ?></select></label><?php endif; ?>
<?php if($r['request_type']==='Изключване'): ?><label>Техника за връщане<select name="return_status"><?php foreach(['Чака','Няма техника за връщане','Има техника за връщане','Върната','Проверено'] as $v): ?><option <?=$v===$r['return_status']?'selected':''?>><?=e($v)?></option><?php endforeach; ?></select></label><?php endif; ?>
<?php endif; ?>
<p class="muted">Може да запазиш текущия етап или да завършиш само следващата стъпка.</p><button class="primary">Запази етапа</button></form></details>
<?php endif; ?>
</article>

<script>
document.querySelectorAll('[name=client_kind]').forEach(function(select){function sync(){var fieldset=select.closest('form').querySelector('.company-data');if(fieldset)fieldset.hidden=select.value!=='company';}select.addEventListener('change',sync);sync();});
async function registryLoad(input){var form=input.closest('form'),msg=form.querySelector('.registry-message'),value=(input.value||'').replace(/\D/g,'');if(!/^(\d{9}|\d{13})$/.test(value))return;if(input.dataset.loading==='1'||input.dataset.loaded===value)return;input.dataset.loading='1';msg.textContent='Зареждане от Търговския регистър…';try{var res=await fetch('/company-registry.php?uic='+encodeURIComponent(value),{headers:{'Accept':'application/json'}}),data=await res.json();if(!res.ok||!data.ok)throw new Error(data.error||'Неуспешна справка.');input.value=data.uic||value;input.dataset.loaded=value;form.querySelector('[name=company_legal_name]').value=data.companyName||'';form.querySelector('[name=registered_address]').value=data.address||'';form.querySelector('[name=representative]').value=data.representative||'';form.querySelector('[name=registry_checked_at]').value=data.checkedAt||'';msg.textContent='Заредено: '+(data.companyName||data.uic)+(data.status?' · '+data.status:'');}catch(e){msg.textContent=e.message||'Неуспешно зареждане от регистъра.';}finally{input.dataset.loading='0';}}
document.querySelectorAll('.registry-uic').forEach(function(input){var timer;input.addEventListener('input',function(){clearTimeout(timer);var v=(input.value||'').replace(/\D/g,'');if(v.length===9||v.length===13)timer=setTimeout(function(){registryLoad(input);},450);});input.addEventListener('blur',function(){registryLoad(input);});});
</script>
