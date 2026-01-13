<?php

namespace App\Http\Controllers;

use App\Http\Requests\PackingChecklist\PackingChecklistStoreRequest;
use App\Http\Requests\PackingChecklist\PackingChecklistUpdateRequest;
use App\Services\Contracts\PackingChecklistServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class PackingChecklistController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected PackingChecklistServiceInterface $packingChecklistService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        foreach (['work_order_no', 'wd_part_no', 'user_id'] as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->packingChecklistService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Packing checklists retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load packing checklists.', 500);
        }
    }

    public function store(PackingChecklistStoreRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            unset($data['ul_label_image'], $data['carton_label_image']);
            if (empty($data['user_id']) && $request->user()) {
                $data['user_id'] = $request->user()->id;
            }

            $checklist = $this->packingChecklistService->create(
                $data,
                $request->file('ul_label_image'),
                $request->file('carton_label_image')
            );

            return $this->success('Packing checklist created successfully!', $checklist, 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to create packing checklist.', 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            if (!ctype_digit($id)) {
                return $this->error('Invalid packing checklist id.', 422);
            }

            $checklist = $this->packingChecklistService->detail((int) $id);

            return $this->success('Packing checklist retrieved successfully!', $checklist);
        } catch (Throwable $e) {
            return $this->error('Failed to load packing checklist.', 500);
        }
    }

    public function update(PackingChecklistUpdateRequest $request, string $id): JsonResponse
    {
        try {
            if (!ctype_digit($id)) {
                return $this->error('Invalid packing checklist id.', 422);
            }

            $data = $request->validated();
            unset($data['ul_label_image'], $data['carton_label_image']);
            if (array_key_exists('user_id', $data) && $data['user_id'] === null && $request->user()) {
                $data['user_id'] = $request->user()->id;
            }

            $updated = $this->packingChecklistService->update(
                (int) $id,
                $data,
                $request->file('ul_label_image'),
                $request->file('carton_label_image')
            );

            return !empty($updated)
                ? $this->success('Packing checklist updated successfully!', $updated)
                : $this->error('Nothing to update.', 422);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to update packing checklist.', 500);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            if (!ctype_digit($id)) {
                return $this->error('Invalid packing checklist id.', 422);
            }

            $deleted = $this->packingChecklistService->delete((int) $id);

            return $deleted
                ? $this->success('Packing checklist deleted successfully!')
                : $this->error('Failed to delete packing checklist.', 500);
        } catch (Throwable $e) {
            return $this->error('Failed to delete packing checklist.', 500);
        }
    }
}
