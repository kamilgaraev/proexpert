<?php

namespace App\Providers;

use App\Models\Organization;
use App\Models\User;
use App\Repositories\OrganizationRepository;
use App\Repositories\OrganizationRepositoryInterface;
use App\Repositories\UserRepository;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, function ($app) {
            return new UserRepository(new User());
        });
        
        $this->app->bind(OrganizationRepositoryInterface::class, function ($app) {
            return new OrganizationRepository(new Organization());
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
} 