<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

final class UserAgentParser
{
    public static function parse(string $ua): array
    {
        $browser = 'Other';
        $os = 'Other';
        $device = 'desktop';

        $browsers = [
            'Edg/' => 'Edge',
            'OPR/' => 'Opera',
            'Firefox/' => 'Firefox',
            'Chrome/' => 'Chrome',
            'CriOS/' => 'Chrome iOS',
            'Safari/' => 'Safari',
        ];
        foreach ($browsers as $needle => $name) {
            if (str_contains($ua, $needle)) {
                $browser = $name;
                break;
            }
        }

        $systems = [
            'Windows NT' => 'Windows',
            'Android' => 'Android',
            'iPhone' => 'iOS',
            'iPad' => 'iPadOS',
            'Macintosh' => 'macOS',
            'CrOS' => 'ChromeOS',
            'Linux' => 'Linux',
        ];
        foreach ($systems as $needle => $name) {
            if (str_contains($ua, $needle)) {
                $os = $name;
                break;
            }
        }

        if (preg_match('/Mobile|Android|iPhone|IEMobile/i', $ua)) {
            $device = 'mobile';
        } elseif (preg_match('/iPad|Tablet/i', $ua)) {
            $device = 'tablet';
        }

        return compact('browser', 'os', 'device');
    }
}
