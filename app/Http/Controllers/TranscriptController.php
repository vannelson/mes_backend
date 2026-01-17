<?php

namespace App\Http\Controllers;

use App\Http\Requests\Transcript\TranscriptUploadRequest;
use App\Services\Contracts\TranscriptServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
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
     * Upload a transcript JSON file and return parsed payload.
     */
    public function upload(TranscriptUploadRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $payload = $this->transcriptService->parseUpload($file);

            return $this->success('Transcript uploaded successfully.', [
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'transcript' => $payload,
            ], 201);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 500);
        } catch (Throwable $e) {
            return $this->error('Failed to upload transcript.', 500);
        }
    }

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
