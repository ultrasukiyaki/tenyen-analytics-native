#!/usr/bin/env php
<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/app/bootstrap.php';
$config = $services['config'];
$pdo = $services['pdo'];
$days = max(1, (int)($config['app']['retention_days'] ?? 90));
$cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
$stmt = $pdo->prepare('DELETE FROM tya_events WHERE occurred_at < :cutoff');
$stmt->execute(['cutoff' => $cutoff]);
echo $stmt->rowCount() . " old rows deleted.\n";
