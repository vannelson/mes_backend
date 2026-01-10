<?php

namespace App\Repositories;

use App\Models\PlaylistItem;
use App\Repositories\Contracts\PlaylistItemRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class PlaylistItemRepository extends BaseRepository implements PlaylistItemRepositoryInterface
{
    public function __construct(PlaylistItem $model)
    {
        parent::__construct($model);
    }

    /**
     * Get all playlist items for a virtual screen.
     *
     * @param int $virtualScreenId
     * @return Collection
     */
    public function getByScreen(int $virtualScreenId): Collection
    {
        return $this->model
            ->where('virtual_screen_id', $virtualScreenId)
            ->orderBy('order')
            ->get();
    }

    /**
     * Get active playlist items for a virtual screen.
     *
     * @param int $virtualScreenId
     * @return Collection
     */
    public function getActiveItems(int $virtualScreenId): Collection
    {
        return $this->model
            ->where('virtual_screen_id', $virtualScreenId)
            ->where('is_active', true)
            ->orderBy('order')
            ->get();
    }

    /**
     * Get scheduled playlist items for a virtual screen.
     *
     * @param int $virtualScreenId
     * @return Collection
     */
    public function getScheduledItems(int $virtualScreenId): Collection
    {
        $now = now();

        return $this->model
            ->where('virtual_screen_id', $virtualScreenId)
            ->where('is_active', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('schedule_start')
                    ->orWhere('schedule_start', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('schedule_end')
                    ->orWhere('schedule_end', '>=', $now);
            })
            ->orderBy('order')
            ->get();
    }

    /**
     * Reorder playlist items.
     *
     * @param array $items Array of ['id' => order] pairs
     * @return bool
     */
    public function reorder(array $items): bool
    {
        try {
            foreach ($items as $id => $order) {
                $this->model->where('id', $id)->update(['order' => $order]);
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the next order number for a virtual screen.
     *
     * @param int $virtualScreenId
     * @return int
     */
    public function getNextOrder(int $virtualScreenId): int
    {
        $maxOrder = $this->model
            ->where('virtual_screen_id', $virtualScreenId)
            ->max('order');

        return ($maxOrder ?? -1) + 1;
    }

    /**
     * Delete all items for a virtual screen.
     *
     * @param int $virtualScreenId
     * @return bool
     */
    public function deleteByScreen(int $virtualScreenId): bool
    {
        return (bool) $this->model
            ->where('virtual_screen_id', $virtualScreenId)
            ->delete();
    }
}
