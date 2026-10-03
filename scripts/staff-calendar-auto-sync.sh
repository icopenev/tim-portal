#!/bin/sh
set -u
APP=/var/www/html
YEAR=$(date +%Y)
NEXT=$((YEAR + 1))
LOG=/var/log/staff-calendar-sync.log
{
  echo "[$(date '+%F %T')] calendar sync start"
  php "$APP/scripts/staff-calendar-sync.php" "$YEAR" || echo "[$(date '+%F %T')] current year $YEAR sync failed"
  php "$APP/scripts/staff-calendar-sync.php" "$NEXT" || echo "[$(date '+%F %T')] next year $NEXT not available/invalid yet"
  echo "[$(date '+%F %T')] calendar sync end"
} >> "$LOG" 2>&1
