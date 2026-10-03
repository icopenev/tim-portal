<?php
require __DIR__ . '/auth.php';
require_auth(true);
try {
    $db = app_db();
    $cols=array_column($db->query('PRAGMA table_info(objects)')->fetchAll(PDO::FETCH_ASSOC),'name');
    foreach(['client_name','company_id','representative','phone'] as $c)if(!in_array($c,$cols,true))$db->exec("ALTER TABLE objects ADD COLUMN $c TEXT NOT NULL DEFAULT ''");
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response($db->query('SELECT id,status,number,address,persons,date,client_name,company_id,representative,phone FROM objects ORDER BY rowid')->fetchAll());
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_response(['error'=>'Method not allowed'],405); exit; }
    $raw = file_get_contents('php://input');
    if (strlen($raw)>2*1024*1024) { json_response(['error'=>'Too large'],413); exit; }
    $items = json_decode($raw,true);
    if (!is_array($items) || !array_is_list($items)) { json_response(['error'=>'Invalid data'],400); exit; }
    foreach ($items as $o) {
        if (!is_array($o) || empty($o['id']) || !isset($o['number']) || !in_array($o['status']??'', ['active','disconnected','moved'],true)) {
            json_response(['error'=>'Invalid object'],400); exit;
        }
    }
    $db->beginTransaction();
    $incoming=array_column($items,'id');
    foreach($db->query("SELECT * FROM objects WHERE id LIKE 'request-%'") as $existing)if(!in_array($existing['id'],$incoming,true))$items[]=$existing;
    $db->exec('DELETE FROM objects');
    $stmt = $db->prepare('INSERT INTO objects(id,status,number,address,persons,date,client_name,company_id,representative,phone) VALUES(?,?,?,?,?,?,?,?,?,?)');
    foreach ($items as $o) $stmt->execute([(string)$o['id'],(string)$o['status'],(string)$o['number'],(string)($o['address']??''),(string)($o['persons']??''),(string)($o['date']??''),(string)($o['client_name']??''),(string)($o['company_id']??''),(string)($o['representative']??''),(string)($o['phone']??'')]);
    $db->commit();
    json_response(['success'=>true]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('objects API: '.$e->getMessage());
    json_response(['error'=>'Database error'],500);
}
