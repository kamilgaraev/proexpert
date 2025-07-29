<?php

namespace App\Providers;

use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\BaseRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Repositories\Interfaces\OrganizationRepositoryInterface;
use App\Repositories\Interfaces\ProjectRepositoryInterface;
use App\Repositories\Interfaces\MaterialRepositoryInterface;
use App\Repositories\Interfaces\WorkTypeRepositoryInterface;
use App\Repositories\Interfaces\SupplierRepositoryInterface;
use App\Repositories\Interfaces\RoleRepositoryInterface;
use App\Repositories\Interfaces\Log\MaterialUsageLogRepositoryInterface;
use App\Repositories\Interfaces\Log\WorkCompletionLogRepositoryInterface;
use App\Repositories\Interfaces\MeasurementUnitRepositoryInterface;
use App\Repositories\Interfaces\ReportTemplateRepositoryInterface;
use App\Repositories\Interfaces\RateCoefficientRepositoryInterface;
use App\Repositories\MaterialRepository;
use App\Repositories\OrganizationRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\RoleRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkTypeRepository;
use App\Repositories\Log\MaterialUsageLogRepository;
use App\Repositories\Log\WorkCompletionLogRepository;
use App\Repositories\ReportTemplateRepository;
use App\Repositories\RateCoefficient\RateCoefficientRepository;
use App\Repositories\Eloquent\EloquentMeasurementUnitRepository;
use Illuminate\Support\ServiceProvider;
use App\Repositories\Interfaces\ContractorRepositoryInterface;
use App\Repositories\ContractorRepository;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\Repositories\ContractRepository;
use App\Repositories\Interfaces\ContractPerformanceActRepositoryInterface;
use App\Repositories\ContractPerformanceActRepository;
use App\Repositories\Interfaces\ContractPaymentRepositoryInterface;
use App\Repositories\ContractPaymentRepository;
use App\Repositories\Interfaces\CompletedWorkRepositoryInterface;
use App\Repositories\CompletedWork\CompletedWorkRepository;
use App\Repositories\Interfaces\SiteRequestRepositoryInterface;
use App\Repositories\SiteRequest\SiteRequestRepository;
use App\Repositories\Interfaces\ProjectScheduleRepositoryInterface;
use App\Repositories\Schedule\ProjectScheduleRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // $this->app->bind(RepositoryInterface::class, BaseRepository::class);
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(OrganizationRepositoryInterface::class, OrganizationRepository::class);
        $this->app->bind(ProjectRepositoryInterface::class, ProjectRepository::class);
        $this->app->bind(MaterialRepositoryInterface::class, MaterialRepository::class);
        $this->app->bind(WorkTypeRepositoryInterface::class, WorkTypeRepository::class);
        $this->app->bind(SupplierRepositoryInterface::class, SupplierRepository::class);
        $this->app->bind(RoleRepositoryInterface::class, RoleRepository::class);
        $this->app->bind(MaterialUsageLogRepositoryInterface::class, MaterialUsageLogRepository::class);
        $this->app->bind(WorkCompletionLogRepositoryInterface::class, WorkCompletionLogRepository::class);
        $this->app->bind(ReportTemplateRepositoryInterface::class, ReportTemplateRepository::class);
        $this->app->bind(RateCoefficientRepositoryInterface::class, RateCoefficientRepository::class);
        
        // Привязка для MeasurementUnitRepository
        $this->app->bind(MeasurementUnitRepositoryInterface::class, EloquentMeasurementUnitRepository::class);
        
        // Биндинги для управления договорами
        $this->app->bind(ContractorRepositoryInterface::class, ContractorRepository::class);
        $this->app->bind(ContractRepositoryInterface::class, ContractRepository::class);
        $this->app->bind(ContractPerformanceActRepositoryInterface::class, ContractPerformanceActRepository::class);
        $this->app->bind(ContractPaymentRepositoryInterface::class, ContractPaymentRepository::class);
        
        // Привязка для выполненных работ
        $this->app->bind(CompletedWorkRepositoryInterface::class, CompletedWorkRepository::class);
        
        // Привязка для заявок с объекта
        $this->app->bind(SiteRequestRepositoryInterface::class, SiteRequestRepository::class);
        
        // Привязка для графиков проектов
        $this->app->bind(ProjectScheduleRepositoryInterface::class, ProjectScheduleRepository::class);
        
        // Добавить другие репозитории по мере необходимости
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}