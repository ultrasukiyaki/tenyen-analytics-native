<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

final class Payload
{
    private const EVENT_TYPES = ['pageview', 'engagement', 'outbound', 'download', 'internal_link', 'button', 'form_submit', 'not_found', 'custom'];

    public static function normalize(array $input): array
    {
        $event = self::text($input['event'] ?? 'pageview', 32);
        if (!in_array($event, self::EVENT_TYPES, true)) throw new \InvalidArgumentException('Unsupported event.');
        $eventName = self::text($input['event_name'] ?? '', 64);
        if ($event === 'custom' && !preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $eventName)) throw new \InvalidArgumentException('Invalid event name.');

        return [
            'event' => $event,
            'event_name' => $eventName,
            'visitor_id' => self::uuid($input['visitor_id'] ?? ''),
            'session_id' => self::uuid($input['session_id'] ?? ''),
            'path' => self::text($input['path'] ?? '/', 2048),
            'title' => self::text($input['title'] ?? '', 512),
            'referrer' => self::url($input['referrer'] ?? ''),
            'language' => self::text($input['language'] ?? '', 32),
            'timezone' => self::text($input['timezone'] ?? '', 64),
            'screen' => self::text($input['screen'] ?? '', 32),
            'viewport' => self::text($input['viewport'] ?? '', 32),
            'duration_ms' => self::integer($input['duration_ms'] ?? 0, 0, 86400000),
            'scroll_depth' => self::integer($input['scroll_depth'] ?? 0, 0, 100),
            'target_url' => self::url($input['target_url'] ?? ''),
            'metadata' => self::metadata($input['metadata'] ?? []),
        ];
    }

    /** @return array<string,string|int|float|bool|null> */
    private static function metadata(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value) || count($value) > 12) {
            if ($value === [] || $value === null) return [];
            throw new \InvalidArgumentException('Invalid metadata.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,31}$/', $key) || !(is_scalar($item) || $item === null)) {
                throw new \InvalidArgumentException('Invalid metadata.');
            }
            $result[$key] = is_string($item) ? self::text($item, 255) : $item;
        }
        return $result;
    }

    private static function uuid(mixed $value): string
    {
        $value = is_string($value) ? strtolower($value) : '';
        return preg_match('/^[a-f0-9-]{16,64}$/', $value) ? $value : '';
    }

    private static function text(mixed $value, int $maxLength): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';
        if (!preg_match('//u', $value)) return '';
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength, 'UTF-8')
            : substr($value, 0, $maxLength);
    }

    private static function integer(mixed $value, int $min, int $max): int
    {
        $value = is_numeric($value) ? (int)$value : 0;
        return max($min, min($max, $value));
    }

    private static function url(mixed $value): string
    {
        $value = self::text($value, 2048);
        if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL)) return '';
        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || parse_url($value, PHP_URL_USER) !== null) return '';
        return explode('#', $value, 2)[0];
    }
}
