<?php
function app_db(): PDO {
    $db = new PDO('sqlite:/var/lib/montaji-app/app.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('CREATE TABLE IF NOT EXISTS objects (id TEXT PRIMARY KEY, status TEXT NOT NULL, number TEXT NOT NULL, address TEXT NOT NULL DEFAULT \'\', persons TEXT NOT NULL DEFAULT \'\', date TEXT NOT NULL DEFAULT \'\')');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_objects_number ON objects(number)');
    $db->exec('CREATE TABLE IF NOT EXISTS jobs (id TEXT PRIMARY KEY, number TEXT NOT NULL, data TEXT NOT NULL)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_jobs_number ON jobs(number)');
    $db->exec('CREATE TABLE IF NOT EXISTS changes (id INTEGER PRIMARY KEY AUTOINCREMENT, month TEXT NOT NULL, when_text TEXT NOT NULL, description TEXT NOT NULL)');
    return $db;
}
function json_response($payload, int $code=200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
