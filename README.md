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
- Bot detection, organization classification, and twelve asynchronous admin views
- Collection and analysis exclusion rules with deterministic diagnostics
- Streaming CSV/JSON export, retention-safe cleanup controls, and storage diagnostics
- Retention-safe daily aggregates with resumable rebuilds and hybrid long-range reports
- Independent GeoLite2 City/ASN health, manual upload, and safe scheduled updates
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

![Tenyen Analytics administration dashboard](screenshot_dashboard.png)

## Administrator knowledge layer

Version 0.6.2 adds site-scoped administrator aliases (120 characters), plain-text notes (4,000 characters), reusable case-insensitive tags (50 characters), organization watch status, and private saved views. Supported annotation identities are numeric ASN, the existing opaque anonymous-visitor ID, canonical stored content path, normalized referrer domain, a deterministic JSON tuple of the five UTM dimensions, and external target domain. Aliases are displayed alongside—not instead of—collected values, and metadata never changes raw analytics facts.

Watching an ASN only marks and filters it; it does not send notifications or score a lead. ASN data identifies the organization to which an address is registered and does not prove a visitor’s employer or personal identity. Content annotations remain keyed to the stored normalized path if a URL later changes; orphaned annotations remain available in Knowledge for review.

Saved views are private to the configured administrator using a forward-compatible owner key. They store only report-allowlisted filters, relative or absolute date settings, Human/Bot selection, sorting, page size, visible columns, tag/watch filters, pin state, and one optional default per report. Page numbers, authentication state, CSRF values, tokens, secrets, decrypted IPs, SQL, and response bodies are never saved. Relative dates are recalculated when loaded; custom dates stay absolute.

See [Administrator metadata and saved views](docs/ADMIN_METADATA.md) for entity-key, schema, index, orphan, and upgrade details.

## Exclusion rules

Version 0.6.3 adds authenticated management and diagnostics for exact IP, CIDR, URI, Native administrator, Bot, geo/ASN/organization, browser/OS/device, referrer-domain, and UTM rules. Collection scope prevents future storage; analysis scope hides matching preserved history without deleting it. See [Exclusion rules](docs/EXCLUSIONS.md) for precedence, matching, privacy, and upgrade details.

## Log lifecycle and export

Version 0.7.0 streams filtered access logs, sessions, content, organizations, traffic sources, campaigns, and events as CSV or stable-schema JSON. IPs are omitted by default, with masked and explicitly confirmed raw modes. Retention supports unlimited, presets, and validated custom days; cleanup provides preview counts, an overlap lock, bounded transactions, resumable state, CLI scheduling, and storage diagnostics. See [Log lifecycle, export, and retention](docs/LOG_LIFECYCLE.md).

## Daily aggregation

Version 0.7.1 stores completed local-day totals and bounded content, channel, referrer, campaign, country, ASN/organization, and event dimensions. Dashboard date reports combine covered aggregate days with uncovered raw days at a non-overlapping boundary. Rebuilds are idempotent, checkpointed, limited to 31 days per invocation, and resumable. Cleanup is blocked until every eligible raw day has aggregate coverage. See [Daily aggregation and performance](docs/DAILY_AGGREGATION.md).

## GeoLite2 maintenance

Version 0.8.0 adds independent City and ASN health, encrypted MaxMind license-key storage, conservative weekly scheduling, manual update-now, locking, retry backoff, validated archive extraction, and atomic MMDB replacement. A failed update keeps the current valid database, and manual upload remains available. See [GeoLite2 automatic updates](docs/GEOLITE2_UPDATES.md).

## Event and campaign integration

Automatic external-link and download tracking remains enabled. Internal links can be enabled with `track_internal_links`; buttons require both `track_buttons` and `data-tenyen-event="name"`; forms require `track_forms` and the same explicit attribute. Form values, DOM content, passwords, and payment data are never collected.

Use `TYAnalytics.trackEvent('radio_play', {station: 'example-station', server: 'primary'})` or `TYAnalytics.trackEvent('stream_server_change', {server: 'backup'})`. Names and scalar metadata are strictly bounded. This is a generic API, not automatic radio integration.

In an application/router-independent 404 template, call `TYAnalytics.trackNotFound(location.href)`. To avoid ambiguity, omit the normal pageview embed on that template or accept the pageview plus explicit 404 event intentionally.

Channels are Direct, Organic Search, Social, Referral, Internal, Campaign, and Unknown. Recognized `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, and `utm_term` values override referrer classification. Campaign reports use the landing page as session first touch; later UTM values remain event metadata.

## CLI tools

Run `php bin/doctor.php` for diagnostics, `php bin/aggregate.php incremental` for daily rollups, `php bin/geolite2-update.php scheduled` for due GeoLite2 updates, `php bin/cleanup.php` for retention cleanup, or the existing credential/install tools.

## Updating from an earlier version

Back up first, then overwrite application files while preserving `config.php`, `data/`, and `storage/`. Upgrading from v0.7.1 to v0.8.0 changes no database schema. Existing configuration, installation lock, credentials, keys, language preference, MMDB files, analytics, aggregates, exclusions, metadata, and lifecycle state remain supported. GeoLite2 credentials and update state are created under protected `storage/` only after configuration.

Bounce rate remains one-page entry sessions divided by entry sessions. Engagement averages are calculated from preserved sums and sample counts, never by averaging daily averages. Daily distinct visitors are estimates and can count the same anonymous browser again on another day. Notifications, multi-site, and roles remain deferred.

## Privacy and security

Deployments may process IP addresses, access history, referrers, user-agent/device data, and geographic/ASN enrichment. Operators must disclose their retention period and self-hosted processing in their privacy policy. Restrict access to configuration, logs, backups, and analytics data.

## Troubleshooting

Check PHP 8.1+, PDO MySQL, directory permissions, database credentials, the configured public URL, and HTTPS. GeoLite2 is optional; collection works without it.

## Development

Run `php tests/run.php`, PHP lint, JavaScript syntax checks, and `tools/build-release.sh`. Production operation must not require Composer or Node.

## License

Copyright © 2026 10yendama.com. Licensed under GPL-2.0-or-later. See [LICENSE](LICENSE) and [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
