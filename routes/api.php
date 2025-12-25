<?php

use App\Http\Controllers\AuthController;
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
        Route::get('customers/{id}', [CustomerController::class, 'show']);
        Route::put('customers/{id}', [CustomerController::class, 'update']);
        Route::delete('customers/{id}', [CustomerController::class, 'destroy']);

        Route::get('work-orders', [WorkOrderController::class, 'index']);
        Route::post('work-orders', [WorkOrderController::class, 'store']);
        Route::get('work-orders/{id}', [WorkOrderController::class, 'show']);
        Route::put('work-orders/{id}', [WorkOrderController::class, 'update']);
        Route::delete('work-orders/{id}', [WorkOrderController::class, 'destroy']);

        Route::get('template-routes', [TemplateRouteController::class, 'index']);
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
