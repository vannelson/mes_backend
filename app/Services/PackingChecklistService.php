<?php

namespace App\Services;

use App\Http\Resources\PackingChecklist\PackingChecklistResource;
use App\Repositories\Contracts\PackingChecklistRepositoryInterface;
use App\Services\Contracts\PackingChecklistServiceInterface;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
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

    public function create(array $data, ?UploadedFile $ulLabelImage = null, ?UploadedFile $cartonLabelImage = null): array
    {
        $newUlLabelPath = null;
        $newCartonLabelPath = null;

        if ($ulLabelImage) {
            $newUlLabelPath = $this->storeImage($ulLabelImage);
            $data['ul_label_image'] = $newUlLabelPath;
        }

        if ($cartonLabelImage) {
            $newCartonLabelPath = $this->storeImage($cartonLabelImage);
            $data['carton_label_image'] = $newCartonLabelPath;
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
            throw $e;
        }

        return (new PackingChecklistResource($checklist))->response()->getData(true);
    }

    public function update(int $id, array $data, ?UploadedFile $ulLabelImage = null, ?UploadedFile $cartonLabelImage = null): array
    {
        $checklist = $this->packingChecklistRepository->findById($id);
        $newUlLabelPath = null;
        $newCartonLabelPath = null;

        if ($ulLabelImage) {
            $newUlLabelPath = $this->storeImage($ulLabelImage);
            $data['ul_label_image'] = $newUlLabelPath;
        }

        if ($cartonLabelImage) {
            $newCartonLabelPath = $this->storeImage($cartonLabelImage);
            $data['carton_label_image'] = $newCartonLabelPath;
        }

        $updated = (bool) $this->packingChecklistRepository->update($id, $data);

        if (! $updated) {
            if ($newUlLabelPath) {
                Storage::disk('public')->delete($newUlLabelPath);
            }
            if ($newCartonLabelPath) {
                Storage::disk('public')->delete($newCartonLabelPath);
            }
            return [];
        }

        if ($newUlLabelPath && $checklist->ul_label_image) {
            Storage::disk('public')->delete($checklist->ul_label_image);
        }
        if ($newCartonLabelPath && $checklist->carton_label_image) {
            Storage::disk('public')->delete($checklist->carton_label_image);
        }

        return (new PackingChecklistResource($this->packingChecklistRepository->findById($id)))->response()->getData(true);
    }

    public function delete(int $id): bool
    {
        $checklist = $this->packingChecklistRepository->findById($id);
        $ulLabelPath = $checklist->ul_label_image;
        $cartonLabelPath = $checklist->carton_label_image;

        $deleted = $this->packingChecklistRepository->delete($id);

        if ($deleted && $ulLabelPath) {
            Storage::disk('public')->delete($ulLabelPath);
        }
        if ($deleted && $cartonLabelPath) {
            Storage::disk('public')->delete($cartonLabelPath);
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

        $storedPath = Storage::disk('public')->putFileAs('packing_checklists', new File($tempPath), $filename);
        @unlink($tempPath);

        return $storedPath;
    }
}
