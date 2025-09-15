<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Core;

use App\BusinessModules\Enterprise\MultiOrganization\Core\Domain\Events\OrganizationDataUpdated;
use App\BusinessModules\Enterprise\MultiOrganization\Core\Infrastructure\EventHandlers\UpdateHoldingMetrics;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class MultiOrganizationEventServiceProvider extends ServiceProvider
{
    /**
     * Карта событий и их слушателей
     */
    protected $listen = [
        OrganizationDataUpdated::class => [
            UpdateHoldingMetrics::class,
        ],
    ];

    /**
     * Регистрация любых событий для вашего приложения
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Определение, должны ли события и слушатели автоматически обнаруживаться
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
