<?php

namespace App\Services\Contracts;

interface VirtualScreenServiceInterface
{
    /**
     * Get all virtual screens for a user.
     *
     * @param int $userId
     * @return array
     */
    public function getUserScreens(int $userId): array;

    /**
     * Get virtual screen detail with playlist items.
     *
     * @param int $id
     * @param int $userId
     * @return array
     */
    public function detail(int $id, int $userId): array;

    /**
     * Create a new virtual screen.
     *
     * @param array $data
     * @param int $userId
     * @return array
     */
    public function create(array $data, int $userId): array;

    /**
     * Update a virtual screen.
     *
     * @param int $id
     * @param array $data
     * @param int $userId
     * @return bool
     */
    public function update(int $id, array $data, int $userId): bool;

    /**
     * Delete a virtual screen.
     *
     * @param int $id
     * @param int $userId
     * @return bool
     */
    public function delete(int $id, int $userId): bool;

    /**
     * Get public playlist for player by share token.
     *
     * @param string $shareToken
     * @return array|null
     */
    public function getPublicPlaylist(string $shareToken): ?array;

    /**
     * Toggle screen active status.
     *
     * @param int $id
     * @param int $userId
     * @param bool $isActive
     * @return bool
     */
    public function toggleActive(int $id, int $userId, bool $isActive): bool;

    /**
     * Regenerate share token for a screen.
     *
     * @param int $id
     * @param int $userId
     * @return array
     */
    public function regenerateShareToken(int $id, int $userId): array;
}
