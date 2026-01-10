<?php

namespace App\Http\Controllers;

use App\Http\Requests\VirtualScreen\PlaylistItemStoreRequest;
use App\Http\Requests\VirtualScreen\PlaylistItemUpdateRequest;
use App\Http\Requests\VirtualScreen\PlaylistItemReorderRequest;
use App\Services\Contracts\PlaylistItemServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PlaylistItemController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected PlaylistItemServiceInterface $playlistItemService
    ) {
    }

    /**
     * Get all playlist items for a virtual screen.
     */
    public function index(Request $request, int $screenId): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $data = $this->playlistItemService->getByScreen($screenId, $userId);

            return $this->success('Playlist items retrieved successfully.', $data);
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 500;
            return $this->error($e->getMessage(), $status);
        }
    }

    /**
     * Create a new playlist item.
     */
    public function store(PlaylistItemStoreRequest $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $data = $this->playlistItemService->create($request->validated(), $userId);

            return $this->success('Playlist item created successfully.', $data, 201);
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 500;
            return $this->error($e->getMessage(), $status);
        }
    }

    /**
     * Update a playlist item.
     */
    public function update(PlaylistItemUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $this->playlistItemService->update($id, $request->validated(), $userId);

            return $this->success('Playlist item updated successfully.');
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 500;
            return $this->error($e->getMessage(), $status);
        }
    }

    /**
     * Delete a playlist item.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $this->playlistItemService->delete($id, $userId);

            return $this->success('Playlist item deleted successfully.');
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 500;
            return $this->error($e->getMessage(), $status);
        }
    }

    /**
     * Reorder playlist items.
     */
    public function reorder(PlaylistItemReorderRequest $request, int $screenId): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $items = $request->validated()['items'];

            // Convert to id => order map
            $itemsMap = [];
            foreach ($items as $item) {
                $itemsMap[$item['id']] = $item['order'];
            }

            $this->playlistItemService->reorder($screenId, $itemsMap, $userId);

            return $this->success('Playlist items reordered successfully.');
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 500;
            return $this->error($e->getMessage(), $status);
        }
    }

    /**
     * Toggle item active status.
     */
    public function toggleActive(Request $request, int $id): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $isActive = $request->input('is_active', true);

            $this->playlistItemService->toggleActive($id, $userId, $isActive);

            return $this->success('Playlist item status updated successfully.');
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 500;
            return $this->error($e->getMessage(), $status);
        }
    }
}
