# Log lifecycle, export, and retention

Version 0.7.0 added authenticated CSV/JSON export, raw-log retention, bounded cleanup, and storage diagnostics. Version 0.7.1 now blocks cleanup until daily aggregate coverage preserves eligible days; detailed session and visitor drill-down still disappears with deleted raw events. See [Daily aggregation and performance](DAILY_AGGREGATION.md).

## Export

Exports cover access/event logs, sessions, content summaries, organizations, traffic sources, campaigns, and events. Date, Human/Bot, source, campaign, event, content, country, ASN, organization, watch, tag, and analysis-exclusion filters are applied where meaningful. CSV prefixes formula-like cells with an apostrophe. JSON uses the stable `tenyen.analytics.export.v1` envelope. Rows are fetched with an unbuffered statement and written incrementally.

IP addresses are omitted by default. Masked mode exports IPv4 as `/24` and IPv6 as `/48`. Raw IP mode is restricted to an authenticated, CSRF-protected request with the exact `EXPORT_RAW_IP` confirmation. Export responses use private no-store headers.

## Retention and cleanup

Retention supports unlimited, 30, 90, 180, 365, or 1–3650 custom days. Preview reports the UTC cutoff plus affected event and distinct-session counts. Cleanup holds a non-blocking file lock, selects at most 1,000 IDs per transaction, cascades only event exclusion matches, and records its cutoff and progress after each batch. A stopped job resumes with the same cutoff. Settings, annotations, tags, saved views, credentials, keys, tokens, MMDB files, and unrelated data are never selected for deletion.

Run `php bin/cleanup.php preview`, `php bin/cleanup.php run`, or configure a trusted daily CLI cron for `php bin/cleanup.php scheduled`. There is no public maintenance URL. Lifecycle state and safe failure status are stored atomically in `storage/lifecycle.json` with restrictive permissions.

## Storage diagnostics

The Lifecycle & Export view reports database and event-table sizes, raw-event and distinct-session counts, oldest/newest records, 24 recent monthly counts, configured retention, and cleanup status including last attempt/run, next run, cutoff, remaining rows, and a safe error message.

## Upgrade

v0.7.0 adds no database tables or indexes. Preserve `config.php`, `data/`, `storage/`, `storage/installed.lock`, administrator/database credentials, encryption/HMAC keys, site token, MMDB files, language preference, events, sessions, exclusion rules, annotations, tags, and saved views. Existing installations use `app.retention_days` until an administrator saves a lifecycle override.
