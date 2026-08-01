<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelCurrentReportAbacEvaluator;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\RoleCondition;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;

#[Group('postgresql')]
final class LaravelCurrentReportAbacEvaluatorRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_assignment_revocation_is_snapshot_consistent_and_next_invocation_denies(): void
    {
        [$actor, $organization, $assignment] = $this->authorizationFixture();
        $facts = $this->facts((int) $actor->id, (int) $organization->id);
        $evaluator = new LaravelCurrentReportAbacEvaluator;
        $harness = $this->harness();
        $children = [];
        $transactionOpen = false;

        try {
            DB::beginTransaction();
            $transactionOpen = true;
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            self::assertTrue($evaluator->evaluate((int) $actor->id, 'reports.view', $facts)->granted);

            $children[] = $harness->spawn(1, static function () use ($assignment): array {
                $updated = UserRoleAssignment::query()
                    ->whereKey($assignment->id)
                    ->update(['is_active' => false]);

                return ['updated' => $updated];
            });
            $harness->release(1);
            $harness->waitForChildren($children);
            $children = [];
            self::assertSame(['updated' => 1], $harness->result(1));
            self::assertTrue($evaluator->evaluate((int) $actor->id, 'reports.view', $facts)->granted);
            DB::commit();
            $transactionOpen = false;

            DB::transaction(function () use ($evaluator, $actor, $facts): void {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                self::assertFalse($evaluator->evaluate((int) $actor->id, 'reports.view', $facts)->granted);
            });
        } finally {
            if ($transactionOpen) {
                DB::rollBack();
            }
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    public function test_condition_revocation_is_snapshot_consistent_and_uncached_next_invocation_denies(): void
    {
        [$actor, $organization, $assignment] = $this->authorizationFixture();
        $occurredAt = new DateTimeImmutable('2026-07-29T12:00:00.123456Z');
        $condition = RoleCondition::query()->create([
            'assignment_id' => $assignment->id,
            'condition_type' => 'time',
            'condition_data' => [
                'valid_from' => '2026-07-29T11:00:00.000000Z',
                'valid_until' => '2026-07-29T13:00:00.000000Z',
            ],
            'is_active' => true,
        ]);
        $facts = $this->facts((int) $actor->id, (int) $organization->id, $occurredAt);
        $evaluator = new LaravelCurrentReportAbacEvaluator;
        $harness = $this->harness();
        $children = [];
        $transactionOpen = false;

        try {
            DB::beginTransaction();
            $transactionOpen = true;
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            self::assertTrue($evaluator->evaluate((int) $actor->id, 'reports.view', $facts)->granted);

            $children[] = $harness->spawn(2, static function () use ($condition): array {
                $updated = RoleCondition::query()
                    ->whereKey($condition->id)
                    ->update(['condition_data' => ['valid_until' => '2026-07-29T11:59:59.999999Z']]);

                return ['updated' => $updated];
            });
            $harness->release(2);
            $harness->waitForChildren($children);
            $children = [];
            self::assertSame(['updated' => 1], $harness->result(2));
            self::assertTrue($evaluator->evaluate((int) $actor->id, 'reports.view', $facts)->granted);
            DB::commit();
            $transactionOpen = false;

            DB::transaction(function () use ($evaluator, $actor, $facts): void {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                self::assertFalse($evaluator->evaluate((int) $actor->id, 'reports.view', $facts)->granted);
            });
        } finally {
            if ($transactionOpen) {
                DB::rollBack();
            }
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    public function test_custom_permission_definition_revocation_is_snapshot_consistent_and_next_invocation_denies(): void
    {
        [$actor, $organization, , $role] = $this->authorizationFixture();
        $facts = $this->facts((int) $actor->id, (int) $organization->id);
        $evaluator = new LaravelCurrentReportAbacEvaluator;
        $harness = $this->harness();
        $children = [];
        $transactionOpen = false;

        try {
            DB::beginTransaction();
            $transactionOpen = true;
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            self::assertTrue($evaluator->evaluate((int) $actor->id, 'reports.view', $facts)->granted);

            $children[] = $harness->spawn(3, static function () use ($role): array {
                $updated = OrganizationCustomRole::query()
                    ->whereKey($role->id)
                    ->update(['system_permissions' => json_encode([])]);

                return ['updated' => $updated];
            });
            $harness->release(3);
            $harness->waitForChildren($children);
            $children = [];
            self::assertSame(['updated' => 1], $harness->result(3));
            self::assertTrue($evaluator->evaluate((int) $actor->id, 'reports.view', $facts)->granted);
            DB::commit();
            $transactionOpen = false;

            DB::transaction(function () use ($evaluator, $actor, $facts): void {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                self::assertFalse($evaluator->evaluate((int) $actor->id, 'reports.view', $facts)->granted);
            });
        } finally {
            if ($transactionOpen) {
                DB::rollBack();
            }
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    public function test_system_role_change_is_snapshot_consistent_and_next_invocation_denies(): void
    {
        [$actor, $organization, $customAssignment] = $this->authorizationFixture();
        $customAssignment->update(['is_active' => false]);
        $assignment = UserRoleAssignment::query()->create([
            'user_id' => $actor->id,
            'role_slug' => 'viewer',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'context_id' => AuthorizationContext::getOrganizationContext((int) $organization->id)->id,
            'assigned_by' => $actor->id,
            'expires_at' => null,
            'is_active' => true,
        ]);
        $facts = $this->facts((int) $actor->id, (int) $organization->id);
        $evaluator = new LaravelCurrentReportAbacEvaluator;
        $harness = $this->harness();
        $children = [];
        $transactionOpen = false;

        try {
            DB::beginTransaction();
            $transactionOpen = true;
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            self::assertTrue($evaluator->evaluate((int) $actor->id, 'reports.view', $facts)->granted);

            $children[] = $harness->spawn(4, static function () use ($assignment): array {
                $updated = UserRoleAssignment::query()
                    ->whereKey($assignment->id)
                    ->update(['role_slug' => 'brigade_catalog_moderator']);

                return ['updated' => $updated];
            });
            $harness->release(4);
            $harness->waitForChildren($children);
            $children = [];
            self::assertSame(['updated' => 1], $harness->result(4));
            self::assertTrue($evaluator->evaluate((int) $actor->id, 'reports.view', $facts)->granted);
            DB::commit();
            $transactionOpen = false;

            DB::transaction(function () use ($evaluator, $actor, $facts): void {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                self::assertFalse($evaluator->evaluate((int) $actor->id, 'reports.view', $facts)->granted);
            });
        } finally {
            if ($transactionOpen) {
                DB::rollBack();
            }
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    private function authorizationFixture(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['is_active' => true]);
        $actor->organizations()->attach($organization->id, [
            'is_active' => true,
            'is_owner' => false,
            'project_access_mode' => 'all',
        ]);
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Report reader',
            'slug' => 'report_reader',
            'system_permissions' => ['reports.view'],
            'module_permissions' => [],
            'interface_access' => ['lk'],
            'conditions' => null,
            'is_active' => true,
            'created_by' => $actor->id,
        ]);
        $assignment = UserRoleAssignment::query()->create([
            'user_id' => $actor->id,
            'role_slug' => $role->slug,
            'role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'context_id' => AuthorizationContext::getOrganizationContext((int) $organization->id)->id,
            'assigned_by' => $actor->id,
            'expires_at' => null,
            'is_active' => true,
        ]);

        return [$actor, $organization, $assignment, $role];
    }

    private function facts(
        int $actorId,
        int $organizationId,
        ?DateTimeImmutable $occurredAt = null,
    ): CurrentReportAuthorizationFacts {
        return new CurrentReportAuthorizationFacts(
            'queue',
            $actorId,
            $organizationId,
            null,
            null,
            $occurredAt ?? new DateTimeImmutable('2026-07-29T12:00:00.123456Z'),
        );
    }

    private function harness(): PostgresProcessRaceHarness
    {
        return new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'report-abac-race-'.bin2hex(random_bytes(6)),
        );
    }
}
