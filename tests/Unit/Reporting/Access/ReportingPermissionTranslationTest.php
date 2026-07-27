<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\Helpers\PermissionTranslator;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class ReportingPermissionTranslationTest extends TestCase
{
    private const ROLE_REPORT_PERMISSIONS = [
        'config/RoleDefinitions/lk/organization_owner.json' => [
            'reports.view', 'reports.run', 'reports.export', 'reports.download', 'reports.manage',
            'reports.sensitive', 'reports.audit',
        ],
        'config/RoleDefinitions/lk/organization_admin.json' => [
            'reports.view', 'reports.run', 'reports.export', 'reports.download', 'reports.manage',
            'reports.sensitive', 'reports.audit',
        ],
        'config/RoleDefinitions/lk/accountant.json' => [
            'reports.view', 'reports.run', 'reports.export', 'reports.download',
        ],
        'config/RoleDefinitions/lk/viewer.json' => ['reports.view'],
        'config/RoleDefinitions/project/parent_administrator.json' => [
            'reports.view', 'reports.run', 'reports.export', 'reports.download',
        ],
        'config/RoleDefinitions/project/project_manager.json' => ['reports.view', 'reports.run'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $loader = new FileLoader(new Filesystem(), $this->rootPath('lang'));
        $container->instance('translator', new Translator($loader, 'ru'));
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_role_definitions_grant_exact_reporting_permission_sets(): void
    {
        foreach (self::ROLE_REPORT_PERMISSIONS as $relativePath => $expected) {
            $role = json_decode(
                (string) file_get_contents($this->rootPath($relativePath)),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            self::assertSame($expected, $role['module_permissions']['reports'], $relativePath);
        }
    }

    public function test_all_reporting_permissions_have_human_readable_russian_labels(): void
    {
        $permissions = self::ROLE_REPORT_PERMISSIONS['config/RoleDefinitions/lk/organization_owner.json'];
        $translated = PermissionTranslator::translatePermissionsData([
            'module_permissions' => ['reports' => $permissions],
        ]);

        self::assertSame('Отчёты', $translated['module_groups']['reports']);
        foreach ($permissions as $permission) {
            self::assertIsString($translated['module_permissions']['reports'][$permission] ?? null, $permission);
            self::assertStringNotContainsString(
                $permission,
                $translated['module_permissions']['reports'][$permission],
                $permission,
            );
        }
    }

    public function test_all_stable_report_errors_have_russian_translations(): void
    {
        $translations = require $this->rootPath('lang/ru/reports.php');

        self::assertCount(20, ReportErrorCode::cases());
        foreach (ReportErrorCode::cases() as $code) {
            $key = strtolower($code->value);
            self::assertIsString($translations['errors'][$key] ?? null, $code->value);
            self::assertNotSame('', trim($translations['errors'][$key]), $code->value);
        }
    }

    public function test_report_error_translation_file_contains_no_technical_identifiers(): void
    {
        $translations = require $this->rootPath('lang/ru/reports.php');

        foreach ($translations['errors'] as $message) {
            self::assertDoesNotMatchRegularExpression('/REPORT_|payload|exception|sql/i', $message);
        }
    }

    private function rootPath(string $relativePath): string
    {
        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
