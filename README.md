[日本語](README.ja.md)

# Tenyen Analytics

Tenyen Analytics is a self-hosted web analytics platform for PHP websites with a browser-based installer, pageview and engagement tracking, GeoLite2 geolocation, ASN insights, bot detection, and an asynchronous administration console.

## Overview

The application stores analytics on infrastructure you control. It does not use a CDN, an external analytics service, or a required production build step.

## Features

- Pageview, engagement, link, download, 404, and bounded custom-event collection
- Traffic channels, referrer domains, and first-touch UTM campaign attribution
- Encrypted raw-IP storage with HMAC exact-match search
- GeoLite2 City and ASN enrichment with a built-in MMDB reader
- Bot detection, organization classification, and ten asynchronous admin views
- English and standard-Japanese interfaces

## Requirements

PHP 8.1 or later, PDO MySQL, and MySQL or MariaDB are required. HTTPS is required for public production use; HTTP remains supported for local and test systems.

## Quick installation without SSH or Composer

Upload the stable archive, make `data/` and `storage/` writable by PHP, point the web server at `public/`, and open `/install/`. Composer is optional.

## Shared-hosting installation

If the host cannot assign a custom DocumentRoot, upload the application under `analytics/` and open `/analytics/public/install/`. Protect everything outside `public/`.

## Recommended DocumentRoot layout

Only the `public/` directory is intended to be web-accessible. Keep `app/`, `config.php`, `data/`, `storage/`, and CLI tools outside the DocumentRoot whenever the host permits it.

## GUI installer

The seven-step installer checks the environment, collects site and database details, creates an administrator, optionally accepts GeoLite2, and writes secrets to `config.php`. It writes `storage/installed.lock` when complete.

## GeoLite2 setup

GeoLite2 MMDB files are not included. Obtain them under MaxMind's terms. The GUI uploads in 512 KB chunks and validates database type. The built-in reader is used when the optional official `maxmind-db/reader` package is absent. ASN organization names are displayed unchanged from MaxMind.

## Administration console

Sign in with the installer-created account. Events, Campaigns, Traffic Sources, Sessions, and the existing analysis views load asynchronously with authenticated, CSRF-protected requests.

## Event and campaign integration

Automatic external-link and download tracking remains enabled. Internal links can be enabled with `track_internal_links`; buttons require both `track_buttons` and `data-tenyen-event="name"`; forms require `track_forms` and the same explicit attribute. Form values, DOM content, passwords, and payment data are never collected.

Use `TYAnalytics.trackEvent('radio_play', {station: 'example-station', server: 'primary'})` or `TYAnalytics.trackEvent('stream_server_change', {server: 'backup'})`. Names and scalar metadata are strictly bounded. This is a generic API, not automatic radio integration.

In an application/router-independent 404 template, call `TYAnalytics.trackNotFound(location.href)`. To avoid ambiguity, omit the normal pageview embed on that template or accept the pageview plus explicit 404 event intentionally.

Channels are Direct, Organic Search, Social, Referral, Internal, Campaign, and Unknown. Recognized `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, and `utm_term` values override referrer classification. Campaign reports use the landing page as session first touch; later UTM values remain event metadata.

## CLI tools

Run `php bin/doctor.php` for diagnostics, `php bin/cleanup.php` for retention cleanup, `php bin/generate-secrets.php` for credentials, or `php bin/install.php` for CLI installation guidance.

## Updating from an earlier version

Back up first, then overwrite application files while preserving `config.php`, `data/`, and `storage/`. Version 0.6.1 runs an idempotent migration that adds attribution/event columns and three indexes. Existing v0.6.0 configurations, installed lock, credentials, tokens, keys, MMDB files, and historical events remain supported.

Bounce rate remains one-page entry sessions divided by entry sessions. Click rate is sessions with a matching click from a source page divided by sessions containing a qualifying pageview of that source page; a zero denominator returns 0%. Notification, retention-management, export, aggregation, multi-site, roles, and a full exclusion manager are deferred.

## Privacy and security

Deployments may process IP addresses, access history, referrers, user-agent/device data, and geographic/ASN enrichment. Operators must disclose their retention period and self-hosted processing in their privacy policy. Restrict access to configuration, logs, backups, and analytics data.

## Troubleshooting

Check PHP 8.1+, PDO MySQL, directory permissions, database credentials, the configured public URL, and HTTPS. GeoLite2 is optional; collection works without it.

## Development

Run `php tests/run.php`, PHP lint, JavaScript syntax checks, and `tools/build-release.sh`. Production operation must not require Composer or Node.

## License

Copyright © 2026 10yendama.com. Licensed under GPL-2.0-or-later. See [LICENSE](LICENSE) and [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
