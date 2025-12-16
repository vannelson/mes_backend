<?php

namespace App\Providers;

use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\TemplateRouteRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WorkOrderRepositoryInterface;
use App\Repositories\CustomerRepository;
use App\Repositories\TemplateRouteRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkOrderRepository;
use App\Services\AuthService;
use App\Services\Contracts\AuthServiceInterface;
use App\Services\Contracts\CustomerServiceInterface;
use App\Services\Contracts\TemplateRouteServiceInterface;
use App\Services\Contracts\UserServiceInterface;
use App\Services\Contracts\WorkOrderServiceInterface;
use App\Services\CustomerService;
use App\Services\TemplateRouteService;
use App\Services\UserService;
use App\Services\WorkOrderService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Registering UserServiceInterface and its implementation
        $this->app->bind(UserServiceInterface::class, UserService::class);
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(AuthServiceInterface::class, AuthService::class);
        $this->app->bind(CustomerServiceInterface::class, CustomerService::class);
        $this->app->bind(CustomerRepositoryInterface::class, CustomerRepository::class);
        $this->app->bind(WorkOrderServiceInterface::class, WorkOrderService::class);
        $this->app->bind(WorkOrderRepositoryInterface::class, WorkOrderRepository::class);
        $this->app->bind(TemplateRouteServiceInterface::class, TemplateRouteService::class);
        $this->app->bind(TemplateRouteRepositoryInterface::class, TemplateRouteRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
