<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core/autoload.php';
use Tenyen\Analytics\{SchemaMigrator,SessionAnalytics,Crypto,HostnameCache,VisitorContext};
// Explicit disposable DSN only; this test never loads installation credentials.
$dsn=getenv('TYA_TEST_DSN');
if(!$dsn){fwrite(STDERR,"Set TYA_TEST_DSN to an empty disposable MySQL database.\n");exit(2);}
$pdo=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($pdo->query('SHOW TABLES')->fetchAll())throw new RuntimeException('Test database must be empty.');
$schema=require dirname(__DIR__).'/app/schema.php';
foreach(array_filter(array_map('trim',explode(';',$schema))) as $sql)$pdo->exec($sql);
$pdo->exec('DROP TABLE tya_hostname_cache');SchemaMigrator::migrate($pdo);SchemaMigrator::migrate($pdo);
$crypto=new Crypto(str_repeat('a',64),str_repeat('b',64));
$insert=$pdo->prepare("INSERT INTO tya_events (occurred_at,event_type,visitor_id,session_id,path,page_title,ip_hash,ip_encrypted,country_code,country_name,region,city,asn,asn_org,browser,os,device_type) VALUES (?,'pageview','visitor1','session1','/example','Example',?,?,?,?,?,?,?,?,?,?,?)");
foreach([['2026-10-29 00:00:01','JP','Japan','Tokyo','Chiyoda',12345,'Example Corp','Firefox','Linux','desktop'],['2026-10-29 00:00:02','','','','',null,'','','','']] as $values){$time=array_shift($values);$insert->execute(array_merge([$time,$crypto->hashIp('8.8.8.8'),$crypto->encryptIp('8.8.8.8')],$values));}
$analytics=new SessionAnalytics($pdo);$list=$analytics->listSessions(['actor'=>'human']);
if($list['total']!==1||VisitorContext::location($list['items'][0])!=='Japan (JP) / Tokyo / Chiyoda')throw new RuntimeException('Session aggregate context mismatch.');
$visitor=$analytics->getVisitorSummary('visitor1');
if(VisitorContext::location($visitor['summary'])!=='Japan (JP) / Tokyo / Chiyoda'||VisitorContext::organization($visitor['summary'])!=='AS12345 Example Corp')throw new RuntimeException('Visitor context mismatch.');
$pdo->exec("UPDATE tya_events SET country_code='US',country_name='United States',asn=54321 WHERE occurred_at='2026-10-29 00:00:02'");
$changed=$analytics->listSessions(['actor'=>'human'])['items'][0];
if(VisitorContext::location($changed)!=='United States (US)'||VisitorContext::organization($changed)!=='AS54321')throw new RuntimeException('Context fields mixed between different source events.');
$changedVisitor=$analytics->getVisitorSummary('visitor1')['summary'];
if(VisitorContext::location($changedVisitor)!=='United States (US)'||VisitorContext::organization($changedVisitor)!=='AS54321')throw new RuntimeException('Visitor context fields mixed between different source events.');
$pdo->exec("UPDATE tya_events SET country_code='',country_name='',asn=NULL WHERE occurred_at='2026-10-29 00:00:02'");
if($analytics->listSessions(['actor'=>'human','country'=>'Japan','organization'=>'AS12345','per_page'=>25,'order'=>'asc'])['total']!==1)throw new RuntimeException('Session filters failed.');
if($analytics->listSessions(['actor'=>'bot'])['total']!==0)throw new RuntimeException('Actor filter failed.');
if(count($analytics->getSessionDetail('session1')['events'])!==2)throw new RuntimeException('Session journey failed.');
$pdo->exec("INSERT INTO tya_exclusion_rules (rule_type,rule_value,scope,action,note,precedence,created_at,updated_at) VALUES ('country','JP','analysis','exclude','fixture',100,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$ruleId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO tya_event_exclusions (event_id,rule_id,matched_at) SELECT MIN(event_id),{$ruleId},UTC_TIMESTAMP() FROM tya_events");
if((int)$analytics->listSessions(['actor'=>'human'])['items'][0]['pageviews']!==1)throw new RuntimeException('Session exclusions regressed.');
$pdo->exec('DELETE FROM tya_exclusion_rules WHERE rule_id='.$ruleId);
require dirname(__DIR__).'/app/admin-views.php';
$root=dirname(__DIR__);
$pdo->exec("UPDATE tya_events SET occurred_at=UTC_TIMESTAMP()");
foreach(['en','ja'] as $locale){
    $services=['root'=>$root,'pdo'=>$pdo,'config'=>['app'=>['timezone'=>'UTC','site_url'=>'https://example.com']], 'geoIp'=>new \Tenyen\Analytics\GeoIp('',''),'translator'=>new \Tenyen\Analytics\Translator($locale)];
    foreach(['dashboard'=>5,'realtime'=>7] as $view=>$columns){
        $rendered=tyaav_render($view,$services,['from'=>gmdate('Y-m-d'),'to'=>gmdate('Y-m-d')]);
        if(!str_contains($rendered['html'],'Japan (JP) / Tokyo / Chiyoda')||!str_contains($rendered['html'],'AS12345 Example Corp'))throw new RuntimeException('Rendered context missing.');
        $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$rendered['html']);$xpath=new DOMXPath($dom);
        foreach($xpath->query('//table/tbody/tr') as $tr)if($tr->getElementsByTagName('td')->length!==$columns)throw new RuntimeException('Rendered column count mismatch.');
        $out=getenv('TYA_TEST_ARTIFACTS');
        if($out&&is_dir($out))file_put_contents($out.'/'.$view.'-'.$locale.'.html','<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'.file_get_contents($root.'/public/admin/admin-app.css').'</style><main style="padding:16px">'.$rendered['html'].'</main>');
    }
}
$cache=new HostnameCache($pdo);$calls=0;
$resolver=static function(string $ip)use(&$calls):array{$calls++;return ['status'=>'resolved','hostname'=>'dns.example.com'];};
$cache->run($crypto,$resolver);$cache->run($crypto,$resolver);
if($calls!==1)throw new RuntimeException('Cache deduplication failed.');
$rows=$pdo->query('SELECT ip_hash FROM tya_events')->fetchAll();if(count($cache->forRows($rows))!==1)throw new RuntimeException('Batch cache read failed.');
$package=getenv('TYA_TEST_PACKAGE');
if($package&&is_dir($package)){
    // Generated disposable-package configuration, never production config.php.
    $configuration=['database'=>['dsn'=>$dsn,'user'=>'root','password'=>''],'app'=>['timezone'=>'UTC','locale'=>'en','site_url'=>'https://example.com','encryption_secret'=>str_repeat('a',64),'hash_secret'=>str_repeat('b',64)],'admin'=>['username'=>'fixture-admin']];
    foreach(['en','ja'] as $locale){
        $configuration['app']['locale']=$locale;
        file_put_contents($package.'/config.php',"<?php return ".var_export($configuration,true).";\n");
        foreach(['list','detail','visitor','history'] as $action){
            $endpoint=$package.'/public/admin/api/'.($action==='history'?'events.php':'sessions.php');
            $parameters=['action'=>$action,'actor'=>'human','session_id'=>'session1','visitor_id'=>'visitor1'];
            $code='session_start(); $_SESSION=["tya_admin_authenticated"=>true,"tya_admin_user"=>"fixture-admin","tya_csrf"=>"fixture-csrf"]; $_SERVER["HTTP_X_CSRF_TOKEN"]="fixture-csrf"; $_GET='.var_export($parameters,true).'; require '.var_export($endpoint,true).';';
            $pipes=[];$process=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
            $payload=json_decode($output,true);$html=$payload['html']??$payload['table_html']??'';
            if($exit!==0||$errors!==''||!($payload['ok']??false)||!str_contains($html,'Japan (JP) / Tokyo / Chiyoda')||!str_contains($html,'AS12345 Example Corp'))throw new RuntimeException('Packaged endpoint failed: '.$action.' '.$errors.' '.$output);
            if($action==='history'&&(!str_contains($html,'dns.example.com')||str_contains($html,'8.8.8.8')))throw new RuntimeException('History hostname/privacy failure.');
            $out=getenv('TYA_TEST_ARTIFACTS');
            if($out&&is_dir($out))file_put_contents($out.'/'.$action.'-'.$locale.'.html','<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'.file_get_contents($package.'/public/admin/admin-app.css').file_get_contents($package.'/public/admin/admin-history.css').file_get_contents($package.'/public/admin/admin-sessions.css').'</style><main style="padding:16px"><section class="tya-history">'.$html.'</section></main>');
        }
    }
    unlink($package.'/config.php');
}
$pdo->exec("UPDATE tya_hostname_cache SET expires_at='2000-01-01',status='resolved'");
$cache->run($crypto,static fn()=>['status'=>'failed','hostname'=>'']);$cache->run($crypto,$resolver);
if($calls!==1||$pdo->query('SELECT status FROM tya_hostname_cache')->fetchColumn()!=='failed')throw new RuntimeException('Failure backoff failed.');
$pdo->exec("UPDATE tya_hostname_cache SET expires_at='2000-01-01'");$cache->run($crypto,$resolver);
if($calls!==2)throw new RuntimeException('Expiry retry failed.');
$lock='tya-hostname-'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,32);
$other=new PDO($dsn,'root','');$claim=$other->prepare('SELECT GET_LOCK(?,0)');$claim->execute([$lock]);
if(!$cache->run($crypto,$resolver)['busy'])throw new RuntimeException('Overlapping workers not blocked.');
$release=$other->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lock]);
$pdo->exec('DELETE FROM tya_events');$cache->prune();
if((int)$pdo->query('SELECT COUNT(*) FROM tya_hostname_cache')->fetchColumn()!==0)throw new RuntimeException('Orphan cache retention failed.');
echo "Database migration, aggregate, cache, backoff and retention checks passed.\n";
