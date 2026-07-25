<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

final class TrafficAttribution
{
    private const UTM_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
    private const SEARCH_HOSTS = ['google.', 'bing.com', 'search.yahoo.', 'duckduckgo.com', 'baidu.com', 'yandex.'];
    private const SOCIAL_HOSTS = [
        'facebook.com', 'instagram.com', 'twitter.com', 'x.com', 'bsky.app',
        'linkedin.com', 'reddit.com', 'youtube.com', 'youtu.be', 'mastodon.social',
    ];

    /** @return array{channel:string,referrer_domain:string,utm_source:string,utm_medium:string,utm_campaign:string,utm_content:string,utm_term:string} */
    public static function classify(string $path, string $referrer, string $siteUrl): array
    {
        $utm = self::utm($path);
        $referrerDomain = self::host($referrer);
        $siteDomain = self::host($siteUrl);
        if (array_filter($utm, static fn(string $value): bool => $value !== '')) {
            $channel = 'Campaign';
        } elseif ($referrer === '') {
            $channel = 'Direct';
        } elseif ($referrerDomain === '') {
            $channel = 'Unknown';
        } elseif ($siteDomain !== '' && $referrerDomain === $siteDomain) {
            $channel = 'Internal';
        } elseif (self::matches($referrerDomain, self::SEARCH_HOSTS)) {
            $channel = 'Organic Search';
        } elseif (self::matches($referrerDomain, self::SOCIAL_HOSTS) || str_contains($referrerDomain, 'mastodon.')) {
            $channel = 'Social';
        } else {
            $channel = 'Referral';
        }
        return ['channel' => $channel, 'referrer_domain' => $referrerDomain] + $utm;
    }

    /** @return array{utm_source:string,utm_medium:string,utm_campaign:string,utm_content:string,utm_term:string} */
    private static function utm(string $path): array
    {
        $result = array_fill_keys(self::UTM_FIELDS, '');
        $query = (string)parse_url($path, PHP_URL_QUERY);
        if ($query === '') return $result;
        foreach (explode('&', $query) as $pair) {
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = strtolower(urldecode($rawKey));
            if (!array_key_exists($key, $result) || $result[$key] !== '') continue;
            $result[$key] = self::text(urldecode(str_replace('+', ' ', $rawValue)), 255);
        }
        return $result;
    }

    private static function host(string $url): string
    {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return '';
        $host = strtolower(rtrim((string)parse_url($url, PHP_URL_HOST), '.'));
        return preg_match('/^[a-z0-9.-]{1,253}$/', $host) ? preg_replace('/^www\./', '', $host) : '';
    }

    /** @param list<string> $needles */
    private static function matches(string $host, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_ends_with($needle, '.') ? str_contains($host, $needle) : ($host === $needle || str_ends_with($host, '.' . $needle))) return true;
        }
        return false;
    }

    private static function text(string $value, int $length): string
    {
        if (!preg_match('//u', $value)) return '';
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
