<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Catalog;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ReportPermissionCatalog
{
    private array $knownPermissions;

    private array $translations;

    public function __construct(
        ?string $roleDefinitionsPath = null,
        ?string $moduleSourcesPath = null,
        ?string $translationsPath = null,
    ) {
        $root = dirname(__DIR__, 6);
        $this->knownPermissions = $this->loadKnownPermissions(
            $roleDefinitionsPath ?? $root.'/config/RoleDefinitions',
            $moduleSourcesPath ?? $root.'/app/BusinessModules',
        );
        $this->translations = $this->loadTranslations(
            $translationsPath ?? $root.'/lang/ru/permissions.php',
        );
    }

    public function assertKnownAndTranslated(array $permissionSlugs): void
    {
        if (! array_is_list($permissionSlugs)) {
            throw new RuntimeException('report_permission_catalog_input_invalid');
        }

        foreach ($permissionSlugs as $permissionSlug) {
            if (! is_string($permissionSlug)
                || preg_match('/^[a-z0-9][a-z0-9._-]+$/D', $permissionSlug) !== 1
                || ! $this->isKnown($permissionSlug)
                || ! $this->hasRussianTranslation($permissionSlug)) {
                throw new \LogicException('report_permission_unknown_or_untranslated');
            }
        }
    }

    private function loadKnownPermissions(string $rolesPath, string $modulesPath): array
    {
        $permissions = [];
        $this->collectFiles($rolesPath, static fn (string $path): bool => str_ends_with($path, '.json'), function (string $path) use (&$permissions): void {
            $bytes = @file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('report_permission_source_unreadable');
            }

            $decoded = json_decode($bytes, true);
            if (! is_array($decoded)) {
                throw new RuntimeException('report_permission_source_invalid');
            }

            $this->collectPermissionStrings($decoded, $permissions);
        });

        $this->collectFiles($modulesPath, static fn (string $path): bool => str_ends_with($path, 'Module.php'), function (string $path) use (&$permissions): void {
            $bytes = @file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('report_permission_source_unreadable');
            }

            foreach (token_get_all($bytes) as $token) {
                if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                $value = $this->decodePhpString($token[1]);
                if ($this->isPermissionSourceValue($value)) {
                    $permissions[$value] = true;
                }
            }
        });

        return $permissions;
    }

    private function loadTranslations(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('report_permission_translation_source_unreadable');
        }

        $translations = (static fn (string $translationPath): mixed => require $translationPath)($path);
        if (! is_array($translations)) {
            throw new RuntimeException('report_permission_translation_source_invalid');
        }

        return $translations;
    }

    private function collectFiles(string $root, callable $accept, callable $consume): void
    {
        if (! is_dir($root)) {
            throw new RuntimeException('report_permission_source_unreadable');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && $accept($path)) {
                $consume($path);
            }
        }
    }

    private function collectPermissionStrings(mixed $value, array &$permissions): void
    {
        if (is_string($value)) {
            if ($this->isPermissionSourceValue($value)) {
                $permissions[$value] = true;
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            $this->collectPermissionStrings($item, $permissions);
        }
    }

    private function isPermissionSourceValue(string $value): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9_*][a-z0-9_-]*)+$/D', $value) === 1;
    }

    private function isKnown(string $permissionSlug): bool
    {
        if (isset($this->knownPermissions[$permissionSlug])) {
            return true;
        }

        $namespace = strstr($permissionSlug, '.', true);
        foreach ($this->knownPermissions as $known => $_) {
            if (str_ends_with($known, '.*')
                && str_starts_with($permissionSlug, substr($known, 0, -1))) {
                return true;
            }

            if ($namespace !== false
                && ($known === $namespace || str_starts_with($known, $namespace.'.'))) {
                return true;
            }
        }

        return false;
    }

    private function hasRussianTranslation(string $permissionSlug): bool
    {
        $values = $this->translations['values'] ?? [];
        if (is_array($values) && $this->isRussianLabel($values[$permissionSlug] ?? null, $permissionSlug)) {
            return true;
        }

        $subjects = $this->translations['subjects'] ?? [];
        $actions = $this->translations['actions'] ?? [];
        if (! is_array($subjects) || ! is_array($actions)) {
            return false;
        }

        $subjectKeys = array_keys($subjects);
        usort($subjectKeys, static fn (mixed $left, mixed $right): int => strlen((string) $right) <=> strlen((string) $left));
        foreach ($subjectKeys as $subjectKey) {
            if (! is_string($subjectKey)
                || ($permissionSlug !== $subjectKey && ! str_starts_with($permissionSlug, $subjectKey.'.'))
                || ! $this->isRussianLabel($subjects[$subjectKey] ?? null, $subjectKey)) {
                continue;
            }

            $action = $permissionSlug === $subjectKey
                ? ''
                : substr($permissionSlug, strlen($subjectKey) + 1);
            if ($action === '') {
                return true;
            }

            if ($this->isRussianLabel($actions[$action] ?? null, $action)) {
                return true;
            }

            $lastSegment = str_contains($action, '.')
                ? substr($action, strrpos($action, '.') + 1)
                : $action;

            return $this->isRussianLabel($actions[$lastSegment] ?? null, $lastSegment);
        }

        $lastSegment = str_contains($permissionSlug, '.')
            ? substr($permissionSlug, strrpos($permissionSlug, '.') + 1)
            : $permissionSlug;

        return $this->isRussianLabel($actions[$lastSegment] ?? null, $lastSegment);
    }

    private function isRussianLabel(mixed $label, string $technicalKey): bool
    {
        return is_string($label)
            && trim($label) !== ''
            && $label !== $technicalKey
            && ! str_starts_with($label, 'permissions.')
            && preg_match('/\p{Cyrillic}/u', $label) === 1;
    }

    private function decodePhpString(string $literal): string
    {
        $quote = $literal[0] ?? '';
        $value = substr($literal, 1, -1);

        return $quote === "'"
            ? str_replace(['\\\\', "\\'"], ['\\', "'"], $value)
            : stripcslashes($value);
    }
}
