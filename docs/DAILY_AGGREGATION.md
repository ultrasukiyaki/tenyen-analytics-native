# Daily aggregation and performance

Version 0.7.1 preserves statistical history before raw-log cleanup. It aggregates only complete days in the configured application timezone. Raw event facts are never rewritten.

## Data model

`tya_daily_metrics` stores Human, Bot, and combined daily totals for pageviews, estimated visitors, sessions, bounces, entries/exits, custom/click/download/404 events, and engagement/scroll numerators and sample counts. `tya_daily_dimensions` stores bounded daily content (200), channel (50), referrer (100), campaign (100), country (250), ASN/organization (100), and event (100) rows. `tya_aggregate_state` stores the range checkpoint and safe error state. Primary and range indexes serve day/actor and dimension/date access; no raw-event index is added without production `EXPLAIN` evidence.

Sessions are attributed to the local day of their first pageview. A bounce is a session with one pageview; entries and exits preserve counts, not precomputed rates. Engagement uses the maximum valid duration and 1–100 scroll depth per session/path sample. Reports derive means from sums and sample counts. Daily distinct visitors are estimates across a multi-day range.

## Jobs and recovery

Run `php bin/aggregate.php incremental` daily from a trusted CLI cron before `php bin/cleanup.php scheduled`. A call processes at most 31 days. Use `resume`, `day YYYY-MM-DD`, or `range FROM TO` for recovery and correction. Rerunning a day deletes its dimension rows and upserts its single actor rows in one transaction, so it does not duplicate totals. Incremental runs rebuild the most recent complete day to include late engagement.

The authenticated, CSRF-protected Lifecycle screen exposes the same bounded actions and status. There is no public maintenance endpoint. Dates are strict `YYYY-MM-DD`, ranges are limited to 730 days, actor/dimension values are allowlisted, SQL is prepared, and errors do not expose SQL, paths, credentials, tokens, decrypted IPs, or stack traces.

## Raw/aggregate boundary and cleanup

Dashboard daily/monthly reports read each available aggregate day once and query raw data only for missing days. This permits raw-only, aggregate-only, and mixed ranges without overlap. Real-time, access-history, visitor, and session drill-down remain raw-only.

Retention cutoff is aligned to local midnight, retaining at most one extra partial day. Cleanup compares every eligible raw local day with `tya_daily_metrics`; incomplete coverage blocks deletion. Analysis exclusions are applied while building aggregates. If exclusion rules change, rebuild affected days while raw data is still available.

Back up before upgrading. Preserve `config.php`, `data/`, `storage/`, `storage/installed.lock`, credentials, keys, site token, MMDB files, language preference, events, sessions, annotations, tags, exclusions, saved views, and lifecycle state.
