<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TriggerEmailService
{
    public function send(array $payload): array
    {
        $token = config('services.mailtrap.api_token');
        $endpoint = config('services.mailtrap.endpoint');

        if (! $token || ! $endpoint) {
            throw new \RuntimeException('Mailtrap configuration missing.');
        }

        $fromAddress = config('services.mailtrap.from_address') ?: config('mail.from.address');
        $fromName = config('services.mailtrap.from_name') ?: config('mail.from.name');
        $category = config('services.mailtrap.category', 'MES Automation');
        $timeout = (int) config('services.mailtrap.timeout', 10);

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
        ], static fn ($value) => $value !== null && $value !== []);

        $response = Http::timeout($timeout)
            ->withToken($token)
            ->post($endpoint, $requestPayload)
            ->throw();

        return $response->json() ?? ['ok' => true];
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
