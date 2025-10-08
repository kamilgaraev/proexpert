<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers;

use App\BusinessModules\Features\AdvancedDashboard\Contracts\WidgetProviderInterface;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataResponse;

abstract class AbstractWidgetProvider implements WidgetProviderInterface
{
    protected int $cacheTTL = 300;

    abstract public function getType(): WidgetType;

    abstract protected function fetchData(WidgetDataRequest $request): array;

    public function getData(WidgetDataRequest $request): WidgetDataResponse
    {
        $data = $this->fetchData($request);

        return WidgetDataResponse::success(
            widgetType: $this->getType(),
            data: $data,
            metadata: $this->getMetadata()
        );
    }

    public function getCacheTTL(): int
    {
        return $this->cacheTTL;
    }

    public function getMetadata(): array
    {
        return $this->getType()->getMetadata();
    }

    public function validateRequest(WidgetDataRequest $request): bool
    {
        return true;
    }
}

