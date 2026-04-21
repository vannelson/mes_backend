<?php

namespace App\Http\Controllers;

use App\Http\Resources\Diecut\DiecutToolResource;
use App\Models\DiecutTool;
use App\Models\DiecutToolUsage;
use App\Services\DiecutIntelligenceService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Throwable;

class DiecutToolController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected DiecutIntelligenceService $intelligenceService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        foreach (['q', 'tool_code', 'status', 'is_active', 'source_batch'] as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        try {
            $query = DiecutTool::query()->with('profile');
            if ($search = Arr::get($filters, 'q')) {
                $query->where(function ($q) use ($search) {
                    $q->where('tool_code', 'LIKE', "%{$search}%")
                        ->orWhereHas('profile', fn ($profile) => $profile->where('profile_code', 'LIKE', "%{$search}%"));
                });
            }
            if ($toolCode = Arr::get($filters, 'tool_code')) {
                $query->where('tool_code', 'LIKE', "%{$toolCode}%");
            }
            if ($status = Arr::get($filters, 'status')) {
                $query->where('status', $status);
            }
            if (Arr::has($filters, 'is_active')) {
                $query->where('is_active', filter_var(Arr::get($filters, 'is_active'), FILTER_VALIDATE_BOOLEAN));
            }
            if ($sourceBatch = Arr::get($filters, 'source_batch')) {
                $query->where('source_batch', $sourceBatch);
            }

            $limit = max(1, min((int) $request->get('limit', 20), 100));
            $page = max(1, (int) $request->get('page', 1));
            $paginator = $query->orderByDesc('is_active')->orderBy('tool_code')->paginate($limit, ['*'], 'page', $page);

            return $this->successPagination('Diecut tools retrieved successfully!', [
                'data' => DiecutToolResource::collection($paginator->items()),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        } catch (Throwable) {
            return $this->error('Failed to load diecut tools.', 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $tool = DiecutTool::query()->with(['profile.aliases', 'usages'])->findOrFail($id);

            return $this->success('Diecut tool retrieved successfully!', new DiecutToolResource($tool));
        } catch (Throwable) {
            return $this->error('Failed to load diecut tool.', 500);
        }
    }

    public function storeUsage(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'diecut_tool_id' => ['nullable', 'integer', 'exists:diecut_tools,id'],
            'diecut_profile_id' => ['nullable', 'integer', 'exists:diecut_profiles,id'],
            'diecut_no' => ['nullable', 'string', 'max:180'],
            'usage_date' => ['nullable', 'date'],
            'machine_no' => ['nullable', 'string', 'max:120'],
            'customer_code' => ['nullable', 'string', 'max:120'],
            'work_order_no' => ['nullable', 'string', 'max:120'],
            'customer_part_number' => ['nullable', 'string', 'max:255'],
            'cavity' => ['nullable', 'numeric'],
            'printed_qty' => ['nullable', 'numeric'],
            'number_of_press' => ['nullable', 'numeric'],
        ]);

        try {
            $tool = !empty($payload['diecut_tool_id'])
                ? DiecutTool::query()->find($payload['diecut_tool_id'])
                : null;
            $profile = $tool?->profile ?: $this->intelligenceService->resolveProfile(
                $payload['diecut_no'] ?? null,
                $payload['customer_part_number'] ?? null,
                $payload['customer_code'] ?? null
            );

            $usage = DiecutToolUsage::query()->create([
                ...$payload,
                'diecut_tool_id' => $tool?->id,
                'diecut_profile_id' => $payload['diecut_profile_id'] ?? $profile?->id,
                'source_sheet' => 'MES',
                'source_batch' => now()->format('dmy\THi'),
            ]);

            return $this->success('Diecut usage recorded successfully!', $usage, 201);
        } catch (Throwable) {
            return $this->error('Failed to record diecut usage.', 500);
        }
    }
}
