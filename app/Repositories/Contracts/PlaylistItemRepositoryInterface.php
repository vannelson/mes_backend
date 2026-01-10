<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface PlaylistItemRepositoryInterface extends RepositoryInterface
{
    /**
     * Get all playlist items for a virtual screen.
     *
     * @param int $virtualScreenId
     * @return Collection
     */
    public function getByScreen(int $virtualScreenId): Collection;

    /**
     * Get active playlist items for a virtual screen.
     *
     * @param int $virtualScreenId
     * @return Collection
     */
    public function getActiveItems(int $virtualScreenId): Collection;

    /**
     * Get scheduled playlist items for a virtual screen.
     *
     * @param int $virtualScreenId
     * @return Collection
     */
    public function getScheduledItems(int $virtualScreenId): Collection;

    /**
     * Reorder playlist items.
     *
     * @param array $items Array of ['id' => order] pairs
     * @return bool
     */
    public function reorder(array $items): bool;

    /**
     * Get the next order number for a virtual screen.
     *
     * @param int $virtualScreenId
     * @return int
     */
    public function getNextOrder(int $virtualScreenId): int;

    /**
     * Delete all items for a virtual screen.
     *
     * @param int $virtualScreenId
     * @return bool
     */
    public function deleteByScreen(int $virtualScreenId): bool;
}
