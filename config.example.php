<?php

declare(strict_types=1);

return [
    'app' => [
        'base_url' => 'https://example.com/analytics/public', // config.js.php等があるpublicのURL。HTTPはテスト環境のみ推奨
        'site_url' => 'https://example.com', // 管理画面の記事リンク生成先
        'timezone' => 'Asia/Tokyo',
        'locale' => 'auto', // auto, en, ja
        'fallback_locale' => 'en',
        'site_token' => 'CHANGE_ME_SITE_TOKEN',
        'encryption_secret' => 'CHANGE_ME_64_RANDOM_CHARS',
        'hash_secret' => 'CHANGE_ME_DIFFERENT_64_RANDOM_CHARS',
        'retention_days' => 90,
        'trusted_proxy_header' => '', // '', cf-connecting-ip, x-real-ip, x-forwarded-for
        'log_bots' => true,
        // Optional ASN category overrides: research, government, company, isp, cloud, proxy, bot, unknown.
        'organization_overrides' => [
            // 2907 => 'research',
        ],
    ],
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=analytics;charset=utf8mb4',
        'user' => 'analytics_user',
        'password' => 'CHANGE_ME',
        'options' => [],
    ],
    'geoip' => [
        'city_database' => __DIR__ . '/data/GeoLite2-City.mmdb',
        'asn_database' => __DIR__ . '/data/GeoLite2-ASN.mmdb',
    ],
    'admin' => [
        'username' => 'admin', // Native管理画面のセッションログイン名
        // Run: php bin/generate-secrets.php
        // Copy admin_password_hash here; sign in with the separately displayed admin_password.
        'password_hash' => 'CHANGE_ME_PASSWORD_HASH',
    ],
];
