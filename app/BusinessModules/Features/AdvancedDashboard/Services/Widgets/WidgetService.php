<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets;

use App\BusinessModules\Features\AdvancedDashboard\Contracts\WidgetProviderInterface;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetCategory;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataResponse;
use App\BusinessModules\Features\AdvancedDashboard\Services\DashboardCacheService;
use App\Services\LogService;
use Illuminate\Support\Facades\Cache;
use Exception;

class WidgetService
{
    public function __construct(
        private readonly WidgetRegistry $registry,
        private readonly DashboardCacheService $cacheService,
        private readonly LogService $logService
    ) {}

    public function getWidgetData(WidgetType $type, WidgetDataRequest $request): WidgetDataResponse
    {
        $provider = $this->registry->getProvider($type);

        if (!$provider) {
            throw new Exception("Widget provider not found for type: {$type->value}");
        }

        if (!$provider->validateRequest($request)) {
            throw new Exception("Invalid request for widget: {$type->value}");
        }

        $cacheKey = $this->getCacheKey($type, $request);
        $cacheTTL = $provider->getCacheTTL();

        if ($cacheTTL > 0) {
            $cacheTags = [$type->getCategory()->value, "widget:{$type->value}", "org:{$request->organizationId}"];
            
            return $this->cacheService->remember(
                $cacheKey,
                fn() => $provider->getData($request),
                $cacheTTL,
                $cacheTags
            );
        }

        try {
            $response = $provider->getData($request);

            $this->logService->info("Widget data generated: {$type->value}", [
                'channel' => 'advanced_dashboard',
                'widget_type' => $type->value,
                'organization_id' => $request->organizationId,
                'execution_time' => microtime(true),
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logService->error("Error generating widget data: {$type->value}", [
                'channel' => 'advanced_dashboard',
                'widget_type' => $type->value,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function getMultipleWidgets(array $types, WidgetDataRequest $request): array
    {
        $results = [];

        foreach ($types as $typeValue) {
            try {
                $type = WidgetType::from($typeValue);
                $results[$typeValue] = $this->getWidgetData($type, $request);
            } catch (Exception $e) {
                $results[$typeValue] = [
                    'error' => true,
                    'message' => $e->getMessage(),
                ];

                $this->logService->error("Error in batch widget request: {$typeValue}", [
                    'channel' => 'advanced_dashboard',
                    'widget_type' => $typeValue,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    public function invalidateWidgetCache(WidgetType $type, int $organizationId): void
    {
        Cache::tags(["widget:{$type->value}", "org:{$organizationId}"])->flush();

        $this->logService->info("Widget cache invalidated: {$type->value}", [
            'channel' => 'advanced_dashboard',
            'widget_type' => $type->value,
            'organization_id' => $organizationId,
        ]);
    }

    public function invalidateCategoryCache(WidgetCategory $category, int $organizationId): void
    {
        $this->cacheService->invalidateByCategory($category->value, $organizationId);

        $this->logService->info("Category cache invalidated: {$category->value}", [
            'channel' => 'advanced_dashboard',
            'category' => $category->value,
            'organization_id' => $organizationId,
        ]);
    }

    public function getWidgetMetadata(WidgetType $type): array
    {
        $provider = $this->registry->getProvider($type);

        if (!$provider) {
            throw new Exception("Widget provider not found for type: {$type->value}");
        }

        return array_merge(
            $type->getMetadata(),
            $provider->getMetadata(),
            [
                'category' => $type->getCategory()->value,
                'cache_ttl' => $provider->getCacheTTL(),
            ]
        );
    }

    public function getAllWidgetsMetadata(): array
    {
        $widgets = [];

        foreach (WidgetType::cases() as $type) {
            try {
                $widgets[] = $this->getWidgetMetadata($type);
            } catch (Exception $e) {
                continue;
            }
        }

        return $widgets;
    }

    public function getWidgetsByCategory(): array
    {
        $categorized = [];

        foreach (WidgetCategory::cases() as $category) {
            $categoryData = [
                'id' => $category->value,
                'name' => $category->getName(),
                'description' => $category->getDescription(),
                'color' => $category->getColor(),
                'icon' => $category->getIcon(),
                'widgets' => [],
            ];

            foreach (WidgetType::cases() as $type) {
                if ($type->getCategory() === $category) {
                    try {
                        $categoryData['widgets'][] = $this->getWidgetMetadata($type);
                    } catch (Exception $e) {
                        continue;
                    }
                }
            }

            $categorized[] = $categoryData;
        }

        return $categorized;
    }

    private function getCacheKey(WidgetType $type, WidgetDataRequest $request): string
    {
        $keyParts = [
            'widget',
            $type->value,
            'org', $request->organizationId,
        ];

        if ($request->from) {
            $keyParts[] = 'from_' . $request->from->format('Y-m-d');
        }
        if ($request->to) {
            $keyParts[] = 'to_' . $request->to->format('Y-m-d');
        }
        if ($request->projectId) {
            $keyParts[] = 'project_' . $request->projectId;
        }
        if ($request->contractId) {
            $keyParts[] = 'contract_' . $request->contractId;
        }
        if ($request->employeeId) {
            $keyParts[] = 'employee_' . $request->employeeId;
        }

        if (!empty($request->filters)) {
            $keyParts[] = md5(json_encode($request->filters));
        }

        if (!empty($request->options)) {
            $keyParts[] = md5(json_encode($request->options));
        }

        return implode(':', $keyParts);
    }
}

