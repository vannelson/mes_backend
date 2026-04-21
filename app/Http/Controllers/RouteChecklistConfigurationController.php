<?php

namespace App\Http\Controllers;

use App\Models\RouteChecklistConfiguration;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class RouteChecklistConfigurationController extends Controller
{
    use ResponseTrait;

    public function index(Request $request): JsonResponse
    {
        try {
            $query = RouteChecklistConfiguration::query()
                ->orderBy('sort_order')
                ->orderBy('title');

            if ($request->has('active')) {
                $query->where('is_active', filter_var($request->get('active'), FILTER_VALIDATE_BOOLEAN));
            }

            if ($search = trim((string) $request->get('q', ''))) {
                $query->where(function ($builder) use ($search) {
                    $builder->where('route_type', 'LIKE', "%{$search}%")
                        ->orWhere('title', 'LIKE', "%{$search}%");
                });
            }

            return $this->success('Route checklist configurations retrieved successfully.', $query->get());
        } catch (Throwable) {
            return $this->error('Failed to load route checklist configurations.', 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeRouteTypeInput($request);
        $data = $this->validatePayload($request);

        try {
            $config = RouteChecklistConfiguration::create($data);
            return $this->success('Route checklist configuration created successfully.', $config, 201);
        } catch (Throwable) {
            return $this->error('Failed to create route checklist configuration.', 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $config = RouteChecklistConfiguration::find($id);
        if (!$config) {
            return $this->error('Route checklist configuration not found.', 404);
        }

        $this->normalizeRouteTypeInput($request);
        $data = $this->validatePayload($request, $id);

        try {
            $config->update($data);
            return $this->success('Route checklist configuration updated successfully.', $config->fresh());
        } catch (Throwable) {
            return $this->error('Failed to update route checklist configuration.', 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $config = RouteChecklistConfiguration::find($id);
        if (!$config) {
            return $this->error('Route checklist configuration not found.', 404);
        }

        try {
            $config->delete();
            return $this->success('Route checklist configuration deleted successfully.');
        } catch (Throwable) {
            return $this->error('Failed to delete route checklist configuration.', 500);
        }
    }

    protected function validatePayload(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'route_type' => [
                'required',
                'string',
                'max:80',
                Rule::unique('route_checklist_configurations', 'route_type')->ignore($id),
            ],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*.key' => ['required', 'string', 'max:120'],
            'fields.*.label' => ['required', 'string', 'max:180'],
            'fields.*.type' => ['required', 'string', Rule::in(['ok_ng', 'pass_fail', 'yes_no', 'radio', 'text', 'number', 'checkbox', 'dimension'])],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.options.*' => ['nullable', 'string', 'max:80'],
            'fields.*.unit' => ['nullable', 'string', 'max:40'],
            'fields.*.required' => ['nullable', 'boolean'],
            'fields.*.width' => ['nullable'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    protected function normalizeRouteTypeInput(Request $request): void
    {
        $request->merge([
            'route_type' => RouteChecklistConfiguration::normalizeRouteType((string) $request->input('route_type', '')),
        ]);
    }
}
