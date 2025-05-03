<?php

namespace App\Providers;

use App\Repositories\BaseRepository;
use App\Repositories\UserRepositoryInterface;
use App\Repositories\OrganizationRepositoryInterface;
use App\Repositories\Interfaces\ProjectRepositoryInterface;
use App\Repositories\Interfaces\MaterialRepositoryInterface;
use App\Repositories\Interfaces\WorkTypeRepositoryInterface;
use App\Repositories\Interfaces\SupplierRepositoryInterface;
use App\Repositories\Interfaces\RoleRepositoryInterface;
use App\Repositories\MaterialRepository;
use App\Repositories\OrganizationRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\RepositoryInterface;
use App\Repositories\RoleRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkTypeRepository;
use Illuminate\Support\ServiceProvider;

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
        $this->app->bind(Interfaces\ProjectRepositoryInterface::class, ProjectRepository::class);
        $this->app->bind(Interfaces\MaterialRepositoryInterface::class, MaterialRepository::class);
        $this->app->bind(Interfaces\WorkTypeRepositoryInterface::class, WorkTypeRepository::class);
        $this->app->bind(Interfaces\SupplierRepositoryInterface::class, SupplierRepository::class);
        $this->app->bind(Interfaces\RoleRepositoryInterface::class, RoleRepository::class);
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