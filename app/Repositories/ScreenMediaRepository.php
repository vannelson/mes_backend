<?php

namespace App\Repositories;

use App\Models\ScreenMedia;
use App\Repositories\Contracts\ScreenMediaRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ScreenMediaRepository extends BaseRepository implements ScreenMediaRepositoryInterface
{
    public function __construct(ScreenMedia $model)
    {
        parent::__construct($model);
    }

    /**
     * Get all media for a virtual screen.
     *
     * @param int $virtualScreenId
     * @return Collection
     */
    public function getByScreen(int $virtualScreenId): Collection
    {
        return $this->model
            ->where('virtual_screen_id', $virtualScreenId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Store uploaded media file.
     *
     * @param int $virtualScreenId
     * @param UploadedFile $file
     * @return Model
     */
    public function storeMedia(int $virtualScreenId, UploadedFile $file): Model
    {
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $filename = Str::random(40) . '.' . $extension;

        // Store in public disk under virtual-screens directory
        $path = $file->storeAs(
            'virtual-screens/' . $virtualScreenId,
            $filename,
            'public'
        );

        return $this->model->create([
            'virtual_screen_id' => $virtualScreenId,
            'filename' => $filename,
            'original_name' => $originalName,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
        ]);
    }

    /**
     * Delete all media for a virtual screen.
     *
     * @param int $virtualScreenId
     * @return bool
     */
    public function deleteByScreen(int $virtualScreenId): bool
    {
        $media = $this->getByScreen($virtualScreenId);

        foreach ($media as $item) {
            $item->delete(); // This will trigger the model's deleting event to remove files
        }

        return true;
    }

    /**
     * Get media by filename.
     *
     * @param int $virtualScreenId
     * @param string $filename
     * @return Model|null
     */
    public function findByFilename(int $virtualScreenId, string $filename): ?Model
    {
        return $this->model
            ->where('virtual_screen_id', $virtualScreenId)
            ->where('filename', $filename)
            ->first();
    }
}
