<?php
require __DIR__.'/auth.php';
require_auth(true);
try {
    $db=app_db();
    $db->exec('CREATE TABLE IF NOT EXISTS work_types (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, color TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS app_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    if ((int)$db->query('SELECT count(*) FROM work_types')->fetchColumn()===0) {
        $s=$db->prepare('INSERT INTO work_types(name,color) VALUES(?,?)');
        foreach ([['Нови обекти','#2d9c72'],['Добавка на техника','#8260af'],['Заявка','#d8913c'],['Окабеляване','#3985bc'],['Преместване на техника','#8d9a39'],['Демонтаж','#81919b']] as $t) $s->execute($t);
    }
    $defaults=['owncloud_url'=>'','owncloud_user'=>'','owncloud_root'=>'INFO','smtp_host'=>'','smtp_port'=>'587','smtp_security'=>'tls','smtp_user'=>'','smtp_from'=>'','smtp_from_name'=>'INFO','notification_email'=>'','telegram_chat_id'=>''];
    function settings_data(PDO $db, array $defaults): array {
        $s=$db->query('SELECT key,value FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $safe=[];
        foreach($defaults as $key=>$default) $safe[$key]=$s[$key]??$default;
        $safe['owncloud_password_configured']=!empty($s['owncloud_password']);
        $safe['smtp_password_configured']=!empty($s['smtp_password']);
        $safe['telegram_token_configured']=!empty($s['telegram_token']);
        $logs=[];
        if ($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='notification_log'")->fetchColumn())
            $logs=$db->query('SELECT job_id,kind,channel,status,attempts,sent_at,error FROM notification_log ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
        return ['types'=>$db->query('SELECT id,name,color FROM work_types ORDER BY id')->fetchAll(),'settings'=>$safe,'notification_log'=>$logs];
    }
    if ($_SERVER['REQUEST_METHOD']==='GET') { json_response(settings_data($db,$defaults)); exit; }
    if ($_SERVER['REQUEST_METHOD']!=='POST') { json_response(['error'=>'Method not allowed'],405); exit; }
    $raw=file_get_contents('php://input');
    if(strlen($raw)>16384) { json_response(['error'=>'Too large'],413); exit; }
    $data=json_decode($raw,true);
    if(!is_array($data)) { json_response(['error'=>'Invalid JSON'],400); exit; }
    $action=$data['action']??'';
    if($action==='owncloud') {
        $settings=$data['settings']??null;if(!is_array($settings)){json_response(['error'=>'Невалидни настройки'],400);exit;}
        $url=rtrim(trim((string)($settings['owncloud_url']??'')),'/');$user=trim((string)($settings['owncloud_user']??''));$root=trim((string)($settings['owncloud_root']??'INFO'),'/');
        if($url!==''&&!preg_match('#^https?://#i',$url)){json_response(['error'=>'Невалиден ownCloud URL'],400);exit;}
        if(strlen($url)>255||strlen($user)>160||strlen($root)>160){json_response(['error'=>'Твърде дълга стойност'],400);exit;}
        $q=$db->prepare('INSERT INTO app_settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
        foreach(['owncloud_url'=>$url,'owncloud_user'=>$user,'owncloud_root'=>$root?:'INFO'] as $k=>$v)$q->execute([$k,$v]);
        $plain=(string)($settings['owncloud_password']??'');
        if($plain!==''){
            $secret=file_get_contents('/var/lib/montaji-app/settings.key');if(strlen($secret)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new RuntimeException('Encryption key unavailable');
            $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$enc=base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$secret));$q->execute(['owncloud_password',$enc]);
        }
    } elseif($action==='test_owncloud') {
        require_once __DIR__.'/owncloud-storage.php';$c=oc_settings();if(!oc_ready($c)){json_response(['error'=>'Попълни URL, потребител и парола.'],400);exit;}
        [$code,,$err]=oc_req($c,'PROPFIND','');
        if(!in_array($code,[207,200],true)){json_response(['error'=>'Няма връзка с ownCloud · HTTP '.$code.($err?' · '.$err:'')],502);exit;}
        json_response(['ok'=>true,'message'=>'Връзката с ownCloud е успешна.']);exit;
    } elseif($action==='add_type') {
        $name=trim((string)($data['name']??''));$color=(string)($data['color']??'');
        if($name==='' || strlen($name)>140 || !preg_match('/^#[0-9a-fA-F]{6}$/',$color)) { json_response(['error'=>'Невалидно име или цвят'],400); exit; }
        $s=$db->prepare('INSERT INTO work_types(name,color) VALUES(?,?)');
        $s->execute([$name,$color]);
    } elseif($action==='color') {
        $id=(int)($data['id']??0);$color=(string)($data['color']??'');
        if($id<1 || !preg_match('/^#[0-9a-fA-F]{6}$/',$color)) { json_response(['error'=>'Невалиден цвят'],400); exit; }
        $s=$db->prepare('UPDATE work_types SET color=? WHERE id=?');$s->execute([$color,$id]);
    } elseif($action==='channels') {
        $settings=$data['settings']??null;
        if(!is_array($settings)) { json_response(['error'=>'Невалидни настройки'],400); exit; }
        $port=(int)($settings['smtp_port']??587);
        if($port<1 || $port>65535 || !in_array($settings['smtp_security']??'tls',['tls','ssl','none'],true)) { json_response(['error'=>'Невалиден SMTP порт или защита'],400); exit; }
        $db->beginTransaction();
        $s=$db->prepare('INSERT INTO app_settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
        foreach($defaults as $key=>$default) {
            $value=trim((string)($settings[$key]??$default));
            if(strlen($value)>255) throw new RuntimeException('Твърде дълга стойност');
            if($key==='smtp_port') $value=(string)$port;
            $s->execute([$key,$value]);
        }
        foreach(['smtp_password','telegram_token'] as $key) {
            $plain=(string)($settings[$key]??'');
            if($plain==='') continue;
            if(strlen($plain)>512) throw new RuntimeException('Твърде дълга тайна');
            $secret=file_get_contents('/var/lib/montaji-app/settings.key');
            if(strlen($secret)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('Encryption key unavailable');
            $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $encrypted=base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$secret));
            $s->execute([$key,$encrypted]);
        }
        $db->commit();
    } else { json_response(['error'=>'Unknown action'],400); exit; }
    json_response(settings_data($db,$defaults));
} catch(Throwable $e) {
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    error_log('settings API: '.$e->getMessage());
    $code=$e instanceof PDOException && str_contains($e->getMessage(),'UNIQUE')?409:500;
    json_response(['error'=>$code===409?'Този вид работа вече съществува':'Неуспешен запис'], $code);
}
