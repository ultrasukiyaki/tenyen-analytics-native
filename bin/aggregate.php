#!/usr/bin/env php
<?php

declare(strict_types=1);

use Tenyen\Analytics\DailyAggregation;

$root=dirname(__DIR__);$services=require $root.'/app/bootstrap.php';$config=$services['config'];
$aggregation=new DailyAggregation($services['pdo'],new DateTimeZone((string)($config['app']['timezone']??'Asia/Tokyo')));
$command=(string)($argv[1]??'incremental');
try{
    $result=match($command){
        'status'=>$aggregation->status(),
        'incremental'=>$aggregation->incremental(31),
        'resume'=>$aggregation->resume(31),
        'day'=>$aggregation->rebuildDay((string)($argv[2]??'')),
        'range'=>$aggregation->rebuildRange((string)($argv[2]??''),(string)($argv[3]??''),31),
        default=>throw new InvalidArgumentException('Usage: php bin/aggregate.php [status|incremental|resume|day YYYY-MM-DD|range FROM TO]'),
    };
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
