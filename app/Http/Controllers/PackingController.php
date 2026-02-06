<?php

namespace App\Http\Controllers;

use App\Http\Requests\Packing\PackingBatchStoreRequest;
use App\Http\Requests\Packing\PackingStoreRequest;
use App\Http\Requests\Packing\PackingUpdateRequest;
use App\Services\Contracts\PackingServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PackingController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected PackingServiceInterface $packingService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);

        $filterKeys = [
            'wd_part_no',
            'material',
            'description',
            'batch_number',
            'image',
            'design',
            'shipping_location',
            'customer_code',
            'box_size',
            'qty_per_box',
            'qty_per_roll',
            'rolls_per_box',
            'core_label_left',
            'core_label_right',
            'hm_no',
            'ul_label_no',
            'cas',
            'important',
            'code_1',
            'underline_code',
            'colour_code',
            'wd_revision',
            'revised_by_pic',
            'date_of_revised',
            'remarks',
        ];

        foreach ($filterKeys as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        $search = trim((string) $request->get('search', ''));
        if ($search === '') {
            $search = trim((string) $request->get('q', ''));
        }
        if ($search !== '') {
            $filters['search'] = $search;
        }

        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->packingService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Packings retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load packings.', 500);
        }
    }

    public function listByBatch(Request $request): JsonResponse
    {
        $batchNumber = (string) $request->get('batch_number', '');
        if ($batchNumber === '') {
            return $this->error('batch_number is required.', 422);
        }

        $filters = Arr::get($request->all(), 'filters', []);
        $filters['batch_number'] = $batchNumber;

        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->packingService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Packings retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load packings.', 500);
        }
    }

    public function listByPartNo(Request $request): JsonResponse
    {
        $partNo = (string) $request->get('wd_part_no', '');
        if ($partNo === '') {
            return $this->error('wd_part_no is required.', 422);
        }

        $filters = Arr::get($request->all(), 'filters', []);
        $filters['wd_part_no'] = $partNo;

        try {
            $data = $this->packingService->getList($filters, ['id', 'desc'], 1, 1);
            $item = $data['data'][0] ?? null;

            if (!$item) {
                return $this->success('Packing retrieved successfully!', null);
            }

            return $this->success('Packing retrieved successfully!', $item);
        } catch (Throwable $e) {
            return $this->error('Failed to load packings.', 500);
        }
    }

    public function store(PackingStoreRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            unset($data['image']);
            unset($data['design']);
            $packing = $this->packingService->create(
                $data,
                $request->file('image'),
                $request->file('design')
            );

            return $this->success('Packing created successfully!', $packing, 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to create packing.', 500);
        }
    }

    public function batchStore(PackingBatchStoreRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $batchNumber = $payload['batch_number'] ?? null;
        if ($batchNumber !== null && $batchNumber !== '') {
            foreach ($payload['packings'] as $index => $packing) {
                if (!array_key_exists('batch_number', $packing) || $packing['batch_number'] === null || $packing['batch_number'] === '') {
                    $payload['packings'][$index]['batch_number'] = $batchNumber;
                }
            }
        }
        $packingsCount = count($payload['packings']);
        $images = $request->file('images', []);
        $designs = $request->file('designs', []);
        $imageCount = is_array($images) ? count($images) : 0;
        $designCount = is_array($designs) ? count($designs) : 0;
        $requestId = (string) Str::uuid();
        $rawImagesCount = isset($_FILES['images']['name']) && is_array($_FILES['images']['name'])
            ? count($_FILES['images']['name'])
            : 0;
        $rawDesignsCount = isset($_FILES['designs']['name']) && is_array($_FILES['designs']['name'])
            ? count($_FILES['designs']['name'])
            : 0;
        if ($imageCount !== $packingsCount || $designCount !== $packingsCount) {
            $imageKeys = is_array($images) ? array_map('intval', array_keys($images)) : [];
            $designKeys = is_array($designs) ? array_map('intval', array_keys($designs)) : [];
            $expectedKeys = range(0, max(0, $packingsCount - 1));

            Log::warning('Packing batch upload mismatch.', [
                'request_id' => $requestId,
                'packings_count' => $packingsCount,
                'image_count' => $imageCount,
                'design_count' => $designCount,
                'raw_images_count' => $rawImagesCount,
                'raw_designs_count' => $rawDesignsCount,
                'missing_image_indices' => array_values(array_diff($expectedKeys, $imageKeys)),
                'missing_design_indices' => array_values(array_diff($expectedKeys, $designKeys)),
                'content_length' => $request->server('CONTENT_LENGTH'),
                'content_type' => $request->server('CONTENT_TYPE'),
                'path' => $request->path(),
            ]);
        }

        $result = $this->packingService->createBatchWithFiles(
            $payload['packings'],
            $images,
            $designs
        );

        return $this->success('Packings created successfully!', $result);
    }

    public function show(string $id): JsonResponse
    {
        try {
            if (!ctype_digit($id)) {
                return $this->error('Invalid packing id.', 422);
            }

            $packing = $this->packingService->detail((int) $id);

            return $this->success('Packing retrieved successfully!', $packing);
        } catch (Throwable $e) {
            return $this->error('Failed to load packing.', 500);
        }
    }

    public function update(PackingUpdateRequest $request, string $id): JsonResponse
    {
        try {
            if (!ctype_digit($id)) {
                return $this->error('Invalid packing id.', 422);
            }

            $data = $request->validated();
            unset($data['image']);
            unset($data['design']);
            $updated = $this->packingService->update(
                (int) $id,
                $data,
                $request->file('image'),
                $request->file('design')
            );

            return $updated
                ? $this->success('Packing updated successfully!', $updated)
                : $this->error('Nothing to update.', 422);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to update packing.', 500);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            if (!ctype_digit($id)) {
                return $this->error('Invalid packing id.', 422);
            }

            $this->packingService->delete((int) $id);

            return $this->success('Packing deleted successfully!');
        } catch (Throwable $e) {
            return $this->error('Failed to delete packing.', 500);
        }
    }
}
