<?php

declare(strict_types=1);

use Tenyen\Analytics\LogLifecycle;
use Tenyen\Analytics\DailyAggregation;

$root=dirname(__DIR__,3);$configFile=$root.'/config.php';if(!is_file($configFile)){http_response_code(503);exit;}$config=require $configFile;require_once $root.'/app/admin-auth.php';tyaa_require_auth(is_array($config)?$config:[],true);
$csrf=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['csrf']??'');if(!tyaa_verify_csrf($csrf))tyaa_json(['ok'=>false,'error'=>'csrf_failed','message'=>'The form has expired.'],403);
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')tyaa_json(['ok'=>false,'error'=>'method_not_allowed','message'=>'Method not allowed.'],405);
$services=require $root.'/app/bootstrap.php';$timezone=new DateTimeZone((string)($config['app']['timezone']??'Asia/Tokyo'));$lifecycle=new LogLifecycle($services['pdo'],$root.'/storage/lifecycle.json',(int)($config['app']['retention_days']??90),$timezone->getName());$aggregation=new DailyAggregation($services['pdo'],$timezone);
$input=$_POST;if(str_contains((string)($_SERVER['CONTENT_TYPE']??''),'application/json')){$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))tyaa_json(['ok'=>false,'error'=>'invalid_json','message'=>'Invalid JSON request.'],400);}$action=(string)($input['action']??'');
try{
    if($action==='save_retention'){$value=($input['retention']??'')==='custom'?($input['custom_days']??''):($input['retention']??'');tyaa_json(['ok'=>true,'state'=>$lifecycle->saveRetention($value)]);}
    if($action==='preview')tyaa_json(['ok'=>true,'preview'=>$lifecycle->preview()]);
    if($action==='cleanup')tyaa_json(['ok'=>true,'cleanup'=>$lifecycle->cleanup(1000,100)]);
    if($action==='diagnostics')tyaa_json(['ok'=>true,'diagnostics'=>$lifecycle->diagnostics()]);
    if($action==='aggregate_status')tyaa_json(['ok'=>true,'aggregation'=>$aggregation->status()]);
    if($action==='aggregate_incremental')tyaa_json(['ok'=>true,'aggregation'=>$aggregation->incremental(31)]);
    if($action==='aggregate_resume')tyaa_json(['ok'=>true,'aggregation'=>$aggregation->resume(31)]);
    if($action==='aggregate_day')tyaa_json(['ok'=>true,'aggregation'=>$aggregation->rebuildDay((string)($input['day']??''))]);
    if($action==='aggregate_range')tyaa_json(['ok'=>true,'aggregation'=>$aggregation->rebuildRange((string)($input['from']??''),(string)($input['to']??''),31)]);
    tyaa_json(['ok'=>false,'error'=>'unknown_action','message'=>'Unknown lifecycle action.'],404);
}catch(InvalidArgumentException|RuntimeException $e){tyaa_json(['ok'=>false,'error'=>'operation_failed','message'=>$e->getMessage()],422);}catch(Throwable $e){error_log('[Tenyen Analytics lifecycle] '.$e->getMessage());tyaa_json(['ok'=>false,'error'=>'server_error','message'=>'The lifecycle operation failed.'],500);}
