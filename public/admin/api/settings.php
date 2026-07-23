<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit;
}
$config = require $configFile;
require_once $root . '/app/admin-auth.php';
tyaa_require_auth(is_array($config) ? $config : [], true);
$services = require $root . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tyaa_json(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
if (!tyaa_verify_csrf((string)($_POST['csrf'] ?? ''))) {
    tyaa_json(['ok' => false, 'message' => 'The form has expired.'], 403);
}
$locale = (string)($_POST['locale'] ?? '');
try {
    $services['runtimePreferences']->saveLocale($locale);
    tyaa_json(['ok' => true, 'locale' => $locale]);
} catch (Throwable $e) {
    error_log('[Tenyen Analytics settings] ' . $e->getMessage());
    tyaa_json(['ok' => false, 'message' => 'Could not save settings.'], 422);
}
