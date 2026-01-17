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

        $payload = $request->json()->all();

        if (empty($payload)) {
            return $this->error('Transcript payload is required.', 422);
        }

        return $this->success('Transcript received successfully.', $payload, 201);
    }
}
