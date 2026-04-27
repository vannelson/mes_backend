<?php

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Throwable;

class AuditLogController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected AuditLogService $auditLogService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        foreach (['q', 'action', 'context', 'work_order_no', 'route_key', 'user_id', 'from', 'to'] as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        $limit = (int) Arr::get($request->all(), 'limit', 25);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->auditLogService->list($filters, $request->user(), $limit, $page);

            return $this->successPagination('Audit logs retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load audit logs.', 500);
        }
    }
}
