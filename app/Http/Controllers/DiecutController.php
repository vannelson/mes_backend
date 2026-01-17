<?php

namespace App\Http\Controllers;

use App\Http\Requests\Diecut\DiecutBatchReplaceRequest;
use App\Http\Requests\Diecut\DiecutBatchStoreRequest;
use App\Services\Contracts\DiecutServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Throwable;

class DiecutController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected DiecutServiceInterface $diecutService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        foreach (['q', 'diecut_no', 'diecut_type', 'batch_number'] as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->diecutService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Diecut rows retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load diecut rows.', 500);
        }
    }

    public function batchStore(DiecutBatchStoreRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $result = $this->diecutService->createBatch($payload['diecuts']);

        return $this->success('Diecut rows created successfully!', $result);
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
            $data = $this->diecutService->listByBatch($batchNumber, $limit, $page);

            return $this->successPagination('Diecut rows retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load diecut rows.', 500);
        }
    }

    public function replaceBatch(DiecutBatchReplaceRequest $request): JsonResponse
    {
        $payload = $request->validated();

        try {
            $result = $this->diecutService->replaceBatch($payload['batch_number'], $payload['diecuts']);

            return $this->success('Diecut batch replaced successfully!', $result);
        } catch (Throwable $e) {
            return $this->error('Failed to replace diecut batch.', 500);
        }
    }
}
