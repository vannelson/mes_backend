<?php

namespace App\Services;

use App\Models\ScreenMedia;
use App\Models\VirtualScreen;
use App\Repositories\Contracts\VirtualScreenRepositoryInterface;
use App\Services\Contracts\VirtualScreenServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;

class VirtualScreenService implements VirtualScreenServiceInterface
{
    public function __construct(
        protected VirtualScreenRepositoryInterface $virtualScreenRepository
    ) {
    }

    /**
     * Get all virtual screens for a user.
     *
     * @param int $userId
     * @return array
     */
    public function getUserScreens(int $userId): array
    {
        $screens = $this->virtualScreenRepository->getUserScreens($userId);

        return [
            'data' => $screens->map(function ($screen) {
                return $this->formatScreen($screen);
            })->values()->all(),
        ];
    }

    /**
     * Get virtual screen detail with playlist items.
     *
     * @param int $id
     * @param int $userId
     * @return array
     */
    public function detail(int $id, int $userId): array
    {
        $screen = $this->virtualScreenRepository->getWithPlaylistItems($id);

        // Verify ownership
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to virtual screen.');
        }

        return $this->formatScreen($screen, true);
    }

    /**
     * Create a new virtual screen.
     *
     * @param array $data
     * @param int $userId
     * @return array
     */
    public function create(array $data, int $userId): array
    {
        $data['user_id'] = $userId;

        // Ensure defaults
        $data['orientation'] = $data['orientation'] ?? 'landscape';
        $data['aspect_ratio'] = $data['aspect_ratio'] ?? '16:9';
        $data['timezone'] = $data['timezone'] ?? 'UTC';
        $data['refresh_interval'] = $data['refresh_interval'] ?? 300;
        $data['is_active'] = $data['is_active'] ?? true;
        $data['access_code'] = $data['access_code'] ?? VirtualScreen::generateAccessCode();

        $screen = $this->virtualScreenRepository->create($data);

        return $this->formatScreen($screen);
    }

    /**
     * Update a virtual screen.
     *
     * @param int $id
     * @param array $data
     * @param int $userId
     * @return bool
     */
    public function update(int $id, array $data, int $userId): bool
    {
        $screen = $this->virtualScreenRepository->findById($id);

        // Verify ownership
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to virtual screen.');
        }

        // Remove fields that shouldn't be updated directly
        unset($data['user_id'], $data['share_token']);

        $updated = (bool) $this->virtualScreenRepository->update($id, $data);

        $this->clearPublicCache($screen);

        return $updated;
    }

    /**
     * Delete a virtual screen.
     *
     * @param int $id
     * @param int $userId
     * @return bool
     */
    public function delete(int $id, int $userId): bool
    {
        $screen = $this->virtualScreenRepository->findById($id);

        // Verify ownership
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to virtual screen.');
        }

        $deleted = $this->virtualScreenRepository->delete($id);

        $this->clearPublicCache($screen);

        return $deleted;
    }

    /**
     * Get public playlist for player by share token.
     *
     * @param string $shareToken
     * @return array|null
     */
    public function getPublicPlaylist(string $shareToken): ?array
    {
        $screen = $this->virtualScreenRepository->getPublicScreen($shareToken);

        if (!$screen) {
            return null;
        }

        $this->ensureAccessCode($screen);

        return $this->buildPublicPlaylist($screen);
    }

    public function getPublicPlaylistByAccessCode(string $accessCode): ?array
    {
        $screen = $this->virtualScreenRepository->findByAccessCode($accessCode);

        if (!$screen) {
            return null;
        }

        $this->ensureAccessCode($screen);

        return $this->buildPublicPlaylist($screen);
    }

    /**
     * Toggle screen active status.
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
     * Regenerate share token for a screen.
     *
     * @param int $id
     * @param int $userId
     * @return array
     */
    public function regenerateShareToken(int $id, int $userId): array
    {
        $screen = $this->virtualScreenRepository->findById($id);

        // Verify ownership
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to virtual screen.');
        }

        $oldToken = $screen->share_token;
        $newToken = VirtualScreen::generateUniqueShareToken();
        $newCode = VirtualScreen::generateAccessCode();
        $this->virtualScreenRepository->update($id, [
            'share_token' => $newToken,
            'access_code' => $newCode,
        ]);

        $this->clearPublicCacheByToken($oldToken);

        $updatedScreen = $this->virtualScreenRepository->findById($id);

        return $this->formatScreen($updatedScreen);
    }

    /**
     * Format screen data for response.
     *
     * @param mixed $screen
     * @param bool $includePlaylist
     * @return array
     */
    protected function formatScreen($screen, bool $includePlaylist = false): array
    {
        $data = [
            'id' => $screen->id,
            'user_id' => $screen->user_id,
            'name' => $screen->name,
            'description' => $screen->description,
            'share_token' => $screen->share_token,
            'access_code' => $screen->access_code,
            'player_url' => $screen->player_url,
            'orientation' => $screen->orientation,
            'aspect_ratio' => $screen->aspect_ratio,
            'timezone' => $screen->timezone,
            'refresh_interval' => $screen->refresh_interval,
            'is_active' => $screen->is_active,
            'settings' => $screen->settings ?? [],
            'created_at' => $screen->created_at?->toISOString(),
            'updated_at' => $screen->updated_at?->toISOString(),
        ];

        if ($includePlaylist && $screen->relationLoaded('playlistItems')) {
            $data['playlist_items'] = $screen->playlistItems->map(function ($item) {
                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'content' => $item->content,
                    'duration' => $item->duration,
                    'order' => $item->order,
                    'schedule_start' => $item->schedule_start?->toISOString(),
                    'schedule_end' => $item->schedule_end?->toISOString(),
                    'is_active' => $item->is_active,
                    'created_at' => $item->created_at?->toISOString(),
                ];
            })->values()->all();
        }

        return $data;
    }

    protected function clearPublicCache($screen): void
    {
        if (!$screen || empty($screen->share_token)) {
            return;
        }

        $this->clearPublicCacheByToken($screen->share_token);
    }

    protected function clearPublicCacheByToken(?string $token): void
    {
        if (!$token) {
            return;
        }

        Cache::forget("virtual_screen_playlist_{$token}");
    }

    protected function resolveTimezone(?string $timezone): string
    {
        $fallback = config('app.timezone', 'UTC');
        $timezone = $timezone ?: $fallback;

        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $timezone = $fallback;
        }

        return $timezone;
    }

    protected function parseScheduleTime($value, string $timezone): ?Carbon
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function ensureAccessCode(VirtualScreen $screen): void
    {
        if ($screen->access_code) {
            return;
        }

        $screen->access_code = VirtualScreen::generateAccessCode();
        $screen->saveQuietly();
    }

    protected function buildPublicPlaylist(VirtualScreen $screen): array
    {
        $timezone = $this->resolveTimezone($screen->timezone ?? null);
        $now = Carbon::now($timezone);

        $playlistItems = $screen->playlistItems
            ->filter(function ($item) use ($now, $timezone) {
                if (!$item->is_active) {
                    return false;
                }

                $start = $this->parseScheduleTime($item->schedule_start, $timezone);
                $end = $this->parseScheduleTime($item->schedule_end, $timezone);

                if ($start && $now->lt($start)) {
                    return false;
                }

                if ($end && $now->gt($end)) {
                    return false;
                }

                return true;
            })
            ->values();

        $mediaMap = $this->loadMediaMap($playlistItems);

        return [
            'id' => $screen->id,
            'name' => $screen->name,
            'orientation' => $screen->orientation,
            'aspect_ratio' => $screen->aspect_ratio,
            'timezone' => $screen->timezone,
            'refresh_interval' => $screen->refresh_interval,
            'settings' => $screen->settings ?? [],
            'share_token' => $screen->share_token,
            'access_code' => $screen->access_code,
            'playlist_items' => $playlistItems->map(function ($item) use ($mediaMap, $screen) {
                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'content' => $this->hydrateContent($item, $mediaMap, $screen->share_token),
                    'duration' => $item->duration,
                    'order' => $item->order,
                ];
            })->values()->all(),
        ];
    }

    protected function loadMediaMap($playlistItems)
    {
        $mediaIds = $playlistItems
            ->filter(fn ($item) => in_array($item->type, ['image', 'pdf', 'video', 'audio'], true))
            ->map(function ($item) {
                $content = is_array($item->content) ? $item->content : [];
                return $content['media_id'] ?? null;
            })
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($mediaIds->isEmpty()) {
            return collect();
        }

        return ScreenMedia::query()
            ->whereIn('id', $mediaIds->all())
            ->get()
            ->keyBy('id');
    }

    protected function hydrateContent($item, $mediaMap, ?string $shareToken): array
    {
        $content = is_array($item->content) ? $item->content : [];

        if (!in_array($item->type, ['image', 'pdf', 'video', 'audio'], true)) {
            return $content;
        }

        $mediaId = $content['media_id'] ?? null;
        if (!$mediaId || !$mediaMap->has((int) $mediaId)) {
            return $content;
        }

        $media = $mediaMap->get((int) $mediaId);
        $content['media_id'] = $mediaId;
        $content['url'] = $shareToken
            ? url("/api/v1/public/screens/{$shareToken}/media/{$mediaId}")
            : $media->url;
        $content['original_name'] = $content['original_name'] ?? $media->original_name;
        $content['size'] = $content['size'] ?? $media->size;
        $content['mime_type'] = $content['mime_type'] ?? $media->mime_type;

        return $content;
    }
}
