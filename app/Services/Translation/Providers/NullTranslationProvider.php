<?php

namespace App\Services\Translation\Providers;

class NullTranslationProvider implements TranslationProvider
{
    public function translateMany(array $texts, string $sourceLocale, string $targetLocale): array
    {
        return array_values($texts);
    }
}
