<?php

namespace App\Services;

use App\Repositories\Contracts\ScreenMediaRepositoryInterface;
use App\Repositories\Contracts\VirtualScreenRepositoryInterface;
use App\Services\Contracts\ScreenMediaServiceInterface;
use Illuminate\Http\UploadedFile;

class ScreenMediaService implements ScreenMediaServiceInterface
{
    public function __construct(
        protected ScreenMediaRepositoryInterface $screenMediaRepository,
        protected VirtualScreenRepositoryInterface $virtualScreenRepository
    ) {
    }

    /**
     * Get all media for a virtual screen.
     *
     * @param int $virtualScreenId
     * @param int $userId
     * @return array
     */
    public function getByScreen(int $virtualScreenId, int $userId): array
    {
        // Verify ownership
        $screen = $this->virtualScreenRepository->findById($virtualScreenId);
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to virtual screen.');
        }

        $media = $this->screenMediaRepository->getByScreen($virtualScreenId);

        return [
            'data' => $media->map(function ($item) {
                return $this->formatMedia($item);
            })->values()->all(),
        ];
    }

    /**
     * Upload media file.
     *
     * @param int $virtualScreenId
     * @param UploadedFile $file
     * @param int $userId
     * @return array
     */
    public function uploadMedia(int $virtualScreenId, UploadedFile $file, int $userId): array
    {
        // Verify ownership
        $screen = $this->virtualScreenRepository->findById($virtualScreenId);
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to virtual screen.');
        }

        // Validate file
        $this->validateFile($file);

        $media = $this->screenMediaRepository->storeMedia($virtualScreenId, $file);

        return $this->formatMedia($media);
    }

    /**
     * Delete media file.
     *
     * @param int $id
     * @param int $userId
     * @return bool
     */
    public function deleteMedia(int $id, int $userId): bool
    {
        $media = $this->screenMediaRepository->findById($id);

        // Verify ownership through screen
        $screen = $this->virtualScreenRepository->findById($media->virtual_screen_id);
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to media.');
        }

        return $this->screenMediaRepository->delete($id);
    }

    /**
     * Get media detail.
     *
     * @param int $id
     * @param int $userId
     * @return array
     */
    public function detail(int $id, int $userId): array
    {
        $media = $this->screenMediaRepository->findById($id);

        // Verify ownership through screen
        $screen = $this->virtualScreenRepository->findById($media->virtual_screen_id);
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to media.');
        }

        return $this->formatMedia($media);
    }

    /**
     * Validate uploaded file.
     *
     * @param UploadedFile $file
     * @return void
     * @throws \Exception
     */
    protected function validateFile(UploadedFile $file): void
    {
        // Check file size (max 10MB)
        $maxSize = 10 * 1024 * 1024; // 10MB in bytes
        if ($file->getSize() > $maxSize) {
            throw new \Exception('File size exceeds maximum allowed size of 10MB.');
        }

        // Check mime type
        $allowedMimes = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
        ];

        if (!in_array($file->getMimeType(), $allowedMimes)) {
            throw new \Exception('Invalid file type. Only images (JPEG, PNG, GIF, WebP) and PDF files are allowed.');
        }

        // Additional validation for images
        if (str_starts_with($file->getMimeType(), 'image/')) {
            $imageInfo = getimagesize($file->getRealPath());
            if ($imageInfo === false) {
                throw new \Exception('Invalid image file.');
            }

            // Check dimensions (max 4K resolution)
            [$width, $height] = $imageInfo;
            if ($width > 3840 || $height > 2160) {
                throw new \Exception('Image dimensions exceed maximum allowed size of 3840x2160.');
            }
        }
    }

    /**
     * Format media data for response.
     *
     * @param mixed $media
     * @return array
     */
    protected function formatMedia($media): array
    {
        return [
            'id' => $media->id,
            'virtual_screen_id' => $media->virtual_screen_id,
            'filename' => $media->filename,
            'original_name' => $media->original_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'human_size' => $media->human_size,
            'url' => $media->url,
            'path' => $media->path,
            'is_image' => $media->isImage(),
            'is_pdf' => $media->isPdf(),
            'created_at' => $media->created_at?->toISOString(),
        ];
    }
}
