<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Domain\Authorization\Services\RoleScanner;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Logging\LoggingService;
use App\Modules\Core\AccessController;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use LogicException;
use Mockery;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AIAssistant\Runtime\PublicCoreRuntimeBindingsTest;

final class PermissionResolverCurrentEntitlementInjectionTest extends TestCase
{
    private Application $application;
    private Repository $cache;

    protected function setUp(): void
    {
        $this->application = PublicCoreRuntimeBindingsTest::createIsolatedApplication();
        $this->cache = new Repository(new ArrayStore());
        $this->application->instance('cache', $this->cache);
        $this->application->instance('files', new Filesystem());
        $log = Mockery::mock();
        $log->shouldReceive('debug')->andReturnNull();
        Log::swap($log);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
    }

    public function testCurrentCloneUsesInjectedEntitlementWithoutGlobalResolution(): void
    {
        $this->application->bind(OrganizationEntitlementService::class,
            static function (): never { throw new LogicException('global_entitlement_resolution_forbidden'); });
        $injected = Mockery::mock(OrganizationEntitlementService::class);
        $injected->shouldReceive('getEffectiveModules')->once()->with(37)->andReturn(collect([['slug' => 'ai-assistant']]));
        $resolver = $this->resolver()->withCurrentEntitlementSource($injected)->forCurrentChecks()
            ->forReadScope(new Repository(new ArrayStore()));
        self::assertTrue($resolver->hasModulePermission(self::assignment(), 'ai_assistant.chat', ['organization_id' => 37]));
    }

    public function testClonePreservesSourceAcrossCurrentAndReadScopesWithoutMutatingOriginal(): void
    {
        $default = Mockery::mock(OrganizationEntitlementService::class);
        $default->shouldReceive('getEffectiveModules')->with(37)->andReturn(collect([]));
        $this->application->instance(OrganizationEntitlementService::class, $default);
        $injected = Mockery::mock(OrganizationEntitlementService::class);
        $injected->shouldReceive('getEffectiveModules')->with(37)->andReturn(collect([['slug' => 'ai-assistant']]));
        $original = $this->resolver();
        $selected = $original->withCurrentEntitlementSource($injected);
        self::assertNotSame($original, $selected);
        self::assertTrue($selected->forCurrentChecks()->forReadScope(new Repository(new ArrayStore()))
            ->hasModulePermission(self::assignment(), 'ai_assistant.chat', ['organization_id' => 37]));
        self::assertFalse($original->forCurrentChecks()->hasModulePermission(self::assignment(), 'ai_assistant.chat', ['organization_id' => 37]));
    }

    public function testNewCurrentCloneReloadsChangedEntitlementsInsteadOfReusingDecision(): void
    {
        $injected = Mockery::mock(OrganizationEntitlementService::class);
        $injected->shouldReceive('getEffectiveModules')->with(37)->andReturn(collect([['slug' => 'ai-assistant']]), collect([]));
        $selected = $this->resolver()->withCurrentEntitlementSource($injected);
        self::assertTrue($selected->forCurrentChecks()->forReadScope(new Repository(new ArrayStore()))
            ->hasModulePermission(self::assignment(), 'ai_assistant.chat', ['organization_id' => 37]));
        self::assertFalse($selected->forCurrentChecks()->forReadScope(new Repository(new ArrayStore()))
            ->hasModulePermission(self::assignment(), 'ai_assistant.chat', ['organization_id' => 37]));
    }

    public function testOrdinaryPathKeepsModuleCheckerAndIgnoresCurrentOnlyInjection(): void
    {
        $injected = Mockery::mock(OrganizationEntitlementService::class);
        $injected->shouldNotReceive('getEffectiveModules');
        $access = Mockery::mock(AccessController::class);
        $access->shouldReceive('hasModuleAccess')->once()->with(37, 'ai_assistant')->andReturn(true);
        $resolver = $this->resolver(new ModulePermissionChecker($access))->withCurrentEntitlementSource($injected);
        self::assertTrue($resolver->hasModulePermission(self::assignment(), 'ai_assistant.chat', ['organization_id' => 37]));
    }

    public function testSourceLocalRoleScannerHelpersBypassStaleSharedRoleCache(): void
    {
        $directory = sys_get_temp_dir().'/mostai93-role-'.bin2hex(random_bytes(8));
        $roleDirectory = $directory.'/config/RoleDefinitions/admin';
        if (!mkdir($roleDirectory, 0700, true) && !is_dir($roleDirectory)) {
            throw new LogicException('role_fixture_directory_unavailable');
        }
        $role = ['name' => 'Учебная роль', 'slug' => 'unit-fence-role', 'context' => 'organization', 'interface' => 'admin',
            'system_permissions' => ['ai_assistant.chat'], 'module_permissions' => ['ai_assistant' => ['chat']],
            'interface_access' => ['admin' => true]];
        file_put_contents($roleDirectory.'/unit-fence-role.json', json_encode($role, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->application->setBasePath($directory);
        $stale = $role;
        $stale['system_permissions'] = [];
        $stale['module_permissions'] = [];
        $stale['interface_access'] = [];
        $this->cache->put('authorization_roles:v2', collect(['unit-fence-role' => $stale]), 3600);
        self::assertSame([], (new RoleScanner())->getSystemPermissions('unit-fence-role'));
        $uncached = new class extends RoleScanner {
            public function getRole(string $slug): ?array
            {
                return $this->getRoleUncached($slug);
            }
        };
        self::assertSame(['ai_assistant.chat'], $uncached->getSystemPermissions('unit-fence-role'));
        self::assertSame(['ai_assistant' => ['chat']], $uncached->getModulePermissions('unit-fence-role'));
        self::assertSame(['admin' => true], $uncached->getInterfaceAccess('unit-fence-role'));
        self::assertSame([], (new RoleScanner())->getSystemPermissions('unit-fence-role'));
    }

    private function resolver(?ModulePermissionChecker $checker = null): PermissionResolver
    {
        $scanner = Mockery::mock(RoleScanner::class);
        $scanner->shouldReceive('getModulePermissions')->with('unit-fence-role')->andReturn(['ai_assistant' => ['chat']]);
        $logging = Mockery::mock(LoggingService::class);
        $logging->shouldReceive('technical')->andReturnNull();

        return new PermissionResolver($scanner, $checker ?? new ModulePermissionChecker(Mockery::mock(AccessController::class)), $logging);
    }

    private static function assignment(): UserRoleAssignment
    {
        $context = new AuthorizationContext(['type' => 'organization', 'resource_id' => 37]);
        $context->id = 9;
        $assignment = new UserRoleAssignment(['user_id' => 5, 'role_slug' => 'unit-fence-role', 'role_type' => 'system',
            'context_id' => 9, 'is_active' => true]);
        $assignment->setRelation('context', $context);

        return $assignment;
    }
}
