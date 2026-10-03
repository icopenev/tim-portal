<?php

function reportOverlapHours(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $a, DateTimeImmutable $b): float
{
    $from = max($start->getTimestamp(), $a->getTimestamp());
    $to = min($end->getTimestamp(), $b->getTimestamp());
    return max(0, $to - $from) / 3600;
}

function reportShiftInterval(string $date, ?string $from, ?string $to): ?array
{
    if (!$from || !$to) {
        return null;
    }
    $start = new DateTimeImmutable($date . ' ' . $from);
    $end = new DateTimeImmutable($date . ' ' . $to);
    if ($end <= $start) {
        $end = $end->modify('+1 day');
    }
    return [$start, $end];
}

function reportNightHours(DateTimeImmutable $start, DateTimeImmutable $end): float
{
    $hours = 0.0;
    $day = $start->setTime(0, 0)->modify('-1 day');
    $last = $end->setTime(0, 0);
    while ($day <= $last) {
        $hours += reportOverlapHours($start, $end, $day->setTime(22, 0), $day->modify('+1 day')->setTime(6, 0));
        $day = $day->modify('+1 day');
    }
    return $hours;
}

function reportHolidayHours(PDO $db, DateTimeImmutable $start, DateTimeImmutable $end): float
{
    $hours = 0.0;
    $day = $start->setTime(0, 0);
    $last = $end->setTime(0, 0);
    $query = $db->prepare('SELECT is_holiday FROM staff_work_calendar_days WHERE work_date=?');
    while ($day <= $last) {
        $query->execute([$day->format('Y-m-d')]);
        if ((int) $query->fetchColumn() === 1) {
            $hours += reportOverlapHours($start, $end, $day, $day->modify('+1 day'));
        }
        $day = $day->modify('+1 day');
    }
    return $hours;
}
