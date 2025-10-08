<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets;

use App\BusinessModules\Features\AdvancedDashboard\Contracts\WidgetProviderInterface;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetCategory;

class WidgetRegistry
{
    private array $providers = [];
    private static ?self $instance = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register(WidgetProviderInterface $provider): void
    {
        $this->providers[$provider->getType()->value] = $provider;
    }

    public function getProvider(WidgetType $type): ?WidgetProviderInterface
    {
        return $this->providers[$type->value] ?? null;
    }

    public function hasProvider(WidgetType $type): bool
    {
        return isset($this->providers[$type->value]);
    }

    public function getProvidersByCategory(WidgetCategory $category): array
    {
        return array_filter(
            $this->providers,
            fn(WidgetProviderInterface $provider) => $provider->getType()->getCategory() === $category
        );
    }

    public function getAllProviders(): array
    {
        return $this->providers;
    }

    public function getAllTypes(): array
    {
        return array_keys($this->providers);
    }

    public function count(): int
    {
        return count($this->providers);
    }

    public function clear(): void
    {
        $this->providers = [];
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function getWidgetsMetadata(): array
    {
        $metadata = [];
        foreach ($this->providers as $provider) {
            $metadata[] = $provider->getMetadata();
        }
        return $metadata;
    }

    public function getCategoriesMetadata(): array
    {
        $categories = [];
        foreach (WidgetCategory::cases() as $category) {
            $widgetsCount = count(array_filter(
                $this->providers,
                fn(WidgetProviderInterface $provider) => $provider->getType()->getCategory() === $category
            ));

            $categories[] = [
                'id' => $category->value,
                'name' => $category->getName(),
                'description' => $category->getDescription(),
                'icon' => $category->getIcon(),
                'widgets_count' => $widgetsCount,
            ];
        }
        return $categories;
    }
}

