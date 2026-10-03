<?php if($_SERVER['REQUEST_METHOD']==='POST'){require __DIR__.'/zayavki.php';exit;} require __DIR__.'/auth.php';require_auth();header('Location: /#active-requests');exit;
