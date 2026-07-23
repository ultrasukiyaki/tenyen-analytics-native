<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

final class BotDetector
{
    private const PATTERN = '/bot|crawler|spider|slurp|bingpreview|facebookexternalhit|monitoring|uptime|headless|phantom|selenium|curl|wget|python-requests|go-http-client|httpclient|scrapy/i';

    public static function isBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return true;
        }
        return preg_match(self::PATTERN, $userAgent) === 1;
    }
}
