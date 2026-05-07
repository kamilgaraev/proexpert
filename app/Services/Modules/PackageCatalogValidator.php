<?php

declare(strict_types=1);

namespace App\Services\Modules;

class PackageCatalogValidator
{
    public function __construct(
        private readonly PackageCatalogService $catalog,
    ) {}

    public function validate(
        ?array $packages = null,
        ?array $modules = null,
        ?array $foundationModules = null,
        ?array $moduleClassifications = null,
    ): array {
        $packages ??= $this->catalog->allPackages();
        $modules ??= $this->catalog->moduleDefinitions();
        $foundationModules ??= $this->catalog->foundationModules();
        $moduleClassifications ??= $this->catalog->moduleClassifications();

        $errors = [];
        $warnings = [];
        $knownModuleSlugs = array_fill_keys(array_keys($modules), true);
        $foundationSet = array_fill_keys($foundationModules, true);
        $referencedModules = [];

        foreach ($packages as $package) {
            $packageSlug = $package['slug'] ?? 'unknown-package';

            foreach (($package['tiers'] ?? []) as $tierKey => $tier) {
                $tierModules = $this->stringList($tier['included_modules'] ?? $tier['modules'] ?? []);
                $availableModules = array_fill_keys(array_merge(array_keys($foundationSet), $tierModules), true);

                foreach ($tierModules as $moduleSlug) {
                    $referencedModules[$moduleSlug] = true;

                    if (! isset($knownModuleSlugs[$moduleSlug])) {
                        $errors[] = "{$packageSlug}/{$tierKey} references missing module {$moduleSlug}";
                        continue;
                    }

                    foreach ($this->stringList($modules[$moduleSlug]['dependencies'] ?? []) as $dependencySlug) {
                        if (! isset($knownModuleSlugs[$dependencySlug])) {
                            $errors[] = "{$moduleSlug} depends on unknown module {$dependencySlug}";
                            continue;
                        }

                        if (! isset($availableModules[$dependencySlug])) {
                            $errors[] = "{$packageSlug}/{$tierKey} includes {$moduleSlug}, but misses dependency {$dependencySlug}";
                        }
                    }
                }
            }

            foreach ($this->linkedModuleSlugs($package['integrations'] ?? []) as $moduleSlug) {
                if (! isset($knownModuleSlugs[$moduleSlug])) {
                    $errors[] = "{$packageSlug} integration references missing module {$moduleSlug}";
                }
            }

            foreach ($this->linkedModuleSlugs($package['recommended_addons'] ?? []) as $moduleSlug) {
                if (! isset($knownModuleSlugs[$moduleSlug])) {
                    $errors[] = "{$packageSlug} recommended addon references missing module {$moduleSlug}";
                }
            }

            foreach ($this->linkedModuleSlugs($package['capabilities'] ?? [], 'requires_modules') as $moduleSlug) {
                if (! isset($knownModuleSlugs[$moduleSlug])) {
                    $errors[] = "{$packageSlug} capability references missing module {$moduleSlug}";
                }
            }
        }

        foreach ($foundationModules as $moduleSlug) {
            if (! isset($knownModuleSlugs[$moduleSlug])) {
                $errors[] = "Foundation references missing module {$moduleSlug}";
            }
        }

        foreach ($modules as $moduleSlug => $module) {
            if (isset($moduleClassifications[$moduleSlug])) {
                continue;
            }

            if (isset($referencedModules[$moduleSlug])) {
                continue;
            }

            if (($module['is_system_module'] ?? false) === true || ($module['auto_activate'] ?? false) === true) {
                $warnings[] = "{$moduleSlug} is auto/system and has no package classification";
                continue;
            }

            $errors[] = "{$moduleSlug} has no package classification";
        }

        return [
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function linkedModuleSlugs(array $items, string $key = 'module_slug'): array
    {
        $slugs = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $slugs[] = $item;
                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $value = $item[$key] ?? null;

            if (is_string($value)) {
                $slugs[] = $value;
                continue;
            }

            if (is_array($value)) {
                $slugs = array_merge($slugs, $this->stringList($value));
            }
        }

        return array_values(array_unique($slugs));
    }

    private function stringList(array $items): array
    {
        return array_values(array_unique(array_filter($items, fn (mixed $item): bool => is_string($item) && $item !== '')));
    }
}
