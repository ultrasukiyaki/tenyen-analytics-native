<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

use PDO;

final class SchemaMigrator
{
    /** @var array<string,string> */
    private const COLUMNS = [
        'event_name' => "VARCHAR(64) NOT NULL DEFAULT '' AFTER event_type",
        'traffic_channel' => "VARCHAR(32) NOT NULL DEFAULT '' AFTER referrer",
        'referrer_domain' => "VARCHAR(253) NOT NULL DEFAULT '' AFTER traffic_channel",
        'utm_source' => "VARCHAR(255) NOT NULL DEFAULT '' AFTER referrer_domain",
        'utm_medium' => "VARCHAR(255) NOT NULL DEFAULT '' AFTER utm_source",
        'utm_campaign' => "VARCHAR(255) NOT NULL DEFAULT '' AFTER utm_medium",
        'utm_content' => "VARCHAR(255) NOT NULL DEFAULT '' AFTER utm_campaign",
        'utm_term' => "VARCHAR(255) NOT NULL DEFAULT '' AFTER utm_content",
        'event_metadata' => "TEXT NULL AFTER target_url",
    ];

    public static function migrate(PDO $pdo): void
    {
        $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database === '') return;
        $statement = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
        $statement->execute([$database, 'tya_events']);
        $existing = array_fill_keys($statement->fetchAll(PDO::FETCH_COLUMN), true);
        foreach (self::COLUMNS as $name => $definition) {
            if (!isset($existing[$name])) $pdo->exec("ALTER TABLE tya_events ADD COLUMN {$name} {$definition}");
        }
        $indexes = (array)$pdo->query("SHOW INDEX FROM tya_events")->fetchAll(PDO::FETCH_COLUMN, 2);
        if (!in_array('channel_time', $indexes, true)) $pdo->exec('ALTER TABLE tya_events ADD KEY channel_time (traffic_channel, occurred_at)');
        if (!in_array('campaign_time', $indexes, true)) $pdo->exec('ALTER TABLE tya_events ADD KEY campaign_time (utm_campaign, occurred_at)');
        if (!in_array('event_name_time', $indexes, true)) $pdo->exec('ALTER TABLE tya_events ADD KEY event_name_time (event_name, occurred_at)');
    }
}
