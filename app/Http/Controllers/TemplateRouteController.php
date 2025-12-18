<?php

namespace App\Http\Controllers;

use App\Http\Requests\TemplateRoute\TemplateRouteStoreRequest;
use App\Http\Requests\TemplateRoute\TemplateRouteUpdateRequest;
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
        protected TemplateRouteServiceInterface $templateRouteService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->templateRouteService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Template routes retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load template routes.', 500);
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

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->templateRouteService->delete($id);

            return $this->success('Template route deleted successfully!');
        } catch (Throwable $e) {
            return $this->error('Failed to delete template route.', 500);
        }
    }
}
