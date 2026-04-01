<?php

namespace App\Http\Controllers;

use App\Services\Translation\TranslationService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TranslationController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected TranslationService $translationService
    ) {
    }

    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['nullable', 'string', 'max:20'],
            'texts' => ['required', 'array', 'min:1', 'max:300'],
            'texts.*' => ['required', 'string', 'max:4000'],
        ]);

        $locale = $this->translationService->normalizeLocale(
            $validated['locale'] ?? $request->attributes->get('locale')
        );

        try {
            $translations = $this->translationService->translateMany(
                $validated['texts'],
                $locale
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Translation request failed.', 422, [
                'translation' => [$exception->getMessage()],
            ]);
        }

        return $this->success('Translations retrieved successfully.', [
            'locale' => $locale,
            'source_locale' => $this->translationService->defaultLocale(),
            'supported_locales' => $this->translationService->supportedLocales(),
            'translations' => array_values($translations),
        ]);
    }
}
