# Administrator metadata and saved views

## Scope and identity

Metadata belongs to the installed site and configured administrator. It is never accepted by or returned from the public collector. The current single-administrator configuration is represented by a one-way owner key so a later migration can introduce account IDs without making orphaned views public.

| Concept | `entity_type` | Stable key |
|---|---|---|
| Organization | `organization` | Numeric ASN without the `AS` prefix |
| Anonymous visitor | `visitor` | Existing opaque `visitor_id` |
| Content | `content` | Existing stored normalized path |
| Referrer domain | `referrer` | Lowercase normalized domain |
| Campaign | `campaign` | Ordered JSON object: `source`, `medium`, `campaign`, `content`, `term` |
| External target | `external_target` | Lowercase normalized target domain |

The SHA-256 of the exact normalized key is stored as a fixed-length lookup value while the original normalized key remains available for display. Uniqueness includes entity type and hash. SHA-256 collisions are treated as computationally infeasible; the retained key makes an unexpected mismatch observable.

Content annotations do not follow redirects or URL changes automatically. They remain attached to the stored path and can become orphaned. The Knowledge screen continues to expose orphaned annotations for review. Similar titles never merge content.

## Limits and behavior

Aliases are optional plain text up to 120 Unicode characters. Notes are optional plain text up to 4,000 Unicode characters. Tags have case-insensitive unique names up to 50 characters, a preset accessible color, and a limit of 50 assignments per entity. Output is always escaped. Deleting a tag cascades only its assignments and never analytics.

Watch status applies only to numeric ASNs. It changes no MaxMind name, classification, session, event, Human/Bot value, or metric and sends no notification. An ASN indicates the network organization to which an address is registered; it does not prove a visitor’s employer or identity.

Saved views use schema version 1 and report-specific keys. Relative presets (`today`, `yesterday`, `7d`, `30d`) are recalculated by the report when loaded; custom dates remain absolute. A view may be pinned and one view per report/owner may be the default. Unknown keys and invalid types, dates, actors, or sort directions are rejected. Page number, session/CSRF/authentication state, site token, credentials, encryption keys, decrypted IP, SQL, request IDs, and API bodies are not stored.

Note search is limited to the centralized Knowledge screen to avoid adding text scans to high-volume analytics reports.

## Schema and indexes

Fresh installation and the idempotent v0.6.1 upgrade create:

- `tya_annotations`: one row per `(entity_type, entity_hash)`, with `watched/type/updated` and `type/updated` indexes for management and watchlist queries.
- `tya_tags`: unique `normalized_name`.
- `tya_annotation_tags`: primary key `(annotation_id, tag_id)` and reverse `(tag_id, annotation_id)` index; both foreign keys cascade metadata only.
- `tya_saved_views`: owner/report, owner/pinned, and owner/report/default indexes.

The exact DDL is the four `CREATE TABLE IF NOT EXISTS` statements in `app/schema.php`. `SchemaMigrator` executes the same statements for upgrades after the existing v0.6.1 event migration. It does not alter or rewrite `tya_events`.

## Upgrade preservation

Application upgrades must retain `config.php`, `data/`, `storage/`, `storage/installed.lock`, database and administrator credentials, encryption/HMAC keys, site token, GeoLite2 City/ASN files, language preference, sessions, logs, caches, and all analytics rows. The release archive contains protected empty `data/` and `storage/` directories but no runtime files.

Notifications, lead scoring, exclusion management, export, retention management, daily aggregates, and external enrichment are intentionally absent from v0.6.2.
