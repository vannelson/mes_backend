<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface VirtualScreenRepositoryInterface extends RepositoryInterface
{
    /**
     * Find a virtual screen by share token.
     *
     * @param string $shareToken
     * @return Model|null
     */
    public function findByShareToken(string $shareToken): ?Model;

    /**
     * Get virtual screen with playlist items.
     *
     * @param int $id
     * @return Model
     */
    public function getWithPlaylistItems(int $id): Model;

    /**
     * Get all virtual screens for a user.
     *
     * @param int $userId
     * @return Collection
     */
    public function getUserScreens(int $userId): Collection;

    /**
     * Get active virtual screens for a user.
     *
     * @param int $userId
     * @return Collection
     */
    public function getActiveUserScreens(int $userId): Collection;

    /**
     * Get virtual screen with all relations for public player.
     *
     * @param string $shareToken
     * @return Model|null
     */
    public function getPublicScreen(string $shareToken): ?Model;
}
