<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kreait\Firebase\Factory;

class FirebaseRealtimeService
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 8,
        ]);
    }

    public function publishVirtualizationUpdate(array $payload): bool
    {
        return $this->publishUpdate('mes/workorders/virtualization/last_update', $payload);
    }

    public function publishNotificationUpdate(array $payload): bool
    {
        return $this->publishUpdate('mes/notifications/last_update', $payload);
    }

    protected function publishUpdate(string $path, array $payload): bool
    {
        $databaseUrl = config('services.firebase.database_url');
        $serviceAccountPath = config('services.firebase.service_account_path');

        if (!$databaseUrl) {
            return false;
        }

        $value = $payload;
        $value['updated_at'] = $value['updated_at'] ?? now()->toIso8601String();
        $value['nonce'] = $value['nonce'] ?? (string) Str::uuid();

        if ($serviceAccountPath) {
            if ($this->publishViaSdk($databaseUrl, $serviceAccountPath, $value, $path)) {
                return true;
            }

            if ($this->publishViaRest($databaseUrl, $serviceAccountPath, $value, $path)) {
                return true;
            }
        }

        return $this->publishViaOpenRules($databaseUrl, $value, $path);
    }

    protected function publishViaSdk(string $databaseUrl, string $serviceAccountPath, array $value, string $path): bool
    {
        if (!class_exists(Factory::class)) {
            return false;
        }

        $resolvedPath = $this->resolveServiceAccountPath($serviceAccountPath);
        if (!$resolvedPath) {
            return false;
        }

        try {
            $database = (new Factory())
                ->withServiceAccount($resolvedPath)
                ->withDatabaseUri($databaseUrl)
                ->createDatabase();
            $database->getReference($path)->set($value);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Firebase RTDB SDK publish failed.', [
                'message' => $e->getMessage(),
            ]);
        }

        return false;
    }

    protected function publishViaRest(string $databaseUrl, string $serviceAccountPath, array $value, string $path): bool
    {
        $serviceAccount = $this->loadServiceAccount($serviceAccountPath);
        if (!$serviceAccount) {
            return false;
        }

        $accessToken = $this->getAccessToken($serviceAccount);
        if (!$accessToken) {
            return false;
        }

        $url = rtrim($databaseUrl, '/') . '/' . ltrim($path, '/') . '.json';

        try {
            $this->client->put($url, [
                'query' => ['access_token' => $accessToken],
                'json' => $value,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Firebase RTDB publish failed.', [
                'message' => $e->getMessage(),
            ]);
        }

        return false;
    }

    protected function publishViaOpenRules(string $databaseUrl, array $value, string $path): bool
    {
        $url = rtrim($databaseUrl, '/') . '/' . ltrim($path, '/') . '.json';

        try {
            $this->client->put($url, ['json' => $value]);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Firebase RTDB open-rule publish failed.', [
                'message' => $e->getMessage(),
            ]);
        }

        return false;
    }

    protected function resolveServiceAccountPath(string $path): ?string
    {
        $resolved = $path;
        if (!str_starts_with($path, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:\\\\/', $path)) {
            $resolved = base_path($path);
        }

        if (!is_file($resolved)) {
            Log::warning('Firebase service account file not found.', [
                'path' => $resolved,
            ]);
            return null;
        }

        return $resolved;
    }

    protected function loadServiceAccount(string $path): ?array
    {
        $resolved = $this->resolveServiceAccountPath($path);
        if (!$resolved) {
            return null;
        }

        $raw = file_get_contents($resolved);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    protected function getAccessToken(array $serviceAccount): ?string
    {
        $cacheKey = 'firebase:rtdb:token:' . md5(($serviceAccount['client_email'] ?? '') . '|' . ($serviceAccount['project_id'] ?? ''));

        return Cache::remember($cacheKey, 3300, function () use ($serviceAccount) {
            return $this->requestAccessToken($serviceAccount);
        });
    }

    protected function requestAccessToken(array $serviceAccount): ?string
    {
        $jwt = $this->buildJwt($serviceAccount);
        if (!$jwt) {
            return null;
        }

        try {
            $response = $this->client->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ],
            ]);
            $data = json_decode((string) $response->getBody(), true);
            return is_array($data) ? ($data['access_token'] ?? null) : null;
        } catch (\Throwable $e) {
            Log::warning('Firebase token request failed.', [
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function buildJwt(array $serviceAccount): ?string
    {
        $clientEmail = $serviceAccount['client_email'] ?? null;
        $privateKey = $serviceAccount['private_key'] ?? null;

        if (!$clientEmail || !$privateKey) {
            return null;
        }

        $now = time();
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]));
        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $clientEmail,
            'sub' => $clientEmail,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
            'scope' => 'https://www.googleapis.com/auth/firebase.database https://www.googleapis.com/auth/userinfo.email',
        ]));

        $data = $header . '.' . $payload;
        $key = openssl_pkey_get_private($privateKey);
        if (!$key) {
            return null;
        }

        $signature = '';
        $signed = openssl_sign($data, $signature, $key, 'sha256');
        openssl_free_key($key);

        if (!$signed) {
            return null;
        }

        return $data . '.' . $this->base64UrlEncode($signature);
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
