<?php

declare(strict_types=1);

return <<<'SQL'
CREATE TABLE IF NOT EXISTS tya_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    occurred_at DATETIME NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    event_name VARCHAR(64) NOT NULL DEFAULT '',
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
    traffic_channel VARCHAR(32) NOT NULL DEFAULT '',
    referrer_domain VARCHAR(253) NOT NULL DEFAULT '',
    utm_source VARCHAR(255) NOT NULL DEFAULT '',
    utm_medium VARCHAR(255) NOT NULL DEFAULT '',
    utm_campaign VARCHAR(255) NOT NULL DEFAULT '',
    utm_content VARCHAR(255) NOT NULL DEFAULT '',
    utm_term VARCHAR(255) NOT NULL DEFAULT '',
    target_url TEXT NULL,
    event_metadata TEXT NULL,
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
    ,KEY channel_time (traffic_channel, occurred_at)
    ,KEY campaign_time (utm_campaign, occurred_at)
    ,KEY event_name_time (event_name, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tya_annotations (
    annotation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(32) NOT NULL,
    entity_hash BINARY(32) NOT NULL,
    entity_key TEXT NOT NULL,
    alias VARCHAR(120) NOT NULL DEFAULT '',
    note TEXT NOT NULL,
    watched TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (annotation_id),
    UNIQUE KEY entity_identity (entity_type, entity_hash),
    KEY watched_type_updated (watched, entity_type, updated_at),
    KEY entity_updated (entity_type, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tya_tags (
    tag_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    normalized_name VARCHAR(191) NOT NULL,
    color VARCHAR(16) NOT NULL DEFAULT 'slate',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (tag_id),
    UNIQUE KEY normalized_name (normalized_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tya_annotation_tags (
    annotation_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (annotation_id, tag_id),
    KEY tag_annotation (tag_id, annotation_id),
    CONSTRAINT tya_annotation_tags_annotation FOREIGN KEY (annotation_id) REFERENCES tya_annotations(annotation_id) ON DELETE CASCADE,
    CONSTRAINT tya_annotation_tags_tag FOREIGN KEY (tag_id) REFERENCES tya_tags(tag_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tya_saved_views (
    saved_view_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_key VARCHAR(191) NOT NULL,
    report VARCHAR(32) NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    state_json TEXT NOT NULL,
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (saved_view_id),
    KEY owner_report (owner_key, report),
    KEY owner_pinned (owner_key, pinned, updated_at),
    KEY owner_default (owner_key, report, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tya_exclusion_rules (
    rule_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_type VARCHAR(32) NOT NULL,
    rule_value VARCHAR(255) NOT NULL,
    scope VARCHAR(16) NOT NULL,
    action VARCHAR(16) NOT NULL DEFAULT 'exclude',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(1000) NOT NULL DEFAULT '',
    precedence SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (rule_id),
    KEY enabled_scope_precedence (enabled, scope, precedence, rule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tya_event_exclusions (
    event_id BIGINT UNSIGNED NOT NULL,
    rule_id BIGINT UNSIGNED NOT NULL,
    matched_at DATETIME NOT NULL,
    PRIMARY KEY (event_id, rule_id),
    KEY rule_event (rule_id, event_id),
    CONSTRAINT tya_event_exclusions_event FOREIGN KEY (event_id) REFERENCES tya_events(event_id) ON DELETE CASCADE,
    CONSTRAINT tya_event_exclusions_rule FOREIGN KEY (rule_id) REFERENCES tya_exclusion_rules(rule_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
