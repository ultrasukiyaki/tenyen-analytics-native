# Visitor context and hostname display (v0.9.0)

Dashboard recent views and realtime rows show stored country code/name, region, and city alongside ASN and registered organization. Session lists, session details, and visitor details use the same formatting. Missing values appear as `—`. ASN registrations and PTR hostnames describe network infrastructure; neither verifies a visitor's employer or identity.

Access history displays a reverse-DNS hostname instead of the decrypted IP, including its tooltip and expanded details. If no cached result exists, the UI shows “Awaiting hostname lookup”; negative or failed results show “Hostname unavailable”. Existing browser column preferences are retained: enable Hostname and Location in display settings if previously hidden.

## Enable hostname refresh

Schedule this CLI worker every minute using your hosting scheduler or cron, with the application's absolute path:

```cron
* * * * * /usr/bin/php /path/to/tenyen-analytics/bin/resolve-hostnames.php
```

Without this worker, uncached hostnames remain pending. It requires PHP UDP stream support and a valid nameserver in `/etc/resolv.conf`. It uses the first configured resolver and never calls an external lookup API. Queries send the public IP to that resolver. Private, loopback, link-local, documentation, and non-public addresses are skipped.

Each run handles up to 20 unique IP hashes, one query at a time, with a 500 ms DNS timeout and a 15-second processing budget. A database advisory lock prevents overlapping workers. Recent events take priority. Successful results refresh after 24 hours, missing PTR records after six hours, and resolver failures after one hour. Truncated or malformed DNS responses fail safely and use the failure delay; TCP fallback is not performed.

Collection and administrator requests do not perform reverse DNS. History reads the visible rows' cache entries in one query. Existing encrypted IP storage, exact-IP HMAC search, and explicit export IP modes are retained.

## Upgrade and retention

The normal idempotent schema migration adds `tya_hostname_cache`, keyed by the existing IP hash with an expiry index. No existing event columns are changed. Keep `config.php`, `data/`, `storage/`, installation lock, credentials, keys, tokens, MMDBs, and logs when upgrading. Do not rerun setup.

The hostname worker and successful log cleanup remove cache entries with no remaining source events. Hostnames remain administrator-only and are not included in existing exports. Deleting a database backup also requires your normal personal-data retention procedure.

## Display parity

| View | v0.9.0 behavior |
|---|---|
| Dashboard recent views | Adds stored location; preserves ASN and classification |
| Realtime | Adds stored location; preserves live state, ASN and engagement |
| History | Shared location/network/environment formatting and cached hostname |
| Sessions list/detail | Adds country code, region and city to existing country/ASN context |
| Visitor detail | Adds complete location, ASN, organization and classification |
| Audience/organizations | Preserves separate country and network aggregates |
| Content/referrer/campaign/event aggregates | Preserves aggregate dimensions; no arbitrary country or ASN |

The comparison baseline is WordPress v0.8.1 (`6499c0f`). Dashboard/realtime location and Native's existing separate ASN columns intentionally extend the WordPress layout. No WordPress hooks or capability APIs are copied.
