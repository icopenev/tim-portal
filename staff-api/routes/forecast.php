<?php

if (preg_match('#^/api/forecasts/(\d+)/(\d{4})/(\d{1,2})/([12])$#', $path, $m) && $method === 'GET') {
    $r = fget($db, (int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4]);
    if (!$r) {
        out(['error' => 'Няма създаден прогнозен график'], 404);
    }out($r);
}
if (preg_match('#^/api/forecasts/(\d+)/(\d{4})/(\d{1,2})/([12])/init$#', $path, $m) && $method === 'POST') {
    $oid = (int)$m[1];
    $y = (int)$m[2];
    $mo = (int)$m[3];
    $per = (int)$m[4];
    $db->beginTransaction();
    $q = $db->prepare("SELECT id FROM staff_forecast_schedules WHERE object_id=? AND year=? AND month=? AND period=?");
    $q->execute([$oid,$y,$mo,$per]);
    $fid = $q->fetchColumn();
    $created = false;
    if (!$fid) {
        $q = $db->prepare("INSERT INTO staff_forecast_schedules(object_id,year,month,period,created_by) VALUES(?,?,?,?,?)");
        $q->execute([$oid,$y,$mo,$per,(int)$_SESSION['user_id']]);
        $fid = $db->lastInsertId();
        $created = true;
    }$q = $db->prepare("SELECT * FROM staff_employees WHERE object_id=? AND active=1 ORDER BY id");
    $q->execute([$oid]);
    $emps = $q->fetchAll();
    $order = 1;
    foreach ($emps as $e) {
        $z = $db->prepare("SELECT id FROM staff_forecast_workers WHERE forecast_id=? AND employee_id=? AND is_extra=0");
        $z->execute([$fid,$e['id']]);
        $wid = $z->fetchColumn();
        if ($wid) {
            $u = $db->prepare("UPDATE staff_forecast_workers SET name=?,position_name=?,sort_order=? WHERE id=?");
            $u->execute([$e['name'],$e['position_name'] ?? '',$order,$wid]);
        } else {
            $u = $db->prepare("INSERT INTO staff_forecast_workers(forecast_id,employee_id,employee_code,name,position_name,is_extra,sort_order) VALUES(?,?,?,?,?,0,?)");
            $u->execute([$fid,$e['id'],$e['employee_code'],$e['name'],$e['position_name'] ?? '',$order]);
        }$order++;
    }if ($created) {
        $start = sprintf('%04d-%02d-01', $y, $mo);
        $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        $q = $db->prepare("SELECT * FROM staff_schedule_entries WHERE object_id=? AND work_date BETWEEN ? AND ?");
        $q->execute([$oid,$start,$end]);
        foreach ($q->fetchAll() as $e) {
            $z = $db->prepare("SELECT id FROM staff_forecast_workers WHERE forecast_id=? AND employee_id=? AND is_extra=0");
            $z->execute([$fid,$e['employee_id']]);
            if ($wid = $z->fetchColumn()) {
                $i = $db->prepare("INSERT OR IGNORE INTO staff_forecast_entries(forecast_id,forecast_worker_id,work_date,shift_id,note) VALUES(?,?,?,?,?)");
                $i->execute([$fid,$wid,$e['work_date'],$e['shift_id'],$e['note'] ?? '']);
            }
        }
    }$db->commit();
    out(['success' => true,'created' => $created,'forecast_id' => (int)$fid,'period' => $per,'start_date' => sprintf('%04d-%02d-01', $y, $mo),'end_date' => (new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $mo)))->modify('last day of this month')->format('Y-m-d'),'copied_from_schedule' => $created]);
}

if (preg_match('#^/api/forecasts/(\d+)/(\d{4})/(\d{1,2})/([12])/generate$#', $path, $m) && $method === 'POST') {
    $oid = (int)$m[1];
    $y = (int)$m[2];
    $mo = (int)$m[3];
    $per = (int)$m[4];
    $q = $db->prepare("SELECT work_days,work_hours FROM staff_work_calendar WHERE year=? AND month=?");
    $q->execute([$y,$mo]);
    $norm = $q->fetch();
    if (!$norm) {
        out(['error' => "Няма работен календар за $mo/$y"], 400);
    }$normMin = (int)$norm['work_hours'] * 60;
    $start = sprintf('%04d-%02d-01', $y, $mo);
    $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
    $tstart = $per === 1 ? $start : sprintf('%04d-%02d-16', $y, $mo);
    $tend = $per === 1 ? sprintf('%04d-%02d-15', $y, $mo) : $end;
    $db->beginTransaction();
    $q = $db->prepare("SELECT id FROM staff_forecast_schedules WHERE object_id=? AND year=? AND month=? AND period=?");
    $q->execute([$oid,$y,$mo,$per]);
    $fid = $q->fetchColumn();
    if ($fid) {
        $db->prepare("DELETE FROM staff_forecast_entries WHERE forecast_id=?")->execute([$fid]);
        $db->prepare("DELETE FROM staff_forecast_workers WHERE forecast_id=?")->execute([$fid]);
    } else {
        $q = $db->prepare("INSERT INTO staff_forecast_schedules(object_id,year,month,period,created_by) VALUES(?,?,?,?,?)");
        $q->execute([$oid,$y,$mo,$per,(int)$_SESSION['user_id']]);
        $fid = $db->lastInsertId();
    }$q = $db->prepare("SELECT * FROM staff_employees WHERE object_id=? AND active=1 ORDER BY id");
    $q->execute([$oid]);
    $emps = $q->fetchAll();
    $map = [];
    $order = 1;
    foreach ($emps as $e) {
        $i = $db->prepare("INSERT INTO staff_forecast_workers(forecast_id,employee_id,employee_code,name,position_name,is_extra,sort_order) VALUES(?,?,?,?,?,0,?)");
        $i->execute([$fid,$e['id'],$e['employee_code'],$e['name'],$e['position_name'] ?? '',$order++]);
        $map[(int)$e['id']] = (int)$db->lastInsertId();
    }$q = $db->prepare("SELECT se.*,s.type,s.time_from,s.time_to,COALESCE(w.is_workday,0) is_workday FROM staff_schedule_entries se LEFT JOIN staff_shifts s ON s.id=se.shift_id AND s.object_id=se.object_id LEFT JOIN staff_work_calendar_days w ON w.work_date=se.work_date WHERE se.object_id=? AND se.work_date BETWEEN ? AND ? ORDER BY se.employee_id,se.work_date");
    $q->execute([$oid,$start,$end]);
    $real = $q->fetchAll();
    $copied = [];
    $tot = [];
    foreach ($emps as $e) {
        $tot[(int)$e['id']] = 0;
    }foreach ($real as $e) {
        $eid = (int)$e['employee_id'];
        if (!isset($map[$eid])) {
            continue;
        }$i = $db->prepare("INSERT OR IGNORE INTO staff_forecast_entries(forecast_id,forecast_worker_id,work_date,shift_id,note) VALUES(?,?,?,?,?)");
        $i->execute([$fid,$map[$eid],$e['work_date'],$e['shift_id'],$e['note'] ?? '']);
        $id = (int)$db->lastInsertId();
        $mins = 0;
        if (in_array($e['type'], ['work','duty'], true)) {
            $mins = fminutes($e['time_from'], $e['time_to']);
        } elseif ($e['type'] === 'vacation' && (int)$e['is_workday'] === 1) {
            $mins = 480;
        }$tot[$eid] += $mins;
        $e['id'] = $id;
        $e['minutes'] = $mins;
        $copied[] = $e;
    }$over = [];
    foreach ($emps as $e) {
        $eid = (int)$e['id'];
        if ($tot[$eid] > $normMin) {
            $over[] = ['employee' => $e,'totalMinutes' => $tot[$eid],'excessMinutes' => $tot[$eid] - $normMin];
        }
    }$virtual = [];
    $makeVirtual = function () use (&$virtual, &$order, $db, $fid) {
        $n = count($virtual) + 1;
        $name = "Допълнителен служител $n";
        $i = $db->prepare("INSERT INTO staff_forecast_workers(forecast_id,employee_id,name,position_name,is_extra,sort_order) VALUES(?,NULL,?,'Прогнозен',1,?)");
        $i->execute([$fid,$name,$order++]);
        $w = ['id' => (int)$db->lastInsertId(),'name' => $name,'occupied' => []];
        $virtual[] = $w;
        return count($virtual) - 1;
    };
    if ($over) {
        $makeVirtual();
    }$transfers = [];
    foreach ($over as &$o) {
        $eid = (int)$o['employee']['id'];
        $cand = [];
        foreach ($copied as $e) {
            if ((int)$e['employee_id'] === $eid && in_array($e['type'], ['work','duty'], true) && $e['work_date'] >= $tstart && $e['work_date'] <= $tend && $e['minutes'] > 0) {
                $cand[] = $e;
            }
        }$sel = fchoose($cand, $o['excessMinutes']);
        $moved = 0;
        foreach ($sel as $e) {
            $vi = null;
            foreach ($virtual as $k => $v) {
                if (empty($v['occupied'][$e['work_date']])) {
                        $vi = $k;
                        break;
                }
            }if ($vi === null) {
                $vi = $makeVirtual();
            }$db->prepare("UPDATE staff_forecast_entries SET forecast_worker_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND forecast_id=?")->execute([$virtual[$vi]['id'],$e['id'],$fid]);
            $virtual[$vi]['occupied'][$e['work_date']] = 1;
            $moved += (int)$e['minutes'];
            $transfers[] = ['from_employee_id' => $eid,'from_employee' => $o['employee']['name'],'work_date' => $e['work_date'],'shift_id' => $e['shift_id'],'minutes' => (int)$e['minutes'],'hours' => $e['minutes'] / 60,'virtual_worker_id' => $virtual[$vi]['id'],'virtual_worker' => $virtual[$vi]['name']];
        }$o['transferredMinutes'] = $moved;
        $o['remainingMinutes'] = max(0, $o['excessMinutes'] - $moved);
        $o['finalMinutes'] = $o['totalMinutes'] - $moved;
    }unset($o);
    $db->commit();
    out(['success' => true,'forecast_id' => (int)$fid,'variant' => $per,'transfer_half' => $per === 1 ? '1-15' : '16-end','month_norm' => ['work_days' => (int)$norm['work_days'],'work_hours' => (int)$norm['work_hours']],'virtual_workers' => array_map(fn($w)=>['id' => $w['id'],'name' => $w['name']], $virtual),'overloaded' => array_map(fn($o)=>['employee_id' => (int)$o['employee']['id'],'name' => $o['employee']['name'],'original_hours' => $o['totalMinutes'] / 60,'excess_hours' => $o['excessMinutes'] / 60,'transferred_hours' => ($o['transferredMinutes'] ?? 0) / 60,'final_hours' => ($o['finalMinutes'] ?? $o['totalMinutes']) / 60,'remaining_excess_hours' => ($o['remainingMinutes'] ?? 0) / 60], $over),'transfers' => $transfers]);
}
if (preg_match('#^/api/forecasts/(\d+)/(\d{4})/(\d{1,2})/([12])/workers$#', $path, $m) && $method === 'POST') {
    $q = $db->prepare("SELECT id FROM staff_forecast_schedules WHERE object_id=? AND year=? AND month=? AND period=?");
    $q->execute([(int)$m[1],(int)$m[2],(int)$m[3],(int)$m[4]]);
    $fid = $q->fetchColumn();
    if (!$fid) {
        out(['error' => 'Първо създайте прогнозния график'], 404);
    }$q = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM staff_forecast_workers WHERE forecast_id=?");
    $q->execute([$fid]);
    $ord = $q->fetchColumn();
    $q = $db->prepare("INSERT INTO staff_forecast_workers(forecast_id,employee_id,employee_code,name,position_name,is_extra,sort_order) VALUES(?,NULL,?,?,?,1,?)");
    $q->execute([$fid,trim($in['employee_code'] ?? '') ?: null,trim($in['name'] ?? ''),trim($in['position_name'] ?? ''),$ord]);
    $id = $db->lastInsertId();
    $q = $db->prepare("SELECT * FROM staff_forecast_workers WHERE id=?");
    $q->execute([$id]);
    out($q->fetch(), 201);
}
if (preg_match('#^/api/forecasts/(\d+)/entry$#', $path, $m) && $method === 'PUT') {
    $fid = (int)($in['forecast_id'] ?? 0);
    $wid = (int)($in['forecast_worker_id'] ?? 0);
    $date = $in['work_date'] ?? '';
    $sid = $in['shift_id'] ?? null;
    if ($sid === null || $sid === '') {
        $q = $db->prepare("DELETE FROM staff_forecast_entries WHERE forecast_id=? AND forecast_worker_id=? AND work_date=?");
        $q->execute([$fid,$wid,$date]);
        out(['success' => true,'deleted' => true]);
    }$q = $db->prepare("INSERT INTO staff_forecast_entries(forecast_id,forecast_worker_id,work_date,shift_id,note) VALUES(?,?,?,?,?) ON CONFLICT(forecast_worker_id,work_date) DO UPDATE SET shift_id=excluded.shift_id,note=excluded.note,updated_at=CURRENT_TIMESTAMP");
    $q->execute([$fid,$wid,$date,(int)$sid,$in['note'] ?? '']);
    out(['success' => true]);
}
if (preg_match('#^/api/forecasts/(\d+)/workers/(\d+)$#', $path, $m)) {
    if ($method === 'DELETE') {
        $db->prepare("DELETE FROM staff_forecast_workers WHERE id=?")->execute([(int)$m[2]]);
        out(['success' => true]);
    }if ($method === 'PUT') {
        $db->prepare("UPDATE staff_forecast_workers SET employee_code=?,name=?,position_name=? WHERE id=?")->execute([trim($in['employee_code'] ?? '') ?: null,trim($in['name'] ?? ''),trim($in['position_name'] ?? ''),(int)$m[2]]);
        out(['success' => true]);
    }
}
if (preg_match('#^/api/forecasts/(\d+)/workers/(\d+)/name$#', $path, $m) && $method === 'PUT') {
    $db->prepare("UPDATE staff_forecast_workers SET name=? WHERE id=?")->execute([trim($in['name'] ?? ''),(int)$m[2]]);
    out(['success' => true]);
}
