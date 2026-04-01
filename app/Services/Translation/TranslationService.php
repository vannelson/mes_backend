<?php

namespace App\Services\Translation;

use App\Services\Translation\Providers\LibreTranslateProvider;
use App\Services\Translation\Providers\NullTranslationProvider;
use App\Services\Translation\Providers\OpenAiTranslationProvider;
use App\Services\Translation\Providers\TranslationProvider;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class TranslationService
{
    public function __construct(
        protected LocaleManager $localeManager
    ) {
    }

    public function defaultLocale(): string
    {
        return $this->localeManager->defaultLocale();
    }

    public function normalizeLocale(?string $locale): string
    {
        return $this->localeManager->normalize($locale);
    }

    /**
     * @param array<int, string> $texts
     * @return array<int, string>
     */
    public function translateMany(array $texts, string $targetLocale, ?string $sourceLocale = null): array
    {
        $targetLocale = $this->normalizeLocale($targetLocale);
        $sourceLocale = $this->normalizeLocale($sourceLocale ?: $this->defaultLocale());
        $normalizedTexts = array_map(
            static fn ($value) => is_scalar($value) ? trim((string) $value) : '',
            array_values($texts)
        );

        if (!$this->localeManager->shouldTranslate($targetLocale) || $sourceLocale === $targetLocale) {
            return $normalizedTexts;
        }

        $provider = $this->resolveProvider();
        $results = $normalizedTexts;
        $pending = [];

        foreach ($normalizedTexts as $index => $text) {
            if (!$this->isTranslatable($text)) {
                continue;
            }

            $cacheKey = $this->cacheKey($sourceLocale, $targetLocale, $text);
            $cached = Cache::get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                $results[$index] = $cached;
                continue;
            }

            $pending[$index] = $text;
        }

        if ($pending === []) {
            return $results;
        }

        $ttl = now()->addMinutes((int) config('translation.cache_ttl_minutes', 43200));
        $chunkSize = max(1, (int) config('translation.chunk_size', 40));

        foreach (array_chunk($pending, $chunkSize, true) as $chunk) {
            $translated = $provider->translateMany(
                array_values($chunk),
                $this->localeManager->providerLocale($sourceLocale),
                $this->localeManager->providerLocale($targetLocale)
            );

            if (count($translated) !== count($chunk)) {
                throw new RuntimeException('Translation provider returned an unexpected translation count.');
            }

            $offset = 0;
            foreach ($chunk as $index => $text) {
                $value = trim((string) ($translated[$offset] ?? ''));
                $resolved = $value !== '' ? $value : $text;
                $results[$index] = $resolved;
                Cache::put($this->cacheKey($sourceLocale, $targetLocale, $text), $resolved, $ttl);
                $offset++;
            }
        }

        return $results;
    }

    public function supportedLocales(): array
    {
        return $this->localeManager->supportedLocales();
    }

    protected function resolveProvider(): TranslationProvider
    {
        return match (config('translation.driver', 'openai')) {
            'libretranslate' => new LibreTranslateProvider(),
            'openai' => new OpenAiTranslationProvider(),
            default => new NullTranslationProvider(),
        };
    }

    protected function cacheKey(string $sourceLocale, string $targetLocale, string $text): string
    {
        return sprintf(
            '%s%s:%s:%s',
            (string) config('translation.cache_prefix', 'mes_translation:'),
            $sourceLocale,
            $targetLocale,
            sha1($text)
        );
    }

    protected function isTranslatable(string $text): bool
    {
        if ($text === '' || !preg_match('/\p{L}/u', $text)) {
            return false;
        }

        if (preg_match('/^(https?:\/\/|www\.|\/)/i', $text)) {
            return false;
        }

        if (preg_match('/^[A-Z0-9._\-\/]+$/', $text)) {
            return false;
        }

        if (preg_match('/^[-+]?[\d\s.,:%()\/]+$/', $text)) {
            return false;
        }

        return true;
    }
}
