<?php
declare(strict_types=1);
namespace Tenyen\Analytics;

/** Bounded PTR queries using the host's configured resolver. */
final class ReverseDns
{
    public static function publicAddress(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        $binary = inet_pton($ip);
        if (strlen($binary) === 16) {
            $hex = bin2hex($binary);
            return (ord($binary[0]) & 0xe0) === 0x20 && !str_starts_with($hex, '20010db8') && !str_starts_with($hex, '20010000') && !str_starts_with($hex, '2002');
        }
        $n = unpack('N', $binary)[1];
        foreach (['100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3'] as $cidr) {
            [$network, $bits] = explode('/', $cidr);
            $mask = (0xffffffff << (32 - (int)$bits)) & 0xffffffff;
            if (($n & $mask) === (unpack('N', inet_pton($network))[1] & $mask)) return false;
        }
        return true;
    }
    public static function hostname(string $name): string
    {
        $name = strtolower(rtrim($name, '.'));
        if (strlen($name) > 253 || filter_var($name, FILTER_VALIDATE_IP)) return '';
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $name) ? $name : '';
    }
    public function resolve(string $ip): array
    {
        if (!self::publicAddress($ip)) return ['status' => 'unavailable', 'hostname' => ''];
        $configuration = @file_get_contents('/etc/resolv.conf');
        if (!is_string($configuration) || !preg_match('/^\s*nameserver\s+([0-9a-fA-F:.]+)\s*$/m', $configuration, $match) || !filter_var($match[1], FILTER_VALIDATE_IP)) return ['status' => 'failed', 'hostname' => ''];
        $server = str_contains($match[1], ':') ? '[' . $match[1] . ']' : $match[1];
        $socket = @stream_socket_client('udp://' . $server . ':53', $errno, $error, 0.5);
        if (!$socket) return ['status' => 'failed', 'hostname' => ''];
        try {
            stream_set_timeout($socket, 0, 500000);
            $reverse = str_contains($ip, ':') ? implode('.', array_reverse(str_split(bin2hex(inet_pton($ip))))) . '.ip6.arpa' : implode('.', array_reverse(explode('.', $ip))) . '.in-addr.arpa';
            $id = random_int(0, 65535); $question = '';
            foreach (explode('.', $reverse) as $label) $question .= chr(strlen($label)) . $label;
            $request = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0) . $question . "\0" . pack('nn', 12, 1);
            if (fwrite($socket, $request) !== strlen($request)) return ['status' => 'failed', 'hostname' => ''];
            $packet = fread($socket, 4096);
            return self::response(is_string($packet) ? $packet : '', $id, $reverse);
        } finally { fclose($socket); }
    }
    public static function response(string $packet, int $id, string $question): array
    {
        $failed = ['status' => 'failed', 'hostname' => ''];
        if (strlen($packet) < 12) return $failed;
        $header = unpack('nid/nflags/nquestions/nanswers/nauthority/nadditional', substr($packet, 0, 12));
        if ($header['id'] !== $id || ($header['flags'] & 0xfa00) !== 0x8000 || $header['questions'] !== 1) return $failed;
        $rcode = $header['flags'] & 15;
        if (!in_array($rcode, [0, 3], true)) return $failed;
        try {
            $offset = 12;
            if (self::name($packet, $offset) !== $question || substr($packet, $offset, 4) !== pack('nn', 12, 1)) return $failed;
            $offset += 4;
            if ($rcode === 3) return ['status' => 'unavailable', 'hostname' => ''];
            $owners = [$question => true];
            for ($i = 0; $i < min(64, $header['answers']); $i++) {
                $owner = self::name($packet, $offset);
                if ($offset + 10 > strlen($packet)) return $failed;
                $record = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10)); $offset += 10;
                $end = $offset + $record['length'];
                if ($end > strlen($packet)) return $failed;
                if (isset($owners[$owner]) && $record['class'] === 1 && in_array($record['type'], [5, 12], true)) {
                    $cursor = $offset; $value = self::name($packet, $cursor);
                    if ($cursor !== $end) return $failed;
                    if ($record['type'] === 5) $owners[$value] = true;
                    else {
                        $hostname = self::hostname($value);
                        if ($hostname !== '') return ['status' => 'resolved', 'hostname' => $hostname];
                    }
                }
                $offset = $end;
            }
            return ['status' => 'unavailable', 'hostname' => ''];
        } catch (\RuntimeException) { return $failed; }
    }
    private static function name(string $packet, int &$offset): string
    {
        $cursor = $offset; $jumped = false; $labels = []; $visited = [];
        for ($steps = 0; $steps < 128; $steps++) {
            if ($cursor >= strlen($packet) || isset($visited[$cursor])) throw new \RuntimeException('Invalid DNS name.');
            $visited[$cursor] = true; $length = ord($packet[$cursor++]);
            if (($length & 0xc0) === 0xc0) {
                if ($cursor >= strlen($packet)) throw new \RuntimeException('Invalid DNS pointer.');
                $pointer = (($length & 63) << 8) | ord($packet[$cursor++]);
                if (!$jumped) $offset = $cursor;
                $jumped = true; $cursor = $pointer; continue;
            }
            if ($length > 63 || $cursor + $length > strlen($packet)) throw new \RuntimeException('Invalid DNS label.');
            if (!$length) { if (!$jumped) $offset = $cursor; return strtolower(implode('.', $labels)); }
            $labels[] = substr($packet, $cursor, $length); $cursor += $length;
            if (strlen(implode('.', $labels)) > 253) throw new \RuntimeException('DNS name too long.');
        }
        throw new \RuntimeException('DNS compression loop.');
    }
}
