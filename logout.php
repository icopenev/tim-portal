<?php
require __DIR__.'/auth.php';
info_session();
if ($_SERVER['REQUEST_METHOD']==='POST' && hash_equals(csrf_token(),(string)($_POST['csrf']??''))) {
    $_SESSION=[];
    session_destroy();
    setcookie('INFOSESSID','',['expires'=>time()-3600,'path'=>'/','httponly'=>true,'samesite'=>'Strict']);
}
header('Location: /login.php');
