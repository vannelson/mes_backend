<?php

namespace App\Repositories;

use App\Models\VirtualScreen;
use App\Repositories\Contracts\VirtualScreenRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class VirtualScreenRepository extends BaseRepository implements VirtualScreenRepositoryInterface
{
    public function __construct(VirtualScreen $model)
    {
        parent::__construct($model);
    }

    /**
     * Find a virtual screen by share token.
     *
     * @param string $shareToken
     * @return Model|null
     */
    public function findByShareToken(string $shareToken): ?Model
    {
        return $this->model->where('share_token', $shareToken)->first();
    }

    /**
     * Get virtual screen with playlist items.
     *
     * @param int $id
     * @return Model
     */
    public function getWithPlaylistItems(int $id): Model
    {
        return $this->model
            ->with([
                'playlistItems' => function ($query) {
                    $query->orderBy('order');
                }
            ])
            ->findOrFail($id);
    }

    /**
     * Get all virtual screens for a user.
     *
     * @param int $userId
     * @return Collection
     */
    public function getUserScreens(int $userId): Collection
    {
        return $this->model
            ->where('user_id', $userId)
            ->with(['playlistItems'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get all virtual screens.
     *
     * @return Collection
     */
    public function getAllScreens(): Collection
    {
        return $this->model
            ->with(['playlistItems'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get active virtual screens for a user.
     *
     * @param int $userId
     * @return Collection
     */
    public function getActiveUserScreens(int $userId): Collection
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->with(['playlistItems'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get virtual screen with all relations for public player.
     *
     * @param string $shareToken
     * @return Model|null
     */
    public function getPublicScreen(string $shareToken): ?Model
    {
        return $this->model
            ->where('share_token', $shareToken)
            ->where('is_active', true)
            ->with([
                'playlistItems' => function ($query) {
                    $query->orderBy('order');
                }
            ])
            ->first();
    }

    public function findByAccessCode(string $accessCode): ?Model
    {
        return $this->model
            ->where('access_code', $accessCode)
            ->where('is_active', true)
            ->with([
                'playlistItems' => function ($query) {
                    $query->orderBy('order');
                },
            ])
            ->first();
    }
}
