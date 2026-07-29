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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
final class LaravelCurrentReportAbacEvaluatorBehaviorTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    #[DataProvider('authorizationCases')]
    public function test_closed_abac_behavior_matrix(string $case, bool $expected): void
    {
        [$actor, $organization, $assignment] = $this->authorizationFixture();
        $projectId = null;

        match ($case) {
            'organization_role' => null,
            'revoked_assignment' => $assignment->delete(),
            'expired_assignment' => $assignment->update(['expires_at' => '2026-07-29 11:59:59']),
            'inactive_assignment' => $assignment->update(['is_active' => false]),
            'system_permission_removed' => $this->replaceWithSystemRole($assignment, 'brigade_catalog_moderator'),
            'custom_permission_removed' => OrganizationCustomRole::query()
                ->where('slug', $assignment->role_slug)
                ->update(['system_permissions' => json_encode([])]),
            'inactive_condition' => $this->condition($assignment, 'time', [], false),
            'valid_time_condition' => $this->condition($assignment, 'time', [
                'valid_from' => '2026-07-29T11:00:00Z',
                'valid_until' => '2026-07-29T13:00:00Z',
            ]),
            'expired_time_condition' => $this->condition($assignment, 'time', [
                'valid_until' => '2026-07-29T11:59:59Z',
            ]),
            'fresh_project_count_condition' => $this->condition($assignment, 'project_count', ['max_projects' => 1]),
            'request_only_condition' => $this->condition($assignment, 'location', ['country' => 'RU']),
            'malformed_condition' => $this->condition($assignment, 'time', []),
            'missing_project_fact' => $this->moveToProjectContext($assignment, 7001, (int) $organization->id),
            'singular_project_role' => $projectId = $this->moveToProjectContext(
                $assignment,
                7002,
                (int) $organization->id,
            ),
            'foreign_project_parent' => $projectId = $this->moveToProjectContext(
                $assignment,
                7003,
                (int) Organization::factory()->create()->id,
            ),
            'one_of_two_assignments_grants' => $this->addMalformedAssignment(
                (int) $actor->id,
                (int) $organization->id,
            ),
            default => self::fail('Unknown ABAC matrix case: '.$case),
        };

        $facts = new CurrentReportAuthorizationFacts(
            'queue',
            (int) $actor->id,
            (int) $organization->id,
            $projectId,
            null,
            new DateTimeImmutable('2026-07-29T12:00:00Z'),
        );

        DB::transaction(function () use ($actor, $facts, $expected): void {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            self::assertSame(
                $expected,
                (new LaravelCurrentReportAbacEvaluator)
                    ->evaluate((int) $actor->id, 'reports.view', $facts)
                    ->granted,
            );
        });
    }

    public static function authorizationCases(): iterable
    {
        yield 'current organization role' => ['organization_role', true];
        yield 'revoked assignment' => ['revoked_assignment', false];
        yield 'expired assignment' => ['expired_assignment', false];
        yield 'inactive assignment' => ['inactive_assignment', false];
        yield 'system permission removed' => ['system_permission_removed', false];
        yield 'custom permission removed' => ['custom_permission_removed', false];
        yield 'inactive condition ignored' => ['inactive_condition', true];
        yield 'valid time condition' => ['valid_time_condition', true];
        yield 'expired time condition' => ['expired_time_condition', false];
        yield 'fresh project count condition' => ['fresh_project_count_condition', true];
        yield 'request-only condition fails closed' => ['request_only_condition', false];
        yield 'malformed condition fails closed' => ['malformed_condition', false];
        yield 'missing required project fact' => ['missing_project_fact', false];
        yield 'singular project role' => ['singular_project_role', true];
        yield 'project parent organization mismatch' => ['foreign_project_parent', false];
        yield 'one bad assignment does not mask a valid assignment' => ['one_of_two_assignments_grants', true];
    }

    private function authorizationFixture(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['is_active' => true]);
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Report reader',
            'slug' => 'matrix_report_reader',
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

        return [$actor, $organization, $assignment];
    }

    private function replaceWithSystemRole(UserRoleAssignment $assignment, string $slug): void
    {
        $assignment->update([
            'role_slug' => $slug,
            'role_type' => UserRoleAssignment::TYPE_SYSTEM,
        ]);
    }

    private function condition(
        UserRoleAssignment $assignment,
        string $type,
        array $data,
        bool $active = true,
    ): void {
        RoleCondition::query()->create([
            'assignment_id' => $assignment->id,
            'condition_type' => $type,
            'condition_data' => $data,
            'is_active' => $active,
        ]);
    }

    private function moveToProjectContext(
        UserRoleAssignment $assignment,
        int $projectId,
        int $organizationId,
    ): int {
        $assignment->update([
            'context_id' => AuthorizationContext::getProjectContext($projectId, $organizationId)->id,
        ]);

        return $projectId;
    }

    private function addMalformedAssignment(int $actorId, int $organizationId): void
    {
        DB::table('organization_custom_roles')->insert([
            'organization_id' => $organizationId,
            'name' => 'Malformed role',
            'slug' => 'a_malformed_role',
            'system_permissions' => '"invalid"',
            'module_permissions' => '"invalid"',
            'interface_access' => '[]',
            'conditions' => null,
            'is_active' => true,
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $actorId,
            'role_slug' => 'a_malformed_role',
            'role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'context_id' => AuthorizationContext::getOrganizationContext($organizationId)->id,
            'assigned_by' => $actorId,
            'expires_at' => null,
            'is_active' => true,
        ]);
    }
}
