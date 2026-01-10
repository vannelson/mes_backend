<?php

namespace App\Http\Controllers;

use App\Http\Requests\VirtualScreen\VirtualScreenStoreRequest;
use App\Http\Requests\VirtualScreen\VirtualScreenUpdateRequest;
use App\Services\Contracts\VirtualScreenServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class VirtualScreenController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected VirtualScreenServiceInterface $virtualScreenService
    ) {
    }

    /**
     * Get all virtual screens for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $data = $this->virtualScreenService->getUserScreens($userId);

            return $this->success('Virtual screens retrieved successfully.', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load virtual screens: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get a single virtual screen with playlist items.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $data = $this->virtualScreenService->detail($id, $userId);

            return $this->success('Virtual screen retrieved successfully.', $data);
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 500;
            return $this->error($e->getMessage(), $status);
        }
    }

    /**
     * Create a new virtual screen.
     */
    public function store(VirtualScreenStoreRequest $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $data = $this->virtualScreenService->create($request->validated(), $userId);

            return $this->success('Virtual screen created successfully.', $data, 201);
        } catch (Throwable $e) {
            return $this->error('Failed to create virtual screen: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update a virtual screen.
     */
    public function update(VirtualScreenUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $this->virtualScreenService->update($id, $request->validated(), $userId);

            return $this->success('Virtual screen updated successfully.');
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 500;
            return $this->error($e->getMessage(), $status);
        }
    }

    /**
     * Delete a virtual screen.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $this->virtualScreenService->delete($id, $userId);

            return $this->success('Virtual screen deleted successfully.');
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 500;
            return $this->error($e->getMessage(), $status);
        }
    }

    /**
     * Toggle screen active status.
     */
    public function toggleActive(Request $request, int $id): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $isActive = $request->input('is_active', true);

            $this->virtualScreenService->toggleActive($id, $userId, $isActive);

            return $this->success('Virtual screen status updated successfully.');
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 500;
            return $this->error($e->getMessage(), $status);
        }
    }

    /**
     * Regenerate share token for a screen.
     */
    public function regenerateToken(Request $request, int $id): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $data = $this->virtualScreenService->regenerateShareToken($id, $userId);

            return $this->success('Share token regenerated successfully.', $data);
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 500;
            return $this->error($e->getMessage(), $status);
        }
    }
}
