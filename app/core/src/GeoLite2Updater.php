<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

use InvalidArgumentException;
use PharData;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class GeoLite2Updater
{
    private const EDITIONS = ['city' => 'GeoLite2-City', 'asn' => 'GeoLite2-ASN'];
    private const AUTH_BASE = 'https://download.maxmind.com/geoip/databases/';
    private const DELIVERY_HOSTS = [
        'mm-prod-geoip-databases.a2649acb697e2c09b632799562c076f2.r2.cloudflarestorage.com',
    ];
    private const REDIRECT_CODES = [301, 302, 303, 307, 308];
    private const MAX_REDIRECT_HOPS = 3;
    private const MAX_HEADER_BYTES = 65536;
    private const MAX_ARCHIVE = 157286400;
    private const MAX_MMDB = 104857600;
    private const MAX_ENTRIES = 200;
    private const USER_AGENT = 'Tenyen-Analytics/0.8.1';

    /** @var null|callable */
    private $httpDouble;

    public function __construct(
        private readonly string $root,
        private readonly array $config,
        private readonly Crypto $crypto,
        ?callable $httpDouble = null
    ) {
        $this->httpDouble = $httpDouble;
    }

    public static function validateAccountId(mixed $value): string
    {
        $value = trim((string) $value);
        if (!preg_match('/^[0-9]{1,20}$/', $value)) {
            throw new InvalidArgumentException('MaxMind account ID is required.');
        }
        return $value;
    }

    public static function validateLicenseKey(mixed $value): string
    {
        $value = trim((string) $value);
        if (!preg_match('/^[A-Za-z0-9_-]{12,80}$/', $value)) {
            throw new InvalidArgumentException('A valid MaxMind license key is required.');
        }
        return $value;
    }

    public static function maskSecret(string $value): string
    {
        return $value === '' ? 'not configured' : '••••••••' . substr($value, -4);
    }

    public static function validateArchivePath(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        return $path !== ''
            && !str_starts_with($path, '/')
            && !preg_match('~(^|/)\.\.(/|$)~', $path)
            && !str_contains($path, "\0");
    }

    /** @return list<string> */
    public static function deliveryHosts(): array
    {
        return self::DELIVERY_HOSTS;
    }

    public static function validateRedirectUrl(mixed $value): string
    {
        $url = is_string($value) ? trim($value) : '';
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('GeoLite2 redirect is missing or invalid.');
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || !in_array($parts['host'], self::DELIVERY_HOSTS, true)
        ) {
            throw new RuntimeException('GeoLite2 redirect host or scheme is not trusted.');
        }
        return $url;
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        $stored = $this->readJson($this->credentialsPath());
        $encoded = base64_decode((string) ($stored['license_key'] ?? ''), true);
        $key = $this->crypto->decryptSecret($encoded === false ? '' : $encoded);
        return [
            'account_id' => (string) ($stored['account_id'] ?? ''),
            'license_mask' => self::maskSecret($key),
            'configured' => (string) ($stored['account_id'] ?? '') !== '' && $key !== '',
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'schedule' => (string) ($stored['schedule'] ?? 'weekly'),
        ];
    }

    /** @return array<string,mixed> */
    public function saveSettings(mixed $accountId, mixed $licenseKey, mixed $enabled): array
    {
        $current = $this->readJson($this->credentialsPath());
        $account = self::validateAccountId($accountId);
        $key = trim((string) $licenseKey);
        if ($key === '') {
            $encrypted = (string) ($current['license_key'] ?? '');
            if ($encrypted === '') {
                throw new InvalidArgumentException('A MaxMind license key is required.');
            }
        } else {
            $encrypted = base64_encode($this->crypto->encryptSecret(self::validateLicenseKey($key)));
        }
        $payload = [
            'account_id' => $account,
            'license_key' => $encrypted,
            'enabled' => filter_var($enabled, FILTER_VALIDATE_BOOLEAN),
            'schedule' => 'weekly',
            'updated_at' => gmdate(DATE_ATOM),
        ];
        $this->writeJson($this->credentialsPath(), $payload);
        $state = $this->state();
        $state['enabled'] = $payload['enabled'];
        $state['next_run'] = $payload['enabled'] ? gmdate(DATE_ATOM, time() + 86400) : null;
        $this->writeJson($this->statePath(), $state);
        return $this->settings();
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        $default = [
            'enabled' => false,
            'schedule' => 'weekly',
            'next_run' => null,
            'last_run' => null,
            'retry_count' => 0,
            'city' => $this->emptyDatabase('city'),
            'asn' => $this->emptyDatabase('asn'),
        ];
        return array_replace_recursive($default, $this->readJson($this->statePath()));
    }

    public function due(): bool
    {
        $state = $this->state();
        return !empty($state['enabled'])
            && ($state['next_run'] === null || strtotime((string) $state['next_run']) <= time());
    }

    /** @return array<string,mixed> */
    public function updateAll(bool $scheduled = false): array
    {
        if ($scheduled && !$this->due()) {
            return ['status' => 'not_due'] + $this->publicStatus();
        }
        $directory = $this->workDirectory();
        $lock = fopen($directory . '/update.lock', 'c+');
        if ($lock === false) {
            throw new RuntimeException('A GeoLite2 update lock could not be created.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('A GeoLite2 update is already running.');
        }
        try {
            $results = [];
            foreach (array_keys(self::EDITIONS) as $kind) {
                try {
                    $results[$kind] = $this->update($kind);
                } catch (Throwable $error) {
                    $results[$kind] = ['ok' => false, 'message' => $this->safeError($error)];
                }
            }
            $state = $this->state();
            $failures = count(array_filter($results, static fn(array $result): bool => empty($result['ok'])));
            $state['last_run'] = gmdate(DATE_ATOM);
            $state['retry_count'] = $failures ? min(6, (int) $state['retry_count'] + 1) : 0;
            $delay = $failures
                ? min(7 * 86400, 6 * 3600 * (2 ** max(0, $state['retry_count'] - 1)))
                : 7 * 86400;
            $state['next_run'] = !empty($state['enabled']) ? gmdate(DATE_ATOM, time() + $delay) : null;
            $this->writeJson($this->statePath(), $state);
            return [
                'status' => $failures ? 'partial_failure' : 'success',
                'results' => $results,
                'next_run' => $state['next_run'],
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,mixed> */
    public function update(string $kind): array
    {
        $kind = $this->kind($kind);
        $credentials = $this->credentials();
        $state = $this->state();
        $state[$kind] = array_replace($state[$kind], [
            'last_attempt' => gmdate(DATE_ATOM),
            'status' => 'running',
            'error' => null,
        ]);
        $this->writeJson($this->statePath(), $state);
        $work = $this->workDirectory();
        $this->cleanupTemps($work);
        $token = bin2hex(random_bytes(16));
        $archive = $work . '/' . $kind . '-' . $token . '.tar.gz';
        $candidate = $work . '/' . $kind . '-' . $token . '.mmdb';
        try {
            $this->download($kind, $credentials, $archive);
            $this->extractExpected($archive, self::EDITIONS[$kind] . '.mmdb', $candidate);
            $inspection = (new Installer($this->root))->inspectMmdb($candidate, $kind);
            if (!$inspection['ok']) {
                throw new RuntimeException(
                    $inspection['type'] === ''
                        ? 'Downloaded MMDB is corrupt or unreadable.'
                        : 'Downloaded MMDB has the wrong database type.'
                );
            }
            $destination = $this->destination($kind);
            $this->atomicReplace($candidate, $destination);
            $meta = $this->metadata($destination, $kind, (string) $inspection['type']);
            $state = $this->state();
            $state[$kind] = array_replace($state[$kind], $meta, [
                'status' => 'current',
                'last_success' => gmdate(DATE_ATOM),
                'last_attempt' => gmdate(DATE_ATOM),
                'error' => null,
            ]);
            $this->writeJson($this->statePath(), $state);
            unset($meta['path']);
            return ['ok' => true, 'kind' => $kind] + $meta;
        } catch (Throwable $error) {
            $safe = $this->safeError($error);
            $state = $this->state();
            $state[$kind] = array_replace($state[$kind], $this->health($kind), [
                'status' => 'failed',
                'last_attempt' => gmdate(DATE_ATOM),
                'error' => $safe,
            ]);
            $this->writeJson($this->statePath(), $state);
            throw new RuntimeException($safe);
        } finally {
            @unlink($archive);
            @unlink($candidate);
        }
    }

    public function recordManual(string $kind): void
    {
        $kind = $this->kind($kind);
        $state = $this->state();
        $health = $this->health($kind);
        $state[$kind] = array_replace($state[$kind], $health, [
            'status' => $health['installed'] ? 'manual' : 'missing',
            'last_success' => $health['installed'] ? gmdate(DATE_ATOM) : $state[$kind]['last_success'],
            'error' => null,
        ]);
        $this->writeJson($this->statePath(), $state);
    }

    /** @return array<string,mixed> */
    public function publicStatus(): array
    {
        $state = $this->state();
        foreach (array_keys(self::EDITIONS) as $kind) {
            $state[$kind] = array_replace($state[$kind], $this->health($kind));
            unset($state[$kind]['path']);
        }
        $settings = $this->settings();
        unset($settings['account_id']);
        return ['settings' => $settings, 'state' => $state];
    }

    /** @return array{account_id:string,license_key:string} */
    private function credentials(): array
    {
        $stored = $this->readJson($this->credentialsPath());
        $encoded = base64_decode((string) ($stored['license_key'] ?? ''), true);
        $key = $this->crypto->decryptSecret($encoded === false ? '' : $encoded);
        return [
            'account_id' => self::validateAccountId($stored['account_id'] ?? ''),
            'license_key' => self::validateLicenseKey($key),
        ];
    }

    /** @param array{account_id:string,license_key:string} $credentials */
    private function download(string $kind, array $credentials, string $target): void
    {
        $url = self::AUTH_BASE . rawurlencode(self::EDITIONS[$kind]) . '/download?suffix=tar.gz';
        $response = $this->request($url, $target, $credentials);
        $seen = [];
        $hops = 0;
        while (in_array($response['status'], self::REDIRECT_CODES, true)) {
            if ($hops >= self::MAX_REDIRECT_HOPS) {
                throw new RuntimeException('GeoLite2 redirect limit was exceeded.');
            }
            $url = self::validateRedirectUrl($response['location']);
            $identity = hash('sha256', $url);
            if (isset($seen[$identity])) {
                throw new RuntimeException('GeoLite2 redirect loop was rejected.');
            }
            $seen[$identity] = true;
            $hops++;
            $response = $this->request($url, $target, null);
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException($this->httpError($response['status'], $hops === 0));
        }
        $size = is_file($target) ? (int) filesize($target) : 0;
        if ($size < 1024) {
            throw new RuntimeException('GeoLite2 downloaded archive is empty or incomplete.');
        }
        if ($size > self::MAX_ARCHIVE) {
            throw new RuntimeException('GeoLite2 download exceeded the archive size limit.');
        }
        @chmod($target, 0600);
    }

    /**
     * A new cURL handle is created for every call. Credentials are nullable so an
     * artifact request cannot inherit authentication from the MaxMind request.
     *
     * @param null|array{account_id:string,license_key:string} $credentials
     * @return array{status:int,location:?string,bytes:int}
     */
    private function request(string $url, string $target, ?array $credentials): array
    {
        if ($this->httpDouble !== null) {
            $result = ($this->httpDouble)($url, $target, $credentials, self::MAX_ARCHIVE);
            if (!is_array($result)) {
                throw new RuntimeException('GeoLite2 HTTPS request failed.');
            }
            $bytes = (int) ($result['bytes'] ?? (is_file($target) ? filesize($target) : 0));
            if ($bytes > self::MAX_ARCHIVE) {
                throw new RuntimeException('GeoLite2 download exceeded the archive size limit.');
            }
            return [
                'status' => (int) ($result['status'] ?? 0),
                'location' => isset($result['location']) ? (string) $result['location'] : null,
                'bytes' => $bytes,
            ];
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for automatic GeoLite2 updates.');
        }
        $out = fopen($target, 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not create the temporary archive.');
        }
        @chmod($target, 0600);
        $curl = curl_init($url);
        if ($curl === false) {
            fclose($out);
            throw new RuntimeException('GeoLite2 HTTPS request failed.');
        }
        $location = null;
        $written = 0;
        $headerBytes = 0;
        $sizeViolation = false;
        $headerViolation = false;
        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_FAILONERROR => false,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => ['Accept: application/gzip, application/octet-stream'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location, &$headerBytes, &$headerViolation): int {
                $length = strlen($line);
                $headerBytes += $length;
                if ($headerBytes > self::MAX_HEADER_BYTES) {
                    $headerViolation = true;
                    return 0;
                }
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                if (stripos($line, 'Content-Length:') === 0
                    && (int) trim(substr($line, 15)) > self::MAX_ARCHIVE
                ) {
                    $headerViolation = true;
                    return 0;
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($out, &$written, &$sizeViolation): int {
                $length = strlen($chunk);
                if ($written + $length > self::MAX_ARCHIVE) {
                    $sizeViolation = true;
                    return 0;
                }
                $offset = 0;
                while ($offset < $length) {
                    $count = fwrite($out, substr($chunk, $offset));
                    if ($count === false || $count === 0) {
                        return 0;
                    }
                    $offset += $count;
                }
                $written += $length;
                return $length;
            },
        ];
        if ($credentials !== null) {
            $options[CURLOPT_USERPWD] = $credentials['account_id'] . ':' . $credentials['license_key'];
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        }
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        fclose($out);
        if ($sizeViolation || $headerViolation) {
            @unlink($target);
            throw new RuntimeException('GeoLite2 download exceeded a response size limit.');
        }
        if ($ok !== true) {
            @unlink($target);
            throw new RuntimeException('GeoLite2 HTTPS request failed.');
        }
        return ['status' => $status, 'location' => $location, 'bytes' => $written];
    }

    private function httpError(int $status, bool $authenticationRequest): string
    {
        if ($authenticationRequest && $status === 401) {
            return 'MaxMind rejected the account ID or license key.';
        }
        if ($authenticationRequest && $status === 403) {
            return 'MaxMind credentials do not permit this database.';
        }
        if ($status === 429) {
            return 'MaxMind rate limit reached. Retry later.';
        }
        return 'GeoLite2 ' . ($authenticationRequest ? 'authentication' : 'artifact')
            . ' request failed with HTTP ' . max(0, $status) . '.';
    }

    private function extractExpected(string $archive, string $expected, string $target): void
    {
        try {
            $phar = new PharData($archive);
            $count = 0;
            $found = null;
            foreach (new RecursiveIteratorIterator($phar) as $path => $file) {
                $count++;
                $relative = str_replace('phar://' . $archive . '/', '', (string) $path);
                if ($count > self::MAX_ENTRIES || !self::validateArchivePath($relative)) {
                    throw new RuntimeException('The GeoLite2 archive contains unsafe paths.');
                }
                if ($file->isLink() || !in_array($file->getType(), ['file', 'dir'], true)) {
                    throw new RuntimeException('The GeoLite2 archive contains unsafe entry types.');
                }
                if ($file->isFile() && basename($relative) === $expected) {
                    if ($found !== null) {
                        throw new RuntimeException('The GeoLite2 archive contains duplicate databases.');
                    }
                    $found = $path;
                }
            }
            if ($found === null) {
                throw new RuntimeException('The expected MMDB is missing from the archive.');
            }
            $source = fopen((string) $found, 'rb');
            $out = fopen($target, 'wb');
            if ($source === false || $out === false) {
                throw new RuntimeException('Could not extract the GeoLite2 database.');
            }
            $written = stream_copy_to_stream($source, $out, self::MAX_MMDB + 1);
            fclose($source);
            fclose($out);
            if ($written === false || $written < 1024 || $written > self::MAX_MMDB) {
                throw new RuntimeException('The extracted MMDB size is invalid.');
            }
            @chmod($target, 0600);
        } catch (RuntimeException $error) {
            throw $error;
        } catch (Throwable) {
            throw new RuntimeException('The GeoLite2 archive is invalid.');
        }
    }

    private function atomicReplace(string $candidate, string $destination): void
    {
        $directory = dirname($destination);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('The configured GeoLite2 directory is not writable.');
        }
        $incoming = $destination . '.incoming-' . bin2hex(random_bytes(8));
        $backup = $destination . '.previous';
        if (!@rename($candidate, $incoming) && !@copy($candidate, $incoming)) {
            throw new RuntimeException('Could not stage the validated MMDB.');
        }
        @chmod($incoming, 0600);
        if (is_file($destination)) {
            @unlink($backup);
            if (!@rename($destination, $backup)) {
                @unlink($incoming);
                throw new RuntimeException('Could not preserve the current MMDB.');
            }
        }
        if (!@rename($incoming, $destination)) {
            if (is_file($backup)) {
                @rename($backup, $destination);
            }
            @unlink($incoming);
            throw new RuntimeException('Could not activate the validated MMDB.');
        }
        @chmod($destination, 0600);
        @unlink($backup);
    }

    /** @return array<string,mixed> */
    private function health(string $kind): array
    {
        $path = $this->destination($kind);
        if (!is_file($path)) {
            return ['installed' => false, 'readable' => false, 'health' => 'missing', 'path' => $path, 'filename' => basename($path), 'size' => 0, 'build_date' => null, 'stale' => true];
        }
        if (!is_readable($path)) {
            return ['installed' => true, 'readable' => false, 'health' => 'unreadable', 'path' => $path, 'filename' => basename($path), 'size' => (int) filesize($path), 'build_date' => null, 'stale' => true];
        }
        $inspection = (new Installer($this->root))->inspectMmdb($path, $kind);
        if (!$inspection['ok']) {
            return ['installed' => true, 'readable' => true, 'health' => $inspection['type'] === '' ? 'corrupt' : 'wrong_type', 'path' => $path, 'filename' => basename($path), 'size' => (int) filesize($path), 'build_date' => null, 'stale' => true];
        }
        return $this->metadata($path, $kind, (string) $inspection['type']);
    }

    /** @return array<string,mixed> */
    private function metadata(string $path, string $kind, string $type): array
    {
        $build = 0;
        try {
            $reader = class_exists(\MaxMind\Db\Reader::class) ? new \MaxMind\Db\Reader($path) : new MmdbReader($path);
            $metadata = $reader->metadata();
            $reader->close();
            $build = is_array($metadata)
                ? (int) ($metadata['build_epoch'] ?? 0)
                : (int) ($metadata->buildEpoch ?? 0);
        } catch (Throwable) {
        }
        if ($build <= 0) {
            $build = (int) (filemtime($path) ?: 0);
        }
        $stale = $build < time() - 45 * 86400;
        return [
            'installed' => true,
            'readable' => true,
            'health' => $stale ? 'stale' : 'current',
            'path' => $path,
            'filename' => basename($path),
            'database_type' => $type,
            'size' => (int) filesize($path),
            'build_date' => $build ? gmdate('Y-m-d', $build) : null,
            'stale' => $stale,
        ];
    }

    private function destination(string $kind): string
    {
        $geo = $this->config['geoip'] ?? [];
        return (string) ($kind === 'city'
            ? ($geo['city_database'] ?? $this->root . '/data/GeoLite2-City.mmdb')
            : ($geo['asn_database'] ?? $this->root . '/data/GeoLite2-ASN.mmdb'));
    }

    private function kind(string $kind): string
    {
        if (!isset(self::EDITIONS[$kind])) {
            throw new InvalidArgumentException('Database kind must be city or asn.');
        }
        return $kind;
    }

    private function workDirectory(): string
    {
        $directory = $this->root . '/storage/geolite2';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('GeoLite2 storage directory is unavailable.');
        }
        @chmod($directory, 0700);
        return $directory;
    }

    private function credentialsPath(): string
    {
        return $this->root . '/storage/geolite2-credentials.json';
    }

    private function statePath(): string
    {
        return $this->root . '/storage/geolite2-state.json';
    }

    /** @return array<string,mixed> */
    private function emptyDatabase(string $kind): array
    {
        $path = $this->destination($kind);
        return ['kind' => $kind, 'installed' => false, 'path' => $path, 'filename' => basename($path), 'build_date' => null, 'size' => 0, 'last_success' => null, 'last_attempt' => null, 'status' => 'never', 'health' => 'missing', 'error' => null];
    }

    private function cleanupTemps(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)
                && preg_match('/\.(tar\.gz|mmdb|incoming-[a-f0-9]+)$/', basename($file))
                && filemtime($file) !== false
                && filemtime($file) < time() - 86400
            ) {
                @unlink($file);
            }
        }
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Storage directory is not writable.');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('Could not save GeoLite2 state.');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not save GeoLite2 state.');
        }
    }

    private function safeError(Throwable $error): string
    {
        $message = $error->getMessage();
        $fixed = [
            'MaxMind rejected the account ID or license key.',
            'MaxMind credentials do not permit this database.',
            'MaxMind rate limit reached. Retry later.',
            'GeoLite2 redirect is missing or invalid.',
            'GeoLite2 redirect host or scheme is not trusted.',
            'GeoLite2 redirect limit was exceeded.',
            'GeoLite2 redirect loop was rejected.',
            'GeoLite2 HTTPS request failed.',
            'GeoLite2 download exceeded a response size limit.',
            'GeoLite2 download exceeded the archive size limit.',
            'GeoLite2 downloaded archive is empty or incomplete.',
            'PHP cURL is required for automatic GeoLite2 updates.',
            'The GeoLite2 archive contains unsafe paths.',
            'The GeoLite2 archive contains unsafe entry types.',
            'The GeoLite2 archive contains duplicate databases.',
            'The expected MMDB is missing from the archive.',
            'The GeoLite2 archive is invalid.',
            'The extracted MMDB size is invalid.',
            'Downloaded MMDB is corrupt or unreadable.',
            'Downloaded MMDB has the wrong database type.',
            'Could not stage the validated MMDB.',
            'Could not preserve the current MMDB.',
            'Could not activate the validated MMDB.',
            'The configured GeoLite2 directory is not writable.',
            'GeoLite2 storage directory is unavailable.',
            'A GeoLite2 update lock could not be created.',
            'A GeoLite2 update is already running.',
        ];
        if (in_array($message, $fixed, true)
            || preg_match('/^GeoLite2 (authentication|artifact) request failed with HTTP [0-9]{1,3}\.$/', $message)
        ) {
            return $message;
        }
        return 'GeoLite2 update failed. Check credentials, HTTPS access, and storage permissions.';
    }
}
