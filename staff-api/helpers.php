<?php

function out(mixed $payload, int $status = 200): never
{
    json_response($payload, $status);
    exit;
}

function roleAdmin(): bool
{
    return in_array(current_user_role(), ['admin', 'supervisor'], true);
}

function staffCanAccessObject(PDO $db, int $objectId): bool
{
    if (roleAdmin()) {
        return true;
    }

    $query = $db->prepare(
        'SELECT 1 FROM staff_user_objects WHERE user_id = ? AND object_id = ? LIMIT 1'
    );
    $query->execute([(int) $_SESSION['user_id'], $objectId]);

    return (bool) $query->fetchColumn();
}

function staffRequireObject(PDO $db, int $objectId): void
{
    if (!staffCanAccessObject($db, $objectId)) {
        out(['error' => 'Нямате достъп до този обект'], 403);
    }
}

function staffRequireAdmin(): void
{
    if (!roleAdmin()) {
        out(['error' => 'Нямате права за тази операция'], 403);
    }
}

function forecastShiftMinutes(?string $timeFrom, ?string $timeTo): int
{
    if (!$timeFrom || !$timeTo) {
        return 0;
    }

    [$fromHour, $fromMinute] = array_map('intval', explode(':', $timeFrom));
    [$toHour, $toMinute] = array_map('intval', explode(':', $timeTo));
    $start = $fromHour * 60 + $fromMinute;
    $end = $toHour * 60 + $toMinute;

    if ($end <= $start) {
        $end += 24 * 60;
    }

    return $end - $start;
}

// Backward-compatible internal name used by the route modules.
function fminutes($timeFrom, $timeTo): int
{
    return forecastShiftMinutes($timeFrom, $timeTo);
}

function chooseForecastEntries(array $candidates, int $requiredMinutes): array
{
    if ($requiredMinutes <= 0 || $candidates === []) {
        return [];
    }

    $combinations = [0 => []];
    foreach ($candidates as $index => $candidate) {
        $minutes = (int) ($candidate['minutes'] ?? 0);
        if ($minutes <= 0) {
            continue;
        }
        foreach ($combinations as $sum => $selected) {
            $newSum = $sum + $minutes;
            if (!isset($combinations[$newSum])) {
                $combinations[$newSum] = [...$selected, $index];
            }
        }
    }

    $sums = array_values(array_filter(array_keys($combinations), static fn ($sum) => $sum > 0));
    sort($sums, SORT_NUMERIC);
    $best = null;
    foreach ($sums as $sum) {
        if ($sum >= $requiredMinutes) {
            $best = $sum;
            break;
        }
    }
    $best ??= $sums ? end($sums) : 0;

    return $best
        ? array_map(static fn ($index) => $candidates[$index], $combinations[$best])
        : [];
}

function fchoose($candidates, $requiredMinutes): array
{
    return chooseForecastEntries($candidates, (int) $requiredMinutes);
}

function fget(PDO $db, int $objectId, int $year, int $month, int $period): ?array
{
    $query = $db->prepare(
        'SELECT * FROM staff_forecast_schedules WHERE object_id=? AND year=? AND month=? AND period=?'
    );
    $query->execute([$objectId, $year, $month, $period]);
    $forecast = $query->fetch();
    if (!$forecast) {
        return null;
    }

    $query = $db->prepare('SELECT * FROM staff_forecast_workers WHERE forecast_id=? ORDER BY sort_order,id');
    $query->execute([$forecast['id']]);
    $workers = $query->fetchAll();

    $query = $db->prepare(
        'SELECT fe.*, s.code shift_code, s.name shift_name, s.type shift_type,
                s.time_from, s.time_to, s.counts_as_work_day
         FROM staff_forecast_entries fe
         LEFT JOIN staff_shifts s ON s.id=fe.shift_id AND s.object_id=?
         WHERE fe.forecast_id=?
         ORDER BY fe.forecast_worker_id, fe.work_date'
    );
    $query->execute([$objectId, $forecast['id']]);

    $start = sprintf('%04d-%02d-01', $year, $month);
    return [
        'forecast' => $forecast,
        'period' => $period,
        'start_date' => $start,
        'end_date' => (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d'),
        'workers' => $workers,
        'entries' => $query->fetchAll(),
    ];
}
