<?php

namespace App\Http\Controllers;

use App\Services\Contracts\VirtualScreenServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Throwable;

class PublicScreenController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected VirtualScreenServiceInterface $virtualScreenService
    ) {
    }

    /**
     * Get public playlist by share token (for player).
     * This endpoint is public and rate-limited.
     */
    public function show(string $shareToken): JsonResponse
    {
        try {
            $data = $this->virtualScreenService->getPublicPlaylist($shareToken);

            if (!$data) {
                return $this->error('Virtual screen not found or inactive.', 404);
            }

            return $this->success('Playlist retrieved successfully.', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load playlist: ' . $e->getMessage(), 500);
        }
    }

    public function showByAccessCode(string $accessCode): JsonResponse
    {
        try {
            $data = $this->virtualScreenService->getPublicPlaylistByAccessCode($accessCode);

            if (!$data) {
                return $this->error('Virtual screen not found or inactive.', 404);
            }

            return $this->success('Playlist retrieved successfully.', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load playlist: ' . $e->getMessage(), 500);
        }
    }
}
