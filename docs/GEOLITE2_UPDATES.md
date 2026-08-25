# GeoLite2 automatic updates

Version 0.8.0 keeps GeoLite2 City and ASN independently healthy while retaining manual MMDB upload. Configure a MaxMind account ID and license key over the HTTPS administration screen. The key is encrypted with the installation encryption secret, stored with mode 0600 under protected `storage/`, masked in the UI, excluded from logs, JavaScript, and release packages, and never written to `config.php` during upgrades.

Run `php bin/geolite2-update.php scheduled` daily from a trusted host cron. Enabled installations download only when the weekly due time arrives. Failures use a six-hour exponential backoff capped at seven days. `status`, `run`, `city`, and `asn` provide bounded CLI maintenance. A non-blocking lock prevents overlap.

Downloads use the fixed MaxMind HTTPS endpoint without redirects. Archives are limited to 150 MB and 200 entries; absolute paths, traversal, duplicates, missing expected MMDBs, corrupt databases, and wrong City/ASN types are rejected. The expected MMDB is copied to a protected temporary file, validated with the existing reader, staged beside the destination, and atomically renamed. The previous valid database is restored if activation fails. Temporary files older than one day are removed.

Health tracks installed/readable state, configured path internally, safe filename, database type, build date, size, staleness after 45 days, last attempt/success, status, and safe errors separately for City and ASN. Filesystem paths and secrets are not returned to the browser. A City failure never disables ASN or vice versa.

Automatic updates require PHP cURL and outbound HTTPS. Manual chunked upload remains supported when automatic credentials or outbound access are unavailable. MMDB files, credentials, state, installation locks, configuration, analytics, and aggregates are never included in the release archive.
