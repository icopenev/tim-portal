<?php

if ($path === '/api/auth/me') {
    out(['user' => ['id' => (int)$_SESSION['user_id'],'username' => $_SESSION['username'] ?? '','full_name' => $_SESSION['username'] ?? '','role' => current_user_role()]]);
}
if ($path === '/api/auth/logout') {
    info_session();
    session_destroy();
    out(['success' => true]);
}
if ($path === '/api/objects' && $method === 'GET') {
    if (roleAdmin()) {
        $q = $db->query("SELECT o.id,o.name,o.description,o.active,o.reported_hours_minus,o.reported_hours_plus,(SELECT COUNT(*) FROM staff_employees e WHERE e.object_id=o.id AND e.active=1) AS employee_count FROM staff_objects o WHERE o.active=1 ORDER BY o.name");
    } else {
        $q = $db->prepare("SELECT o.id,o.name,o.description,o.active,o.reported_hours_minus,o.reported_hours_plus,(SELECT COUNT(*) FROM staff_employees e WHERE e.object_id=o.id AND e.active=1) AS employee_count FROM staff_objects o JOIN staff_user_objects uo ON uo.object_id=o.id WHERE o.active=1 AND uo.user_id=? ORDER BY o.name");
        $q->execute([(int)$_SESSION['user_id']]);
    }out($q->fetchAll());
}
if ($path === '/api/objects' && $method === 'POST') {
    if (!roleAdmin()) {
        out(['error' => 'Нямате права'], 403);
    }
    $db->beginTransaction();
    try {
        $s = $db->prepare("INSERT INTO staff_objects(name,description) VALUES(?,?)");
        $s->execute([trim($in['name'] ?? ''),trim($in['description'] ?? '')]);
        $objectId = (int)$db->lastInsertId();

        $shift = $db->prepare(
            "INSERT INTO staff_shifts(object_id,code,name,type,time_from,time_to,counts_as_work_day,active)
             VALUES(?, 'П', 'Почивка', 'off', NULL, NULL, 0, 1)"
        );
        $shift->execute([$objectId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    out(['id' => $objectId,'success' => true]);
}
if (preg_match('#^/api/objects/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    if ($method === 'GET') {
        $s = $db->prepare("SELECT * FROM staff_objects WHERE id=?");
        $s->execute([$id]);
        out($s->fetch() ?: ['error' => 'Обектът не е намерен']);
    }if ($method === 'PUT') {
        if (!roleAdmin()) {
            out(['error' => 'Нямате права'], 403);
        }$s = $db->prepare("UPDATE staff_objects SET name=?,description=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $s->execute([trim($in['name'] ?? ''),trim($in['description'] ?? ''),$id]);
        out(['success' => true]);
    }if ($method === 'DELETE') {
        if (!roleAdmin()) {
            out(['error' => 'Нямате права'], 403);
        }$s = $db->prepare("UPDATE staff_objects SET active=0 WHERE id=?");
        $s->execute([$id]);
        out(['success' => true]);
    }
}
if (preg_match('#^/api/objects/(\d+)/employees$#', $path, $m)) {
    $oid = (int)$m[1];
    if ($method === 'GET') {
        $s = $db->prepare("SELECT * FROM staff_employees WHERE object_id=? AND active=1 ORDER BY id");
        $s->execute([$oid]);
        out($s->fetchAll());
    }if ($method === 'POST') {
        staffRequireAdmin();
        $name = trim($in['name'] ?? '');
        $code = trim((string)($in['employee_code'] ?? ''));
        if ($name === '') {
            out(['error' => 'Името на служителя е задължително'], 400);
        }$db->beginTransaction();
        $rid = null;
        if ($code !== '') {
            $q = $db->prepare("SELECT id,name FROM staff_employee_registry WHERE employee_code=?");
            $q->execute([$code]);
            $r = $q->fetch();
            if ($r && mb_strtolower(trim($r['name'])) !== mb_strtolower($name)) {
                out(['error' => "Идентификатор $code вече принадлежи на {$r['name']}"], 400);
            }if ($r) {
                $rid = $r['id'];
            } else {
                $q = $db->prepare("INSERT INTO staff_employee_registry(employee_code,name,position_name) VALUES(?,?,?)");
                $q->execute([$code,$name,trim($in['position_name'] ?? '')]);
                $rid = $db->lastInsertId();
            }
        }$q = $db->prepare("INSERT INTO staff_employees(registry_id,employee_code,object_id,name,position_name,active) VALUES(?,?,?,?,?,1)");
        $q->execute([$rid,$code ?: null,$oid,$name,trim($in['position_name'] ?? '')]);
        $id = $db->lastInsertId();
        $db->commit();
        $q = $db->prepare("SELECT * FROM staff_employees WHERE id=?");
        $q->execute([$id]);
        out($q->fetch());
    }
}
if (preg_match('#^/api/employees/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    if ($method === 'PUT') {
        staffRequireAdmin();
        $q = $db->prepare("UPDATE staff_employees SET employee_code=?,name=?,position_name=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $q->execute([trim((string)($in['employee_code'] ?? '')) ?: null,trim($in['name'] ?? ''),trim($in['position_name'] ?? ''),(int)($in['active'] ?? 1),$id]);
        out(['success' => true]);
    }if ($method === 'DELETE') {
        staffRequireAdmin();
        $q = $db->prepare("UPDATE staff_employees SET active=0,updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $q->execute([$id]);
        out(['success' => true]);
    }
}
if (preg_match('#^/api/objects/(\d+)/employees/(\d+)$#', $path, $m)) {
    $_SERVER['PATH_INFO'] = '/api/employees/' . $m[2];
    $path = $_SERVER['PATH_INFO'];
    $id = (int)$m[2];
    if ($method === 'PUT') {
        staffRequireAdmin();
        $q = $db->prepare("UPDATE staff_employees SET employee_code=?,name=?,position_name=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND object_id=?");
        $q->execute([trim((string)($in['employee_code'] ?? '')) ?: null,trim($in['name'] ?? ''),trim($in['position_name'] ?? ''),(int)($in['active'] ?? 1),$id,(int)$m[1]]);
        out(['success' => true]);
    }if ($method === 'DELETE') {
        staffRequireAdmin();
        $q = $db->prepare("UPDATE staff_employees SET active=0 WHERE id=? AND object_id=?");
        $q->execute([$id,(int)$m[1]]);
        out(['success' => true]);
    }
}
if (preg_match('#^/api/objects/(\d+)/shifts$#', $path, $m)) {
    $oid = (int)$m[1];
    if ($method === 'GET') {
        $q = $db->prepare("SELECT * FROM staff_shifts WHERE object_id=? AND active=1 ORDER BY id");
        $q->execute([$oid]);
        out($q->fetchAll());
    }if ($method === 'POST') {
        staffRequireAdmin();
        $types = ['work','duty','off','vacation','sick'];
        if (!in_array($in['type'] ?? '', $types, true)) {
            out(['error' => 'Невалиден тип смяна'], 400);
        }$q = $db->prepare("INSERT INTO staff_shifts(object_id,code,name,type,time_from,time_to,counts_as_work_day,active) VALUES(?,?,?,?,?,?,?,1)");
        $q->execute([$oid,trim($in['code'] ?? ''),trim($in['name'] ?? ''),$in['type'],$in['time_from'] ?: null,$in['time_to'] ?: null,(int)($in['counts_as_work_day'] ?? 0)]);
        $id = $db->lastInsertId();
        $q = $db->prepare("SELECT * FROM staff_shifts WHERE id=?");
        $q->execute([$id]);
        out($q->fetch());
    }
}
if (preg_match('#^/api/shifts/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    if ($method === 'PUT') {
        staffRequireAdmin();
        $q = $db->prepare("UPDATE staff_shifts SET code=?,name=?,type=?,time_from=?,time_to=?,counts_as_work_day=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $q->execute([trim($in['code'] ?? ''),trim($in['name'] ?? ''),$in['type'],$in['time_from'] ?: null,$in['time_to'] ?: null,(int)($in['counts_as_work_day'] ?? 0),(int)($in['active'] ?? 1),$id]);
        out(['success' => true]);
    }if ($method === 'DELETE') {
        staffRequireAdmin();
        $q = $db->prepare("UPDATE staff_shifts SET active=0 WHERE id=?");
        $q->execute([$id]);
        out(['success' => true]);
    }
}
if (preg_match('#^/api/objects/(\d+)/shifts/(\d+)$#', $path, $m)) {
    if ($method === 'PUT') {
        staffRequireAdmin();
        $q = $db->prepare("UPDATE staff_shifts SET code=?,name=?,type=?,time_from=?,time_to=?,counts_as_work_day=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND object_id=?");
        $q->execute([trim($in['code'] ?? ''),trim($in['name'] ?? ''),$in['type'],$in['time_from'] ?: null,$in['time_to'] ?: null,(int)($in['counts_as_work_day'] ?? 0),(int)($in['active'] ?? 1),(int)$m[2],(int)$m[1]]);
        out(['success' => true]);
    }if ($method === 'DELETE') {
        staffRequireAdmin();
        $q = $db->prepare("UPDATE staff_shifts SET active=0 WHERE id=? AND object_id=?");
        $q->execute([(int)$m[2],(int)$m[1]]);
        out(['success' => true]);
    }
}
if (preg_match('#^/api/employees/(\d+)$#', $path, $m) && $method === 'GET') {
    $s = $db->prepare("SELECT * FROM staff_employees WHERE object_id=? AND active=1 ORDER BY id");
    $s->execute([(int)$m[1]]);
    out($s->fetchAll());
}
if (preg_match('#^/api/shifts/(\d+)$#', $path, $m) && $method === 'GET') {
    $s = $db->prepare("SELECT * FROM staff_shifts WHERE object_id=? AND active=1 ORDER BY id");
    $s->execute([(int)$m[1]]);
    out($s->fetchAll());
}
if (preg_match('#^/api/calendar/(\d{4})/(\d{1,2})$#', $path, $m) && $method === 'GET') {
    $q = $db->prepare("SELECT year,month,work_days,work_hours,source,updated_at FROM staff_work_calendar WHERE year=? AND month=? LIMIT 1");
    $q->execute([(int)$m[1],(int)$m[2]]);
    $c = $q->fetch();
    if (!$c) {
        out(['error' => 'Няма локален календар за ' . $m[2] . '/' . $m[1]], 404);
    }out(['calendar' => $c,'status' => null,'fallback' => false]);
}
if (preg_match('#^/api/calendar/(\d{4})/(\d{1,2})/days$#', $path, $m) && $method === 'GET') {
    $start = sprintf('%04d-%02d-01', $m[1], $m[2]);
    $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
    $q = $db->prepare("SELECT work_date,is_workday,is_holiday,holiday_name FROM staff_work_calendar_days WHERE work_date BETWEEN ? AND ? ORDER BY work_date");
    $q->execute([$start,$end]);
    out($q->fetchAll());
}
