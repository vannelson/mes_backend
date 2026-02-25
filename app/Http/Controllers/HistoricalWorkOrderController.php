<?php

namespace App\Http\Controllers;

use App\Http\Requests\HistoricalWorkOrder\HistoricalWorkOrderImportRequest;
use App\Services\HistoricalWorkOrderImportService;
use App\Services\HistoricalWorkOrderService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Throwable;

class HistoricalWorkOrderController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected HistoricalWorkOrderImportService $historicalWorkOrderImportService,
        protected HistoricalWorkOrderService $historicalWorkOrderService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        foreach (
            [
                'q',
                'work_order_no',
                'material_batch_no',
                'die_cut',
                'machine_name',
                'machine_code',
                'machine_type',
                'staff_code',
                'staff_name',
                'customer_part_number',
                'customer_code',
                'date_completed_from',
                'date_completed_to',
                'no_of_press_min',
                'no_of_press_max',
                'no_of_ups_min',
                'no_of_ups_max',
                'printed_quantity_min',
                'printed_quantity_max',
            ] as $key
        ) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }
        $order = Arr::get($request->all(), 'order', ['date_completed', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 25);
        $limit = max(1, min($limit, 200));
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->historicalWorkOrderService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Historical work orders retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load historical work orders.', 500);
        }
    }

    public function summary(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        foreach (
            [
                'q',
                'work_order_no',
                'material_batch_no',
                'die_cut',
                'machine_name',
                'machine_code',
                'machine_type',
                'staff_code',
                'staff_name',
                'customer_part_number',
                'customer_code',
                'date_completed_from',
                'date_completed_to',
                'no_of_press_min',
                'no_of_press_max',
                'no_of_ups_min',
                'no_of_ups_max',
                'printed_quantity_min',
                'printed_quantity_max',
            ] as $key
        ) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        try {
            $data = $this->historicalWorkOrderService->summary($filters);

            return $this->success('Historical work order summary retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load historical work order summary.', 500);
        }
    }

    public function filterOptions(Request $request): JsonResponse
    {
        $column = trim((string) $request->get('column', ''));
        $allowed = [
            'work_order_no',
            'material_batch_no',
            'die_cut',
            'machine_name',
            'machine_code',
            'machine_type',
            'staff_code',
            'staff_name',
            'customer_part_number',
            'customer_code',
        ];

        if ($column === '' || !in_array($column, $allowed, true)) {
            return $this->error('Invalid filter column.', 422);
        }

        $filters = Arr::get($request->all(), 'filters', []);
        foreach (
            [
                'q',
                'work_order_no',
                'material_batch_no',
                'die_cut',
                'machine_name',
                'machine_code',
                'machine_type',
                'staff_code',
                'staff_name',
                'customer_part_number',
                'customer_code',
                'date_completed_from',
                'date_completed_to',
                'no_of_press_min',
                'no_of_press_max',
                'no_of_ups_min',
                'no_of_ups_max',
                'printed_quantity_min',
                'printed_quantity_max',
            ] as $key
        ) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        unset($filters[$column]);

        try {
            $data = $this->historicalWorkOrderService->getFilterOptions($column, $filters);

            return $this->success('Historical work order filter options retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load historical work order filter options.', 500);
        }
    }

    public function import(HistoricalWorkOrderImportRequest $request): JsonResponse
    {
        try {
            $payload = $request->validated();
            $result = $this->historicalWorkOrderImportService->import(
                $payload['file'],
                $payload['sheet'] ?? null
            );

            return $this->success('Historical work orders imported successfully!', $result);
        } catch (Throwable $e) {
            \Log::error('Historical work order import failed', [
                'message' => $e->getMessage(),
            ]);

            return $this->error('Failed to import historical work orders.', 500);
        }
    }
}
