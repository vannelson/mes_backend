<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TriggerEmailService
{
    public function send(array $payload): array
    {
        $token = $this->getEnvValue('MAILTRAP_API_TOKEN', config('services.mailtrap.api_token'));
        $endpoint = $this->getEnvValue('MAILTRAP_ENDPOINT', config('services.mailtrap.endpoint'));

        if (! $token || ! $endpoint) {
            throw new \RuntimeException('Mailtrap configuration missing.');
        }

        $fromAddress = $this->getEnvValue('MAIL_FROM', null)
            ?: $this->getEnvValue('MAIL_FROM_ADDRESS', config('services.mailtrap.from_address'))
            ?: config('mail.from.address');
        $fromName = $this->getEnvValue('MAIL_FROM_NAME', config('services.mailtrap.from_name'))
            ?: config('mail.from.name');
        $category = $this->getEnvValue('MAILTRAP_CATEGORY', config('services.mailtrap.category', 'MES Automation'));
        $timeout = (int) $this->getEnvValue('MAILTRAP_TIMEOUT', config('services.mailtrap.timeout', 10));
        $debug = strtolower((string) $this->getEnvValue('DEBUG_EMAIL', '')) === 'true';

        $to = $this->normalizeRecipients($payload['to'] ?? []);
        if (empty($to)) {
            throw new \RuntimeException('Email recipients are required.');
        }

        $requestPayload = array_filter([
            'from' => [
                'email' => $fromAddress,
                'name' => $fromName,
            ],
            'to' => $to,
            'cc' => $this->normalizeRecipients($payload['cc'] ?? []),
            'bcc' => $this->normalizeRecipients($payload['bcc'] ?? []),
            'subject' => $payload['subject'] ?? '',
            'html' => $payload['html'] ?? '',
            'text' => $payload['text'] ?? null,
            'category' => $payload['category'] ?? $category,
            'attachments' => $this->normalizeAttachments($payload['attachments'] ?? []),
        ], static fn ($value) => $value !== null && $value !== []);

        if ($debug) {
            try {
                logger()->info('[TriggerEmailService] sending email', [
                    'to' => array_map(static fn ($entry) => $entry['email'] ?? null, $to),
                    'subject' => $requestPayload['subject'] ?? null,
                    'attachments' => isset($requestPayload['attachments'])
                        ? count($requestPayload['attachments'])
                        : 0,
                ]);
            } catch (\Throwable) {
                // Ignore logging failures.
            }
        }

        $response = Http::timeout($timeout)
            ->withToken($token)
            ->post($endpoint, $requestPayload)
            ->throw();

        return $response->json() ?? ['ok' => true];
    }

    protected function normalizeAttachments(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $attachments = [];
        foreach ($value as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }
            $filename = trim((string) ($attachment['filename'] ?? ''));
            $content = (string) ($attachment['content'] ?? '');
            if ($filename === '' || $content === '') {
                continue;
            }
            $attachments[] = [
                'filename' => $filename,
                'content' => preg_replace('/^data:[^;]+;base64,/', '', $content) ?? $content,
                'type' => $attachment['type'] ?? 'application/pdf',
                'disposition' => $attachment['disposition'] ?? 'attachment',
            ];
        }

        return $attachments ?: null;
    }

    protected function getEnvValue(string $key, mixed $fallback = null): mixed
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }

        $value = env($key);
        if ($value !== null && $value !== '') {
            return $value;
        }

        return $fallback;
    }

    protected function normalizeRecipients(mixed $value): array
    {
        $entries = [];

        if (is_array($value)) {
            $entries = $value;
        } elseif (is_string($value)) {
            $entries = preg_split('/[,\s;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $emails = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && isset($entry['email'])) {
                $email = trim((string) $entry['email']);
            } else {
                $email = trim((string) $entry);
            }

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = ['email' => $email];
            }
        }

        return $emails;
    }
}
