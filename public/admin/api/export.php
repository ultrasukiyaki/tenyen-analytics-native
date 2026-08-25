<?php

declare(strict_types=1);

use Tenyen\Analytics\AnalyticsExport;

$root=dirname(__DIR__,3);$configFile=$root.'/config.php';if(!is_file($configFile)){http_response_code(503);exit;}$config=require $configFile;require_once $root.'/app/admin-auth.php';tyaa_require_auth(is_array($config)?$config:[],false);
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}if(!tyaa_verify_csrf((string)($_POST['csrf']??''))){http_response_code(403);exit;}
$services=require $root.'/app/bootstrap.php';$dataset=(string)($_POST['dataset']??'access');$format=(string)($_POST['format']??'csv');$ipMode=(string)($_POST['ip_mode']??'omit');
try{
    if(!in_array($dataset,AnalyticsExport::DATASETS,true)||!in_array($format,AnalyticsExport::FORMATS,true)||!in_array($ipMode,AnalyticsExport::IP_MODES,true))throw new InvalidArgumentException('Invalid export option.');
    if($ipMode==='raw'&&!hash_equals('EXPORT_RAW_IP',(string)($_POST['raw_confirmation']??'')))throw new InvalidArgumentException('Raw IP export requires explicit confirmation.');
    if($dataset!=='access')$ipMode='omit';$export=new AnalyticsExport($services['pdo'],$services['crypto']);$result=$export->query($dataset,$_POST);$stmt=$result['statement'];$filename='tenyen-'.$dataset.'-'.gmdate('Ymd-His').'.'.$format;
    header('Content-Type: '.($format==='csv'?'text/csv':'application/json').'; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
    if($format==='csv'){
        $output=fopen('php://output','wb');fwrite($output,"\xEF\xBB\xBF");$first=$stmt->fetch();$columns=$result['columns'];if($dataset==='access'){$columns=array_values(array_diff($columns,['ip_encrypted','ip_hash']));if($ipMode!=='omit')$columns[]='ip';}fputcsv($output,$columns);
        if($first){do{$row=$dataset==='access'?$export->privacyRow($first,$ipMode):$first;$ordered=[];foreach($columns as $column)$ordered[]=AnalyticsExport::csvCell($row[$column]??'');fputcsv($output,$ordered);}while($first=$stmt->fetch());}fclose($output);exit;
    }
    echo '{"schema":"tenyen.analytics.export.v1","dataset":'.json_encode($dataset).',"generated_at":'.json_encode(gmdate(DATE_ATOM)).',"items":[';$first=true;while($row=$stmt->fetch()){$row=$dataset==='access'?$export->privacyRow($row,$ipMode):$row;if(!$first)echo ',';echo json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);$first=false;}echo ']}';
}catch(InvalidArgumentException $e){http_response_code(422);header('Content-Type: text/plain; charset=UTF-8');echo $e->getMessage();}catch(Throwable $e){error_log('[Tenyen Analytics export] '.$e->getMessage());http_response_code(500);header('Content-Type: text/plain; charset=UTF-8');echo 'Export failed.';}
