#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$services = require dirname(__DIR__) . '/app/bootstrap.php';
try {
    echo json_encode((new \Tenyen\Analytics\HostnameCache($services['pdo']))->run($services['crypto']), JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable) {
    fwrite(STDERR, "Hostname refresh failed. Check database and DNS resolver settings.\n"); exit(1);
}
