#!/usr/bin/env php
<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = $services['pdo'];
$sql = require dirname(__DIR__) . '/app/schema.php';
$pdo->exec($sql);

$storage = dirname(__DIR__) . '/storage/ratelimit';
if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
    throw new RuntimeException('Could not create storage/ratelimit.');
}

echo "Tenyen Analytics native schema installed.\n";
