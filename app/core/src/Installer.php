<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

use PDO;
use RuntimeException;
use Throwable;

final class Installer
{
    public function __construct(private readonly string $root)
    {
    }

    /** @return array<string,array{ok:bool,label:string,detail:string,required:bool}> */
    public function environment(): array
    {
        $storage = $this->root . '/storage';
        $data = $this->root . '/data';
        $config = $this->root . '/config.php';
        $configWritable = is_file($config) ? is_writable($config) : is_writable($this->root);
        $crypto = function_exists('sodium_crypto_secretbox') || function_exists('openssl_encrypt');
        $uploadLimit = min(self::iniBytes((string)ini_get('upload_max_filesize')), self::iniBytes((string)ini_get('post_max_size')));
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        return [
            'php' => ['ok' => PHP_VERSION_ID >= 80100, 'label' => 'PHP 8.1以上', 'detail' => PHP_VERSION, 'required' => true],
            'pdo' => ['ok' => extension_loaded('pdo'), 'label' => 'PDO', 'detail' => extension_loaded('pdo') ? '利用可能' : '未導入', 'required' => true],
            'pdo_mysql' => ['ok' => extension_loaded('pdo_mysql'), 'label' => 'PDO MySQL', 'detail' => extension_loaded('pdo_mysql') ? '利用可能' : '未導入', 'required' => true],
            'json' => ['ok' => extension_loaded('json'), 'label' => 'JSON', 'detail' => extension_loaded('json') ? '利用可能' : '未導入', 'required' => true],
            'crypto' => ['ok' => $crypto, 'label' => 'IP暗号化', 'detail' => function_exists('sodium_crypto_secretbox') ? 'Sodium' : (function_exists('openssl_encrypt') ? 'OpenSSL' : '利用不可'), 'required' => true],
            'random' => ['ok' => function_exists('random_bytes'), 'label' => '安全な乱数生成', 'detail' => function_exists('random_bytes') ? '利用可能' : '利用不可', 'required' => true],
            'config' => ['ok' => $configWritable, 'label' => 'config.php作成権限', 'detail' => $configWritable ? '書込み可能' : '書込み不可', 'required' => true],
            'storage' => ['ok' => is_dir($storage) && is_writable($storage), 'label' => 'storage書込み権限', 'detail' => is_dir($storage) && is_writable($storage) ? '書込み可能' : 'FTPで755または775を確認', 'required' => true],
            'data' => ['ok' => is_dir($data) && is_writable($data), 'label' => 'data書込み権限', 'detail' => is_dir($data) && is_writable($data) ? '書込み可能' : 'GeoLite2をGUIアップロードする場合は書込み権限が必要', 'required' => false],
            'mmdb' => ['ok' => class_exists(MmdbReader::class), 'label' => 'GeoLite2読込機能', 'detail' => class_exists(\MaxMind\Db\Reader::class) ? '公式Reader' : '内蔵Reader', 'required' => true],
            'upload' => ['ok' => $uploadLimit >= 1024 * 1024, 'label' => 'PHPアップロード上限', 'detail' => self::humanBytes($uploadLimit) . '（MMDBは512KBずつ分割するため低い上限でも対応）', 'required' => false],
            'transport' => ['ok' => $https, 'label' => 'HTTPS', 'detail' => $https ? 'HTTPSで接続中' : 'HTTPテスト環境（本番ではHTTPS推奨）', 'required' => false],
        ];
    }

    public function canInstall(): bool
    {
        foreach ($this->environment() as $check) {
            if ($check['required'] && !$check['ok']) return false;
        }
        return true;
    }

    /** @param array{host:string,port:int,name:string,user:string,password:string} $database */
    public function connect(array $database): PDO
    {
        $host = trim($database['host']);
        $name = trim($database['name']);
        $user = trim($database['user']);
        $port = max(1, min(65535, (int)$database['port']));
        if ($host === '' || $name === '' || $user === '') {
            throw new RuntimeException('DBホスト・DB名・DBユーザーを入力してください。');
        }
        if (!preg_match('/^[A-Za-z0-9_$-]+$/', $name)) {
            throw new RuntimeException('DB名に使用できない文字が含まれています。');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
        return new PDO($dsn, $user, (string)$database['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /** @return array{ok:bool,type:string,message:string} */
    public function inspectMmdb(string $path, string $expected): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['ok' => false, 'type' => '', 'message' => 'ファイルを読み込めません。'];
        }
        try {
            $reader = class_exists(\MaxMind\Db\Reader::class)
                ? new \MaxMind\Db\Reader($path)
                : new MmdbReader($path);
            $metadataObject = $reader->metadata();
            $type = '';
            if (is_array($metadataObject)) {
                $type = (string)($metadataObject['database_type'] ?? '');
            } elseif (is_object($metadataObject) && isset($metadataObject->databaseType)) {
                $type = (string)$metadataObject->databaseType;
            }
            $reader->close();
            $matches = $expected === 'city'
                ? (stripos($type, 'City') !== false || stripos($type, 'Country') !== false)
                : stripos($type, 'ASN') !== false;
            return [
                'ok' => $matches,
                'type' => $type,
                'message' => $matches ? 'データベースを確認しました。' : '選択した種類とMMDBの内容が一致しません。',
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'type' => '', 'message' => 'MMDBを検査できません：' . $e->getMessage()];
        }
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{admin_url:string,collect_url:string,config_url:string,tracker_url:string,embed_code:string}
     */
    public function install(array $settings): array
    {
        if (!$this->canInstall()) {
            throw new RuntimeException('必須の動作環境を満たしていません。');
        }
        $database = $settings['database'] ?? [];
        if (!is_array($database)) throw new RuntimeException('データベース設定がありません。');
        /** @var array{host:string,port:int,name:string,user:string,password:string} $database */
        $pdo = $this->connect($database);
        $schema = require $this->root . '/app/schema.php';
        $pdo->exec($schema);

        $publicUrl = rtrim((string)($settings['public_url'] ?? ''), '/');
        $siteUrl = rtrim((string)($settings['site_url'] ?? ''), '/');
        if (!self::httpUrl($publicUrl) || !self::httpUrl($siteUrl)) {
            throw new RuntimeException('サイトURLまたは公開URLが正しくありません。');
        }
        $timezone = (string)($settings['timezone'] ?? 'Asia/Tokyo');
        if (!in_array($timezone, timezone_identifiers_list(), true)) $timezone = 'Asia/Tokyo';
        $adminUser = trim((string)($settings['admin_username'] ?? ''));
        $adminHash = (string)($settings['admin_password_hash'] ?? '');
        if ($adminUser === '' || $adminHash === '') {
            throw new RuntimeException('管理画面アカウントが設定されていません。');
        }

        $config = [
            'app' => [
                'base_url' => $publicUrl,
                'site_url' => $siteUrl,
                'timezone' => $timezone,
                'locale' => in_array(($settings['locale'] ?? 'auto'), ['en', 'ja'], true)
                    ? (string)$settings['locale']
                    : 'auto',
                'fallback_locale' => 'en',
                'site_token' => bin2hex(random_bytes(32)),
                'encryption_secret' => bin2hex(random_bytes(48)),
                'hash_secret' => bin2hex(random_bytes(48)),
                'retention_days' => 90,
                'trusted_proxy_header' => '',
                'log_bots' => true,
                'organization_overrides' => [],
            ],
            'database' => [
                'dsn' => sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    trim((string)$database['host']),
                    (int)$database['port'],
                    trim((string)$database['name'])
                ),
                'user' => trim((string)$database['user']),
                'password' => (string)$database['password'],
                'options' => [],
            ],
            'geoip' => [
                'city_database' => '__ROOT__/data/GeoLite2-City.mmdb',
                'asn_database' => '__ROOT__/data/GeoLite2-ASN.mmdb',
            ],
            'admin' => [
                'username' => $adminUser,
                'password_hash' => $adminHash,
            ],
        ];

        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        $php = str_replace("'__ROOT__/data/", "__DIR__ . '/data/", $php);
        $this->atomicWrite($this->root . '/config.php', $php, 0600);

        $rateLimit = $this->root . '/storage/ratelimit';
        if (!is_dir($rateLimit) && !mkdir($rateLimit, 0700, true) && !is_dir($rateLimit)) {
            throw new RuntimeException('storage/ratelimitを作成できません。');
        }
        $lockPayload = json_encode([
            'version' => '0.5.7',
            'installed_at' => gmdate(DATE_ATOM),
            'public_url' => $publicUrl,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->atomicWrite($this->root . '/storage/installed.lock', (string)$lockPayload . "\n", 0600);

        $configUrl = $publicUrl . '/config.js.php';
        $trackerUrl = $publicUrl . '/tracker.js';
        return [
            'admin_url' => $publicUrl . '/admin/',
            'collect_url' => $publicUrl . '/collect.php',
            'config_url' => $configUrl,
            'tracker_url' => $trackerUrl,
            'embed_code' => '<script src="' . $configUrl . '"></script>' . "\n"
                . '<script defer src="' . $trackerUrl . '"></script>',
        ];
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('書込み先ディレクトリへアクセスできません：' . $directory);
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('一時ファイルを書き込めません：' . $temporary);
        }
        @chmod($temporary, $mode);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('設定ファイルを確定できません：' . $path);
        }
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') return PHP_INT_MAX;
        $unit = strtolower(substr($value, -1));
        $number = (float)$value;
        return match ($unit) {
            'g' => (int)($number * 1024 * 1024 * 1024),
            'm' => (int)($number * 1024 * 1024),
            'k' => (int)($number * 1024),
            default => (int)$number,
        };
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) return rtrim(rtrim(number_format($bytes / (1024 * 1024 * 1024), 1), '0'), '.') . ' GB';
        if ($bytes >= 1024 * 1024) return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1), '0'), '.') . ' MB';
        return max(0, (int)ceil($bytes / 1024)) . ' KB';
    }

    private static function httpUrl(string $value): bool
    {
        if (!filter_var($value, FILTER_VALIDATE_URL)) return false;
        return in_array(strtolower((string)parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
