<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

interface ScreenMediaRepositoryInterface extends RepositoryInterface
{
    /**
     * Get all media for a virtual screen.
     *
     * @param int $virtualScreenId
     * @return Collection
     */
    public function getByScreen(int $virtualScreenId): Collection;

    /**
     * Store uploaded media file.
     *
     * @param int $virtualScreenId
     * @param UploadedFile $file
     * @return Model
     */
    public function storeMedia(int $virtualScreenId, UploadedFile $file): Model;

    /**
     * Delete all media for a virtual screen.
     *
     * @param int $virtualScreenId
     * @return bool
     */
    public function deleteByScreen(int $virtualScreenId): bool;

    /**
     * Get media by filename.
     *
     * @param int $virtualScreenId
     * @param string $filename
     * @return Model|null
     */
    public function findByFilename(int $virtualScreenId, string $filename): ?Model;
}
