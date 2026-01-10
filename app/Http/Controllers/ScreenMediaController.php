<?php

namespace App\Http\Controllers;

use App\Http\Requests\VirtualScreen\ScreenMediaUploadRequest;
use App\Services\Contracts\ScreenMediaServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ScreenMediaController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected ScreenMediaServiceInterface $screenMediaService
    ) {
    }

    /**
     * Get all media for a virtual screen.
     */
    public function index(Request $request, int $screenId): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $data = $this->screenMediaService->getByScreen($screenId, $userId);

            return $this->success('Media retrieved successfully.', $data);
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 500;
            return $this->error($e->getMessage(), $status);
        }
    }

    /**
     * Upload media file.
     */
    public function upload(ScreenMediaUploadRequest $request, int $screenId): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $file = $request->file('file');

            $data = $this->screenMediaService->uploadMedia($screenId, $file, $userId);

            return $this->success('Media uploaded successfully.', $data, 201);
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 500;
            return $this->error($e->getMessage(), $status);
        }
    }

    /**
     * Get media detail.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $data = $this->screenMediaService->detail($id, $userId);

            return $this->success('Media retrieved successfully.', $data);
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 500;
            return $this->error($e->getMessage(), $status);
        }
    }

    /**
     * Delete media file.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $this->screenMediaService->deleteMedia($id, $userId);

            return $this->success('Media deleted successfully.');
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 500;
            return $this->error($e->getMessage(), $status);
        }
    }
}
