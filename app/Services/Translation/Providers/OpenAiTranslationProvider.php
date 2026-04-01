<?php

namespace App\Services\Translation\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiTranslationProvider implements TranslationProvider
{
    public function translateMany(array $texts, string $sourceLocale, string $targetLocale): array
    {
        $apiKey = (string) config('services.openai.key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured for translation.');
        }

        $payload = [
            'model' => config('translation.openai.model', 'gpt-4o-mini'),
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You translate enterprise manufacturing UI strings. Return strict JSON as {"translations":["..."]}. Preserve order, codes, placeholders, line breaks, punctuation, and product identifiers. Do not explain anything.',
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'source_locale' => $sourceLocale,
                        'target_locale' => $targetLocale,
                        'texts' => array_values($texts),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];

        $response = Http::withToken($apiKey)
            ->timeout((int) config('translation.openai.timeout', 45))
            ->acceptJson()
            ->post((string) config('translation.openai.endpoint'), $payload);

        if ($response->failed()) {
            throw new RuntimeException($response->json('error.message') ?: 'OpenAI translation request failed.');
        }

        $content = $response->json('choices.0.message.content');
        $decoded = is_string($content) ? json_decode($content, true) : null;
        $translations = is_array($decoded) ? ($decoded['translations'] ?? null) : null;

        if (!is_array($translations) || count($translations) !== count($texts)) {
            throw new RuntimeException('OpenAI translation response format was invalid.');
        }

        return array_map(
            static fn ($value) => is_string($value) ? $value : '',
            array_values($translations)
        );
    }
}
