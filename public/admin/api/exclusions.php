<?php

declare(strict_types=1);

use Tenyen\Analytics\ExclusionRules;
use Tenyen\Analytics\OrganizationClassifier;

$root=dirname(__DIR__,3);$configFile=$root.'/config.php';
if(!is_file($configFile)){http_response_code(503);exit;}
$config=require $configFile;
require_once $root.'/app/admin-auth.php';
tyaa_require_auth(is_array($config)?$config:[],true);
$csrf=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['csrf']??'');
if(!tyaa_verify_csrf($csrf))tyaa_json(['ok'=>false,'error'=>'csrf_failed','message'=>'The form has expired.'],403);
$services=require $root.'/app/bootstrap.php';
$rules=new ExclusionRules($services['pdo'],$services['crypto'],(array)($config['app']['organization_overrides']??[]));
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');$input=$_POST;
if(str_contains((string)($_SERVER['CONTENT_TYPE']??''),'application/json')){$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))tyaa_json(['ok'=>false,'error'=>'invalid_json','message'=>'Invalid JSON request.'],400);}
$action=(string)($_GET['action']??$input['action']??'list');
try{
    if($method==='GET'&&$action==='list'){
        $page=max(1,(int)($_GET['page']??1));$perPage=in_array((int)($_GET['per_page']??50),[25,50,100],true)?(int)$_GET['per_page']:50;$total=$rules->count();
        tyaa_json(['ok'=>true,'items'=>$rules->list($page,$perPage),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$perPage)),'types'=>ExclusionRules::TYPES,'scopes'=>ExclusionRules::SCOPES]);
    }
    if($method!=='POST')tyaa_json(['ok'=>false,'error'=>'method_not_allowed','message'=>'Method not allowed.'],405);
    if($action==='save'){
        $id=(int)($input['rule_id']??0);
        tyaa_json(['ok'=>true,'rule'=>$rules->save($id>0?$id:null,(string)($input['rule_type']??''),(string)($input['rule_value']??''),(string)($input['scope']??''),filter_var($input['enabled']??false,FILTER_VALIDATE_BOOLEAN),(string)($input['note']??''))]);
    }
    if($action==='delete'){$rules->delete((int)($input['rule_id']??0));tyaa_json(['ok'=>true]);}
    if($action==='diagnose'){
        $encoded=(string)($input['context_json']??'{}');if(strlen($encoded)>8192)throw new InvalidArgumentException('Diagnostic input is too large.');
        $context=json_decode($encoded,true,32,JSON_THROW_ON_ERROR);if(!is_array($context))throw new InvalidArgumentException('Diagnostic context must be an object.');
        $allowed=['ip','path','native_admin','is_bot','country_code','country_name','region','asn','asn_org','organization_category','browser','os','device_type','referrer_domain','utm_source','utm_medium','utm_campaign'];
        $context=array_intersect_key($context,array_flip($allowed));
        foreach($context as $key=>$value){if(!is_scalar($value)||strlen((string)$value)>1024)throw new InvalidArgumentException('Invalid diagnostic field: '.$key);}
        if(!isset($context['organization_category'])){$classified=OrganizationClassifier::classify(isset($context['asn'])?(int)$context['asn']:null,(string)($context['asn_org']??''),!empty($context['is_bot']),(array)($config['app']['organization_overrides']??[]));$context['organization_category']=$classified['category'];}
        tyaa_json(['ok'=>true,'diagnostic'=>$rules->diagnose($context,(string)($input['scope']??'analysis'))]);
    }
    tyaa_json(['ok'=>false,'error'=>'unknown_action','message'=>'Unknown exclusion action.'],404);
}catch(JsonException|InvalidArgumentException $e){tyaa_json(['ok'=>false,'error'=>'validation_failed','message'=>$e->getMessage()],422);}catch(Throwable $e){error_log('[Tenyen Analytics exclusions] '.$e->getMessage());tyaa_json(['ok'=>false,'error'=>'server_error','message'=>'The exclusion operation failed.'],500);}
