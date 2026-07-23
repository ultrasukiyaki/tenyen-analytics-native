<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

final class IpResolver
{
    public static function resolve(array $server, string $trustedHeader = ''): string
    {
        $remote = self::validIp((string)($server['REMOTE_ADDR'] ?? ''));

        if ($trustedHeader === '') {
            return $remote;
        }

        $headerMap = [
            'cf-connecting-ip' => 'HTTP_CF_CONNECTING_IP',
            'x-real-ip' => 'HTTP_X_REAL_IP',
            'x-forwarded-for' => 'HTTP_X_FORWARDED_FOR',
        ];
        $key = $headerMap[strtolower($trustedHeader)] ?? '';
        if ($key === '' || empty($server[$key])) {
            return $remote;
        }

        $candidates = explode(',', (string)$server[$key]);
        foreach ($candidates as $candidate) {
            $ip = self::validIp(trim($candidate));
            if ($ip !== '') {
                return $ip;
            }
        }

        return $remote;
    }

    public static function version(string $ip): int
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 4;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 6;
        }
        return 0;
    }

    private static function validIp(string $ip): string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }
}
