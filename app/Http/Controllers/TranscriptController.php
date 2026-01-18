<?php

namespace App\Http\Controllers;

use App\Services\Contracts\TranscriptServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TranscriptController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected TranscriptServiceInterface $transcriptService
    ) {
    }

    /**
     * Accept transcript JSON body, send to Gemini, return to-do items.
     */
    public function store(Request $request): JsonResponse
    {
        $payload = [];

        try {
            if (!$request->isJson()) {
                return $this->error('JSON body is required.', 422);
            }

            $payload = $request->json()->all();

            if (empty($payload)) {
                return $this->error('Transcript payload is required.', 422);
            }

            $callId = data_get($payload, 'data.object.callId') ?? data_get($payload, 'callId');
            $dialogue = data_get($payload, 'data.object.dialogue', data_get($payload, 'dialogue', []));

            Log::info('Transcript received.', [
                'call_id' => $callId,
                'dialogue_count' => is_array($dialogue) ? count($dialogue) : 0,
            ]);

            $todos = $this->transcriptService->extractTodos($payload);
  

            return $this->success('Transcript processed successfully.', [
                'to_do_items' => $todos,
            ], 200);
        } catch (RuntimeException $e) {
            $errorId = (string) Str::uuid();
            Log::error('Transcript processing runtime error.', [
                'error_id' => $errorId,
                'message' => $e->getMessage(),
                'call_id' => data_get($payload, 'data.object.callId'),
                'exception' => $e,
            ]);
            $message = config('app.debug') ? $e->getMessage() : 'AI request failed.';
            $errors = ['error_id' => $errorId];
            if (config('app.debug')) {
                $errors['ai_error'] = $e->getMessage();
            }
            return $this->error($message, 502, $errors);
        } catch (Throwable $e) {
            $errorId = (string) Str::uuid();
            Log::error('Transcript processing failed.', [
                'error_id' => $errorId,
                'message' => $e->getMessage(),
                'call_id' => data_get($payload, 'data.object.callId'),
                'exception' => $e,
            ]);
            $message = config('app.debug') ? $e->getMessage() : 'Failed to process transcript.';
            return $this->error($message, 500, ['error_id' => $errorId]);
        }
    }
}
