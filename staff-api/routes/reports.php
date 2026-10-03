<?php

require_once __DIR__ . '/../report_helpers.php';

if ($path === '/api/reports/employees/search' && $method === 'GET') {
    staffRequireAdmin();
    $search = trim((string) ($_GET['q'] ?? ''));
    $sql = "SELECT e.employee_code, MAX(e.name) name, MAX(e.position_name) position_name,
                   GROUP_CONCAT(DISTINCT o.name) object_names
            FROM staff_employees e
            JOIN staff_objects o ON o.id=e.object_id
            WHERE e.employee_code IS NOT NULL AND e.employee_code<>''";
    $params = [];
    if ($search !== '') {
        $sql .= ' AND (e.employee_code LIKE ? OR e.name LIKE ?)';
        $like = '%' . $search . '%';
        $params = [$like, $like];
    }
    $sql .= ' GROUP BY e.employee_code ORDER BY MAX(e.name) LIMIT ' . ($search === '' ? '500' : '50');
    $query = $db->prepare($sql);
    $query->execute($params);
    out($query->fetchAll());
}

if (preg_match('#^/api/reports/employee/([^/]+)$#', $path, $match) && $method === 'GET') {
    staffRequireAdmin();
    $code = trim(urldecode($match[1]));
    if ($code === '') {
        out(['error' => 'Липсва идентификатор на служителя'], 400);
    }

    $query = $db->prepare('SELECT employee_code,name,position_name FROM staff_employees WHERE employee_code=? ORDER BY id DESC LIMIT 1');
    $query->execute([$code]);
    $employee = $query->fetch();
    if (!$employee) {
        out(['error' => 'Служителят не е намерен'], 404);
    }

    $from = isset($_GET['from']) && $_GET['from'] !== '' ? (string) $_GET['from'] : null;
    $to = isset($_GET['to']) && $_GET['to'] !== '' ? (string) $_GET['to'] : null;
    $sql = "SELECT se.work_date,se.object_id,o.name object_name,s.type,s.time_from,s.time_to,s.counts_as_work_day,
                   COALESCE(w.is_workday,0) is_workday
            FROM staff_schedule_entries se
            JOIN staff_employees e ON e.id=se.employee_id AND e.object_id=se.object_id
            JOIN staff_objects o ON o.id=se.object_id
            LEFT JOIN staff_shifts s ON s.id=se.shift_id AND s.object_id=se.object_id
            LEFT JOIN staff_work_calendar_days w ON w.work_date=se.work_date
            WHERE e.employee_code=?";
    $params = [$code];
    if ($from) {
        $sql .= ' AND se.work_date>=?';
        $params[] = $from;
    }
    if ($to) {
        $sql .= ' AND se.work_date<=?';
        $params[] = $to;
    }
    $sql .= ' ORDER BY se.work_date,se.object_id';
    $query = $db->prepare($sql);
    $query->execute($params);

    $objects = [];
    $months = [];
    $total = ['work_days' => 0,'hours' => 0.0,'night_hours' => 0.0,'holiday_hours' => 0.0,'vacation' => 0,'sick' => 0];
    foreach ($query->fetchAll() as $row) {
        $objectKey = (string) $row['object_id'];
        $monthKey = substr($row['work_date'], 0, 7) . '|' . $objectKey;
        if (!isset($objects[$objectKey])) {
            $objects[$objectKey] = ['object_id' => (int)$row['object_id'],'object_name' => $row['object_name'],'first_date' => $row['work_date'],'last_date' => $row['work_date'],'work_days' => 0,'hours' => 0.0,'night_hours' => 0.0,'holiday_hours' => 0.0,'vacation' => 0,'sick' => 0];
        }
        if (!isset($months[$monthKey])) {
            $months[$monthKey] = ['year' => (int)substr($row['work_date'], 0, 4),'month' => (int)substr($row['work_date'], 5, 2),'object_id' => (int)$row['object_id'],'object_name' => $row['object_name'],'work_days' => 0,'hours' => 0.0,'night_hours' => 0.0,'holiday_hours' => 0.0,'vacation' => 0,'sick' => 0];
        }
        $objects[$objectKey]['last_date'] = $row['work_date'];

        $delta = ['work_days' => (int)$row['counts_as_work_day'],'hours' => 0.0,'night_hours' => 0.0,'holiday_hours' => 0.0,'vacation' => $row['type'] === 'vacation' ? 1 : 0,'sick' => $row['type'] === 'sick' ? 1 : 0];
        if (in_array($row['type'], ['work','duty'], true) && ($interval = reportShiftInterval($row['work_date'], $row['time_from'], $row['time_to']))) {
            [$start,$end] = $interval;
            $delta['hours'] = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
            $delta['night_hours'] = reportNightHours($start, $end);
            $delta['holiday_hours'] = reportHolidayHours($db, $start, $end);
        } elseif ($row['type'] === 'vacation' && (int)$row['is_workday'] === 1) {
            $delta['hours'] = 8.0;
        }
        foreach ($delta as $key => $value) {
            $objects[$objectKey][$key] += $value;
            $months[$monthKey][$key] += $value;
            $total[$key] += $value;
        }
    }
    $round = static function (&$row) {
        foreach (['hours','night_hours','holiday_hours'] as $key) {
            $row[$key] = round((float)$row[$key], 1);
        }
    };
    foreach ($objects as &$row) {
        $round($row);
    } unset($row);
    foreach ($months as &$row) {
        $round($row);
    } unset($row);
    $round($total);
    out(['employee' => ['employee_code' => $employee['employee_code'],'name' => $employee['name'],'position_name' => $employee['position_name'] ?? ''],'period' => ['from' => $from,'to' => $to],'objects' => array_values($objects),'months' => array_values($months),'total' => $total]);
}
