<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierChangeControl\SupplierChangeControlStepRequest;
use App\Http\Requests\SupplierChangeControl\SupplierChangeControlStoreRequest;
use App\Http\Requests\SupplierChangeControl\SupplierChangeControlUpdateRequest;
use App\Services\Contracts\SupplierChangeControlServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SupplierChangeControlController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected SupplierChangeControlServiceInterface $supplierChangeControlService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        foreach (['supplier_name', 'status', 'current_step', 'search', 'q'] as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->supplierChangeControlService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Supplier change controls retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load supplier change controls.', 500);
        }
    }

    public function store(SupplierChangeControlStoreRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $eventNote = $data['event_note'] ?? null;
            unset($data['attachment'], $data['event_note']);

            $userId = $request->user()?->id ? (int) $request->user()->id : null;
            if ($userId && empty($data['created_by_user_id'])) {
                $data['created_by_user_id'] = $userId;
            }
            if ($userId && empty($data['updated_by_user_id'])) {
                $data['updated_by_user_id'] = $userId;
            }

            $record = $this->supplierChangeControlService->create(
                $data,
                $request->file('attachment'),
                $eventNote,
                $userId
            );

            return $this->success('Supplier change control created successfully!', $record, 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to create supplier change control.', 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            if (! ctype_digit($id)) {
                return $this->error('Invalid supplier change control id.', 422);
            }

            $record = $this->supplierChangeControlService->detail((int) $id);

            return $this->success('Supplier change control retrieved successfully!', $record);
        } catch (Throwable $e) {
            return $this->error('Failed to load supplier change control.', 500);
        }
    }

    public function attachment(string $id)
    {
        if (! ctype_digit($id)) {
            abort(422, 'Invalid supplier change control id.');
        }

        try {
            $record = $this->supplierChangeControlService->detail((int) $id);
            $path = (string) ($record['data']['attachment_path'] ?? '');
            if ($path === '') {
                abort(404);
            }

            $cleanPath = ltrim(str_replace('\\', '/', $path), '/');
            if ($cleanPath === '' || str_contains($cleanPath, '..')) {
                abort(403);
            }

            if (!Storage::disk('public')->exists($cleanPath)) {
                abort(404);
            }

            $absolutePath = Storage::disk('public')->path($cleanPath);
            $mimeType = Storage::disk('public')->mimeType($cleanPath) ?: 'application/octet-stream';

            return response()->file($absolutePath, ['Content-Type' => $mimeType]);
        } catch (Throwable $e) {
            abort(404);
        }
    }

    public function update(SupplierChangeControlUpdateRequest $request, string $id): JsonResponse
    {
        try {
            if (! ctype_digit($id)) {
                return $this->error('Invalid supplier change control id.', 422);
            }

            $data = $request->validated();
            $eventNote = $data['event_note'] ?? null;
            unset($data['attachment'], $data['event_note']);

            $userId = $request->user()?->id ? (int) $request->user()->id : null;
            if ($userId && empty($data['updated_by_user_id'])) {
                $data['updated_by_user_id'] = $userId;
            }

            $record = $this->supplierChangeControlService->update(
                (int) $id,
                $data,
                $request->file('attachment'),
                $eventNote,
                $userId
            );

            return !empty($record)
                ? $this->success('Supplier change control updated successfully!', $record)
                : $this->error('Nothing to update.', 422);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to update supplier change control.', 500);
        }
    }

    public function updateStep(SupplierChangeControlStepRequest $request, string $id): JsonResponse
    {
        try {
            if (! ctype_digit($id)) {
                return $this->error('Invalid supplier change control id.', 422);
            }

            $data = $request->validated();
            $userId = $request->user()?->id ? (int) $request->user()->id : null;
            $record = $this->supplierChangeControlService->updateStep(
                (int) $id,
                (int) $data['current_step'],
                $data['status'] ?? null,
                $data['note'] ?? null,
                $userId
            );

            return !empty($record)
                ? $this->success('Supplier change control step updated successfully!', $record)
                : $this->error('Nothing to update.', 422);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to update supplier change control step.', 500);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            if (! ctype_digit($id)) {
                return $this->error('Invalid supplier change control id.', 422);
            }

            $deleted = $this->supplierChangeControlService->delete((int) $id);

            return $deleted
                ? $this->success('Supplier change control deleted successfully!')
                : $this->error('Failed to delete supplier change control.', 500);
        } catch (Throwable $e) {
            return $this->error('Failed to delete supplier change control.', 500);
        }
    }
}
