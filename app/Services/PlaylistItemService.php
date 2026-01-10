<?php

namespace App\Services;

use App\Repositories\Contracts\PlaylistItemRepositoryInterface;
use App\Repositories\Contracts\VirtualScreenRepositoryInterface;
use App\Services\Contracts\PlaylistItemServiceInterface;
use Illuminate\Support\Facades\Cache;

class PlaylistItemService implements PlaylistItemServiceInterface
{
    public function __construct(
        protected PlaylistItemRepositoryInterface $playlistItemRepository,
        protected VirtualScreenRepositoryInterface $virtualScreenRepository
    ) {
    }

    /**
     * Get all playlist items for a virtual screen.
     *
     * @param int $virtualScreenId
     * @param int $userId
     * @return array
     */
    public function getByScreen(int $virtualScreenId, int $userId): array
    {
        // Verify ownership
        $screen = $this->virtualScreenRepository->findById($virtualScreenId);
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to virtual screen.');
        }

        $items = $this->playlistItemRepository->getByScreen($virtualScreenId);

        return [
            'data' => $items->map(function ($item) {
                return $this->formatItem($item);
            })->values()->all(),
        ];
    }

    /**
     * Create a new playlist item.
     *
     * @param array $data
     * @param int $userId
     * @return array
     */
    public function create(array $data, int $userId): array
    {
        // Verify ownership
        $screen = $this->virtualScreenRepository->findById($data['virtual_screen_id']);
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to virtual screen.');
        }

        // Set default values
        $data['duration'] = $data['duration'] ?? 10;
        $data['is_active'] = $data['is_active'] ?? true;

        // Auto-assign order if not provided
        if (!isset($data['order'])) {
            $data['order'] = $this->playlistItemRepository->getNextOrder($data['virtual_screen_id']);
        }

        $item = $this->playlistItemRepository->create($data);

        $this->clearPublicCache($screen);

        return $this->formatItem($item);
    }

    /**
     * Update a playlist item.
     *
     * @param int $id
     * @param array $data
     * @param int $userId
     * @return bool
     */
    public function update(int $id, array $data, int $userId): bool
    {
        $item = $this->playlistItemRepository->findById($id);

        // Verify ownership through screen
        $screen = $this->virtualScreenRepository->findById($item->virtual_screen_id);
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to playlist item.');
        }

        // Remove fields that shouldn't be updated directly
        unset($data['virtual_screen_id']);

        $updated = (bool) $this->playlistItemRepository->update($id, $data);

        $this->clearPublicCache($screen);

        return $updated;
    }

    /**
     * Delete a playlist item.
     *
     * @param int $id
     * @param int $userId
     * @return bool
     */
    public function delete(int $id, int $userId): bool
    {
        $item = $this->playlistItemRepository->findById($id);

        // Verify ownership through screen
        $screen = $this->virtualScreenRepository->findById($item->virtual_screen_id);
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to playlist item.');
        }

        $deleted = $this->playlistItemRepository->delete($id);

        $this->clearPublicCache($screen);

        return $deleted;
    }

    /**
     * Reorder playlist items.
     *
     * @param int $virtualScreenId
     * @param array $items
     * @param int $userId
     * @return bool
     */
    public function reorder(int $virtualScreenId, array $items, int $userId): bool
    {
        // Verify ownership
        $screen = $this->virtualScreenRepository->findById($virtualScreenId);
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to virtual screen.');
        }

        // Items should be an array of ['id' => order] pairs
        $reordered = $this->playlistItemRepository->reorder($items);

        $this->clearPublicCache($screen);

        return $reordered;
    }

    /**
     * Toggle item active status.
     *
     * @param int $id
     * @param int $userId
     * @param bool $isActive
     * @return bool
     */
    public function toggleActive(int $id, int $userId, bool $isActive): bool
    {
        return $this->update($id, ['is_active' => $isActive], $userId);
    }

    /**
     * Format item data for response.
     *
     * @param mixed $item
     * @return array
     */
    protected function formatItem($item): array
    {
        return [
            'id' => $item->id,
            'virtual_screen_id' => $item->virtual_screen_id,
            'type' => $item->type,
            'content' => $item->content,
            'duration' => $item->duration,
            'order' => $item->order,
            'schedule_start' => $item->schedule_start?->toISOString(),
            'schedule_end' => $item->schedule_end?->toISOString(),
            'is_active' => $item->is_active,
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
        ];
    }

    protected function clearPublicCache($screen): void
    {
        if (!$screen || empty($screen->share_token)) {
            return;
        }

        Cache::forget("virtual_screen_playlist_{$screen->share_token}");
    }
}
