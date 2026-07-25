#!/usr/bin/env php
<?php

declare(strict_types=1);

use Tenyen\Analytics\GeoIp;
use Tenyen\Analytics\Installer;
use Tenyen\Analytics\MmdbReader;

$root = dirname(__DIR__);
require_once $root . '/app/core/autoload.php';
$installer = new Installer($root);

function row(string $label, bool $ok, string $detail = ''): void
{
    printf("%-31s %s%s\n", $label, $ok ? '[ OK ]' : '[ NG ]', $detail !== '' ? '  ' . $detail : '');
}

echo "Tenyen Analytics Diagnostics v0.6.1\n";
echo str_repeat('=', 64) . "\n";
foreach ($installer->environment() as $check) {
    row($check['label'], $check['ok'], $check['detail']);
}

$configFile = $root . '/config.php';
row('config.php', is_file($configFile), is_file($configFile) ? '存在' : '未作成');
if (!is_file($configFile)) {
    echo "\nOpen public/install/ in a browser or use config.example.php as a guide.\n";
    exit(1);
}

try {
    $services = require $root . '/app/bootstrap.php';
    $config = $services['config'];
    $pdo = $services['pdo'];
    $geoIp = $services['geoIp'];
    row('データベース接続', true, (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
    $table = $pdo->query("SHOW TABLES LIKE 'tya_events'")->fetchColumn();
    row('tya_eventsテーブル', $table !== false, $table !== false ? '存在' : 'php bin/install.php を実行');
    $count = $table !== false ? (int)$pdo->query('SELECT COUNT(*) FROM tya_events')->fetchColumn() : 0;
    row('保存イベント数', $table !== false, number_format($count) . '件');

    $geo = $config['geoip'] ?? [];
    foreach (['City' => (string)($geo['city_database'] ?? ''), 'ASN' => (string)($geo['asn_database'] ?? '')] as $kind => $path) {
        $exists = is_file($path) && is_readable($path);
        $detail = $exists ? basename($path) . ' / ' . number_format((int)filesize($path)) . ' bytes' : '未配置（解析本体は動作可能）';
        row('GeoLite2 ' . $kind, $exists, $detail);
    }
    row('GeoIP Reader', $geoIp instanceof GeoIp && $geoIp->isReaderAvailable(), class_exists(\MaxMind\Db\Reader::class) ? '公式Reader' : (class_exists(MmdbReader::class) ? '内蔵Reader' : 'なし'));

    $app = $config['app'] ?? [];
    $base = rtrim((string)($app['base_url'] ?? ''), '/');
    $site = rtrim((string)($app['site_url'] ?? ''), '/');
    row('解析対象サイトURL', $site !== '', $site);
    row('Tenyen公開URL', $base !== '', $base);
    echo "\n埋め込みコード:\n";
    echo '<script src="' . $base . '/config.js.php"></script>' . "\n";
    echo '<script defer src="' . $base . '/tracker.js"></script>' . "\n";
    echo "\n管理画面: " . $base . "/admin/\n";
} catch (Throwable $e) {
    row('起動テスト', false, $e->getMessage());
    exit(1);
}
