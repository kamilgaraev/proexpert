<?php

declare(strict_types=1);

namespace App\Services\Modules;

use RuntimeException;

class PackageCatalogService
{
    private const TIER_ORDER = ['standard'];

    private ?array $packages = null;
    private ?array $modules = null;
    private ?array $settings = null;

    public function __construct(
        private readonly ?string $packagesPath = null,
        private readonly ?string $moduleListPath = null,
        private readonly ?string $settingsPath = null,
    ) {}

    public function allPackages(): array
    {
        if ($this->packages !== null) {
            return $this->packages;
        }

        $packages = [];

        foreach (glob($this->packagesPath() . '/*.json') ?: [] as $filePath) {
            $package = json_decode((string) file_get_contents($filePath), true);

            if (! is_array($package) || ! isset($package['slug'], $package['tiers'])) {
                continue;
            }

            $packages[] = $this->normalizePackage($package);
        }

        usort($packages, fn (array $a, array $b): int => ($a['sort_order'] ?? 99) <=> ($b['sort_order'] ?? 99));

        return $this->packages = $packages;
    }

    public function package(string $packageSlug): ?array
    {
        foreach ($this->allPackages() as $package) {
            if ($package['slug'] === $packageSlug) {
                return $package;
            }
        }

        return null;
    }

    public function requirePackage(string $packageSlug): array
    {
        $package = $this->package($packageSlug);

        if ($package === null) {
            throw new RuntimeException("Package '{$packageSlug}' is not configured.");
        }

        return $package;
    }

    public function tierModules(string $packageSlug, string $tier, bool $includeFoundation = false): array
    {
        $package = $this->package($packageSlug);

        if ($package === null || ! isset($package['tiers'][$tier])) {
            return [];
        }

        $modules = $package['tiers'][$tier]['included_modules'] ?? $package['tiers'][$tier]['modules'] ?? [];

        if ($includeFoundation) {
            $modules = array_merge($package['foundation_modules'] ?? $this->foundationModules(), $modules);
        }

        return $this->uniqueStrings($modules);
    }

    public function tierExists(string $packageSlug, string $tier): bool
    {
        $package = $this->package($packageSlug);

        return $package !== null && isset($package['tiers'][$tier]);
    }

    public function moduleDefinitions(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $modules = [];

        foreach (glob($this->moduleListPath() . '/*/*.json') ?: [] as $filePath) {
            $module = json_decode((string) file_get_contents($filePath), true);

            if (is_array($module) && isset($module['slug'])) {
                $modules[$module['slug']] = $module;
            }
        }

        ksort($modules);

        return $this->modules = $modules;
    }

    public function foundationModules(): array
    {
        return $this->uniqueStrings($this->settings()['foundation_modules'] ?? []);
    }

    public function moduleClassifications(): array
    {
        return array_filter(
            $this->settings()['module_classifications'] ?? [],
            fn (mixed $classification, mixed $slug): bool => is_string($slug) && is_string($classification),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function normalizePackage(array $package): array
    {
        $package['schema_version'] = (int) ($package['schema_version'] ?? 2);
        $package['foundation_modules'] = $this->uniqueStrings($package['foundation_modules'] ?? $this->foundationModules());
        $package['integrations'] = $this->normalizeList($package['integrations'] ?? []);
        $package['recommended_addons'] = $this->normalizeList($package['recommended_addons'] ?? []);
        $package['business_outcomes'] = $this->uniqueStrings($package['business_outcomes'] ?? []);
        $package['data_sources'] = $this->normalizeList($package['data_sources'] ?? []);
        $package['capabilities'] = $this->normalizeList($package['capabilities'] ?? []);

        $tiers = [];
        foreach (self::TIER_ORDER as $tierKey) {
            if (! isset($package['tiers'][$tierKey]) || ! is_array($package['tiers'][$tierKey])) {
                continue;
            }

            $tier = $package['tiers'][$tierKey];
            $modules = $this->uniqueStrings($tier['modules'] ?? []);
            $tier['modules'] = $modules;
            $tier['included_modules'] = $this->uniqueStrings($tier['included_modules'] ?? $modules);
            $tier['highlights'] = $this->uniqueStrings($tier['highlights'] ?? []);
            $tiers[$tierKey] = $tier;
        }

        $package['tiers'] = $tiers;

        return $package;
    }

    private function normalizeList(array $items): array
    {
        return array_values(array_filter($items, fn (mixed $item): bool => is_array($item) || is_string($item)));
    }

    private function uniqueStrings(array $items): array
    {
        return array_values(array_unique(array_filter($items, fn (mixed $item): bool => is_string($item) && $item !== '')));
    }

    private function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $path = $this->settingsPath ?? dirname($this->packagesPath()) . '/module_packages.php';

        if (! is_file($path)) {
            return $this->settings = [
                'foundation_modules' => [],
                'module_classifications' => [],
            ];
        }

        $settings = require $path;

        return $this->settings = is_array($settings) ? $settings : [];
    }

    private function packagesPath(): string
    {
        if ($this->packagesPath !== null) {
            return $this->packagesPath;
        }

        return config_path('Packages');
    }

    private function moduleListPath(): string
    {
        if ($this->moduleListPath !== null) {
            return $this->moduleListPath;
        }

        return config_path('ModuleList');
    }
}
