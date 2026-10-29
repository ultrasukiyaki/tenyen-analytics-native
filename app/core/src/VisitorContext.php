<?php
declare(strict_types=1);
namespace Tenyen\Analytics;

/** Plain text only: callers escape for the output context. */
final class VisitorContext
{
    private static function join(array $values, string $separator = ' / '): string
    {
        return implode($separator, array_filter(array_map(static fn($v) => trim((string)$v), $values), static fn($v) => $v !== '')) ?: '—';
    }
    public static function location(array $row): string
    {
        $code = strtoupper(trim((string)($row['country_code'] ?? '')));
        $country = trim((string)($row['country_name'] ?? ''));
        if (preg_match('/^[A-Z]{2}$/D', $code)) $country = $country === '' ? $code : $country . ' (' . $code . ')';
        return self::join([$country, $row['region'] ?? '', $row['city'] ?? '']);
    }
    public static function organization(array $row): string
    {
        $asn = filter_var($row['asn'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        return self::join([$asn === false ? '' : 'AS' . $asn, $row['asn_org'] ?? ''], ' ');
    }
    public static function environment(array $row): string
    {
        return self::join([$row['browser'] ?? '', $row['os'] ?? '', $row['device_type'] ?? '']);
    }
}
