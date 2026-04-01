<?php

namespace App\Services\Translation;

use Illuminate\Http\Request;

class LocaleManager
{
    public function normalize(?string $locale): string
    {
        $fallback = $this->defaultLocale();
        $value = trim((string) $locale);

        if ($value === '') {
            return $fallback;
        }

        $supported = config('translation.supported_locales', []);
        if (array_key_exists($value, $supported)) {
            return $value;
        }

        $normalized = strtolower(str_replace('_', '-', $value));
        $aliases = config('translation.aliases', []);
        if (array_key_exists($normalized, $aliases)) {
            return $aliases[$normalized];
        }

        $prefix = strtok($normalized, '-');
        if ($prefix && array_key_exists($prefix, $aliases)) {
            return $aliases[$prefix];
        }

        return $fallback;
    }

    public function resolveRequestLocale(Request $request): string
    {
        $candidates = [
            $request->header('X-MES-Locale'),
            $request->query('locale'),
            $request->input('locale'),
            $request->cookie('mes_locale'),
            $request->getPreferredLanguage(array_keys(config('translation.supported_locales', []))),
        ];

        foreach ($candidates as $candidate) {
            $locale = $this->normalize($candidate);
            if ($locale !== '') {
                return $locale;
            }
        }

        return $this->defaultLocale();
    }

    public function defaultLocale(): string
    {
        return $this->normalize(config('translation.source_locale', 'en'));
    }

    public function isSupported(string $locale): bool
    {
        return array_key_exists($this->normalize($locale), config('translation.supported_locales', []));
    }

    public function shouldTranslate(string $locale): bool
    {
        return $this->normalize($locale) !== $this->defaultLocale();
    }

    public function htmlLang(string $locale): string
    {
        $locale = $this->normalize($locale);

        return (string) data_get(config('translation.supported_locales'), "{$locale}.html_lang", $locale);
    }

    public function providerLocale(string $locale): string
    {
        $locale = $this->normalize($locale);

        return (string) data_get(config('translation.supported_locales'), "{$locale}.provider_locale", $locale);
    }

    public function supportedLocales(): array
    {
        return array_keys(config('translation.supported_locales', []));
    }
}
