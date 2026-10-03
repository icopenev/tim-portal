<?php
require __DIR__ . '/auth.php';
info_session();
if (!empty($_SESSION['user_id'])) { header('Location: /'); exit; }
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals(csrf_token(),(string)($_POST['csrf']??''))) { http_response_code(400); $error='Невалидна заявка.'; }
    else {
        try {
            $db=app_db();
            $s=$db->prepare('SELECT id,username,password_hash FROM users WHERE username=?');
            $s->execute([(string)($_POST['username']??'')]);
            $user=$s->fetch();
            if ($user && password_verify((string)($_POST['password']??''),$user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']=$user['id'];
                $_SESSION['username']=$user['username'];
                $_SESSION['last_seen']=time();
                header('Location: /'); exit;
            }
            usleep(350000);
            $error='Невалиден акаунт или парола.';
        } catch (Throwable $e) { error_log('INFO login: '.$e->getMessage()); $error='Входът временно не е достъпен.'; }
    }
}
?><!doctype html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Вход · INFO</title><style>:root{font-family:Inter,Segoe UI,Arial,sans-serif;background:#edf3f6;color:#173447}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px}.card{width:min(410px,100%);background:white;border:1px solid #dae5ea;border-radius:16px;padding:32px;box-shadow:0 15px 50px #132d3e1c}.logo{display:inline-block;background:#183346;color:#f5b858;padding:11px 14px;border-radius:11px;font-weight:800;letter-spacing:.09em}h1{font-size:25px;margin:22px 0 6px}p{color:#637c8a;margin:0 0 23px}label{display:block;margin:16px 0 6px;font-weight:650;font-size:14px}input{width:100%;padding:12px;border:1px solid #c4d4dc;border-radius:8px;font:inherit}button{width:100%;background:#e9aa4b;color:#173447;border:0;border-radius:8px;padding:13px;margin-top:23px;font:inherit;font-weight:750;cursor:pointer}.error{background:#fbe7e4;color:#a5392c;padding:10px;border-radius:7px;margin-top:14px}</style></head><body><form class="card" method="post" action="/login.php"><div class="logo">INFO</div><h1>Вход в системата</h1><p>Избери услуга след влизане.</p><?php if($error): ?><div class="error"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif; ?><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8')?>"><label for="u">Акаунт</label><input id="u" name="username" autocomplete="username" required autofocus><label for="p">Парола</label><input id="p" name="password" type="password" autocomplete="current-password" required><button>Вход</button></form></body></html>
