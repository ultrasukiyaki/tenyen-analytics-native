<?php

declare(strict_types=1);

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

try {
    $configFile = dirname(__DIR__) . '/config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('Tenyen Analytics is not installed.');
    }
    $config = require $configFile;
    $app = $config['app'] ?? [];
    $base = rtrim((string)($app['base_url'] ?? ''), '/');
    $payload = [
        'endpoint' => $base . '/collect.php',
        'token' => (string)($app['site_token'] ?? ''),
        'siteOrigin' => (string)($app['site_url'] ?? ''),
        'trackInternalLinks' => (bool)($app['track_internal_links'] ?? false),
        'trackButtons' => (bool)($app['track_buttons'] ?? false),
        'trackForms' => (bool)($app['track_forms'] ?? false),
    ];
    echo 'window.TYAnalyticsConfig=' . json_encode($payload, JSON_UNESCAPED_SLASHES) . ';';
} catch (Throwable) {
    http_response_code(500);
    echo 'window.TYAnalyticsConfig={};';
}
