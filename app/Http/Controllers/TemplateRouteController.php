<?php

namespace App\Http\Controllers;

use App\Http\Requests\TemplateRoute\TemplateRouteBatchReplaceRequest;
use App\Http\Requests\TemplateRoute\TemplateRouteFileImportRequest;
use App\Http\Requests\TemplateRoute\TemplateRouteStoreRequest;
use App\Http\Requests\TemplateRoute\TemplateRouteImportRequest;
use App\Http\Requests\TemplateRoute\TemplateRouteUpdateRequest;
use App\Services\TemplateRouteImportService;
use App\Services\Contracts\TemplateRouteServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class TemplateRouteController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected TemplateRouteServiceInterface $templateRouteService,
        protected TemplateRouteImportService $templateRouteImportService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $page = (int) Arr::get($request->all(), 'page', 1);

        if ($request->filled('batch_number')) {
            $filters['batch_number'] = $request->get('batch_number');
        }
        if ($request->filled('customer_part_no')) {
            $filters['customer_part_no'] = $request->get('customer_part_no');
        }
        if ($request->has('is_active')) {
            $filters['is_active'] = $request->get('is_active');
        }
        if ($request->has('unique_route_sequence')) {
            $filters['unique_route_sequence'] = filter_var(
                $request->get('unique_route_sequence'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? false;
        }

        try {
            $data = $this->templateRouteService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Template routes retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load template routes.', 500);
        }
    }

    public function options(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        $order = Arr::get($request->all(), 'order', ['template', 'asc']);
        $limit = (int) Arr::get($request->all(), 'limit', 100);
        $limit = max(1, min($limit, 500));
        $page = (int) Arr::get($request->all(), 'page', 1);

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $filters['template'] = $search;
        }
        if ($request->filled('customer_part_no')) {
            $filters['customer_part_no'] = $request->get('customer_part_no');
        }
        if ($request->has('is_active')) {
            $filters['is_active'] = $request->get('is_active');
        }
        if (!array_key_exists('with_work_orders', $filters) && $request->has('with_work_orders')) {
            $filters['with_work_orders'] = $request->get('with_work_orders');
        }

        $withCounts = filter_var(
            $request->get('with_work_orders_count'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? false;

        try {
            $data = $this->templateRouteService->getOptions($filters, $order, $limit, $page, $withCounts);

            return $this->successPagination('Template route options retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load template route options.', 500);
        }
    }

    public function top(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        $limit = max(1, (int) Arr::get($request->all(), 'limit', 5));

        try {
            $data = $this->templateRouteService->getTopUsed($filters, $limit);

            return $this->success('Top templates retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load top templates.', 500);
        }
    }
    public function import(TemplateRouteImportRequest $request): JsonResponse
    {
        try {
            $payload = $request->validated();

            $result = $this->templateRouteService->importTemplates(
                $payload['templates'],
                (int) $payload['user_id']
            );

            return $this->success('Template routes imported successfully!', $result);
        } catch (Throwable $e) {
            \Log::error('Template route import failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->error('Failed to import template routes.', 500);
        }
    }

    public function preview(TemplateRouteFileImportRequest $request): JsonResponse
    {
        try {
            set_time_limit(120);
            $payload = $request->validated();

            $result = $this->templateRouteImportService->import(
                $payload['file'],
                $payload['sheet'] ?? null,
                (int) $payload['user_id'],
                true,
                $payload['batch_number'] ?? null
            );

            return $this->success('Template routes preview generated successfully!', $result);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            \Log::error('Template route preview failed', [
                'message' => $e->getMessage(),
            ]);
            return $this->error('Failed to preview template routes.', 500);
        }
    }

    public function replace(TemplateRouteFileImportRequest $request): JsonResponse
    {
        try {
            set_time_limit(120);
            $payload = $request->validated();

            $result = $this->templateRouteImportService->import(
                $payload['file'],
                $payload['sheet'] ?? null,
                (int) $payload['user_id'],
                false,
                $payload['batch_number'] ?? null
            );

            return $this->success('Template routes replaced successfully!', $result);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            \Log::error('Template route replace failed', [
                'message' => $e->getMessage(),
            ]);
            return $this->error('Failed to replace template routes.', 500);
        }
    }

    public function store(TemplateRouteStoreRequest $request): JsonResponse
    {
        try {
            $templateRoute = $this->templateRouteService->create($request->validated());

            return $this->success('Template route created successfully!', $templateRoute);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to create template route.', 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $templateRoute = $this->templateRouteService->detail($id);

            return $this->success('Template route retrieved successfully!', $templateRoute);
        } catch (Throwable $e) {
            return $this->error('Failed to load template route.', 500);
        }
    }

    public function update(TemplateRouteUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $templateRoute = $this->templateRouteService->update($id, $request->validated());

            return !empty($templateRoute)
                ? $this->success('Template route updated successfully!', $templateRoute)
                : $this->error('Nothing to update.', 422);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to update template route.', 500);
        }
    }

    public function createVersion(TemplateRouteUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $templateRoute = $this->templateRouteService->createVersion($id, $request->validated());

            return $this->success('Template route version created successfully!', $templateRoute);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to create template route version.', 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->templateRouteService->delete($id);

            return $this->success('Template route deleted successfully!');
        } catch (Throwable $e) {
            return $this->error('Failed to delete template route.', 500);
        }
    }

    public function orderedByWorkOrders(Request $request): JsonResponse
    {
        $limit = (int) $request->get('limit', 10);
        $page = (int) $request->get('page', 1);

        try {
            $data = $this->templateRouteService->listOrderedByWorkOrders($limit, $page);

            return $this->successPagination('Template routes ordered by work order usage retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load template routes ordered by work orders.', 500);
        }
    }

    public function versionsByCustomerPart(Request $request): JsonResponse
    {
        $customerPartNo = trim((string) $request->get('customer_part_no', ''));
        if ($customerPartNo === '') {
            return $this->error('Customer part number is required.', 422);
        }

        try {
            $data = $this->templateRouteService->listVersionsByCustomerPartNo($customerPartNo);

            return $this->success('Template route versions retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load template route versions.', 500);
        }
    }

    public function replaceBatch(TemplateRouteBatchReplaceRequest $request): JsonResponse
    {
        $payload = $request->validated();

        try {
            $result = $this->templateRouteService->replaceBatch($payload['batch_number'], $payload['templates']);

            return $this->success('Template routes replaced successfully!', $result);
        } catch (Throwable $e) {
            return $this->error('Failed to replace template routes.', 500);
        }
    }
}
