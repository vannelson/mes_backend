<?php

namespace App\Services;

use App\Http\Resources\WorkOrderEvidence\WorkOrderEvidenceResource;
use App\Repositories\Contracts\WorkOrderEvidenceRepositoryInterface;
use App\Services\Contracts\WorkOrderEvidenceServiceInterface;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WorkOrderEvidenceService implements WorkOrderEvidenceServiceInterface
{
    public function __construct(
        protected WorkOrderEvidenceRepositoryInterface $workOrderEvidenceRepository
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        return WorkOrderEvidenceResource::collection(
            $this->workOrderEvidenceRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function create(array $data, array $images = []): array
    {
        $files = [];
        foreach ($images as $image) {
            if ($image instanceof UploadedFile) {
                $files[] = $image;
            }
        }

        if (empty($files)) {
            return [];
        }

        $created = [];
        $storedPaths = [];

        try {
            foreach ($files as $file) {
                $storedPath = $this->storeImage($file);
                $storedPaths[] = $storedPath;
                $created[] = $this->workOrderEvidenceRepository->create([
                    'work_order_no' => $data['work_order_no'],
                    'route_name' => $data['route_name'],
                    'image_path' => $storedPath,
                    'original_name' => $file->getClientOriginalName() ?: null,
                ]);
            }
        } catch (\Throwable $e) {
            if (!empty($storedPaths)) {
                Storage::disk('public')->delete($storedPaths);
            }
            throw $e;
        }

        return WorkOrderEvidenceResource::collection(collect($created))->resolve();
    }

    public function delete(int $id): bool
    {
        $record = $this->workOrderEvidenceRepository->findById($id);
        $path = $record->image_path;

        $deleted = $this->workOrderEvidenceRepository->delete($id);
        if ($deleted && $path) {
            $clean = ltrim($path, '/');
            if (str_starts_with($clean, 'evidence_image/')) {
                $filePath = public_path('images/' . $clean);
                if (FileFacade::exists($filePath)) {
                    FileFacade::delete($filePath);
                } else {
                    Storage::disk('public')->delete($path);
                }
            } elseif (str_starts_with($clean, 'images/evidence_image/')) {
                $filePath = public_path($clean);
                if (FileFacade::exists($filePath)) {
                    FileFacade::delete($filePath);
                } else {
                    Storage::disk('public')->delete($path);
                }
            } else {
                Storage::disk('public')->delete($path);
            }
        }

        return $deleted;
    }

    protected function storeImage(UploadedFile $image): string
    {
        $filename = Str::uuid()->toString() . '.png';
        $tempPath = tempnam(sys_get_temp_dir(), 'evidence_image_');
        if ($tempPath === false) {
            throw new \RuntimeException('Failed to create temp file for image conversion.');
        }

        $mimeType = $image->getMimeType();
        $resource = match ($mimeType) {
            'image/jpeg', 'image/jpg' => imagecreatefromjpeg($image->getRealPath()),
            'image/png' => imagecreatefrompng($image->getRealPath()),
            'image/webp' => imagecreatefromwebp($image->getRealPath()),
            default => false,
        };

        if (! $resource) {
            @unlink($tempPath);
            throw new \RuntimeException('Unsupported image type for PNG conversion.');
        }

        imagealphablending($resource, true);
        imagesavealpha($resource, true);
        $saved = imagepng($resource, $tempPath, 6);
        imagedestroy($resource);

        if (! $saved) {
            @unlink($tempPath);
            throw new \RuntimeException('Failed to convert image to PNG.');
        }

        $targetDir = public_path('images/evidence_image');
        if (!FileFacade::isDirectory($targetDir)) {
            FileFacade::makeDirectory($targetDir, 0755, true);
        }
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        FileFacade::move($tempPath, $targetPath);
        @FileFacade::chmod($targetPath, 0644);

        return "evidence_image/{$filename}";
    }
}
