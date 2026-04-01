<?php

namespace App\Services\Translation\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LibreTranslateProvider implements TranslationProvider
{
    public function translateMany(array $texts, string $sourceLocale, string $targetLocale): array
    {
        $payload = [
            'q' => array_values($texts),
            'source' => $this->toProviderLocale($sourceLocale),
            'target' => $this->toProviderLocale($targetLocale),
            'format' => 'text',
        ];

        $apiKey = config('translation.libretranslate.api_key');
        if ($apiKey) {
            $payload['api_key'] = $apiKey;
        }

        $response = Http::timeout((int) config('translation.libretranslate.timeout', 20))
            ->acceptJson()
            ->post((string) config('translation.libretranslate.endpoint'), $payload);

        if ($response->failed()) {
            throw new RuntimeException($response->json('error') ?: 'LibreTranslate request failed.');
        }

        $translations = $response->json('translatedText');

        if (!is_array($translations)) {
            $single = is_string($translations) ? [$translations] : [];
            $translations = $single;
        }

        if (count($translations) !== count($texts)) {
            throw new RuntimeException('LibreTranslate returned an unexpected translation count.');
        }

        return array_map(
            static fn ($value) => is_string($value) ? $value : '',
            array_values($translations)
        );
    }

    protected function toProviderLocale(string $locale): string
    {
        return match ($locale) {
            'zh-Hans' => 'zh',
            default => $locale,
        };
    }
}
