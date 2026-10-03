<?php
require_once __DIR__ . '/db.php';
function info_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('INFOSESSID');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']),'httponly'=>true,'samesite'=>'Strict']);
    session_start();
}
function require_auth(bool $api=false): void {
    info_session();
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['last_seen']) && time()-$_SESSION['last_seen'] < 28800) {
        $_SESSION['last_seen']=time();
        return;
    }
    $_SESSION=[];
    if ($api) { json_response(['error'=>'Необходим е вход'],401); exit; }
    header('Location: /login.php');
    exit;
}
function csrf_token(): string {
    info_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function current_user_role(): string {
    require_auth();
    $db=app_db();
    $cols=array_column($db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC),'name');
    if(!in_array('role',$cols,true)) return 'user';
    $s=$db->prepare('SELECT role FROM users WHERE id=?');
    $s->execute([$_SESSION['user_id']]);
    return (string)($s->fetchColumn()?:'user');
}
function is_admin(): bool { return current_user_role()==='admin'; }
function is_supervisor(): bool { return in_array(current_user_role(),['supervisor','admin'],true); }
