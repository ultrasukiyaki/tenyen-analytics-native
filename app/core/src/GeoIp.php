<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

use Throwable;

final class GeoIp
{
    public function __construct(
        private readonly string $cityDatabase,
        private readonly string $asnDatabase
    ) {
    }

    public function isReaderAvailable(): bool
    {
        return class_exists(\MaxMind\Db\Reader::class) || class_exists(MmdbReader::class);
    }

    /** @return array<string,mixed> */
    public function lookup(string $ip): array
    {
        $result = [
            'country_code' => '',
            'country_name' => '',
            'region' => '',
            'city' => '',
            'latitude' => null,
            'longitude' => null,
            'accuracy_radius' => null,
            'asn' => null,
            'asn_org' => '',
        ];

        if ($ip === '' || !$this->isReaderAvailable()) {
            return $result;
        }

        if (is_readable($this->cityDatabase)) {
            try {
                $reader = $this->createReader($this->cityDatabase);
                $record = $reader->get($ip);
                $reader->close();
                if (is_array($record)) {
                    $result['country_code'] = self::string($record, ['country', 'iso_code']);
                    $result['country_name'] = self::localizedName($record['country']['names'] ?? []);
                    $subdivision = $record['subdivisions'][0] ?? [];
                    $result['region'] = self::localizedName(is_array($subdivision) ? ($subdivision['names'] ?? []) : []);
                    $result['city'] = self::localizedName($record['city']['names'] ?? []);
                    $result['latitude'] = self::number($record, ['location', 'latitude']);
                    $result['longitude'] = self::number($record, ['location', 'longitude']);
                    $result['accuracy_radius'] = self::integer($record, ['location', 'accuracy_radius']);
                }
            } catch (Throwable $e) {
                error_log('[Tenyen Analytics] City MMDB lookup failed: ' . $e->getMessage());
            }
        }

        if (is_readable($this->asnDatabase)) {
            try {
                $reader = $this->createReader($this->asnDatabase);
                $record = $reader->get($ip);
                $reader->close();
                if (is_array($record)) {
                    $result['asn'] = isset($record['autonomous_system_number'])
                        ? (int)$record['autonomous_system_number']
                        : null;
                    $result['asn_org'] = isset($record['autonomous_system_organization'])
                        ? (string)$record['autonomous_system_organization']
                        : '';
                }
            } catch (Throwable $e) {
                error_log('[Tenyen Analytics] ASN MMDB lookup failed: ' . $e->getMessage());
            }
        }

        return $result;
    }

    /** @return object{get:callable,close:callable} */
    private function createReader(string $database): object
    {
        if (class_exists(\MaxMind\Db\Reader::class)) {
            return new \MaxMind\Db\Reader($database);
        }
        return new MmdbReader($database);
    }

    /** @param array<string,mixed> $names */
    private static function localizedName(array $names): string
    {
        $fallback = reset($names);
        return (string)($names['ja'] ?? $names['en'] ?? (is_scalar($fallback) ? $fallback : ''));
    }

    /** @param array<string,mixed> $array @param list<string> $path */
    private static function string(array $array, array $path): string
    {
        $value = self::value($array, $path);
        return is_scalar($value) ? (string)$value : '';
    }

    /** @param array<string,mixed> $array @param list<string> $path */
    private static function number(array $array, array $path): ?float
    {
        $value = self::value($array, $path);
        return is_numeric($value) ? (float)$value : null;
    }

    /** @param array<string,mixed> $array @param list<string> $path */
    private static function integer(array $array, array $path): ?int
    {
        $value = self::value($array, $path);
        return is_numeric($value) ? (int)$value : null;
    }

    /** @param array<string,mixed> $array @param list<string> $path */
    private static function value(array $array, array $path): mixed
    {
        $value = $array;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }
}
