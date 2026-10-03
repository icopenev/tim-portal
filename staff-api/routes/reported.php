<?php

if (preg_match('#^/api/reported/(\d+)/(\d{4})/(\d{1,2})/initialize$#', $path, $m) && $method === 'POST') {
    $oid = (int)$m[1];
    $y = (int)$m[2];
    $mo = (int)$m[3];
    $q = $db->prepare("SELECT id FROM staff_reported_schedules WHERE object_id=? AND year=? AND month=?");
    $q->execute([$oid,$y,$mo]);
    if ($rid = $q->fetchColumn()) {
        out(['success' => true,'created' => false,'reported_schedule_id' => (int)$rid]);
    }$db->beginTransaction();
    $q = $db->prepare("INSERT INTO staff_reported_schedules(object_id,year,month) VALUES(?,?,?)");
    $q->execute([$oid,$y,$mo]);
    $rid = $db->lastInsertId();
    $start = sprintf('%04d-%02d-01', $y, $mo);
    $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
    $q = $db->prepare("SELECT se.*,s.type,s.time_from,s.time_to FROM staff_schedule_entries se LEFT JOIN staff_shifts s ON s.id=se.shift_id AND s.object_id=se.object_id WHERE se.object_id=? AND se.work_date BETWEEN ? AND ? AND se.shift_id IS NOT NULL");
    $q->execute([$oid,$start,$end]);
    $n = 0;
    foreach ($q->fetchAll() as $e) {
        $dur = 12;
        if (in_array($e['type'], ['work','duty'], true)) {
            $real = fminutes($e['time_from'], $e['time_to']) / 60;
            if (in_array($real, [8,12], true)) {
                $dur = $real;
            }if ($real == 12) {
                $dur = 12;
            }
        }$i = $db->prepare("INSERT INTO staff_reported_entries(reported_schedule_id,employee_id,work_date,shift_id,duration_hours,note) VALUES(?,?,?,?,?,?)");
        $i->execute([$rid,$e['employee_id'],$e['work_date'],$e['shift_id'],$dur,$e['note'] ?? '']);
        $n++;
    }$db->commit();
    out(['success' => true,'created' => true,'reported_schedule_id' => (int)$rid,'copied_entries' => $n]);
}
if (preg_match('#^/api/reported/(\d+)/(\d{4})/(\d{1,2})$#', $path, $m) && $method === 'GET') {
    $q = $db->prepare("SELECT * FROM staff_reported_schedules WHERE object_id=? AND year=? AND month=?");
    $q->execute([(int)$m[1],(int)$m[2],(int)$m[3]]);
    $sch = $q->fetch();
    if (!$sch) {
        out(['error' => 'Отчетният график още не е създаден'], 404);
    }$q = $db->prepare("SELECT re.*,s.code,s.name shift_name,s.type,s.time_from,s.time_to,s.counts_as_work_day FROM staff_reported_entries re LEFT JOIN staff_shifts s ON s.id=re.shift_id WHERE re.reported_schedule_id=? ORDER BY re.employee_id,re.work_date");
    $q->execute([$sch['id']]);
    out(['schedule' => $sch,'entries' => $q->fetchAll()]);
}
if (preg_match('#^/api/reported/(\d+)/entries/(\d+)/duration$#', $path, $m) && $method === 'PUT') {
    $dur = (int)($in['duration_hours'] ?? 0);
    if (!in_array($dur, [8,12], true)) {
        out(['error' => 'Продължителността трябва да е 8 или 12 часа'], 400);
    }$q = $db->prepare("SELECT re.id,s.type,s.time_from,s.time_to FROM staff_reported_entries re JOIN staff_reported_schedules rs ON rs.id=re.reported_schedule_id LEFT JOIN staff_shifts s ON s.id=re.shift_id WHERE re.id=? AND rs.object_id=?");
    $q->execute([(int)$m[2],(int)$m[1]]);
    $e = $q->fetch();
    if (!$e) {
        out(['error' => 'Отчетната смяна не е намерена'], 404);
    }if (!in_array($e['type'], ['work','duty'], true)) {
        out(['error' => 'Продължителността може да се променя само за работна смяна или дежурство'], 400);
    }if ($e['time_from'] && $e['time_to'] && strcmp(substr($e['time_to'], 0, 5), substr($e['time_from'], 0, 5)) <= 0 && $dur !== 12) {
        out(['error' => 'Нощната смяна може да бъде само 12 часа'], 400);
    }$db->prepare("UPDATE staff_reported_entries SET duration_hours=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$dur,(int)$m[2]]);
    out(['success' => true,'id' => (int)$m[2],'duration_hours' => $dur]);
}
if (preg_match('#^/api/reported/(\d+)/entry$#', $path, $m) && $method === 'PUT') {
    $oid = (int)$m[1];
    $y = (int)($in['year'] ?? 0);
    $mo = (int)($in['month'] ?? 0);
    $eid = (int)($in['employee_id'] ?? 0);
    $date = $in['work_date'] ?? '';
    $sid = $in['shift_id'] ?? null;
    $q = $db->prepare("SELECT id FROM staff_reported_schedules WHERE object_id=? AND year=? AND month=?");
    $q->execute([$oid,$y,$mo]);
    $rid = $q->fetchColumn();
    if (!$rid) {
        out(['error' => 'Първо създайте отчетния график'], 404);
    }if ($sid === null || $sid === '') {
        $db->prepare("DELETE FROM staff_reported_entries WHERE reported_schedule_id=? AND employee_id=? AND work_date=?")->execute([$rid,$eid,$date]);
        out(['success' => true,'deleted' => true]);
    }$q = $db->prepare("SELECT * FROM staff_shifts WHERE id=? AND object_id=?");
    $q->execute([(int)$sid,$oid]);
    $sh = $q->fetch();
    if (!$sh) {
        out(['error' => 'Невалидна смяна'], 400);
    }$dur = in_array($sh['type'], ['work','duty'], true) ? fminutes($sh['time_from'], $sh['time_to']) / 60 : 12;
    if (!in_array($dur, [8,12], true)) {
        $dur = 12;
    }$db->prepare("INSERT INTO staff_reported_entries(reported_schedule_id,employee_id,work_date,shift_id,duration_hours,note) VALUES(?,?,?,?,?,?) ON CONFLICT(reported_schedule_id,employee_id,work_date) DO UPDATE SET shift_id=excluded.shift_id,duration_hours=excluded.duration_hours,note=excluded.note,updated_at=CURRENT_TIMESTAMP")->execute([$rid,$eid,$date,(int)$sid,$dur,$in['note'] ?? '']);
    out(['success' => true,'duration_hours' => $dur]);
}
if (preg_match('#^/api/reported/(\d+)/entries/(\d+)/start-time$#', $path, $m) && $method === 'PUT') {
    $t = trim($in['start_time'] ?? '');
    if ($t !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
        out(['error' => 'Невалиден начален час'], 400);
    }$db->prepare("UPDATE staff_reported_entries SET start_time=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$t ?: null,(int)$m[2]]);
    out(['success' => true,'start_time' => $t ?: null]);
}
if (preg_match('#^/api/reported/(\d+)/shift-settings$#', $path, $m)) {
    if ($method === 'GET') {
        $q = $db->prepare("SELECT * FROM staff_reported_shift_settings WHERE object_id=? AND base_code IN ('D1','D2') AND duration_hours = 8 ORDER BY base_code,duration_hours DESC");
        $q->execute([(int)$m[1]]);
        out(['settings' => $q->fetchAll()]);
    }if ($method === 'PUT') {
        foreach (($in['settings'] ?? []) as $x) {
            $base = strtoupper(trim($x['base_code'] ?? ''));
            $dur = (int)($x['duration_hours'] ?? 0);
            $time = trim($x['start_time'] ?? '');
            if (!in_array($base, ['D1','D2'], true) || $dur !== 8) {
                out(['error' => 'Невалидна настройка'], 400);
            }$q = $db->prepare("INSERT INTO staff_reported_shift_settings(object_id,base_code,duration_hours,start_time) VALUES(?,?,?,?) ON CONFLICT(object_id,base_code,duration_hours) DO UPDATE SET start_time=excluded.start_time,updated_at=CURRENT_TIMESTAMP");
            $q->execute([(int)$m[1],$base,$dur,$time]);
        }out(['success' => true]);
    }
}
