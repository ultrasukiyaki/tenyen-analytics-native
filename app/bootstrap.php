<?php

declare(strict_types=1);

use Tenyen\Analytics\Crypto;
use Tenyen\Analytics\GeoIp;
use Tenyen\Analytics\LocaleResolver;
use Tenyen\Analytics\RuntimePreferences;
use Tenyen\Analytics\SchemaMigrator;
use Tenyen\Analytics\Translator;

$root = dirname(__DIR__);
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    throw new RuntimeException('config.php is missing. Open public/install/ in a browser or copy config.example.php and edit it.');
}

$config = require $configFile;
if (!is_array($config)) {
    throw new RuntimeException('config.php must return an array.');
}

require_once __DIR__ . '/core/autoload.php';

$runtimePreferences = new RuntimePreferences($root . '/storage/admin-settings.json');
$preferences = $runtimePreferences->load();
$locale = LocaleResolver::resolve(
    $config,
    $preferences['locale'] ?? null,
    null,
    (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')
);
$fallbackLocale = (string)($config['app']['fallback_locale'] ?? 'en');
$translator = new Translator($locale, $fallbackLocale);

$db = $config['database'] ?? [];
$pdo = new PDO(
    (string)($db['dsn'] ?? ''),
    (string)($db['user'] ?? ''),
    (string)($db['password'] ?? ''),
    (array)($db['options'] ?? []) + [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
SchemaMigrator::migrate($pdo);

$app = $config['app'] ?? [];
$crypto = new Crypto(
    (string)($app['encryption_secret'] ?? ''),
    (string)($app['hash_secret'] ?? '')
);
$geoConfig = $config['geoip'] ?? [];
$geoIp = new GeoIp(
    (string)($geoConfig['city_database'] ?? ''),
    (string)($geoConfig['asn_database'] ?? '')
);

return compact('root', 'config', 'pdo', 'crypto', 'geoIp', 'translator', 'runtimePreferences', 'locale');
