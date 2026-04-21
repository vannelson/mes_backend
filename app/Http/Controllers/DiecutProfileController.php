<?php

namespace App\Http\Controllers;

use App\Http\Resources\Diecut\DiecutProfileResource;
use App\Models\DiecutProfile;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Throwable;

class DiecutProfileController extends Controller
{
    use ResponseTrait;

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        foreach (['q', 'profile_code', 'diecut_type', 'status', 'source_batch'] as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        try {
            $query = DiecutProfile::query()->with('aliases');
            if ($search = Arr::get($filters, 'q')) {
                $query->where(function ($q) use ($search) {
                    $q->where('profile_code', 'LIKE', "%{$search}%")
                        ->orWhere('diecut_type', 'LIKE', "%{$search}%")
                        ->orWhereHas('aliases', fn ($alias) => $alias->where('alias_code', 'LIKE', "%{$search}%"));
                });
            }
            if ($profileCode = Arr::get($filters, 'profile_code')) {
                $query->where('profile_code', 'LIKE', "%{$profileCode}%");
            }
            if ($diecutType = Arr::get($filters, 'diecut_type')) {
                $query->where('diecut_type', 'LIKE', "%{$diecutType}%");
            }
            if ($status = Arr::get($filters, 'status')) {
                $query->where('status', $status);
            }
            if ($sourceBatch = Arr::get($filters, 'source_batch')) {
                $query->where('source_batch', $sourceBatch);
            }

            $limit = max(1, min((int) $request->get('limit', 20), 100));
            $page = max(1, (int) $request->get('page', 1));
            $paginator = $query->orderBy('profile_code')->paginate($limit, ['*'], 'page', $page);

            return $this->successPagination('Diecut profiles retrieved successfully!', [
                'data' => DiecutProfileResource::collection($paginator->items()),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        } catch (Throwable) {
            return $this->error('Failed to load diecut profiles.', 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $profile = DiecutProfile::query()
                ->with(['aliases', 'customerPartMappings', 'tools'])
                ->findOrFail($id);

            return $this->success('Diecut profile retrieved successfully!', new DiecutProfileResource($profile));
        } catch (Throwable) {
            return $this->error('Failed to load diecut profile.', 500);
        }
    }
}
