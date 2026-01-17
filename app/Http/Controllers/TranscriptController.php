<?php

namespace App\Http\Controllers;

use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TranscriptController extends Controller
{
    use ResponseTrait;

    /**
     * Accept transcript JSON body and return the same payload.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->isJson()) {
            return $this->error('JSON body is required.', 422);
        }

        $payload = $request->input('payload');

        if (is_null($payload)) {
            return $this->error('Payload is required.', 422);
        }

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->error('Payload must be valid JSON.', 422);
            }
            $payload = $decoded;
        }

        if (empty($payload)) {
            return $this->error('Payload must not be empty.', 422);
        }

        return $this->success('Transcript received successfully.', [
            'payload' => $payload,
        ], 201);
    }
}
