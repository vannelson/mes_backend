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

    public function getByScreen(int $virtualScreenId, int $userId): array
    {
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

    public function uploadMedia(int $virtualScreenId, UploadedFile $file, int $userId): array
    {
        $screen = $this->virtualScreenRepository->findById($virtualScreenId);
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to virtual screen.');
        }

        $this->validateFile($file);

        $media = $this->screenMediaRepository->storeMedia($virtualScreenId, $file);

        return $this->formatMedia($media);
    }

    public function deleteMedia(int $id, int $userId): bool
    {
        $media = $this->screenMediaRepository->findById($id);

        $screen = $this->virtualScreenRepository->findById($media->virtual_screen_id);
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to media.');
        }

        return $this->screenMediaRepository->delete($id);
    }

    public function detail(int $id, int $userId): array
    {
        $media = $this->screenMediaRepository->findById($id);

        $screen = $this->virtualScreenRepository->findById($media->virtual_screen_id);
        if ($screen->user_id !== $userId) {
            throw new \Exception('Unauthorized access to media.');
        }

        return $this->formatMedia($media);
    }

    protected function validateFile(UploadedFile $file): void
    {
        $mime = $file->getMimeType();
        $size = $file->getSize();

        $maxImagePdf = 10 * 1024 * 1024;   // 10MB
        $maxAudio = 25 * 1024 * 1024;   // 25MB
        $maxVideo = 100 * 1024 * 1024;  // 100MB

        $allowedMimes = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',

            'audio/mpeg',
            'audio/mp3',
            'audio/wav',
            'audio/x-wav',
            'audio/ogg',
            'audio/aac',
            'audio/webm',
            'audio/mp4',
            'audio/flac',
            'audio/x-m4a',

            'video/mp4',
            'video/mpeg',
            'video/ogg',
            'video/webm',
            'video/x-msvideo',
            'video/quicktime',
            'video/x-matroska',
        ];

        if (!in_array($mime, $allowedMimes, true)) {
            throw new \Exception('Invalid file type. Only images, PDF, audio, and video files are allowed.');
        }

        if (str_starts_with($mime, 'video/')) {
            if ($size > $maxVideo) {
                throw new \Exception('Video file size exceeds maximum allowed size of 100MB.');
            }
        } elseif (str_starts_with($mime, 'audio/')) {
            if ($size > $maxAudio) {
                throw new \Exception('Audio file size exceeds maximum allowed size of 25MB.');
            }
        } else {
            if ($size > $maxImagePdf) {
                throw new \Exception('File size exceeds maximum allowed size of 10MB.');
            }
        }

        if (str_starts_with($mime, 'image/')) {
            $imageInfo = getimagesize($file->getRealPath());
            if ($imageInfo === false) {
                throw new \Exception('Invalid image file.');
            }

            [$width, $height] = $imageInfo;
            if ($width > 3840 || $height > 2160) {
                throw new \Exception('Image dimensions exceed maximum allowed size of 3840x2160.');
            }
        }
    }

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
