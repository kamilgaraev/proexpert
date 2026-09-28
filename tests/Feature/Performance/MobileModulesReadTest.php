<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\RoleScanner;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Models\Module;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Mobile\MobileModulesService;
use App\Services\Project\UserProjectAccessService;
use App\Services\Logging\LoggingService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MobileModulesReadTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['role_conditions', 'user_role_assignments', 'authorization_contexts', 'organization_custom_roles', 'projects', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('authorization_contexts', static function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedBigInteger('parent_context_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('user_role_assignments', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('context_id');
            $table->string('role_slug');
            $table->string('role_type');
            $table->boolean('is_active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('role_conditions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assignment_id');
            $table->string('condition_type');
            $table->jsonb('condition_data');
            $table->boolean('is_active');
        });
        Schema::create('organization_custom_roles', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
        });
        Schema::create('projects', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->softDeletes();
        });
        Schema::create('organizations', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_organization_id')->nullable();
            $table->softDeletes();
        });
        DB::table('projects')->insert(['id' => 1, 'organization_id' => 10]);
        DB::table('organizations')->insert(['id' => 10]);
        Cache::flush();
    }

    #[DataProvider('permissionSources')]
    public function test_project_catalog_preserves_permissions_with_bounded_database_and_cache_reads(bool $systemWildcard): void
    {
        $permissions = $this->permissions();
        $scanner = Mockery::mock(RoleScanner::class);
        $scanner->shouldReceive('getSystemPermissions')->andReturn($systemWildcard ? ['*'] : ['mobile.access']);
        $scanner->shouldReceive('getInterfaceAccess')->andReturn(['mobile']);
        $scanner->shouldReceive('getModulePermissions')->andReturn($permissions);
        $this->app->instance(RoleScanner::class, $scanner);

        $access = Mockery::mock(AccessController::class);
        $access->shouldReceive('hasModuleAccess')->andReturn(true);
        $access->shouldReceive('getActiveModules')->andReturn(collect(array_map(
            static fn (string $slug): Module => new Module(['slug' => $slug]),
            array_keys($permissions),
        )));
        $this->app->instance(AccessController::class, $access);
        $projectAccess = Mockery::mock(UserProjectAccessService::class);
        $projectAccess->shouldReceive('canAccessProject')->andReturn(true);
        $authorization = $this->authorization($scanner, $access);

        $user = $this->user(1, 10);
        UserRoleAssignment::create([
            'user_id' => 1,
            'context_id' => AuthorizationContext::getOrganizationContext(10)->id,
            'role_slug' => 'foreman',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'is_active' => true,
        ]);
        AuthorizationContext::getProjectContext(1, 10);

        $store = new class extends ArrayStore {
            public int $reads = 0;

            public function get($key)
            {
                $this->reads++;

                return parent::get($key);
            }
        };
        Cache::extend('measured_array', fn (): Repository => new Repository($store));
        config(['cache.default' => 'measured_array', 'cache.stores.measured_array' => ['driver' => 'measured_array']]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = (new MobileModulesService($authorization, $access, $projectAccess))->build($user, 1);
        $queries = count(DB::getQueryLog());
        fwrite(STDERR, sprintf("Mobile modules: %d SQL, %d shared cache reads\n", $queries, $store->reads));

        self::assertContains('quality-control', array_column($result['modules'], 'slug'));
        self::assertContains('safety-management', array_column($result['modules'], 'slug'));
        self::assertSame(array_values($permissions['quality-control']), collect($result['modules'])->firstWhere('slug', 'quality-control')['permissions']);
        self::assertLessThanOrEqual(35, $queries, 'Database reads: '.$queries);
        self::assertLessThanOrEqual(100, $store->reads, 'Shared cache reads: '.$store->reads);
    }

    public function test_read_scope_rechecks_conditions_and_keeps_project_boundaries(): void
    {
        $scanner = Mockery::mock(RoleScanner::class);
        $scanner->shouldReceive('getSystemPermissions')->andReturn(['*']);
        $scanner->shouldReceive('getInterfaceAccess')->andReturn(['mobile']);
        $this->app->instance(RoleScanner::class, $scanner);

        $assignment = UserRoleAssignment::create([
            'user_id' => 2,
            'context_id' => AuthorizationContext::getProjectContext(1, 10)->id,
            'role_slug' => 'foreman',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'is_active' => true,
        ]);
        $conditionId = DB::table('role_conditions')->insertGetId([
            'assignment_id' => $assignment->id,
            'condition_type' => 'time',
            'condition_data' => json_encode(['valid_from' => now()->addDay()->toIso8601String()]),
            'is_active' => true,
        ]);
        $user = $this->user(2, 10);
        $authorization = $this->authorization($scanner, Mockery::mock(AccessController::class));
        $context = ['organization_id' => 10, 'project_id' => 1, 'strict_project_scope' => true];

        self::assertFalse($authorization->forReadScope()->can($user, 'quality-control.view', $context));
        DB::table('role_conditions')->where('id', $conditionId)->delete();
        Cache::driver('array')->flush();
        self::assertTrue($authorization->forReadScope()->can($user, 'quality-control.view', $context));

        Cache::driver('array')->flush();
        self::assertFalse($authorization->forReadScope()->can($user, 'quality-control.view', [
            'organization_id' => 10, 'project_id' => 2, 'strict_project_scope' => true,
        ]));
        Cache::driver('array')->flush();
        self::assertFalse($authorization->forReadScope()->can($this->user(3, 20), 'quality-control.view', [
            'organization_id' => 20, 'project_id' => 1, 'strict_project_scope' => true,
        ]));
    }

    public function test_catalog_without_permissions_does_not_read_module_entitlements(): void
    {
        $access = Mockery::mock(AccessController::class);
        $access->shouldNotReceive('getActiveModules');
        $authorization = $this->authorization(Mockery::mock(RoleScanner::class), $access);
        $service = new MobileModulesService($authorization, $access, Mockery::mock(UserProjectAccessService::class));

        self::assertSame([], $service->build($this->user(1, 10))['modules']);
    }

    private function permissions(): array
    {
        $permissions = [];
        foreach (['quality-control', 'safety-management', 'machinery-operations', 'production-labor', 'workforce-management', 'handover-acceptance', 'workflow-management', 'time-tracking'] as $slug) {
            $permissions[$slug] = array_map(static fn (string $action): string => $slug.'.'.$action, ['view', 'create', 'edit', 'delete', 'approve']);
        }

        return $permissions;
    }

    public static function permissionSources(): array
    {
        return ['system wildcard' => [true], 'module permissions' => [false]];
    }

    private function authorization(RoleScanner $scanner, AccessController $access): AuthorizationService
    {
        $logging = app(LoggingService::class);

        return new AuthorizationService($scanner, new PermissionResolver($scanner, new ModulePermissionChecker($access), $logging), $logging);
    }

    private function user(int $id, int $organizationId): User
    {
        $user = new User;
        $user->id = $id;
        $user->current_organization_id = $organizationId;

        return $user;
    }
}
