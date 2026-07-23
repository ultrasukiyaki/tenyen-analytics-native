<?php

declare(strict_types=1);

return <<<'SQL'
CREATE TABLE IF NOT EXISTS tya_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    occurred_at DATETIME NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    visitor_id VARCHAR(64) NOT NULL DEFAULT '',
    session_id VARCHAR(64) NOT NULL DEFAULT '',
    ip_encrypted VARBINARY(255) NULL,
    ip_hash BINARY(32) NULL,
    ip_version TINYINT UNSIGNED NOT NULL DEFAULT 0,
    country_code CHAR(2) NOT NULL DEFAULT '',
    country_name VARCHAR(128) NOT NULL DEFAULT '',
    region VARCHAR(128) NOT NULL DEFAULT '',
    city VARCHAR(128) NOT NULL DEFAULT '',
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    accuracy_radius SMALLINT UNSIGNED NULL,
    asn INT UNSIGNED NULL,
    asn_org VARCHAR(255) NOT NULL DEFAULT '',
    path TEXT NOT NULL,
    page_title VARCHAR(512) NOT NULL DEFAULT '',
    referrer TEXT NULL,
    target_url TEXT NULL,
    user_agent VARCHAR(1024) NOT NULL DEFAULT '',
    browser VARCHAR(64) NOT NULL DEFAULT '',
    os VARCHAR(64) NOT NULL DEFAULT '',
    device_type VARCHAR(32) NOT NULL DEFAULT '',
    language VARCHAR(32) NOT NULL DEFAULT '',
    timezone VARCHAR(64) NOT NULL DEFAULT '',
    screen VARCHAR(32) NOT NULL DEFAULT '',
    viewport VARCHAR(32) NOT NULL DEFAULT '',
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    scroll_depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_bot TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (event_id),
    KEY occurred_event (occurred_at, event_type),
    KEY visitor_time (visitor_id, occurred_at),
    KEY session_time (session_id, occurred_at),
    KEY ip_time (ip_hash, occurred_at),
    KEY asn_time (asn, occurred_at),
    KEY country_time (country_code, occurred_at),
    KEY bot_time (is_bot, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
