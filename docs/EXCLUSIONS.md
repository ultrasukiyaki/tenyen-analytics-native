# Exclusion rules

Version 0.6.3 separates collection exclusions from analysis exclusions. Collection rules are prospective: a matching request returns an accepted response but is not written to `tya_events`. Analysis rules preserve matching events and hide them from reports through `tya_event_exclusions`. Disabling or deleting an analysis rule makes preserved history visible again.

## Rule types and matching

Rules support exact IPv4/IPv6, IPv4/IPv6 CIDR, exact URI path, URI prefix, authenticated Native administrator sessions, bots, country, region, ASN, organization substring, organization category, browser, OS, device, referrer domain, and UTM source/medium/campaign. URI matching uses the normalized path without a query string. Text dimensions are compared case-insensitively; organization rules use a case-insensitive substring. No user regular expressions or raw SQL are accepted.

The deterministic precedence is Native administrator, exact IP, CIDR, exact URI, URI prefix, bot, country, region, ASN, organization, organization category, browser, OS, device, referrer domain, UTM source, UTM medium, then UTM campaign. The diagnostic lists every match and identifies the winning rule, precedence, scope, action, and reason.

## Administration and privacy

Only the authenticated administration console can list, create, edit, disable, diagnose, or delete rules. Every write and diagnostic request requires CSRF validation. Values and notes are bounded, rule types/scopes are allowlisted, SQL is prepared, and output is escaped. The public collector returns only a generic exclusion result and never exposes rule definitions.

Existing encrypted IPs are decrypted only inside the server-side bounded backfill used when an analysis rule is saved. Reports query indexed event-to-rule matches and do not decrypt or filter the full dataset in PHP. Historical events are not deleted. Exact-IP and CIDR rules support both IPv4 and IPv6.

## Upgrade and schema

The idempotent upgrade adds `tya_exclusion_rules` and `tya_event_exclusions`. Their indexes cover enabled scope/precedence lookup and event/rule joins. The event table and existing analytics facts are not altered. Preserve `config.php`, `data/`, `storage/`, `storage/installed.lock`, credentials, keys, site token, MMDB files, events, sessions, metadata, saved views, and language preference when replacing application files.
