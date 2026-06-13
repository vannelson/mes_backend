<?php

namespace App\Providers;

use App\Repositories\Contracts\BatchLogRepositoryInterface;
use App\Repositories\Contracts\CalibrationMasterRepositoryInterface;
use App\Repositories\Contracts\BomRepositoryInterface;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\DiecutRepositoryInterface;
use App\Repositories\Contracts\MachineRepositoryInterface;
use App\Repositories\Contracts\PackingRepositoryInterface;
use App\Repositories\Contracts\PackingChecklistRepositoryInterface;
use App\Repositories\Contracts\PlaylistItemRepositoryInterface;
use App\Repositories\Contracts\ScreenMediaRepositoryInterface;
use App\Repositories\Contracts\SupplierChangeControlRepositoryInterface;
use App\Repositories\Contracts\TemplateRouteRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\VirtualScreenRepositoryInterface;
use App\Repositories\Contracts\WorkOrderRepositoryInterface;
use App\Repositories\Contracts\WorkOrderCommentRepositoryInterface;
use App\Repositories\Contracts\WorkOrderEvidenceRepositoryInterface;
use App\Repositories\BatchLogRepository;
use App\Repositories\CalibrationMasterRepository;
use App\Repositories\BomRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\DiecutRepository;
use App\Repositories\MachineRepository;
use App\Repositories\PackingRepository;
use App\Repositories\PackingChecklistRepository;
use App\Repositories\PlaylistItemRepository;
use App\Repositories\ScreenMediaRepository;
use App\Repositories\SupplierChangeControlRepository;
use App\Repositories\TemplateRouteRepository;
use App\Repositories\UserRepository;
use App\Repositories\VirtualScreenRepository;
use App\Repositories\WorkOrderRepository;
use App\Repositories\WorkOrderCommentRepository;
use App\Repositories\WorkOrderEvidenceRepository;
use App\Services\AuthService;
use App\Services\Contracts\AuthServiceInterface;
use App\Services\Contracts\BatchLogServiceInterface;
use App\Services\Contracts\CalibrationMasterServiceInterface;
use App\Services\Contracts\BomServiceInterface;
use App\Services\Contracts\CustomerServiceInterface;
use App\Services\Contracts\DiecutServiceInterface;
use App\Services\Contracts\MachineServiceInterface;
use App\Services\Contracts\OperationTriggerServiceInterface;
use App\Services\Contracts\PackingServiceInterface;
use App\Services\Contracts\PackingChecklistServiceInterface;
use App\Services\Contracts\PlaylistItemServiceInterface;
use App\Services\Contracts\ScreenMediaServiceInterface;
use App\Services\Contracts\SupplierChangeControlServiceInterface;
use App\Services\Contracts\TemplateRouteServiceInterface;
use App\Services\Contracts\TranscriptServiceInterface;
use App\Services\Contracts\UserServiceInterface;
use App\Services\Contracts\VirtualScreenServiceInterface;
use App\Services\Contracts\WorkOrderServiceInterface;
use App\Services\Contracts\WorkOrderCommentServiceInterface;
use App\Services\Contracts\WorkOrderEvidenceServiceInterface;
use App\Services\BatchLogService;
use App\Services\CalibrationMasterService;
use App\Services\BomService;
use App\Services\CustomerService;
use App\Services\DiecutService;
use App\Services\MachineService;
use App\Services\OperationTriggerService;
use App\Services\PackingService;
use App\Services\PackingChecklistService;
use App\Services\PlaylistItemService;
use App\Services\ScreenMediaService;
use App\Services\SupplierChangeControlService;
use App\Services\TemplateRouteService;
use App\Services\TranscriptService;
use App\Services\UserService;
use App\Services\VirtualScreenService;
use App\Services\WorkOrderService;
use App\Services\WorkOrderCommentService;
use App\Services\WorkOrderEvidenceService;
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
        $this->app->bind(BatchLogServiceInterface::class, BatchLogService::class);
        $this->app->bind(BatchLogRepositoryInterface::class, BatchLogRepository::class);
        $this->app->bind(CalibrationMasterServiceInterface::class, CalibrationMasterService::class);
        $this->app->bind(CalibrationMasterRepositoryInterface::class, CalibrationMasterRepository::class);
        $this->app->bind(BomServiceInterface::class, BomService::class);
        $this->app->bind(BomRepositoryInterface::class, BomRepository::class);
        $this->app->bind(DiecutServiceInterface::class, DiecutService::class);
        $this->app->bind(DiecutRepositoryInterface::class, DiecutRepository::class);
        $this->app->bind(WorkOrderServiceInterface::class, WorkOrderService::class);
        $this->app->bind(WorkOrderRepositoryInterface::class, WorkOrderRepository::class);
        $this->app->bind(WorkOrderCommentServiceInterface::class, WorkOrderCommentService::class);
        $this->app->bind(WorkOrderCommentRepositoryInterface::class, WorkOrderCommentRepository::class);
        $this->app->bind(WorkOrderEvidenceServiceInterface::class, WorkOrderEvidenceService::class);
        $this->app->bind(WorkOrderEvidenceRepositoryInterface::class, WorkOrderEvidenceRepository::class);
        $this->app->bind(TemplateRouteServiceInterface::class, TemplateRouteService::class);
        $this->app->bind(TemplateRouteRepositoryInterface::class, TemplateRouteRepository::class);
        $this->app->bind(MachineServiceInterface::class, MachineService::class);
        $this->app->bind(MachineRepositoryInterface::class, MachineRepository::class);
        $this->app->bind(OperationTriggerServiceInterface::class, OperationTriggerService::class);
        $this->app->bind(PackingServiceInterface::class, PackingService::class);
        $this->app->bind(PackingRepositoryInterface::class, PackingRepository::class);
        $this->app->bind(PackingChecklistServiceInterface::class, PackingChecklistService::class);
        $this->app->bind(PackingChecklistRepositoryInterface::class, PackingChecklistRepository::class);
        $this->app->bind(SupplierChangeControlServiceInterface::class, SupplierChangeControlService::class);
        $this->app->bind(SupplierChangeControlRepositoryInterface::class, SupplierChangeControlRepository::class);

        // Virtual Screens
        $this->app->bind(VirtualScreenServiceInterface::class, VirtualScreenService::class);
        $this->app->bind(VirtualScreenRepositoryInterface::class, VirtualScreenRepository::class);
        $this->app->bind(PlaylistItemServiceInterface::class, PlaylistItemService::class);
        $this->app->bind(PlaylistItemRepositoryInterface::class, PlaylistItemRepository::class);
        $this->app->bind(ScreenMediaServiceInterface::class, ScreenMediaService::class);
        $this->app->bind(ScreenMediaRepositoryInterface::class, ScreenMediaRepository::class);
        $this->app->bind(TranscriptServiceInterface::class, TranscriptService::class);

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
