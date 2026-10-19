<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\ModulePermissionCatalog;
use App\Domain\Authorization\Services\RoleScanner;
use App\Http\Controllers\Api\V1\UserPermissionsController;
use App\Models\Module;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use PDOException;
use ReflectionMethod;
use Tests\TestCase;

class UserPermissionsCatalogBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fakeExcept([QueryExecuted::class]);
    }

    public function test_wildcard_catalog_is_batched_with_exact_order_alias_and_database_precedence(): void
    {
        $this->seedCatalog();
        $controller = app(UserPermissionsController::class);
        [$flat, $reads] = $this->captureModuleReads(fn (): array => $this->flatten($controller, $this->mixedPermissions()));

        $this->assertSame($this->expectedMixedPermissions(), $flat);
        $this->assertCount(1, $reads);
        $this->assertSame([2], $reads);
    }

    public function test_empty_null_and_missing_catalog_entries_preserve_configuration_fallback(): void
    {
        $config = json_decode(file_get_contents(config_path('ModuleList/addons/ai-estimates.json')), true, 512, JSON_THROW_ON_ERROR);
        $expected = array_values(array_unique(array_map(
            static fn ($permission): string => is_string($permission) ? $permission : $permission['name'],
            $config['permissions'],
        )));
        $this->assertNotEmpty($expected);
        $controller = app(UserPermissionsController::class);

        foreach ([[], null] as $permissions) {
            $this->catalogModule('ai-estimates', $permissions);
            [$flat, $reads] = $this->captureModuleReads(fn (): array => $this->flatten($controller, [
                'modules' => ['estimate-generation' => ['*'], 'missing-catalog-fixture' => ['*']],
            ]));
            $this->assertSame($expected, $flat);
            $this->assertSame([2], $reads);
        }
    }

    public function test_truthy_database_permissions_do_not_fall_back_after_normalizing_to_empty(): void
    {
        $this->catalogModule('ai-estimates', [['unsupported' => 'fixture']]);
        [$flat, $reads] = $this->captureModuleReads(fn (): array => $this->flatten(app(UserPermissionsController::class), [
            'system' => ['system.keep'],
            'modules' => ['estimate-generation' => ['*']],
        ]));

        $this->assertSame(['system.keep'], $flat);
        $this->assertSame([1], $reads);
    }

    public function test_explicit_permissions_do_not_query_the_module_catalog(): void
    {
        [$flat, $reads] = $this->captureModuleReads(fn (): array => $this->flatten(app(UserPermissionsController::class), [
            'system' => ['system.view', 'system.view'],
            'modules' => ['catalog-fixture' => ['catalog.explicit', ['name' => 'catalog.named']]],
        ]));

        $this->assertSame(['system.view', 'catalog.explicit', 'catalog.named'], $flat);
        $this->assertSame([], $reads);
    }

    public function test_subclass_module_catalog_override_keeps_the_original_dispatch(): void
    {
        $controller = new class(app(AuthorizationService::class), app(RoleScanner::class)) extends UserPermissionsController
        {
            protected function getModulePermissions(string $moduleSlug): array
            {
                return ['override.'.$moduleSlug];
            }
        };
        [$flat, $reads] = $this->captureModuleReads(fn (): array => $this->flatten($controller, [
            'modules' => ['estimate-generation' => ['*'], 'catalog-fixture' => ['*']],
        ]));

        $this->assertSame(['override.estimate-generation', 'override.catalog-fixture'], $flat);
        $this->assertSame([], $reads);
    }

    public function test_batch_failure_uses_the_original_per_module_lookup(): void
    {
        $this->seedCatalog();
        $catalog = Mockery::mock(ModulePermissionCatalog::class);
        $catalog->shouldReceive('load')->once()->andReturnNull();
        $this->app->instance(ModulePermissionCatalog::class, $catalog);
        [$flat, $reads] = $this->captureModuleReads(fn (): array => $this->flatten(app(UserPermissionsController::class), $this->mixedPermissions()));

        $this->assertSame($this->expectedMixedPermissions(), $flat);
        $this->assertSame([1, 1, 1, 1], $reads);
    }

    public function test_catalog_is_read_again_on_the_next_flatten_call(): void
    {
        $module = $this->catalogModule('catalog-fresh-fixture', ['catalog.before']);
        $controller = app(UserPermissionsController::class);
        $permissions = ['modules' => ['catalog-fresh-fixture' => ['*']]];
        [$first, $firstReads] = $this->captureModuleReads(fn (): array => $this->flatten($controller, $permissions));
        $module->update(['permissions' => ['catalog.after']]);
        [$second, $secondReads] = $this->captureModuleReads(fn (): array => $this->flatten($controller, $permissions));

        $this->assertSame(['catalog.before'], $first);
        $this->assertSame(['catalog.after'], $second);
        $this->assertSame([1], $firstReads);
        $this->assertSame([1], $secondReads);
    }

    public function test_real_postgres_batch_failure_recovers_before_the_legacy_lookup(): void
    {
        $this->seedCatalog();
        $connection = (new Module)->getConnection();
        $level = $connection->transactionLevel();
        $inject = true;
        $connection->beforeExecuting(static function (string $sql) use ($connection, &$inject): void {
            if ($inject && str_starts_with($sql, 'select "slug", "permissions" from "modules"')) {
                $inject = false;
                $connection->select('SELECT 1 / 0');
            }
        });
        try {
            [$flat, $reads] = $this->captureModuleReads(fn (): array => $this->flatten(app(UserPermissionsController::class), $this->mixedPermissions()));
            $this->assertFalse($inject);
            $this->assertSame($this->expectedMixedPermissions(), $flat);
            $this->assertSame([1, 1, 1, 1], $reads);
            $this->assertSame($level, $connection->transactionLevel());
            $this->assertSame(1, (int) $connection->selectOne('SELECT 1 AS recovered')->recovered);
        } finally {
            $inject = false;
        }
    }

    public function test_second_chunk_failure_does_not_publish_a_partial_catalog(): void
    {
        $this->catalogModule('catalog-bound-0', ['catalog.first']);
        $modules = [];
        foreach (range(0, 500) as $index) {
            $modules['catalog-bound-'.$index] = ['*'];
        }
        $connection = (new Module)->getConnection();
        $level = $connection->transactionLevel();
        $attempts = 0;
        $inject = true;
        $connection->beforeExecuting(static function (string $sql) use ($connection, &$attempts, &$inject): void {
            if ($inject && str_starts_with($sql, 'select "slug", "permissions" from "modules"') && ++$attempts === 2) {
                $inject = false;
                $connection->select('SELECT 1 / 0');
            }
        });
        try {
            [$catalog, $reads] = $this->captureModuleReads(fn () => app(ModulePermissionCatalog::class)->load($modules));
            $this->assertNull($catalog);
            $this->assertSame(2, $attempts);
            $this->assertSame([500], $reads);
            $this->assertSame($level, $connection->transactionLevel());
            $this->assertSame(1, (int) $connection->selectOne('SELECT 1 AS recovered')->recovered);
        } finally {
            $inject = false;
        }
    }

    public function test_nested_catalog_read_preserves_the_outer_transaction_and_commit_callbacks(): void
    {
        $connection = (new Module)->getConnection();
        $level = $connection->transactionLevel();
        $committed = false;
        $connection->beginTransaction();
        try {
            $this->catalogModule('catalog-outer-fixture', ['catalog.outer']);
            $connection->afterCommit(static function () use (&$committed): void {
                $committed = true;
            });
            $catalog = app(ModulePermissionCatalog::class)->load(['catalog-outer-fixture' => ['*']]);
            $this->assertSame(['catalog.outer'], $catalog['catalog-outer-fixture']->permissions);
            $this->assertSame($level + 1, $connection->transactionLevel());
            $this->assertFalse($committed);
            $this->assertTrue(Module::where('slug', 'catalog-outer-fixture')->exists());
        } finally {
            $connection->rollBack($level);
        }
        $this->assertFalse($committed);
        $this->assertFalse(Module::where('slug', 'catalog-outer-fixture')->exists());
    }

    public function test_concurrency_failure_is_not_swallowed_or_followed_by_legacy_queries(): void
    {
        $this->seedCatalog();
        $connection = (new Module)->getConnection();
        $level = $connection->transactionLevel();
        $inject = true;
        $connection->beforeExecuting(static function (string $sql) use (&$inject): void {
            if ($inject && str_starts_with($sql, 'select "slug", "permissions" from "modules"')) {
                $inject = false;
                throw new PDOException('deadlock detected', 40001);
            }
        });
        try {
            [$failure, $reads] = $this->captureModuleReads(function () {
                try {
                    $this->flatten(app(UserPermissionsController::class), $this->mixedPermissions());
                } catch (DeadlockException $e) {
                    return $e;
                }

                return null;
            });
            $this->assertInstanceOf(DeadlockException::class, $failure);
            $this->assertFalse($inject);
            $this->assertSame([], $reads);
            $this->assertSame($level, $connection->transactionLevel());
        } finally {
            $inject = false;
        }
    }

    public function test_catalog_queries_are_bounded_when_many_wildcard_modules_are_requested(): void
    {
        $this->catalogModule('catalog-bound-0', ['catalog.first']);
        $this->catalogModule('catalog-bound-500', ['catalog.last']);
        $modules = [];
        foreach (range(0, 500) as $index) {
            $modules['catalog-bound-'.$index] = ['*'];
        }
        [$catalog, $reads] = $this->captureModuleReads(fn (): array => app(ModulePermissionCatalog::class)->load($modules));

        $this->assertSame(['catalog-bound-0', 'catalog-bound-500'], array_keys($catalog));
        $this->assertSame(['catalog.first'], $catalog['catalog-bound-0']->permissions);
        $this->assertSame(['catalog.last'], $catalog['catalog-bound-500']->permissions);
        $this->assertSame([500, 1], $reads);
    }

    public function test_level_zero_catalog_load_reads_the_current_database_permissions(): void
    {
        $this->withUnwrappedCatalog(function (ModulePermissionCatalog $catalog, $connection, Module $model): void {
            $module = $model->newQuery()->create([
                'slug' => 'catalog-level-zero-fixture',
                'name' => 'catalog-level-zero-fixture',
                'version' => '1.0.0',
                'type' => 'feature',
                'billing_model' => 'free',
                'permissions' => ['catalog.zero'],
            ]);
            try {
                [$result, $reads] = $this->captureModuleReads(fn () => $catalog->load(['catalog-level-zero-fixture' => ['*']]));
                $this->assertSame(['catalog.zero'], $result['catalog-level-zero-fixture']->permissions);
                $this->assertSame([1], $reads);
                $this->assertSame(0, $connection->transactionLevel());
            } finally {
                $module->delete();
            }
        });
    }

    public function test_level_zero_ordinary_postgres_failure_allows_the_legacy_fallback(): void
    {
        $this->withUnwrappedCatalog(function (ModulePermissionCatalog $catalog, $connection): void {
            $inject = true;
            $connection->beforeExecuting(static function (string $sql) use ($connection, &$inject): void {
                if ($inject && str_starts_with($sql, 'select "slug", "permissions" from "modules"')) {
                    $inject = false;
                    $connection->select('SELECT 1 / 0');
                }
            });
            try {
                $this->assertNull($catalog->load(['catalog-level-zero-fixture' => ['*']]));
                $this->assertFalse($inject);
                $this->assertSame(0, $connection->transactionLevel());
                $this->assertSame(1, (int) $connection->selectOne('SELECT 1 AS recovered')->recovered);
            } finally {
                $inject = false;
            }
        });
    }

    public function test_level_zero_concurrency_and_connection_failures_propagate_without_legacy_reads(): void
    {
        $this->withUnwrappedCatalog(function (ModulePermissionCatalog $catalog, $connection): void {
            $this->app->instance(ModulePermissionCatalog::class, $catalog);
            foreach (['40001', '40P01', '08006'] as $state) {
                $injection = (object) ['enabled' => true];
                $connection->beforeExecuting(static function (string $sql) use ($connection, $state, $injection): void {
                    if ($injection->enabled && str_starts_with($sql, 'select "slug", "permissions" from "modules"')) {
                        $injection->enabled = false;
                        $connection->unprepared("DO \$catalog\$ BEGIN RAISE EXCEPTION USING MESSAGE = 'Controlled catalog failure', ERRCODE = '{$state}'; END \$catalog\$;");
                    }
                });
                try {
                    [$failure, $reads] = $this->captureModuleReads(function () {
                        try {
                            $this->flatten(app(UserPermissionsController::class), $this->mixedPermissions());
                        } catch (QueryException $e) {
                            return $e;
                        }

                        return null;
                    });
                    $this->assertInstanceOf(QueryException::class, $failure);
                    $this->assertSame($state, $failure->errorInfo[0]);
                    $this->assertFalse($injection->enabled);
                    $this->assertSame([], $reads);
                    $this->assertSame(0, $connection->transactionLevel());
                    $this->assertSame(1, (int) $connection->selectOne('SELECT 1 AS recovered')->recovered);
                } finally {
                    $injection->enabled = false;
                }
            }
        });
    }

    private function withUnwrappedCatalog(callable $operation): void
    {
        $alias = 'permission_catalog_unwrapped';
        $connections = config('database.connections');
        $configuration = DB::connection()->getConfig();
        unset($configuration['name']);
        try {
            config(['database.connections.'.$alias => $configuration]);
            $connection = DB::connection($alias);
            $this->assertSame('pgsql', $connection->getDriverName());
            $this->assertSame(0, $connection->transactionLevel());
            $model = (new Module)->setConnection($alias);
            $operation(new ModulePermissionCatalog($model), $connection, $model);
        } finally {
            DB::purge($alias);
            config(['database.connections' => $connections]);
        }
    }

    private function seedCatalog(): void
    {
        $this->catalogModule('catalog-first-fixture', ['catalog.first', ['name' => 'catalog.second'], ['unsupported' => 'fixture']]);
        $this->catalogModule('ai-estimates', ['catalog.alias']);
    }

    private function catalogModule(string $slug, ?array $permissions): Module
    {
        return Module::query()->updateOrCreate(['slug' => $slug], [
            'name' => $slug,
            'version' => '1.0.0',
            'type' => 'feature',
            'billing_model' => 'free',
            'permissions' => $permissions,
            'is_active' => false,
        ]);
    }

    private function mixedPermissions(): array
    {
        return [
            'system' => ['system.view', 'system.audit', 'system.view'],
            'modules' => [
                'catalog-first-fixture' => ['*', 'catalog.explicit', '*'],
                'estimate-generation' => ['*'],
                'ai-estimates' => ['*'],
            ],
        ];
    }

    private function expectedMixedPermissions(): array
    {
        return ['system.view', 'system.audit', 'catalog.first', 'catalog.second', 'catalog.explicit', 'catalog.alias'];
    }

    private function flatten(UserPermissionsController $controller, array $permissions): array
    {
        return (new ReflectionMethod(UserPermissionsController::class, 'flattenPermissions'))->invoke($controller, $permissions);
    }

    private function captureModuleReads(callable $operation): array
    {
        $reads = [];
        $listening = true;
        DB::listen(static function (QueryExecuted $query) use (&$reads, &$listening): void {
            if ($listening && str_starts_with(strtolower($query->sql), 'select ') && str_contains($query->sql, 'from "modules"')) {
                $reads[] = count($query->bindings);
            }
        });
        try {
            $result = $operation();
        } finally {
            $listening = false;
        }

        return [$result, $reads];
    }
}
