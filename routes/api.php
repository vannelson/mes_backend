<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BatchLogController;
use App\Http\Controllers\BomController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\TemplateRouteController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::get('users/{id}', [UserController::class, 'show']);
        Route::put('users/{id}', [UserController::class, 'update']);
        Route::delete('users/{id}', [UserController::class, 'destroy']);

        Route::get('customers', [CustomerController::class, 'index']);
        Route::post('customers', [CustomerController::class, 'store']);
        Route::get('customers/options', [CustomerController::class, 'options']);
        Route::get('customers/{id}', [CustomerController::class, 'show']);
        Route::put('customers/{id}', [CustomerController::class, 'update']);
        Route::delete('customers/{id}', [CustomerController::class, 'destroy']);

        Route::get('batch-logs', [BatchLogController::class, 'index']);
        Route::post('batch-logs', [BatchLogController::class, 'store']);
        Route::get('batch-logs/{id}', [BatchLogController::class, 'show']);
        Route::put('batch-logs/{id}', [BatchLogController::class, 'update']);
        Route::delete('batch-logs/{id}', [BatchLogController::class, 'destroy']);

        Route::get('work-orders', [WorkOrderController::class, 'index']);
        Route::get('work-orders/by-batch', [WorkOrderController::class, 'listByBatch']);
        Route::get('work-orders/by-template-route-batch', [WorkOrderController::class, 'listByTemplateRouteBatch']);
        Route::get('work-orders/by-template-route-batch/count', [WorkOrderController::class, 'countByTemplateRouteBatch']);
        Route::post('work-orders', [WorkOrderController::class, 'store']);
        Route::post('work-orders/batch', [WorkOrderController::class, 'batchStore']);
        Route::post('work-orders/batch/replace', [WorkOrderController::class, 'replaceBatch']);
        Route::post('work-orders/import', [WorkOrderController::class, 'import']);
        Route::post('work-orders/link-template-routes', [WorkOrderController::class, 'linkTemplateRoutes']);
        Route::get('work-orders/detail', [WorkOrderController::class, 'detailBy']);
        Route::get('work-orders/options', [WorkOrderController::class, 'options']);
        Route::get('work-orders/with-template-routes', [WorkOrderController::class, 'withActiveTemplateRoutes']);
        Route::get('work-orders/{id}', [WorkOrderController::class, 'show']);
        Route::put('work-orders/{id}', [WorkOrderController::class, 'update']);
        Route::delete('work-orders/{id}', [WorkOrderController::class, 'destroy']);

        Route::get('boms', [BomController::class, 'index']);
        Route::get('boms/by-batch', [BomController::class, 'listByBatch']);
        Route::post('boms/batch', [BomController::class, 'batchStore']);
        Route::post('boms/batch/replace', [BomController::class, 'replaceBatch']);

        Route::get('template-routes', [TemplateRouteController::class, 'index']);
        Route::get('template-routes/ordered-by-work-orders', [TemplateRouteController::class, 'orderedByWorkOrders']);
        Route::post('template-routes/import', [TemplateRouteController::class, 'import']);
        Route::post('template-routes/batch/replace', [TemplateRouteController::class, 'replaceBatch']);
        Route::post('template-routes', [TemplateRouteController::class, 'store']);
        Route::get('template-routes/{id}', [TemplateRouteController::class, 'show']);
        Route::put('template-routes/{id}', [TemplateRouteController::class, 'update']);
        Route::delete('template-routes/{id}', [TemplateRouteController::class, 'destroy']);

        Route::get('machines', [MachineController::class, 'index']);
        Route::post('machines', [MachineController::class, 'store']);
        Route::get('machines/{id}', [MachineController::class, 'show']);
        Route::put('machines/{id}', [MachineController::class, 'update']);
        Route::delete('machines/{id}', [MachineController::class, 'destroy']);
    });
});
