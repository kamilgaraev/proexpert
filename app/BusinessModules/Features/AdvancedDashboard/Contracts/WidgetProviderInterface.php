<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Contracts;

use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataResponse;

interface WidgetProviderInterface
{
    public function getType(): WidgetType;

    public function getData(WidgetDataRequest $request): WidgetDataResponse;

    public function getCacheTTL(): int;

    public function getMetadata(): array;

    public function validateRequest(WidgetDataRequest $request): bool;
}

