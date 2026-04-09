<?php

namespace App\Services;

use App\Http\Resources\Packing\PackingResource;
use App\Repositories\Contracts\PackingRepositoryInterface;
use App\Services\Contracts\PackingServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PackingService implements PackingServiceInterface
{
    public function __construct(
        protected PackingRepositoryInterface $packingRepository
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        return PackingResource::collection(
            $this->packingRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function detail(int $id): array
    {
        return (new PackingResource($this->packingRepository->findById($id)->load(['customer', 'packingChecklist'])))->response()->getData(true);
    }

    public function create(array $data, ?UploadedFile $image = null, ?UploadedFile $design = null): array
    {
        $newImagePath = null;
        $newDesignPath = null;
        if ($image) {
            $newImagePath = $this->storeImage($image);
            $data['image'] = $newImagePath;
        }
        if ($design) {
            $newDesignPath = $this->storeImage($design);
            $data['design'] = $newDesignPath;
        }

        try {
            $packing = $this->packingRepository->create($data);
        } catch (\Throwable $e) {
            $this->deletePackingAsset($newImagePath);
            $this->deletePackingAsset($newDesignPath);
            throw $e;
        }

        return (new PackingResource($packing))->response()->getData(true);
    }

    public function createBatch(array $packings): array
    {
        $created = [];
        $failed = [];

        foreach ($packings as $index => $packing) {
            try {
                $created[] = $this->packingRepository->create($packing);
            } catch (\Throwable $e) {
                $failed[] = [
                    'index' => $index,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'items' => PackingResource::collection(collect($created))->resolve(),
            'count' => count($created),
            'failed' => count($failed),
            'errors' => $failed,
        ];
    }

    public function createBatchWithFiles(array $packings, array $images = [], array $designs = []): array
    {
        $created = [];
        $failed = [];

        foreach ($packings as $index => $packing) {
            $newImagePath = null;
            $newDesignPath = null;
            $image = $images[$index] ?? null;
            $design = $designs[$index] ?? null;

            try {
                if ($image instanceof UploadedFile) {
                    $newImagePath = $this->storeImage($image);
                    $packing['image'] = $newImagePath;
                }

                if ($design instanceof UploadedFile) {
                    $newDesignPath = $this->storeImage($design);
                    $packing['design'] = $newDesignPath;
                }

                $created[] = $this->packingRepository->create($packing);
            } catch (\Throwable $e) {
                $this->deletePackingAsset($newImagePath);
                $this->deletePackingAsset($newDesignPath);
                $failed[] = [
                    'index' => $index,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'items' => PackingResource::collection(collect($created))->resolve(),
            'count' => count($created),
            'failed' => count($failed),
            'errors' => $failed,
        ];
    }

    public function update(int $id, array $data, ?UploadedFile $image = null, ?UploadedFile $design = null): array
    {
        $packing = $this->packingRepository->findById($id);
        $newImagePath = null;
        $newDesignPath = null;

        if ($image) {
            $newImagePath = $this->storeImage($image);
            $data['image'] = $newImagePath;
        }
        if ($design) {
            $newDesignPath = $this->storeImage($design);
            $data['design'] = $newDesignPath;
        }

        $updated = (bool) $this->packingRepository->update($id, $data);

        if (! $updated) {
            $this->deletePackingAsset($newImagePath);
            $this->deletePackingAsset($newDesignPath);
            return [];
        }

        if ($newImagePath && $packing->image) {
            $this->deletePackingAsset($packing->image);
        }
        if ($newDesignPath && $packing->design) {
            $this->deletePackingAsset($packing->design);
        }

        return (new PackingResource($this->packingRepository->findById($id)))->response()->getData(true);
    }

    public function delete(int $id): bool
    {
        $packing = $this->packingRepository->findById($id);
        $imagePath = $packing->image;
        $designPath = $packing->design;

        $deleted = $this->packingRepository->delete($id);

        if ($deleted && $imagePath) {
            $this->deletePackingAsset($imagePath);
        }
        if ($deleted && $designPath) {
            $this->deletePackingAsset($designPath);
        }

        return $deleted;
    }

    protected function storeImage(UploadedFile $image): string
    {
        $filename = Str::uuid()->toString() . '.png';
        $tempPath = tempnam(sys_get_temp_dir(), 'packing_');
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

        $targetDir = public_path('images/packing');
        if (!FileFacade::isDirectory($targetDir)) {
            FileFacade::makeDirectory($targetDir, 0755, true);
        }
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        FileFacade::move($tempPath, $targetPath);
        @FileFacade::chmod($targetPath, 0644);

        return "packing/{$filename}";
    }

    protected function deletePackingAsset(?string $path): void
    {
        if (!$path) {
            return;
        }
        $clean = ltrim($path, '/');
        if (str_starts_with($clean, 'images/')) {
            $clean = substr($clean, strlen('images/'));
        }
        if (str_starts_with($clean, 'storage/')) {
            $clean = substr($clean, strlen('storage/'));
        }

        $publicPath = public_path('images/' . $clean);
        if (FileFacade::exists($publicPath)) {
            FileFacade::delete($publicPath);
            return;
        }

        Storage::disk('public')->delete($clean);
    }
}
