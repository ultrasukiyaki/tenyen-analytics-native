[日本語](README.ja.md)

# Tenyen Analytics

Tenyen Analytics is a self-hosted web analytics platform for PHP websites with a browser-based installer, pageview and engagement tracking, GeoLite2 geolocation, ASN insights, bot detection, and an asynchronous administration console.

## Overview

The application stores analytics on infrastructure you control. It does not use a CDN, an external analytics service, or a required production build step.

## Features

- Pageview, engagement, external-click, and download collection
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

Sign in with the installer-created account. Dashboard, Real-time, Access History, Content, Referrers, ASN / Organizations, Audience, Engagement, System, and Settings load asynchronously. Settings can store a non-secret interface-language preference under protected `storage/`.

## CLI tools

Run `php bin/doctor.php` for diagnostics, `php bin/cleanup.php` for retention cleanup, `php bin/generate-secrets.php` for credentials, or `php bin/install.php` for CLI installation guidance.

## Updating from an earlier version

Back up first, then overwrite application files while preserving `config.php`, `data/`, and `storage/`. Version 0.6.0 requires no database migration. Existing v0.5.7 and earlier configurations and data, including configurations without locale keys, remain supported.

Version 0.6.0 adds authenticated, CSRF-protected session and anonymous-browser journey views. A bounce is an entry session with exactly one pageview. Bounce rate is bounces divided by entries; exit rate is sessions where a page is the exit divided by that page's pageviews. Empty denominators return 0%.

## Privacy and security

Deployments may process IP addresses, access history, referrers, user-agent/device data, and geographic/ASN enrichment. Operators must disclose their retention period and self-hosted processing in their privacy policy. Restrict access to configuration, logs, backups, and analytics data.

## Troubleshooting

Check PHP 8.1+, PDO MySQL, directory permissions, database credentials, the configured public URL, and HTTPS. GeoLite2 is optional; collection works without it.

## Development

Run `php tests/run.php`, PHP lint, JavaScript syntax checks, and `tools/build-release.sh`. Production operation must not require Composer or Node.

## License

Copyright © 2026 10yendama.com. Licensed under GPL-2.0-or-later. See [LICENSE](LICENSE) and [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
