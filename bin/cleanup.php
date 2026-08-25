#!/usr/bin/env php
<?php

declare(strict_types=1);

$root=dirname(__DIR__);$services=require $root.'/app/bootstrap.php';$config=$services['config'];
$lifecycle=new \Tenyen\Analytics\LogLifecycle($services['pdo'],$root.'/storage/lifecycle.json',(int)($config['app']['retention_days']??90));
$command=(string)($argv[1]??'run');
try{
    if($command==='preview'){echo json_encode($lifecycle->preview(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit;}
    if($command==='scheduled'&&!$lifecycle->due()){echo "Cleanup is not due.\n";exit;}
    if(!in_array($command,['run','scheduled'],true))throw new InvalidArgumentException('Usage: php bin/cleanup.php [preview|run|scheduled]');
    echo json_encode($lifecycle->cleanup(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
