<?php

require __DIR__ . '/staff-api/bootstrap.php';

try {
    // One authorization gate for every object-scoped scheduling route.
    if (preg_match('#^/api/(?:objects|schedules|forecasts|reported)/(\d+)(?:/|$)#', $path, $match)) {
        staffRequireObject($db, (int) $match[1]);
    }
    if ($method === 'GET' && preg_match('#^/api/(?:employees|shifts)/(\d+)$#', $path, $match)) {
        staffRequireObject($db, (int) $match[1]);
    }

    require __DIR__ . '/staff-api/routes/master.php';
    require __DIR__ . '/staff-api/routes/schedule.php';
    require __DIR__ . '/staff-api/routes/forecast.php';
    require __DIR__ . '/staff-api/routes/reported.php';
    require __DIR__ . '/staff-api/routes/reports.php';

    out(['error' => 'Непозната операция: ' . $path], 404);
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Staff API: ' . $error->getMessage());
    out(['error' => 'Операцията не може да бъде изпълнена'], 400);
}
