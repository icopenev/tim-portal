<?php

if (preg_match('#^/api/schedules/(\d+)/(\d{4})/(\d{1,2})$#', $path, $m) && $method === 'GET') {
    $oid = (int)$m[1];
    $start = sprintf('%04d-%02d-01', $m[2], $m[3]);
    $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
    $q = $db->prepare("SELECT id FROM staff_shifts WHERE object_id=? AND type='off' AND active=1 ORDER BY CASE WHEN code='П' THEN 0 ELSE 1 END,id LIMIT 1");
    $q->execute([$oid]);
    $off = $q->fetchColumn();
    if (!$off) {
        out(['error' => 'За този обект няма активна смяна от тип Почивка'], 400);
    }$q = $db->prepare("SELECT id FROM staff_employees WHERE object_id=? AND active=1");
    $q->execute([$oid]);
    $emps = $q->fetchAll(PDO::FETCH_COLUMN);
    $ins = $db->prepare("INSERT OR IGNORE INTO staff_schedule_entries(object_id,employee_id,work_date,shift_id,note) VALUES(?,?,?,?,?)");foreach ($emps as $eid) {
        for ($d = new DateTimeImmutable($start); $d->format('Y-m-d') <= $end; $d = $d->modify('+1 day')) {
            $ins->execute([$oid,$eid,$d->format('Y-m-d'),$off,'']);
        }
    }$q = $db->prepare("SELECT se.id,se.object_id,se.employee_id,e.name employee_name,se.work_date,se.shift_id,s.code shift_code,s.name shift_name,s.type shift_type,s.time_from,s.time_to,s.counts_as_work_day,se.note FROM staff_schedule_entries se JOIN staff_employees e ON e.id=se.employee_id AND e.object_id=se.object_id LEFT JOIN staff_shifts s ON s.id=se.shift_id AND s.object_id=se.object_id WHERE se.object_id=? AND se.work_date BETWEEN ? AND ? ORDER BY e.name,se.work_date");
    $q->execute([$oid,$start,$end]);
    out($q->fetchAll());
}
if ($path === '/api/schedules/entry' && in_array($method, ['POST','PUT'], true)) {
    $oid = (int)($in['object_id'] ?? 0);
    $eid = (int)($in['employee_id'] ?? 0);
    $date = (string)($in['work_date'] ?? '');
    $sid = $in['shift_id'] ?? null;
    $force = !empty($in['force']);
    if (!$oid || !$eid || !$date) {
        out(['error' => 'Липсват данни за клетката'], 400);
    }$q = $db->prepare("SELECT id FROM staff_employees WHERE id=? AND object_id=?");
    $q->execute([$eid,$oid]);
    if (!$q->fetchColumn()) {
        out(['error' => 'Служителят не принадлежи на този обект'], 400);
    }if ($sid === null || $sid === '') {
        $q = $db->prepare("DELETE FROM staff_schedule_entries WHERE object_id=? AND employee_id=? AND work_date=?");
        $q->execute([$oid,$eid,$date]);
        out(['success' => true,'deleted' => true]);
    }$q = $db->prepare("SELECT * FROM staff_shifts WHERE id=? AND object_id=?");
    $q->execute([(int)$sid,$oid]);
    $shift = $q->fetch();
    if (!$shift) {
        out(['error' => 'Смяната не принадлежи на този обект'], 400);
    }$warning = null;
    if (in_array($shift['type'], ['work','duty'], true)) {
        if (!$shift['time_from'] || !$shift['time_to']) {
            out(['error' => 'Смяната ' . $shift['code'] . ' няма зададен начален или краен час.'], 400);
        }$ival = function ($d, $a, $b) {
            $st = new DateTimeImmutable($d . ' ' . $a);
            $en = new DateTimeImmutable($d . ' ' . $b);
            if ($en <= $st) {
                $en = $en->modify('+1 day');
            }return[$st,$en];
        };
        [$ps,$pe] = $ival($date, $shift['time_from'], $shift['time_to']);
        foreach ([['<','DESC','Предишна'],['>','ASC','Следваща']] as $x) {
            [$op,$ord,$label] = $x;
            $q = $db->prepare("SELECT se.work_date,s.code,s.time_from,s.time_to FROM staff_schedule_entries se JOIN staff_shifts s ON s.id=se.shift_id AND s.object_id=se.object_id WHERE se.object_id=? AND se.employee_id=? AND se.work_date $op ? AND s.type IN ('work','duty') ORDER BY se.work_date $ord LIMIT 1");
            $q->execute([$oid,$eid,$date]);
            if ($r = $q->fetch()) {
                [$os,$oe] = $ival($r['work_date'], $r['time_from'], $r['time_to']);
                $hours = $op === '<' ? ($ps->getTimestamp() - $oe->getTimestamp()) / 3600 : ($os->getTimestamp() - $pe->getTimestamp()) / 3600;
                if ($hours < 12) {
                    $warning = "Не са осигурени минимум 12 часа почивка. $label смяна: {$r['code']}. Почивка: " . number_format(max(0, $hours), 1, '.', '') . " часа.";
                }
            }
        }
    }if ($warning && !$force) {
        out(['warning' => $warning,'requires_confirmation' => true], 409);
    }$q = $db->prepare("INSERT INTO staff_schedule_entries(object_id,employee_id,work_date,shift_id,note) VALUES(?,?,?,?,?) ON CONFLICT(employee_id,work_date) DO UPDATE SET object_id=excluded.object_id,shift_id=excluded.shift_id,note=excluded.note,updated_at=CURRENT_TIMESTAMP");
    $q->execute([$oid,$eid,$date,(int)$sid,$in['note'] ?? '']);
    $q = $db->prepare("SELECT se.id,se.object_id,se.employee_id,e.name employee_name,se.work_date,se.shift_id,s.code shift_code,s.name shift_name,s.type shift_type,s.time_from,s.time_to,s.counts_as_work_day,se.note FROM staff_schedule_entries se JOIN staff_employees e ON e.id=se.employee_id LEFT JOIN staff_shifts s ON s.id=se.shift_id WHERE se.object_id=? AND se.employee_id=? AND se.work_date=?");
    $q->execute([$oid,$eid,$date]);
    $row = $q->fetch();
    $row['warning'] = $warning;
    out($row);
}
