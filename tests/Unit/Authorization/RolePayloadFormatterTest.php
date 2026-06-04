<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Domain\Authorization\Services\RolePayloadFormatter;
use App\Services\PermissionTranslationService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

class RolePayloadFormatterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('app', new class {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('config', new Repository([
            'app' => [
                'fallback_locale' => 'ru',
            ],
        ]));
        $container->instance('translator', new Translator(
            new FileLoader(new Filesystem(), dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lang'),
            'ru'
        ));
        $container->instance('log', new class {
            public function warning(string $message): void
            {
            }
        });

        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_system_role_payload_contains_normalized_permission_groups(): void
    {
        $translator = new class extends PermissionTranslationService {
            public array $lastPayload = [];

            public function __construct()
            {
            }

            public function processPermissionsForFrontend(array $permissionsData): array
            {
                $this->lastPayload = $permissionsData;

                return [
                    'system_permissions' => array_combine(
                        $permissionsData['system_permissions'],
                        $permissionsData['system_permissions']
                    ),
                    'module_permissions' => [],
                    'module_groups' => [],
                    'interface_access' => array_combine(
                        $permissionsData['interface_access'],
                        $permissionsData['interface_access']
                    ),
                ];
            }
        };

        $formatter = new RolePayloadFormatter($translator);
        $payload = $formatter->formatSystemRole('admin_role', [
            'slug' => 'admin_role',
            'name' => 'Admin role',
            'context' => 'organization',
            'interface' => 'admin',
            'interface_access' => ['admin'],
            'system_permissions' => ['profile.view'],
            'module_permissions' => [],
        ]);

        $this->assertContains('admin.access', $translator->lastPayload['system_permissions']);
        $this->assertContains('admin.view', $translator->lastPayload['system_permissions']);
        $this->assertContains('dashboard.view', $translator->lastPayload['system_permissions']);
        $this->assertNotEmpty($payload['permission_groups']);
        $this->assertSame('Системные права', $payload['permission_groups'][1]['name']);
    }
}
