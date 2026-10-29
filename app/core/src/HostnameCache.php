<?php
declare(strict_types=1);
namespace Tenyen\Analytics;
use PDO;

final class HostnameCache
{
    public function __construct(private readonly PDO $pdo) {}
    /** One query for the visible page. No decryption or DNS. */
    public function forRows(array $rows): array
    {
        $hashes = [];
        foreach ($rows as $row) if (is_string($row['ip_hash'] ?? null) && strlen($row['ip_hash']) === 32) $hashes[bin2hex($row['ip_hash'])] = $row['ip_hash'];
        if (!$hashes) return [];
        $statement = $this->pdo->prepare('SELECT ip_hash,hostname,status,expires_at FROM tya_hostname_cache WHERE ip_hash IN (' . implode(',', array_fill(0, count($hashes), '?')) . ')');
        $statement->execute(array_values($hashes)); $result = [];
        foreach ($statement->fetchAll() as $row) $result[bin2hex($row['ip_hash'])] = $row;
        return $result;
    }
    public static function display(?array $entry, Translator $translator): string
    {
        if (!$entry || (string)$entry['expires_at'] <= gmdate('Y-m-d H:i:s')) return $translator->get('hostname.pending');
        $hostname = ReverseDns::hostname((string)($entry['hostname'] ?? ''));
        return $entry['status'] === 'resolved' && $hostname !== '' ? $hostname : $translator->get('hostname.unavailable');
    }
    /** CLI worker: at most 20 queries, one at a time, under a database lock. */
    public function run(Crypto $crypto, ?callable $resolve = null): array
    {
        $lock = 'tya-hostname-' . substr(hash('sha256', (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
        $claim = $this->pdo->prepare('SELECT GET_LOCK(?,0)'); $claim->execute([$lock]);
        if ((int)$claim->fetchColumn() !== 1) return ['processed' => 0, 'busy' => true];
        try {
            $this->prune();
            $rows = $this->pdo->query("SELECT e.ip_hash,MAX(e.event_id) event_id FROM tya_events e LEFT JOIN tya_hostname_cache c ON c.ip_hash=e.ip_hash WHERE e.ip_hash IS NOT NULL AND (c.ip_hash IS NULL OR c.expires_at<=UTC_TIMESTAMP()) GROUP BY e.ip_hash ORDER BY event_id DESC LIMIT 20")->fetchAll();
            $event = $this->pdo->prepare('SELECT ip_encrypted FROM tya_events WHERE event_id=?');
            $save = $this->pdo->prepare('INSERT INTO tya_hostname_cache (ip_hash,hostname,status,expires_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE hostname=VALUES(hostname),status=VALUES(status),expires_at=VALUES(expires_at)');
            $resolve ??= (new ReverseDns())->resolve(...);
            $processed = 0; $deadline = microtime(true) + 15;
            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) break;
                $event->execute([(int)$row['event_id']]); $ip = $crypto->decryptIp($event->fetchColumn() ?: null);
                $result = ['status' => 'unavailable', 'hostname' => ''];
                if (ReverseDns::publicAddress($ip)) {
                    try { $result = $resolve($ip); } catch (\Throwable) { $result = ['status' => 'failed', 'hostname' => '']; }
                }
                $hostname = ReverseDns::hostname((string)($result['hostname'] ?? ''));
                $status = ($result['status'] ?? '') === 'resolved' && $hostname !== '' ? 'resolved' : (($result['status'] ?? '') === 'failed' ? 'failed' : 'unavailable');
                $ttl = $status === 'resolved' ? 86400 : ($status === 'failed' ? 3600 : 21600);
                $save->execute([$row['ip_hash'], $status === 'resolved' ? $hostname : '', $status, gmdate('Y-m-d H:i:s', time() + $ttl)]); $processed++;
            }
            return ['processed' => $processed, 'busy' => false];
        } finally { $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lock]); }
    }
    public function prune(): void
    {
        $this->pdo->exec('DELETE c FROM tya_hostname_cache c WHERE NOT EXISTS (SELECT 1 FROM tya_events e WHERE e.ip_hash=c.ip_hash)');
    }
}
