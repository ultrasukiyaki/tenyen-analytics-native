<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'message'=>'セットアップが完了していません。']);
    exit;
}
$config = require $configFile;
if (!is_array($config)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'message'=>'config.php must return an array.']);
    exit;
}
require_once $root . '/app/admin-auth.php';
tyaa_require_auth($config, true);
$services = require $root . '/app/bootstrap.php';
require_once $root . '/app/admin-views.php';

try {
    $view = preg_replace('/[^a-z]/', '', (string)($_GET['view'] ?? 'dashboard')) ?: 'dashboard';
    $result = tyaav_render($view, $services, $_GET);
    tyaav_json(['ok'=>true] + $result);
} catch (Throwable $e) {
    error_log('[Tenyen Analytics admin] ' . $e->getMessage());
    tyaav_json(['ok'=>false,'message'=>'画面の取得に失敗したで：'.$e->getMessage()], 500);
}
