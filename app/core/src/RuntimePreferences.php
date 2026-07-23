<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

use RuntimeException;

final class RuntimePreferences
{
    public function __construct(private readonly string $path)
    {
    }

    /** @return array{locale?:string} */
    public function load(): array
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($this->path), true);
        if (!is_array($decoded)) {
            return [];
        }
        $locale = LocaleResolver::validate($decoded['locale'] ?? null);
        return $locale === null ? [] : ['locale' => $locale];
    }

    public function saveLocale(string $locale): void
    {
        if (LocaleResolver::validate($locale) === null) {
            throw new RuntimeException('Unsupported locale.');
        }
        $directory = dirname($this->path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Runtime preferences directory is not writable.');
        }
        $temporary = $this->path . '.tmp-' . bin2hex(random_bytes(6));
        $payload = json_encode(['locale' => $locale], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Could not write runtime preferences.');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not save runtime preferences.');
        }
    }
}
