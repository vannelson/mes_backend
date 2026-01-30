<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkOrderEvidence\WorkOrderEvidenceStoreRequest;
use App\Services\Contracts\WorkOrderEvidenceServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorkOrderEvidenceController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected WorkOrderEvidenceServiceInterface $workOrderEvidenceService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        foreach (['work_order_no', 'route_name'] as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 20);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->workOrderEvidenceService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Work order evidence retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load work order evidence.', 500);
        }
    }

    public function store(WorkOrderEvidenceStoreRequest $request): JsonResponse
    {
        $images = $request->file('images', []);
        if ($images instanceof UploadedFile) {
            $images = [$images];
        }
        $single = $request->file('image');
        if ($single instanceof UploadedFile) {
            $images[] = $single;
        }

        if (empty($images)) {
            return $this->error('No evidence images provided.', 422);
        }

        // try {
            $data = $request->validated();
            unset($data['images'], $data['image']);

            $created = $this->workOrderEvidenceService->create($data, $images);

            return $this->success('Work order evidence saved successfully!', $created, 201);
        // } catch (ValidationException $e) {
            return $this->validationError($e);
        // } catch (Throwable $e) {
        //     return $this->error('Failed to save work order evidence.', 500);
        // }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            if (!ctype_digit($id)) {
                return $this->error('Invalid evidence id.', 422);
            }

            $deleted = $this->workOrderEvidenceService->delete((int) $id);

            return $deleted
                ? $this->success('Work order evidence deleted successfully!')
                : $this->error('Failed to delete work order evidence.', 500);
        } catch (Throwable $e) {
            return $this->error('Failed to delete work order evidence.', 500);
        }
    }

    public function proxyImage(Request $request)
    {
        $path = (string) $request->query('path', '');
        if ($path === '') {
            abort(404);
        }

        $clean = urldecode($path);
        if (str_starts_with($clean, 'http://') || str_starts_with($clean, 'https://')) {
            $parsed = parse_url($clean);
            if (!empty($parsed['path'])) {
                $clean = $parsed['path'];
            }
        }

        $clean = str_replace('\\', '/', $clean);
        $clean = ltrim($clean, '/');
        if ($clean === '' || str_contains($clean, '..')) {
            abort(403);
        }

        $candidates = [];
        if (str_starts_with($clean, 'images/evidence_image/')) {
            $candidates[] = public_path($clean);
        } elseif (str_starts_with($clean, 'evidence_image/')) {
            $candidates[] = public_path('images/' . $clean);
        } elseif (str_starts_with($clean, 'storage/evidence_image/')) {
            $candidates[] = public_path($clean);
        } else {
            abort(404);
        }

        foreach ($candidates as $candidate) {
            if (File::exists($candidate)) {
                $mime = File::mimeType($candidate) ?: 'application/octet-stream';
                return response()->file($candidate, ['Content-Type' => $mime]);
            }
        }

        abort(404);
    }
}
