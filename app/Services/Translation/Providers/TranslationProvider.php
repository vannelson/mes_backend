<?php

namespace App\Services\Translation\Providers;

interface TranslationProvider
{
    /**
     * @param array<int, string> $texts
     * @return array<int, string>
     */
    public function translateMany(array $texts, string $sourceLocale, string $targetLocale): array;
}
