<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

final class LocaleResolver
{
    /** @param array<string,mixed> $config */
    public static function resolve(
        array $config = [],
        ?string $runtimePreference = null,
        ?string $installerPreference = null,
        ?string $acceptLanguage = null
    ): string {
        $configured = self::valid($runtimePreference)
            ?? self::valid($config['app']['locale'] ?? null)
            ?? self::valid($installerPreference)
            ?? 'auto';
        if ($configured !== 'auto') {
            return $configured;
        }
        return self::fromAcceptLanguage($acceptLanguage ?? '');
    }

    public static function validate(mixed $locale): ?string
    {
        return self::valid($locale);
    }

    public static function fromAcceptLanguage(string $header): string
    {
        foreach (preg_split('/\s*,\s*/', strtolower($header)) ?: [] as $part) {
            $language = explode(';', $part, 2)[0];
            if ($language === 'ja' || str_starts_with($language, 'ja-')) {
                return 'ja';
            }
            if ($language === 'en' || str_starts_with($language, 'en-')) {
                return 'en';
            }
        }
        return 'en';
    }

    private static function valid(mixed $locale): ?string
    {
        return is_string($locale) && in_array($locale, ['auto', 'en', 'ja'], true)
            ? $locale
            : null;
    }
}
