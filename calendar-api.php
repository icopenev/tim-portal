<?php
require __DIR__.'/auth.php'; require_auth();
header('Content-Type: application/json; charset=utf-8');
$year=filter_input(INPUT_GET,'year',FILTER_VALIDATE_INT);
if (!$year || $year<2020 || $year>2100) {http_response_code(400);echo json_encode(['error'=>'Невалидна година']);exit;}
$file='/var/lib/montaji-app/kik-calendar/'.$year.'.json';
if (!is_file($file)) {http_response_code(404);echo json_encode(['error'=>'Календарът за тази година още не е зареден']);exit;}
$data=json_decode(file_get_contents($file),true);
if (!is_array($data) || ($data['year']??null)!==$year || !isset($data['days'])) {http_response_code(503);echo json_encode(['error'=>'Невалиден календар']);exit;}
require_once __DIR__.'/db.php';
$db=app_db();
$data['last_changes']=[];
$exists=$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='calendar_refresh_log'")->fetchColumn();
if ($exists) {
    $statement=$db->prepare('SELECT checked_at,changed FROM calendar_refresh_log WHERE year=? ORDER BY id DESC LIMIT 1');
    $statement->execute([$year]);
    if ($row=$statement->fetch(PDO::FETCH_ASSOC)) $data['last_changes']=json_decode($row['changed'],true)?:[];
}
echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
