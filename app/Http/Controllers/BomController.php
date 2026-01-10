<?php

namespace App\Http\Controllers;

use App\Http\Requests\Bom\BomBatchReplaceRequest;
use App\Http\Requests\Bom\BomBatchStoreRequest;
use App\Services\Contracts\BomServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Throwable;

class BomController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected BomServiceInterface $bomService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        foreach (['q', 'customer_code', 'part_no', 'material_code', 'batch_number'] as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->bomService->getList($filters, $order, $limit, $page);

            return $this->successPagination('BOM rows retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load BOM rows.', 500);
        }
    }

    public function stats(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        foreach (['q', 'customer_code', 'part_no', 'material_code', 'batch_number', 'description'] as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        try {
            $data = $this->bomService->getStats($filters);

            return $this->success('BOM summary generated successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load BOM summary.', 500);
        }
    }

    public function batchStore(BomBatchStoreRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $result = $this->bomService->createBatch($payload['boms']);

        return $this->success('BOM rows created successfully!', $result);
    }

    public function listByBatch(Request $request): JsonResponse
    {
        $batchNumber = trim((string) $request->get('batch_number', ''));
        $limit = (int) $request->get('limit', 10);
        $page = (int) $request->get('page', 1);

        if ($batchNumber === '') {
            return $this->error('Batch number is required.', 422);
        }

        try {
            $data = $this->bomService->listByBatch($batchNumber, $limit, $page);

            return $this->successPagination('BOM rows retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load BOM rows.', 500);
        }
    }

    public function listByCustomerPart(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        $customerCode = trim((string) $request->get('customer_code', Arr::get($filters, 'customer_code', '')));
        $partNo = trim((string) $request->get('part_no', Arr::get($filters, 'part_no', '')));
        $limit = (int) $request->get('limit', 10);
        $page = (int) $request->get('page', 1);
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);

        if ($customerCode === '' || $partNo === '') {
            return $this->error('Customer code and part number are required.', 422);
        }

        $filters['customer_code'] = $customerCode;
        $filters['part_no'] = $partNo;

        foreach (['q', 'material_code'] as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        try {
            $data = $this->bomService->getList($filters, $order, $limit, $page);

            return $this->successPagination('BOM rows retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load BOM rows.', 500);
        }
    }

    public function replaceBatch(BomBatchReplaceRequest $request): JsonResponse
    {
        $payload = $request->validated();

        try {
            $result = $this->bomService->replaceBatch($payload['batch_number'], $payload['boms']);

            return $this->success('BOM batch replaced successfully!', $result);
        } catch (Throwable $e) {
            return $this->error('Failed to replace BOM batch.', 500);
        }
    }
}
