<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Services;

use App\Helpers\PermissionTranslator;
use FilesystemIterator;
use Illuminate\Support\Facades\Lang;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class KnowledgeArticleTargetingOptions
{
    private const MODULE_LIST_PATH = 'config/ModuleList';
    private const ROLE_DEFINITIONS_PATH = 'config/RoleDefinitions';

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $moduleDefinitions = null;

    /**
     * @var array<string, string|null>|null
     */
    private static ?array $rolePermissions = null;

    /**
     * @return array<string, string>
     */
    public function moduleOptions(): array
    {
        $options = [];

        foreach ($this->moduleDefinitions() as $slug => $definition) {
            $options[$slug] = $this->stringValue($definition['name'] ?? null) ?? $this->readableKey($slug);
        }

        return $this->sortOptions($options);
    }

    /**
     * @return array<string, string>
     */
    public function permissionOptions(): array
    {
        $permissionModules = [];

        foreach ($this->moduleDefinitions() as $moduleSlug => $definition) {
            foreach ($this->stringList($definition['permissions'] ?? []) as $permission) {
                if ($this->isPermissionKey($permission)) {
                    $permissionModules[$permission] = $moduleSlug;
                }
            }
        }

        foreach ($this->rolePermissionModules() as $permission => $moduleSlug) {
            if ($this->isPermissionKey($permission) && ! array_key_exists($permission, $permissionModules)) {
                $permissionModules[$permission] = $moduleSlug;
            }
        }

        foreach ($this->translationPermissionKeys() as $permission) {
            if ($this->isPermissionKey($permission) && ! array_key_exists($permission, $permissionModules)) {
                $permissionModules[$permission] = $this->inferModuleSlug($permission);
            }
        }

        $options = [];

        foreach ($permissionModules as $permission => $moduleSlug) {
            $options[$permission] = $this->permissionLabel($permission, $moduleSlug);
        }

        return $this->sortOptions($options);
    }

    /**
     * @return array<string, string>
     */
    public function contextOptions(): array
    {
        $options = $this->explicitContextOptions();
        $moduleOptions = $this->moduleOptions();

        foreach ($moduleOptions as $moduleSlug => $moduleLabel) {
            $contextPrefix = str_replace('-', '_', $moduleSlug);
            $options[$contextPrefix.'.index'] ??= trans_message('knowledge_hub.context_suffixes.list', ['name' => $moduleLabel]);
            $options[$contextPrefix.'.create'] ??= trans_message('knowledge_hub.context_suffixes.create', ['name' => $moduleLabel]);
            $options[$contextPrefix.'.detail'] ??= trans_message('knowledge_hub.context_suffixes.detail', ['name' => $moduleLabel]);
        }

        foreach ($this->permissionSubjects() as $subjectKey => $subjectLabel) {
            if (! is_string($subjectKey) || ! is_string($subjectLabel) || trim($subjectLabel) === '') {
                continue;
            }

            $options[$subjectKey] ??= trans_message('knowledge_hub.context_suffixes.section', ['name' => $subjectLabel]);
        }

        foreach ($this->permissionOptions() as $permission => $label) {
            $options[$permission] ??= trans_message('knowledge_hub.context_suffixes.action', ['name' => $label]);
        }

        return $this->sortOptions($options);
    }

    /**
     * @param list<string>|array<int|string, mixed> $values
     * @return array<string, string>
     */
    public function moduleLabels(array $values): array
    {
        return $this->labelsForValues($values, $this->moduleOptions());
    }

    /**
     * @param list<string>|array<int|string, mixed> $values
     * @return array<string, string>
     */
    public function permissionLabels(array $values): array
    {
        return $this->labelsForValues($values, $this->permissionOptions(), function (string $value): string {
            return $this->permissionLabel($value, $this->inferModuleSlug($value));
        });
    }

    /**
     * @param list<string>|array<int|string, mixed> $values
     * @return array<string, string>
     */
    public function contextLabels(array $values): array
    {
        return $this->labelsForValues($values, $this->contextOptions());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function moduleDefinitions(): array
    {
        if (self::$moduleDefinitions !== null) {
            return self::$moduleDefinitions;
        }

        $definitions = [];

        foreach ($this->jsonFiles(base_path(self::MODULE_LIST_PATH)) as $file) {
            $definition = $this->readJsonFile($file);
            $slug = $this->stringValue($definition['slug'] ?? null);

            if ($slug === null) {
                continue;
            }

            $definitions[$slug] = $definition;
        }

        self::$moduleDefinitions = $definitions;

        return self::$moduleDefinitions;
    }

    /**
     * @return array<string, string|null>
     */
    private function rolePermissionModules(): array
    {
        if (self::$rolePermissions !== null) {
            return self::$rolePermissions;
        }

        $permissions = [];

        foreach ($this->jsonFiles(base_path(self::ROLE_DEFINITIONS_PATH)) as $file) {
            $this->collectRolePermissions($this->readJsonFile($file), null, $permissions);
        }

        self::$rolePermissions = $permissions;

        return self::$rolePermissions;
    }

    /**
     * @param array<string, string|null> $permissions
     */
    private function collectRolePermissions(mixed $value, ?string $moduleSlug, array &$permissions): void
    {
        if (is_string($value)) {
            if ($this->isPermissionKey($value) && ! array_key_exists($value, $permissions)) {
                $permissions[$value] = $moduleSlug;
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $nestedValue) {
            $nestedModuleSlug = $moduleSlug;

            if (is_string($key) && is_array($nestedValue) && $this->isModuleGroupKey($key)) {
                $nestedModuleSlug = $key;
            }

            $this->collectRolePermissions($nestedValue, $nestedModuleSlug, $permissions);
        }
    }

    /**
     * @return list<string>
     */
    private function translationPermissionKeys(): array
    {
        $keys = [];
        $values = Lang::get('permissions.values', [], 'ru');

        if (is_array($values)) {
            foreach (array_keys($values) as $permission) {
                if (is_string($permission)) {
                    $keys[] = $permission;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, string>
     */
    private function explicitContextOptions(): array
    {
        $options = [];
        $translations = Lang::get('knowledge_hub.context_options', [], 'ru');

        if (! is_array($translations)) {
            return $options;
        }

        foreach ($translations as $key => $label) {
            if (is_string($key) && is_string($label) && trim($label) !== '') {
                $options[$key] = $label;
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function permissionSubjects(): array
    {
        $subjects = Lang::get('permissions.subjects', [], 'ru');

        return is_array($subjects) ? $subjects : [];
    }

    private function permissionLabel(string $permission, ?string $moduleSlug): string
    {
        $label = PermissionTranslator::getPermissionTranslation($permission, $moduleSlug);
        $moduleLabel = $moduleSlug !== null ? ($this->moduleOptions()[$moduleSlug] ?? null) : null;

        if ($moduleLabel === null || str_contains($label, $moduleLabel)) {
            return $label;
        }

        return "{$moduleLabel} — {$label}";
    }

    private function inferModuleSlug(string $permission): ?string
    {
        if (! str_contains($permission, '.')) {
            return null;
        }

        $prefix = explode('.', $permission, 2)[0] ?? '';
        $slug = str_replace('_', '-', $prefix);

        return array_key_exists($slug, $this->moduleOptions()) ? $slug : null;
    }

    private function isPermissionKey(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && $value !== '*' && str_contains($value, '.');
    }

    private function isModuleGroupKey(string $key): bool
    {
        return ! in_array($key, [
            'system_permissions',
            'module_permissions',
            'interface_access',
            'hierarchy',
            'can_manage_roles',
            'cannot_manage',
            'modules_visible',
            'modules_hidden',
        ], true);
    }

    /**
     * @return list<SplFileInfo>
     */
    private function jsonFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'json') {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(SplFileInfo $file): array
    {
        try {
            $contents = file_get_contents($file->getPathname());
            $decoded = json_decode(is_string($contents) ? $contents : '{}', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''));
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, string> $options
     * @return array<string, string>
     */
    private function sortOptions(array $options): array
    {
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /**
     * @param list<string>|array<int|string, mixed> $values
     * @param array<string, string> $options
     * @return array<string, string>
     */
    private function labelsForValues(array $values, array $options, ?callable $fallback = null): array
    {
        $labels = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $labels[$value] = $options[$value] ?? ($fallback !== null ? $fallback($value) : $this->readableKey($value));
        }

        return $labels;
    }

    private function readableKey(string $key): string
    {
        $label = str_replace(['.', '-', '_'], ' ', $key);

        return mb_convert_case(trim($label), MB_CASE_TITLE, 'UTF-8');
    }
}
