#!/usr/bin/env php
<?php
declare(strict_types=1);
use Tenyen\Analytics\GeoLite2Updater;
$root=dirname(__DIR__);$services=require $root.'/app/bootstrap.php';$updater=new GeoLite2Updater($root,$services['config'],$services['crypto']);$command=(string)($argv[1]??'scheduled');
try{$result=match($command){'status'=>$updater->publicStatus(),'scheduled'=>$updater->updateAll(true),'run'=>$updater->updateAll(false),'city'=>$updater->update('city'),'asn'=>$updater->update('asn'),default=>throw new InvalidArgumentException('Usage: php bin/geolite2-update.php [status|scheduled|run|city|asn]')};echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
