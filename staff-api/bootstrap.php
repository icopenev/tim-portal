<?php

require_once __DIR__ . '/../auth.php';

require_auth(true);
$db = app_db();
$path = $_SERVER['PATH_INFO'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = json_decode(file_get_contents('php://input'), true);
$in = is_array($input) ? $input : $_POST;

require_once __DIR__ . '/helpers.php';
