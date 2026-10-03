<?php
require __DIR__.'/auth.php';
require_auth();
$error='';$ok='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals(csrf_token(),(string)($_POST['csrf']??''))) { $error='Невалидна заявка.'; }
    else {
        $current=(string)($_POST['current']??'');
        $new=(string)($_POST['new']??'');
        $again=(string)($_POST['again']??'');
        if(strlen($new)<12) $error='Новата парола трябва да е поне 12 символа.';
        elseif($new!==$again) $error='Новите пароли не съвпадат.';
        else {
            $db=app_db();
            $s=$db->prepare('SELECT password_hash FROM users WHERE id=?');$s->execute([$_SESSION['user_id']]);
            $hash=$s->fetchColumn();
            if(!$hash || !password_verify($current,$hash)) $error='Текущата парола е грешна.';
            else {
                $s=$db->prepare('UPDATE users SET password_hash=? WHERE id=?');
                $s->execute([password_hash($new,PASSWORD_DEFAULT),$_SESSION['user_id']]);
                session_regenerate_id(true);$ok='Паролата е сменена.';
            }
        }
    }
}
?><!doctype html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Смяна на парола · INFO</title><style>:root{font-family:Inter,Segoe UI,Arial,sans-serif;background:#edf3f6;color:#173447}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px}.card{width:min(440px,100%);background:#fff;border:1px solid #d8e4ea;border-radius:14px;padding:28px}h1{margin:0 0 10px;font-size:23px}label{display:block;margin-top:16px;font-size:14px;font-weight:650}input{width:100%;padding:11px;margin-top:5px;border:1px solid #c7d5dd;border-radius:7px;font:inherit}button{margin-top:22px;padding:12px 15px;background:#e9aa4b;border:0;border-radius:7px;font-weight:750;font:inherit;cursor:pointer}.error{color:#a5392c}.ok{color:#25764e}a{display:inline-block;margin-left:15px;color:#2a6c88}</style><link rel="stylesheet" href="/assets/tim-theme.css?v=20261003"></head><body><form class="card" method="post"><h1>Смяна на парола</h1><?php if($error):?><p class="error"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></p><?php endif;?><?php if($ok):?><p class="ok"><?=htmlspecialchars($ok,ENT_QUOTES,'UTF-8')?></p><?php endif;?><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8')?>"><label>Текуща парола<input name="current" type="password" autocomplete="current-password" required></label><label>Нова парола<input name="new" type="password" autocomplete="new-password" required minlength="12"></label><label>Повтори новата<input name="again" type="password" autocomplete="new-password" required minlength="12"></label><button>Запази</button><a href="/">Към INFO</a></form></body></html>
