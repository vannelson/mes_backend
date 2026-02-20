<?php

namespace App\Http\Controllers;

use App\Http\Resources\WorkOrderNotification\WorkOrderNotificationResource;
use App\Services\WorkOrderNotificationService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WorkOrderNotificationController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected WorkOrderNotificationService $notificationService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthorized.', 401);
        }

        $limit = max(1, min((int) $request->get('limit', 20), 50));
        $page = max(1, (int) $request->get('page', 1));
        $status = $request->get('status');

        try {
            $paginator = $this->notificationService->listForUser(
                $user,
                ['status' => $status],
                $limit,
                $page
            );

            $items = WorkOrderNotificationResource::collection($paginator)->resolve();
            $meta = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'unread_count' => $this->notificationService->unreadCount($user),
            ];

            return $this->successPagination('Notifications retrieved successfully!', [
                'data' => $items,
                'meta' => $meta,
            ]);
        } catch (Throwable $e) {
            return $this->error('Failed to load notifications.', 500);
        }
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthorized.', 401);
        }

        try {
            $count = $this->notificationService->unreadCount($user);
            return $this->success('Unread notification count retrieved.', [
                'unread_count' => $count,
            ]);
        } catch (Throwable $e) {
            return $this->error('Failed to load unread count.', 500);
        }
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthorized.', 401);
        }

        try {
            $updated = $this->notificationService->markRead($user, [$id]);
            return $this->success('Notification marked as read.', [
                'updated' => $updated,
            ]);
        } catch (Throwable $e) {
            return $this->error('Failed to mark notification as read.', 500);
        }
    }

    public function markUnread(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthorized.', 401);
        }

        try {
            $updated = $this->notificationService->markUnread($user, [$id]);
            return $this->success('Notification marked as unread.', [
                'updated' => $updated,
            ]);
        } catch (Throwable $e) {
            return $this->error('Failed to mark notification as unread.', 500);
        }
    }

    public function markManyRead(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthorized.', 401);
        }

        $ids = $request->input('ids', []);
        $all = filter_var($request->input('all', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $updated = $this->notificationService->markRead($user, (array) $ids, $all);
            return $this->success('Notifications marked as read.', [
                'updated' => $updated,
            ]);
        } catch (Throwable $e) {
            return $this->error('Failed to mark notifications as read.', 500);
        }
    }
}
