<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AiLabsController;
use App\Http\Controllers\BatchLogController;
use App\Http\Controllers\BomController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiecutController;
use App\Http\Controllers\DiecutTypeController;
use App\Http\Controllers\HistoricalWorkOrderController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\OperationTriggerController;
use App\Http\Controllers\PackingChecklistController;
use App\Http\Controllers\PackingController;
use App\Http\Controllers\PlaylistItemController;
use App\Http\Controllers\PublicMediaController;
use App\Http\Controllers\PublicScreenController;
use App\Http\Controllers\ScreenMediaController;
use App\Http\Controllers\SupplierChangeControlController;
use App\Http\Controllers\TemplateRouteController;
use App\Http\Controllers\TranslationController;
use App\Http\Controllers\TranscriptController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VirtualScreenController;
use App\Http\Controllers\WorkOrderController;
use App\Http\Controllers\WorkOrderCommentController;
use App\Http\Controllers\WorkOrderEvidenceController;
use App\Http\Controllers\WorkOrderNotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('translations/batch', [TranslationController::class, 'batch'])->middleware('throttle:120,1');
    Route::post('transcripts', [TranscriptController::class, 'store']);
    Route::get('images/{path}', [ImageController::class, 'show'])->where('path', '.*');
    Route::get('supplier-change-controls/{id}/attachment', [SupplierChangeControlController::class, 'attachment'])
        ->whereNumber('id');
    Route::post('operation-triggers/{id}/execute-internal', [OperationTriggerController::class, 'execute'])
        ->whereNumber('id');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/confirm-password', [AuthController::class, 'confirmPassword']);
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::get('users/{id}', [UserController::class, 'show']);
        Route::put('users/{id}', [UserController::class, 'update']);
        Route::delete('users/{id}', [UserController::class, 'destroy']);

        Route::get('customers', [CustomerController::class, 'index']);
        Route::post('customers', [CustomerController::class, 'store']);
        Route::get('customers/top', [CustomerController::class, 'top']);
        Route::get('customers/options', [CustomerController::class, 'options']);
        Route::get('customers/{id}', [CustomerController::class, 'show'])->whereNumber('id');
        Route::put('customers/{id}', [CustomerController::class, 'update']);
        Route::delete('customers/{id}', [CustomerController::class, 'destroy']);

        Route::get('batch-logs', [BatchLogController::class, 'index']);
        Route::post('batch-logs', [BatchLogController::class, 'store']);
        Route::get('batch-logs/{id}', [BatchLogController::class, 'show']);
        Route::put('batch-logs/{id}', [BatchLogController::class, 'update']);
        Route::delete('batch-logs/{id}', [BatchLogController::class, 'destroy']);

        Route::get('work-orders', [WorkOrderController::class, 'index']);
        Route::get('notifications', [WorkOrderNotificationController::class, 'index']);
        Route::get('notifications/unread-count', [WorkOrderNotificationController::class, 'unreadCount']);
        Route::put('notifications/{id}/read', [WorkOrderNotificationController::class, 'markRead'])->whereNumber('id');
        Route::put('notifications/{id}/unread', [WorkOrderNotificationController::class, 'markUnread'])->whereNumber('id');
        Route::post('notifications/mark-read', [WorkOrderNotificationController::class, 'markManyRead']);
        Route::get('messages/threads', [MessageController::class, 'threads']);
        Route::get('messages/unread-count', [MessageController::class, 'unreadCount']);
        Route::get('messages/conversations/{userId}', [MessageController::class, 'conversation'])->whereNumber('userId');
        Route::post('messages/mark-read', [MessageController::class, 'markRead']);
        Route::post('messages', [MessageController::class, 'store']);
        Route::get('work-order-comments', [WorkOrderCommentController::class, 'index']);
        Route::post('work-order-comments', [WorkOrderCommentController::class, 'store']);
        Route::get('work-order-comments/{id}', [WorkOrderCommentController::class, 'show']);
        Route::put('work-order-comments/{id}', [WorkOrderCommentController::class, 'update']);
        Route::delete('work-order-comments/{id}', [WorkOrderCommentController::class, 'destroy']);
        Route::get('work-orders/by-batch', [WorkOrderController::class, 'listByBatch']);
        Route::get('work-orders/by-template-route-batch', [WorkOrderController::class, 'listByTemplateRouteBatch']);
        Route::get('work-orders/by-template-route-batch/count', [WorkOrderController::class, 'countByTemplateRouteBatch']);
        Route::get('work-orders/summary', [WorkOrderController::class, 'summary']);
        Route::get('work-orders/calendar-summary', [WorkOrderController::class, 'calendarSummary']);
        Route::get('work-orders/calendar-day', [WorkOrderController::class, 'calendarDay']);
        Route::get('work-orders/collection-report', [WorkOrderController::class, 'collectionReport']);
        Route::get('work-orders/wip', [WorkOrderController::class, 'wip']);
        Route::post('work-orders', [WorkOrderController::class, 'store']);
        Route::post('work-orders/batch', [WorkOrderController::class, 'batchStore']);
        Route::post('work-orders/batch/replace', [WorkOrderController::class, 'replaceBatch']);
        Route::post('work-orders/import', [WorkOrderController::class, 'import']);
        Route::get('historical-work-orders', [HistoricalWorkOrderController::class, 'index']);
        Route::get('historical-work-orders/summary', [HistoricalWorkOrderController::class, 'summary']);
        Route::get('historical-work-orders/filter-options', [HistoricalWorkOrderController::class, 'filterOptions']);
        Route::post('historical-work-orders/import', [HistoricalWorkOrderController::class, 'import']);
        Route::post('work-orders/link-template-routes', [WorkOrderController::class, 'linkTemplateRoutes']);
        Route::get('work-orders/detail', [WorkOrderController::class, 'detailBy']);
        Route::get('work-orders/options', [WorkOrderController::class, 'options']);
        Route::get('work-orders/with-template-routes', [WorkOrderController::class, 'withActiveTemplateRoutes']);
        Route::get('work-orders/virtualization', [WorkOrderController::class, 'virtualization']);
        Route::put('work-orders/bulk-update', [WorkOrderController::class, 'bulkUpdateByCustomer']);
        Route::get('work-orders/{id}', [WorkOrderController::class, 'show'])->whereNumber('id');
        Route::put('work-orders/{id}', [WorkOrderController::class, 'update']);
        Route::post('work-orders/{id}/assignments', [WorkOrderController::class, 'syncAssignments']);
        Route::post('work-orders/{id}/time-tracker', [WorkOrderController::class, 'recordTimeTracker']);
        Route::delete('work-orders/{id}', [WorkOrderController::class, 'destroy']);

        Route::get('operation-triggers', [OperationTriggerController::class, 'index']);
        Route::post('operation-triggers', [OperationTriggerController::class, 'store']);
        Route::get('operation-triggers/{id}', [OperationTriggerController::class, 'show'])->whereNumber('id');
        Route::put('operation-triggers/{id}', [OperationTriggerController::class, 'update'])->whereNumber('id');
        Route::delete('operation-triggers/{id}', [OperationTriggerController::class, 'destroy'])->whereNumber('id');
        Route::post('operation-triggers/{id}/publish', [OperationTriggerController::class, 'publish'])->whereNumber('id');
        Route::post('operation-triggers/{id}/disable', [OperationTriggerController::class, 'disable'])->whereNumber('id');
        Route::post('operation-triggers/{id}/simulate', [OperationTriggerController::class, 'simulate'])->whereNumber('id');
        Route::post('operation-triggers/{id}/execute', [OperationTriggerController::class, 'execute'])->whereNumber('id');
        Route::post('operation-triggers/{id}/api-tool-preview', [OperationTriggerController::class, 'previewApiTool'])->whereNumber('id');

        Route::get('work-order-evidences', [WorkOrderEvidenceController::class, 'index']);
        Route::post('work-order-evidences', [WorkOrderEvidenceController::class, 'store']);
        Route::delete('work-order-evidences/{id}', [WorkOrderEvidenceController::class, 'destroy']);
        Route::get('evidence-image', [WorkOrderEvidenceController::class, 'proxyImage']);

        Route::get('boms', [BomController::class, 'index']);
        Route::get('boms/stats', [BomController::class, 'stats']);
        Route::get('boms/by-batch', [BomController::class, 'listByBatch']);
        Route::get('boms/by-customer-part', [BomController::class, 'listByCustomerPart']);
        Route::post('boms/batch', [BomController::class, 'batchStore']);
        Route::post('boms/batch/replace', [BomController::class, 'replaceBatch']);

        Route::get('diecuts', [DiecutController::class, 'index']);
        Route::get('diecuts/by-batch', [DiecutController::class, 'listByBatch']);
        Route::post('diecuts/batch', [DiecutController::class, 'batchStore']);
        Route::post('diecuts/batch/replace', [DiecutController::class, 'replaceBatch']);
        Route::get('diecut-types', [DiecutTypeController::class, 'index']);

        Route::get('template-routes', [TemplateRouteController::class, 'index']);
        Route::get('template-routes/top', [TemplateRouteController::class, 'top']);
        Route::get('template-routes/options', [TemplateRouteController::class, 'options']);
        Route::get('template-routes/ordered-by-work-orders', [TemplateRouteController::class, 'orderedByWorkOrders']);
        Route::post('template-routes/import', [TemplateRouteController::class, 'import']);
        Route::post('template-routes/batch/replace', [TemplateRouteController::class, 'replaceBatch']);
        Route::post('template-routes', [TemplateRouteController::class, 'store']);
        Route::get('template-routes/{id}', [TemplateRouteController::class, 'show'])->whereNumber('id');
        Route::put('template-routes/{id}', [TemplateRouteController::class, 'update']);
        Route::delete('template-routes/{id}', [TemplateRouteController::class, 'destroy']);

        Route::get('machines', [MachineController::class, 'index']);
        Route::post('machines', [MachineController::class, 'store']);
        Route::get('machines/{id}', [MachineController::class, 'show']);
        Route::put('machines/{id}', [MachineController::class, 'update']);
        Route::delete('machines/{id}', [MachineController::class, 'destroy']);

        Route::get('packings', [PackingController::class, 'index']);
        Route::get('packings/by-part-no', [PackingController::class, 'listByPartNo']);
        Route::get('packings/by-batch', [PackingController::class, 'listByBatch']);
        Route::post('packings', [PackingController::class, 'store']);
        Route::post('packings/batch', [PackingController::class, 'batchStore']);
        Route::get('packings/{id}', [PackingController::class, 'show']);
        Route::put('packings/{id}', [PackingController::class, 'update']);
        Route::delete('packings/{id}', [PackingController::class, 'destroy']);

        Route::get('packing-checklists', [PackingChecklistController::class, 'index']);
        Route::post('packing-checklists', [PackingChecklistController::class, 'store']);
        Route::get('packing-checklists/{id}', [PackingChecklistController::class, 'show']);
        Route::put('packing-checklists/{id}', [PackingChecklistController::class, 'update']);
        Route::delete('packing-checklists/{id}', [PackingChecklistController::class, 'destroy']);

        Route::get('supplier-change-controls', [SupplierChangeControlController::class, 'index']);
        Route::post('supplier-change-controls', [SupplierChangeControlController::class, 'store']);
        Route::get('supplier-change-controls/{id}', [SupplierChangeControlController::class, 'show'])->whereNumber('id');
        Route::put('supplier-change-controls/{id}', [SupplierChangeControlController::class, 'update'])->whereNumber('id');
        Route::post('supplier-change-controls/{id}/step', [SupplierChangeControlController::class, 'updateStep'])->whereNumber('id');
        Route::delete('supplier-change-controls/{id}', [SupplierChangeControlController::class, 'destroy'])->whereNumber('id');

        Route::get('dashboard/overview', [DashboardController::class, 'overview']);

        Route::get('ai-labs/context', [AiLabsController::class, 'context']);

        // Virtual Screens
        Route::get('virtual-screens', [VirtualScreenController::class, 'index']);
        Route::post('virtual-screens', [VirtualScreenController::class, 'store']);
        Route::get('virtual-screens/{id}', [VirtualScreenController::class, 'show']);
        Route::put('virtual-screens/{id}', [VirtualScreenController::class, 'update']);
        Route::delete('virtual-screens/{id}', [VirtualScreenController::class, 'destroy']);
        Route::post('virtual-screens/{id}/toggle-active', [VirtualScreenController::class, 'toggleActive']);
        Route::post('virtual-screens/{id}/regenerate-token', [VirtualScreenController::class, 'regenerateToken']);

        // Playlist Items
        Route::get('virtual-screens/{screenId}/playlist-items', [PlaylistItemController::class, 'index']);
        Route::post('playlist-items', [PlaylistItemController::class, 'store']);
        Route::put('playlist-items/{id}', [PlaylistItemController::class, 'update']);
        Route::delete('playlist-items/{id}', [PlaylistItemController::class, 'destroy']);
        Route::post('virtual-screens/{screenId}/playlist-items/reorder', [PlaylistItemController::class, 'reorder']);
        Route::post('playlist-items/{id}/toggle-active', [PlaylistItemController::class, 'toggleActive']);

        // Screen Media
        Route::get('virtual-screens/{screenId}/media', [ScreenMediaController::class, 'index']);
        Route::post('virtual-screens/{screenId}/media', [ScreenMediaController::class, 'upload']);
        Route::get('screen-media/{id}', [ScreenMediaController::class, 'show']);
        Route::delete('screen-media/{id}', [ScreenMediaController::class, 'destroy']);
    });

    // Public Virtual Screen Player (rate-limited, no auth)
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('public/screens/access-code/{accessCode}', [PublicScreenController::class, 'showByAccessCode']);
        Route::get('public/screens/{shareToken}', [PublicScreenController::class, 'show']);
        Route::get('public/screens/{shareToken}/media/{mediaId}', [PublicMediaController::class, 'show']);
    });
});

Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
    Route::post('template-routes/preview', [TemplateRouteController::class, 'preview']);
    Route::post('template-routes/replace', [TemplateRouteController::class, 'replace']);
});
