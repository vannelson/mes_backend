<?php

namespace App\Services;

use App\Services\Contracts\TranscriptServiceInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use LucianoTonet\GroqLaravel\Facades\Groq;

class TranscriptService implements TranscriptServiceInterface
{
    public function extractTodos(array $payload): array
    {
        $callId = Arr::get($payload, 'data.object.callId') ?? Arr::get($payload, 'callId');
        $dialogue = $this->findDialogue($payload);
        $lines = $this->formatDialogueLines($dialogue);

        if (!empty($lines)) {
            Log::info('Transcript dialogue prepared.', [
                'call_id' => $callId,
                'line_count' => count($lines),
            ]);
            $prompt = $this->buildPrompt(implode("\n", $lines));
        } else {
            Log::warning('Transcript dialogue missing, using raw payload.', [
                'call_id' => $callId,
            ]);
            $prompt = $this->buildPayloadPrompt($payload);
        }

        $response = $this->callGroq($prompt);
        $todos = $this->decodeTodoResponse($response);

        Log::info('Transcript todo extraction complete.', [
            'call_id' => $callId,
            'todo_count' => count($todos),
        ]);

        return $todos;
    }

    private function findDialogue(array $payload): array
    {
        if (isset($payload['dialogue']) && is_array($payload['dialogue'])) {
            return $payload['dialogue'];
        }

        foreach ($payload as $value) {
            if (!is_array($value)) {
                continue;
            }

            $dialogue = $this->findDialogue($value);
            if (!empty($dialogue)) {
                return $dialogue;
            }
        }

        return [];
    }

    private function formatDialogueLines(array $dialogue): array
    {
        $lines = [];

        foreach ($dialogue as $turn) {
            if (is_string($turn)) {
                $content = trim($turn);
                if ($content !== '') {
                    $lines[] = 'UNKNOWN: ' . $content;
                }
                continue;
            }

            if (!is_array($turn)) {
                continue;
            }

            $speaker = Arr::get($turn, 'identifier')
                ?? Arr::get($turn, 'speaker')
                ?? Arr::get($turn, 'role', 'speaker');
            $content = trim((string) Arr::get($turn, 'content', ''));

            if ($content === '') {
                continue;
            }

            $lines[] = strtoupper((string) $speaker) . ': ' . $content;
        }

        return $lines;
    }

    private function buildPrompt(string $dialogueText): string
    {
        return <<<PROMPT
You are an AI assistant that extracts actionable to-do items from a rental business phone call transcript.

Rules:
- Return ONLY valid JSON.
- Do not include any explanation or markdown.
- Output schema:
{
  "to_do_items": [
    {
      "task": string,
      "priority": "low" | "medium" | "high",
      "due_date": string | null,
      "assigned_to": string
    }
  ]
}
- If no tasks are present, return {"to_do_items": []}.
- Use ISO 8601 date if a specific date is mentioned, otherwise null.
- Use "sales" as the default assigned_to when not specified.

Transcript:
{$dialogueText}
PROMPT;
    }

    private function buildPayloadPrompt(array $payload): string
    {
        $json = json_encode($payload);

        return <<<PROMPT
You are an AI assistant that extracts actionable to-do items from a rental business phone call transcript payload.

Rules:
- Return ONLY valid JSON.
- Do not include any explanation or markdown.
- Output schema:
{
  "to_do_items": [
    {
      "task": string,
      "priority": "low" | "medium" | "high",
      "due_date": string | null,
      "assigned_to": string
    }
  ]
}
- If no tasks are present, return {"to_do_items": []}.
- Use ISO 8601 date if a specific date is mentioned, otherwise null.
- Use "sales" as the default assigned_to when not specified.

Payload (JSON):
{$json}
PROMPT;
    }

    private function callGroq(string $prompt): string
    {
        $apiKey = config('groq.api_key');
        $model = config('groq.model', 'llama-3.1-8b-instant');

        if (!$apiKey) {
            throw new RuntimeException('Groq API key is not configured.');
        }

        Log::info('Groq request started.', [
            'model' => $model,
            'prompt_chars' => strlen($prompt),
        ]);

        try {
            $response = Groq::chat()->completions()->create([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You output strict JSON only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.2,
            ]);
        } catch (\Throwable $e) {
            Log::error('Groq request failed.', [
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException('Groq request failed: ' . $e->getMessage());
        }

        $content = data_get($response, 'choices.0.message.content');

        if (!$content) {
            throw new RuntimeException('Groq returned empty content.');
        }

        return $content;
    }

    private function decodeTodoResponse(string $content): array
    {
        $clean = trim($content);
        $clean = preg_replace('/^```json/i', '```', $clean);
        $clean = preg_replace('/^```/', '', $clean);
        $clean = preg_replace('/```$/', '', $clean);
        $clean = trim($clean);

        $decoded = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('AI response parse failed.', [
                'content_preview' => Str::limit($content, 500),
            ]);
            throw new RuntimeException('Unable to parse AI response.');
        }

        if (!is_array($decoded) || !isset($decoded['to_do_items']) || !is_array($decoded['to_do_items'])) {
            throw new RuntimeException('AI response does not match expected schema.');
        }

        return $decoded['to_do_items'];
    }
}
