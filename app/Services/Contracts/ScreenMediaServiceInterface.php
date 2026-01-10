<?php

namespace App\Services\Contracts;

use Illuminate\Http\UploadedFile;

interface ScreenMediaServiceInterface
{
    /**
     * Get all media for a virtual screen.
     *
     * @param int $virtualScreenId
     * @param int $userId
     * @return array
     */
    public function getByScreen(int $virtualScreenId, int $userId): array;

    /**
     * Upload media file.
     *
     * @param int $virtualScreenId
     * @param UploadedFile $file
     * @param int $userId
     * @return array
     */
    public function uploadMedia(int $virtualScreenId, UploadedFile $file, int $userId): array;

    /**
     * Delete media file.
     *
     * @param int $id
     * @param int $userId
     * @return bool
     */
    public function deleteMedia(int $id, int $userId): bool;

    /**
     * Get media detail.
     *
     * @param int $id
     * @param int $userId
     * @return array
     */
    public function detail(int $id, int $userId): array;
}
