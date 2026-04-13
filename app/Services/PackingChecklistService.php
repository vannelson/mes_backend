<?php

namespace App\Services;

use App\Http\Resources\PackingChecklist\PackingChecklistResource;
use App\Repositories\Contracts\PackingChecklistRepositoryInterface;
use App\Services\Contracts\PackingChecklistServiceInterface;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PackingChecklistService implements PackingChecklistServiceInterface
{
    public function __construct(
        protected PackingChecklistRepositoryInterface $packingChecklistRepository
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        return PackingChecklistResource::collection(
            $this->packingChecklistRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function detail(int $id): array
    {
        return (new PackingChecklistResource($this->packingChecklistRepository->findById($id)))->response()->getData(true);
    }

    public function upsertByWdPartNo(
        array $data,
        ?UploadedFile $ulLabelImage = null,
        ?UploadedFile $cartonLabelImage = null,
        ?UploadedFile $productImage = null,
        ?UploadedFile $coreImage = null
    ): array
    {
        $wdPartNo = (string) ($data['wd_part_no'] ?? '');
        $wdPartNo = trim($wdPartNo);

        if ($wdPartNo === '') {
            return $this->create($data, $ulLabelImage, $cartonLabelImage, $productImage, $coreImage);
        }

        $existing = $this->packingChecklistRepository->findByWdPartNo($wdPartNo);
        if (! $existing) {
            return $this->create($data, $ulLabelImage, $cartonLabelImage, $productImage, $coreImage);
        }

        return $this->update($existing->id, $data, $ulLabelImage, $cartonLabelImage, $productImage, $coreImage);
    }

    public function create(
        array $data,
        ?UploadedFile $ulLabelImage = null,
        ?UploadedFile $cartonLabelImage = null,
        ?UploadedFile $productImage = null,
        ?UploadedFile $coreImage = null
    ): array
    {
        $newUlLabelPath = null;
        $newCartonLabelPath = null;
        $newProductImagePath = null;
        $newCoreImagePath = null;

        if ($ulLabelImage) {
            $newUlLabelPath = $this->storeImage($ulLabelImage);
            $data['ul_label_image'] = $newUlLabelPath;
        }

        if ($cartonLabelImage) {
            $newCartonLabelPath = $this->storeImage($cartonLabelImage);
            $data['carton_label_image'] = $newCartonLabelPath;
        }
        if ($productImage) {
            $newProductImagePath = $this->storeImage($productImage);
            $data['product_image'] = $newProductImagePath;
        }
        if ($coreImage) {
            $newCoreImagePath = $this->storeImage($coreImage);
            $data['core_image'] = $newCoreImagePath;
        }

        try {
            $checklist = $this->packingChecklistRepository->create($data);
        } catch (\Throwable $e) {
            if ($newUlLabelPath) {
                Storage::disk('public')->delete($newUlLabelPath);
            }
            if ($newCartonLabelPath) {
                Storage::disk('public')->delete($newCartonLabelPath);
            }
            if ($newProductImagePath) {
                Storage::disk('public')->delete($newProductImagePath);
            }
            if ($newCoreImagePath) {
                Storage::disk('public')->delete($newCoreImagePath);
            }
            throw $e;
        }

        return (new PackingChecklistResource($checklist))->response()->getData(true);
    }

    public function update(
        int $id,
        array $data,
        ?UploadedFile $ulLabelImage = null,
        ?UploadedFile $cartonLabelImage = null,
        ?UploadedFile $productImage = null,
        ?UploadedFile $coreImage = null
    ): array
    {
        $checklist = $this->packingChecklistRepository->findById($id);
        $newUlLabelPath = null;
        $newCartonLabelPath = null;
        $newProductImagePath = null;
        $newCoreImagePath = null;

        if ($ulLabelImage) {
            $newUlLabelPath = $this->storeImage($ulLabelImage);
            $data['ul_label_image'] = $newUlLabelPath;
        }

        if ($cartonLabelImage) {
            $newCartonLabelPath = $this->storeImage($cartonLabelImage);
            $data['carton_label_image'] = $newCartonLabelPath;
        }
        if ($productImage) {
            $newProductImagePath = $this->storeImage($productImage);
            $data['product_image'] = $newProductImagePath;
        }
        if ($coreImage) {
            $newCoreImagePath = $this->storeImage($coreImage);
            $data['core_image'] = $newCoreImagePath;
        }

        $updated = (bool) $this->packingChecklistRepository->update($id, $data);

        if (! $updated) {
            if ($newUlLabelPath) {
                Storage::disk('public')->delete($newUlLabelPath);
            }
            if ($newCartonLabelPath) {
                Storage::disk('public')->delete($newCartonLabelPath);
            }
            if ($newProductImagePath) {
                Storage::disk('public')->delete($newProductImagePath);
            }
            if ($newCoreImagePath) {
                Storage::disk('public')->delete($newCoreImagePath);
            }
            return [];
        }

        if ($newUlLabelPath && $checklist->ul_label_image) {
            Storage::disk('public')->delete($checklist->ul_label_image);
        }
        if ($newCartonLabelPath && $checklist->carton_label_image) {
            Storage::disk('public')->delete($checklist->carton_label_image);
        }
        if ($newProductImagePath && $checklist->product_image) {
            Storage::disk('public')->delete($checklist->product_image);
        }
        if ($newCoreImagePath && $checklist->core_image) {
            Storage::disk('public')->delete($checklist->core_image);
        }

        return (new PackingChecklistResource($this->packingChecklistRepository->findById($id)))->response()->getData(true);
    }

    public function delete(int $id): bool
    {
        $checklist = $this->packingChecklistRepository->findById($id);
        $ulLabelPath = $checklist->ul_label_image;
        $cartonLabelPath = $checklist->carton_label_image;
        $productImagePath = $checklist->product_image;
        $coreImagePath = $checklist->core_image;

        $deleted = $this->packingChecklistRepository->delete($id);

        if ($deleted) {
            $this->deleteChecklistAsset($ulLabelPath);
            $this->deleteChecklistAsset($cartonLabelPath);
            $this->deleteChecklistAsset($productImagePath);
            $this->deleteChecklistAsset($coreImagePath);
        }

        return $deleted;
    }

    protected function storeImage(UploadedFile $image): string
    {
        $filename = Str::uuid()->toString() . '.png';
        $tempPath = tempnam(sys_get_temp_dir(), 'packing_checklist_');
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

        $targetDir = public_path('images/packingChecklist');
        if (!FileFacade::isDirectory($targetDir)) {
            FileFacade::makeDirectory($targetDir, 0755, true);
        }
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        FileFacade::move($tempPath, $targetPath);
        @FileFacade::chmod($targetPath, 0644);

        return "packingChecklist/{$filename}";
    }

    protected function deleteChecklistAsset(?string $path): void
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
