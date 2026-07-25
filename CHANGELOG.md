English | [日本語](CHANGELOG.ja.md)

# Changelog

## 0.6.1 - 2026-07-26

### Added

- Added stable traffic-channel and referrer-domain classification with first-touch UTM attribution.
- Added Events and Campaigns reports and event steps in session journeys.
- Added bounded `TYAnalytics.trackEvent()` and `trackNotFound()` APIs.
- Added configurable internal-link, explicit button, and opt-in form-submit tracking.

### Changed

- Unified new events with the existing collector, Human/Bot classification, authentication, CSRF, and asynchronous view routing.
- Separated English and Japanese changelogs.

### Security

- Limited UTM fields, event names, metadata keys, scalar values, and payload depth; form values and DOM content are never collected.

### Compatibility

- Adds nullable/defaulted attribution and event columns plus `channel_time`, `campaign_time`, and `event_name_time` indexes through an idempotent migration.
- v0.6.0 data remains valid. Preserve `config.php`, `data/`, and `storage/` during upgrade.

## 0.6.0 - 2026-07-25

### Added

- Added an asynchronous session list, ordered session journeys, anonymous visitor history, content journey metrics, and navigation from access history.

### Changed

- Extended English and standard-Japanese resources and removed residual dialect text.

### Security

- Protected the session API with existing authentication and CSRF verification.

### Compatibility

- No schema changes; v0.5.7 configuration and data remain compatible.

## 0.5.7 - 2026-07-23

### Fixed

- Prevented long expanded-history values from overlapping columns, enabled safe wrapping, and updated the stylesheet cache key.
- No schema changes; v0.5.6 data remains compatible.

## 0.5.6 - 2026-07-23

### Fixed

- Replaced remaining Kansai-dialect interface text, audited translations, and preserved raw MaxMind organization names.
- No schema changes; v0.5.5 data remains compatible.

## 0.5.5

- Added English and standard-Japanese UI support, localization, public repository metadata, GPL licensing, bilingual documentation, community files, CI, tests, and release tooling.
- Preserved raw MaxMind organization names. No schema changes.

## 0.5.4

- Fixed persistent loading UI caused by CSS overriding `hidden`; added GeoLite2 upload to Settings while retaining System upload.
- Documented HTTP test uploads. No schema changes.

## 0.5.3

- Added chunked GeoLite2 upload, progress and validation, session-login authentication with Basic-auth fallback, HTTP test support, HTTPS-only Secure cookies, and corrected Apache protection.
- No schema changes.

## 0.5.2

- Added the browser installer, generated configuration/secrets/token/schema/lock, MMDB validation and built-in reader, asynchronous administration views, diagnostics, origin validation, and shared-hosting protection.
- No schema changes.

## 0.5.0

- Added authenticated asynchronous history, filters, pagination, display preferences, and HMAC exact IP search.
- No schema changes.

## 0.4.1

- Unified time-bucket SQL with the WordPress edition and removed `DATE_FORMAT()` dependency.
- No schema changes.

## 0.4.0

- Added safe content/referrer links, PV/visitor/session/engagement metrics, trends, environment breakdowns, Human/Bot selection, `app.site_url`, and local charts.
- No schema changes.

## 0.3.0

- Added ASN organization classification, notable activity, combined recent views, rankings, raw-log details, and configuration overrides.
- No schema changes from v0.2.0.

## 0.2.0

- Added search, date/event/Human-Bot filters, and 25/50/100 pagination.

## 0.1.1

- Fixed administrator password generation display and FastCGI Basic authentication.

## 0.1.0

- Initial Native PHP release.
