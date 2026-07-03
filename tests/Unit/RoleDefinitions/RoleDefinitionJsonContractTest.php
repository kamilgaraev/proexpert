<?php

declare(strict_types=1);

namespace Tests\Unit\RoleDefinitions;

use App\BusinessModules\Features\SiteRequests\SiteRequestsModule;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class RoleDefinitionJsonContractTest extends TestCase
{
    private const REQUIRED_SITE_REQUESTS_PERMISSIONS = [
        'site_requests.view',
        'site_requests.create',
        'site_requests.edit',
        'site_requests.delete',
        'site_requests.approve',
        'site_requests.reject',
        'site_requests.assign',
        'site_requests.change_status',
        'site_requests.statistics',
        'site_requests.templates.view',
        'site_requests.templates.manage',
        'site_requests.calendar.view',
        'site_requests.calendar.export',
        'site_requests.files.upload',
        'site_requests.files.delete',
        'site_requests.export',
    ];

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = dirname(__DIR__, 3);
    }

    public function test_role_definition_files_do_not_have_duplicate_top_level_keys(): void
    {
        foreach ($this->roleDefinitionFiles() as $filePath) {
            $content = (string) file_get_contents($filePath);
            $keys = $this->extractTopLevelKeys($content);
            $duplicates = array_keys(array_filter(array_count_values($keys), static fn (int $count): bool => $count > 1));

            $this->assertSame(
                [],
                $duplicates,
                sprintf(
                    'Duplicate top-level keys in %s: %s',
                    str_replace($this->basePath . DIRECTORY_SEPARATOR, '', $filePath),
                    implode(', ', $duplicates),
                ),
            );
        }
    }

    public function test_site_requests_permissions_are_synchronized_in_module_and_manifest(): void
    {
        $modulePermissions = (new SiteRequestsModule())->getPermissions();
        $manifest = json_decode(
            (string) file_get_contents($this->basePath . '/config/ModuleList/features/site-requests.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $manifestPermissions = $manifest['permissions'] ?? [];

        foreach (self::REQUIRED_SITE_REQUESTS_PERMISSIONS as $permission) {
            $this->assertContains($permission, $modulePermissions, "SiteRequestsModule misses {$permission}");
            $this->assertContains($permission, $manifestPermissions, "site-requests manifest misses {$permission}");
        }
    }

    public function test_role_translations_cover_all_role_definitions(): void
    {
        $translations = require $this->basePath . '/lang/ru/roles.php';

        foreach ($this->roleDefinitionFiles() as $filePath) {
            $role = json_decode((string) file_get_contents($filePath), true, 512, JSON_THROW_ON_ERROR);
            $slug = $role['slug'] ?? null;

            $this->assertIsString($slug, "Role slug is missing in {$filePath}");
            $this->assertArrayHasKey($slug, $translations, "Role translation is missing for {$slug}");
            $this->assertNotSame('', trim((string) ($translations[$slug]['name'] ?? '')), "Role name is empty for {$slug}");
            $this->assertNotSame('', trim((string) ($translations[$slug]['description'] ?? '')), "Role description is empty for {$slug}");
        }
    }

    public function test_customer_role_definitions_are_not_assignable_in_general_catalog(): void
    {
        foreach ($this->roleDefinitionFiles() as $filePath) {
            if (!str_contains($filePath, DIRECTORY_SEPARATOR . 'customer' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $role = json_decode((string) file_get_contents($filePath), true, 512, JSON_THROW_ON_ERROR);

            $this->assertFalse(
                $role['assignable'] ?? true,
                sprintf('Customer role %s must not be shown in the general assignable role catalog', $role['slug'] ?? $filePath),
            );
        }
    }

    public function test_supplier_role_has_admin_panel_procurement_and_warehouse_access(): void
    {
        $role = $this->roleDefinition('lk/supplier.json');

        $this->assertContains('admin.access', $role['system_permissions']);
        $this->assertContains('admin', $role['interface_access']);
        $this->assertContains('procurement.view', $role['module_permissions']['procurement'] ?? []);
        $this->assertContains('procurement.purchase_orders.receive', $role['module_permissions']['procurement'] ?? []);
        $this->assertContains('warehouse.manage_stock', $role['module_permissions']['basic-warehouse'] ?? []);
    }

    public function test_duplicate_and_internal_roles_are_not_assignable(): void
    {
        $rolePaths = [
            'system/super_admin.json',
            'system/system_admin.json',
            'system/support.json',
            'admin/web_admin.json',
            'admin/admin_viewer.json',
            'admin/brigade_catalog_moderator.json',
            'project/parent_administrator.json',
            'project/project_viewer.json',
            'mobile/observer.json',
            'lk/brigade_manager.json',
            'lk/brigade_representative.json',
        ];

        foreach ($rolePaths as $rolePath) {
            $role = $this->roleDefinition($rolePath);

            $this->assertFalse(
                $role['assignable'] ?? true,
                sprintf('Role %s must not be shown in assignable role catalogs', $role['slug'] ?? $rolePath),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function roleDefinitionFiles(): array
    {
        $path = $this->basePath . '/config/RoleDefinitions';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        $files = [];

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== 'json') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        return $files;
    }

    private function roleDefinition(string $relativePath): array
    {
        return json_decode(
            (string) file_get_contents($this->basePath . '/config/RoleDefinitions/' . $relativePath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return list<string>
     */
    private function extractTopLevelKeys(string $json): array
    {
        $keys = [];
        $depth = 0;
        $inString = false;
        $escaped = false;
        $expectingKey = false;
        $collectingKey = false;
        $currentKey = '';
        $length = strlen($json);

        for ($index = 0; $index < $length; $index++) {
            $char = $json[$index];

            if ($inString) {
                if ($escaped) {
                    if ($collectingKey) {
                        $currentKey .= $char;
                    }

                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    if ($collectingKey) {
                        $currentKey .= $char;
                    }

                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;

                    if ($collectingKey) {
                        $keys[] = $currentKey;
                        $collectingKey = false;
                        $expectingKey = false;
                    }

                    continue;
                }

                if ($collectingKey) {
                    $currentKey .= $char;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                if ($depth === 1 && $expectingKey) {
                    $collectingKey = true;
                    $currentKey = '';
                }

                continue;
            }

            if ($char === '{' || $char === '[') {
                $depth++;

                if ($depth === 1) {
                    $expectingKey = true;
                }

                continue;
            }

            if ($char === '}' || $char === ']') {
                if ($depth === 1) {
                    $expectingKey = false;
                }

                $depth = max(0, $depth - 1);
                continue;
            }

            if ($char === ',' && $depth === 1) {
                $expectingKey = true;
            }
        }

        return $keys;
    }
}
