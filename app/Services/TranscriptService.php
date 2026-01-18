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

        // TODO: Re-enable AI call after production rollout is stable.
        $todos = $this->getStaticTodos();

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
            You are an AI assistant that analyzes a rental business phone call transcript
            and extracts structured, actionable information for internal operations.

            STRICT RULES:
            - Return ONLY valid JSON.
            - Do NOT include explanations, comments, or markdown.
            - Do NOT invent information that is not clearly stated or implied.
            - If a section has no data, return an empty array [].
            - All outputs MUST follow the schema exactly.

            OUTPUT SCHEMA:
            {
            "task_list": [
                {
                "task": string,
                "priority": "low" | "medium" | "high",
                "due_date": string | null,
                "assigned_to": string
                }
            ],
            "schedules": [
                {
                "event_type": string,
                "event_date": string | null,
                "event_time": string | null,
                "location": string | null
                }
            ],
            "items": [
                {
                "item_name": string,
                "item_type": string | null,
                "quantity": number | null,
                "notes": string | null
                }
            ],
            "concerns": [
                {
                "type": "payment" | "availability" | "logistics" | "item_issue" | "other",
                "description": string,
                "sentiment": "positive" | "negative" | "neutral"
                }
            ],
            "feedback": [
                {
                "speaker": "customer" | "agent",
                "message": string,
                "sentiment": "positive" | "negative" | "neutral"
                }
            ]
            }

            LOGIC GUIDELINES:
            - task_list: Include ONLY actions the agent or business must perform (e.g., send quote, check availability, follow up).
            - schedules: Extract event-related dates, times, and locations mentioned in the call.
            - items: Extract rental items discussed or requested by the customer.
            - concerns: Capture payment terms, deposits, availability issues, comparisons, or risks—even if implied.
            - feedback: Capture explicit or implicit positive/negative feedback from either party.
            - Use ISO 8601 date format (YYYY-MM-DD) when a specific date is mentioned; otherwise null.
            - Default assigned_to = "sales" if not specified.

            TRANSCRIPT INPUT:
            {$dialogueText}

            END OF PROMPT
            PROMPT;
    }

    private function buildPayloadPrompt(array $payload): string
    {
        $json = json_encode($payload);

        return <<<PROMPT
You are an AI assistant that analyzes a rental business phone call transcript
and extracts structured, actionable information for internal operations.

STRICT RULES:
- Return ONLY valid JSON.
- Do NOT include explanations, comments, or markdown.
- Do NOT invent information that is not clearly stated or implied.
- If a section has no data, return an empty array [].
- All outputs MUST follow the schema exactly.

OUTPUT SCHEMA:
{
  "task_list": [
    {
      "task": string,
      "priority": "low" | "medium" | "high",
      "due_date": string | null,
      "assigned_to": string
    }
  ],
  "schedules": [
    {
      "event_type": string,
      "event_date": string | null,
      "event_time": string | null,
      "location": string | null
    }
  ],
  "items": [
    {
      "item_name": string,
      "item_type": string | null,
      "quantity": number | null,
      "notes": string | null
    }
  ],
  "concerns": [
    {
      "type": "payment" | "availability" | "logistics" | "item_issue" | "other",
      "description": string,
      "sentiment": "positive" | "negative" | "neutral"
    }
  ],
  "feedback": [
    {
      "speaker": "customer" | "agent",
      "message": string,
      "sentiment": "positive" | "negative" | "neutral"
    }
  ]
}

LOGIC GUIDELINES:
- task_list: Include ONLY actions the agent or business must perform (e.g., send quote, check availability, follow up).
- schedules: Extract event-related dates, times, and locations mentioned in the call.
- items: Extract rental items discussed or requested by the customer.
- concerns: Capture payment terms, deposits, availability issues, comparisons, or risks—even if implied.
- feedback: Capture explicit or implicit positive/negative feedback from either party.
- Use ISO 8601 date format (YYYY-MM-DD) when a specific date is mentioned; otherwise null.
- Default assigned_to = "sales" if not specified.

Payload (JSON):
{$json}

END OF PROMPT
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

        $requiredKeys = ['task_list', 'schedules', 'items', 'concerns', 'feedback'];

        if (!is_array($decoded)) {
            throw new RuntimeException('AI response does not match expected schema.');
        }

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $decoded) || !is_array($decoded[$key])) {
                throw new RuntimeException('AI response does not match expected schema.');
            }
        }

        return $decoded;
    }

    private function getStaticTodos(): array
    {
        return [
            'task_list' => [
                [
                    'task' => 'Email detailed quote and payment link to customer',
                    'priority' => 'high',
                    'due_date' => null,
                    'assigned_to' => 'sales',
                ],
            ],
            'schedules' => [
                [
                    'event_type' => 'wedding',
                    'event_date' => null,
                    'event_time' => null,
                    'location' => 'San Diego',
                ],
            ],
            'items' => [
                [
                    'item_name' => 'dance floor',
                    'item_type' => 'dance floor',
                    'quantity' => 1,
                    'notes' => 'white vinyl',
                ],
                [
                    'item_name' => 'DJ stage',
                    'item_type' => 'DJ stage',
                    'quantity' => 1,
                    'notes' => 'medium size',
                ],
            ],
            'concerns' => [
                [
                    'type' => 'payment',
                    'description' => '30% deposit required to secure availability, balance due three days before the event',
                    'sentiment' => 'neutral',
                ],
            ],
            'feedback' => [
                [
                    'speaker' => 'customer',
                    'message' => 'Great. How do we reserve them?',
                    'sentiment' => 'positive',
                ],
                [
                    'speaker' => 'customer',
                    'message' => 'That sounds good.',
                    'sentiment' => 'positive',
                ],
                [
                    'speaker' => 'agent',
                    'message' => 'I’d be happy to help.',
                    'sentiment' => 'neutral',
                ],
            ],
        ];
    }
}
