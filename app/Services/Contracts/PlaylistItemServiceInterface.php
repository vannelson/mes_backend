<?php

namespace App\Services\Contracts;

interface PlaylistItemServiceInterface
{
    /**
     * Get all playlist items for a virtual screen.
     *
     * @param int $virtualScreenId
     * @param int $userId
     * @return array
     */
    public function getByScreen(int $virtualScreenId, int $userId): array;

    /**
     * Create a new playlist item.
     *
     * @param array $data
     * @param int $userId
     * @return array
     */
    public function create(array $data, int $userId): array;

    /**
     * Update a playlist item.
     *
     * @param int $id
     * @param array $data
     * @param int $userId
     * @return bool
     */
    public function update(int $id, array $data, int $userId): bool;

    /**
     * Delete a playlist item.
     *
     * @param int $id
     * @param int $userId
     * @return bool
     */
    public function delete(int $id, int $userId): bool;

    /**
     * Reorder playlist items.
     *
     * @param int $virtualScreenId
     * @param array $items
     * @param int $userId
     * @return bool
     */
    public function reorder(int $virtualScreenId, array $items, int $userId): bool;

    /**
     * Toggle item active status.
     *
     * @param int $id
     * @param int $userId
     * @param bool $isActive
     * @return bool
     */
    public function toggleActive(int $id, int $userId, bool $isActive): bool;
}
