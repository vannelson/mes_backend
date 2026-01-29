<?php

namespace App\Http\Controllers;

use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class AiLabsController extends Controller
{
    use ResponseTrait;

    private const KEY_CACHE_PREFIX = 'ai_labs_openai_key:';

    private function cacheKey(int $userId): string
    {
        return self::KEY_CACHE_PREFIX . $userId;
    }

    private function resolveApiKey(int $userId): ?string
    {
        $encrypted = Cache::get($this->cacheKey($userId));
        if (!$encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable $e) {
            Cache::forget($this->cacheKey($userId));
            return null;
        }
    }

    public function status(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $hasKey = Cache::has($this->cacheKey($userId));

        return $this->success('AI key status.', [
            'has_key' => $hasKey,
        ]);
    }

    public function storeKey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'api_key' => ['required', 'string', 'min:20'],
        ]);

        $userId = (int) $request->user()->id;
        $ttlMinutes = (int) config('ai_labs.key_ttl_minutes', 1440);
        $expiresAt = now()->addMinutes($ttlMinutes);

        Cache::put(
            $this->cacheKey($userId),
            Crypt::encryptString($validated['api_key']),
            $expiresAt
        );

        return $this->success('AI key saved.', [
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function clearKey(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        Cache::forget($this->cacheKey($userId));

        return $this->success('AI key cleared.');
    }

    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'messages' => ['required', 'array', 'min:1'],
            'tools' => ['nullable', 'array'],
            'params' => ['nullable', 'array'],
            'params.model' => ['nullable', 'string'],
            'params.temperature' => ['nullable', 'numeric'],
            'params.max_tokens' => ['nullable', 'integer'],
        ]);

        $userId = (int) $request->user()->id;
        $apiKey = $this->resolveApiKey($userId);

        if (!$apiKey) {
            return $this->error('OpenAI API key not set. Please provide one.', 422);
        }

        $params = $validated['params'] ?? [];

        $payload = [
            'model' => $params['model'] ?? config('ai_labs.model', 'gpt-4o-mini'),
            'messages' => $validated['messages'],
            'temperature' => $params['temperature'] ?? 0.6,
            'max_tokens' => $params['max_tokens'] ?? 800,
        ];

        if (!empty($validated['tools'])) {
            $payload['tools'] = $validated['tools'];
        }

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', $payload);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? 'OpenAI request failed.';
            $status = $response->status();
            $httpStatus = in_array($status, [400, 429], true) ? $status : 422;

            return $this->error($message, $httpStatus);
        }

        return $this->success('AI response', $response->json());
    }
}
