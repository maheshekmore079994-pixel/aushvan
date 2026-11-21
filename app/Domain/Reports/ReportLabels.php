<?php

namespace App\Domain\Reports;

/**
 * Words for exported files, read from the same translation files the screens use
 * (resources/js/locales), so a column is called the same thing on screen and in Excel or PDF.
 */
final class ReportLabels
{
    /** @var array<string, array<string, mixed>> */
    private static array $loaded = [];

    public function __construct(private string $locale) {}

    public function get(string $key, array $replace = []): string
    {
        $text = $this->find($this->locale, $key) ?? $this->find('en', $key) ?? $key;
        foreach ($replace as $name => $value) {
            $text = str_replace('{{'.$name.'}}', (string) $value, $text);
        }

        return $text;
    }

    private function find(string $locale, string $key): ?string
    {
        if (! isset(self::$loaded[$locale])) {
            $path = resource_path("js/locales/{$locale}.json");
            self::$loaded[$locale] = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        }

        $value = data_get(self::$loaded[$locale], $key);

        return is_string($value) ? $value : null;
    }
}
