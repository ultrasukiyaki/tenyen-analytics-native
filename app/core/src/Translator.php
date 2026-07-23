<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

final class Translator
{
    /** @var array<string,array<string,string>> */
    private array $catalogues = [];

    public function __construct(
        private readonly string $locale = 'en',
        private readonly string $fallbackLocale = 'en',
        private readonly ?string $directory = null
    ) {
    }

    public function locale(): string
    {
        return in_array($this->locale, ['en', 'ja'], true) ? $this->locale : 'en';
    }

    public function htmlLang(): string
    {
        return $this->locale();
    }

    public function browserLocale(): string
    {
        return $this->locale() === 'ja' ? 'ja-JP' : 'en-US';
    }

    /** @param array<string,string|int|float> $replacements */
    public function get(string $key, array $replacements = []): string
    {
        $value = $this->catalogue($this->locale())[$key]
            ?? $this->catalogue($this->fallbackLocale)[$key]
            ?? $key;
        foreach ($replacements as $name => $replacement) {
            $value = str_replace('{' . $name . '}', (string)$replacement, $value);
        }
        return $value;
    }

    /** @param list<string> $keys @return array<string,string> */
    public function subset(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    /** @return array<string,string> */
    private function catalogue(string $locale): array
    {
        $locale = in_array($locale, ['en', 'ja'], true) ? $locale : 'en';
        if (!isset($this->catalogues[$locale])) {
            $file = ($this->directory ?? dirname(__DIR__, 2) . '/i18n') . '/' . $locale . '.php';
            $catalogue = is_file($file) ? require $file : [];
            $this->catalogues[$locale] = is_array($catalogue) ? $catalogue : [];
        }
        return $this->catalogues[$locale];
    }
}
