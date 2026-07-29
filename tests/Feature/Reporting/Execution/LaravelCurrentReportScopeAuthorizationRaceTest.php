<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportPermissionDecision;
use App\BusinessModules\Core\Reporting\Application\Access\ReportScopedResourceAccessDecision;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\CurrentReportAbacEvaluator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportScopedResourceAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportScopedResourceAuthorizerRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Execution\LaravelCurrentReportScopeAuthorizer;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\TestCase;

#[Group('postgresql')]
final class LaravelCurrentReportScopeAuthorizationRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_membership_revocation_after_snapshot_affects_only_next_authorization(): void
    {
        [$actor, $organization] = $this->actorFixture('all');
        $harness = $this->harness('membership');
        $mutated = false;
        $evaluator = new RecordingCurrentEvaluator(function () use (&$mutated, $harness, $actor, $organization): void {
            if ($mutated) {
                return;
            }
            $mutated = true;
            $this->commitConcurrentMutation($harness, 1, static fn (): int => DB::table('organization_user')
                ->where('user_id', $actor->id)
                ->where('organization_id', $organization->id)
                ->update(['is_active' => false]));
        });
        $authorizer = $this->authorizer($evaluator, []);
        $scope = new ReportScope((int) $organization->id, [(int) $organization->id], [], [], new DateTimeZone('UTC'));

        try {
            $authorization = $authorizer->authorizeExact((int) $actor->id, $scope, $this->target());
            self::assertSame($scope->organizationId, $authorization->decision->organizationId);
            self::assertSame($scope->holdingOrganizationIds, $authorization->decision->holdingOrganizationIds);
            self::assertSame($scope->projectIds, $authorization->decision->projectIds);
            self::assertSame($scope->resources, $authorization->decision->resources);
            $this->expectException(ReportContractException::class);
            $authorizer->authorizeExact((int) $actor->id, $scope, $this->target());
        } finally {
            $harness->cleanup();
        }
    }

    public function test_exact_many_locks_membership_until_serializable_authorization_commits(): void
    {
        [$actor, $organization] = $this->actorFixture('all');
        $harness = $this->harness('exact-many-membership-lock');
        $children = [];
        $triggered = false;
        $evaluator = new RecordingCurrentEvaluator(function () use (
            &$triggered,
            &$children,
            $harness,
            $actor,
            $organization,
        ): void {
            if ($triggered) {
                return;
            }
            $triggered = true;
            $children[] = $harness->spawn(
                5,
                static fn (): array => [
                    'updated' => DB::table('organization_user')
                        ->where('user_id', $actor->id)
                        ->where('organization_id', $organization->id)
                        ->update(['is_active' => false]),
                ],
            );
            $harness->release(5);
            $backendPid = $harness->waitForWorkerBackendPid(5);
            $harness->waitForPostgresWait(
                $harness->independentConnection('report_exact_many_observer'),
                $backendPid,
            );
        });
        $authorizer = $this->authorizer($evaluator, []);
        $scope = new ReportScope(
            (int) $organization->id,
            [(int) $organization->id],
            [],
            [],
            new DateTimeZone('UTC'),
        );

        try {
            $authorizations = DB::transaction(function () use ($authorizer, $actor, $scope): array {
                DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');

                return $authorizer->authorizeExactMany(
                    (int) $actor->id,
                    $scope,
                    [$this->target()],
                );
            }, 3);
            self::assertCount(1, $authorizations);

            $harness->waitForChildren($children);
            $children = [];
            self::assertSame(['updated' => 1], $harness->result(5));

            $this->expectException(ReportContractException::class);
            $authorizer->authorizeExactMany((int) $actor->id, $scope, [$this->target()]);
        } finally {
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    public function test_project_assignment_revocation_after_snapshot_affects_only_next_authorization(): void
    {
        [$actor, $organization] = $this->actorFixture('assigned');
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'status' => 'active',
            'is_archived' => false,
        ]);
        $actor->assignedProjects()->attach($project->id, [
            'role' => 'member',
            'is_active' => true,
            'assigned_at' => now(),
        ]);
        $harness = $this->harness('project');
        $mutated = false;
        $evaluator = new RecordingCurrentEvaluator(function () use (&$mutated, $harness, $actor, $project): void {
            if ($mutated) {
                return;
            }
            $mutated = true;
            $this->commitConcurrentMutation($harness, 2, static fn (): int => DB::table('project_user')
                ->where('user_id', $actor->id)
                ->where('project_id', $project->id)
                ->update(['is_active' => false]));
        });
        $authorizer = $this->authorizer($evaluator, []);
        $scope = new ReportScope(
            (int) $organization->id,
            [(int) $organization->id],
            [(int) $project->id],
            [],
            new DateTimeZone('UTC'),
        );

        try {
            self::assertTrue($authorizer->authorizeExact((int) $actor->id, $scope, $this->target())->visibility->canRun);
            $this->expectException(ReportContractException::class);
            $authorizer->authorizeExact((int) $actor->id, $scope, $this->target());
        } finally {
            $harness->cleanup();
        }
    }

    public function test_holding_child_reparent_after_snapshot_affects_only_next_authorization(): void
    {
        [$actor, $holding] = $this->actorFixture('all');
        $holding->update(['is_holding' => true]);
        $child = Organization::factory()->create([
            'is_active' => true,
            'parent_organization_id' => $holding->id,
        ]);
        $foreignParent = Organization::factory()->create([
            'is_active' => true,
            'is_holding' => true,
        ]);
        $harness = $this->harness('holding');
        $mutated = false;
        $evaluator = new RecordingCurrentEvaluator(function () use (
            &$mutated,
            $harness,
            $child,
            $foreignParent,
        ): void {
            if ($mutated) {
                return;
            }
            $mutated = true;
            $this->commitConcurrentMutation(
                $harness,
                4,
                static fn (): int => Organization::query()
                    ->whereKey($child->id)
                    ->update(['parent_organization_id' => $foreignParent->id]),
            );
        });
        $authorizer = $this->authorizer($evaluator, []);
        $scope = new ReportScope(
            (int) $holding->id,
            [(int) $holding->id, (int) $child->id],
            [],
            [],
            new DateTimeZone('UTC'),
        );

        try {
            self::assertTrue($authorizer->authorizeExact((int) $actor->id, $scope, $this->target())->visibility->canRun);
            $this->expectException(ReportContractException::class);
            $authorizer->authorizeExact((int) $actor->id, $scope, $this->target());
        } finally {
            $harness->cleanup();
        }
    }

    public function test_resource_transfer_after_snapshot_cannot_mix_with_new_permission_facts(): void
    {
        [$actor, $organization] = $this->actorFixture('assigned');
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'status' => 'active',
            'is_archived' => false,
        ]);
        $actor->assignedProjects()->attach($project->id, [
            'role' => 'member',
            'is_active' => true,
            'assigned_at' => now(),
        ]);
        $foreign = Organization::factory()->create(['is_active' => true]);
        $harness = $this->harness('resource');
        $handler = new ProjectResourceAuthorizer(function () use ($harness, $project, $foreign): void {
            $this->commitConcurrentMutation(
                $harness,
                3,
                static fn (): int => Project::query()->whereKey($project->id)->update(['organization_id' => $foreign->id]),
            );
        });
        $authorizer = $this->authorizer(new RecordingCurrentEvaluator, [$handler]);
        $scope = new ReportScope(
            (int) $organization->id,
            [(int) $organization->id],
            [(int) $project->id],
            [new ReportScopedResource('project', (int) $project->id, (int) $project->id)],
            new DateTimeZone('UTC'),
        );

        try {
            self::assertTrue($authorizer->authorizeExact((int) $actor->id, $scope, $this->target())->visibility->canRun);
            $this->expectException(ReportContractException::class);
            $authorizer->authorizeExact((int) $actor->id, $scope, $this->target());
        } finally {
            $harness->cleanup();
        }
    }

    public function test_project_permission_cannot_replace_denied_anchor_organization_permission(): void
    {
        [$actor, $organization] = $this->actorFixture('all');
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'status' => 'active',
            'is_archived' => false,
        ]);
        $scope = new ReportScope(
            (int) $organization->id,
            [(int) $organization->id],
            [(int) $project->id],
            [],
            new DateTimeZone('UTC'),
        );
        $evaluator = new RecordingCurrentEvaluator(
            grant: static fn (CurrentReportAuthorizationFacts $facts): bool => $facts->projectId !== null,
        );

        $this->expectException(ReportContractException::class);
        $this->authorizer($evaluator, [])->authorizeExact((int) $actor->id, $scope, $this->target());
    }

    public function test_existing_repeatable_read_transaction_contains_scope_reads_and_authorization(): void
    {
        [$actor, $organization] = $this->actorFixture('all');
        $scope = new ReportScope(
            (int) $organization->id,
            [(int) $organization->id],
            [],
            [],
            new DateTimeZone('UTC'),
        );
        $authorizer = $this->authorizer(new RecordingCurrentEvaluator, []);

        $authorization = DB::transaction(function () use ($authorizer, $actor, $scope) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $authorizer->authorizeExact((int) $actor->id, $scope, $this->target());
        });

        self::assertTrue($authorization->visibility->canRun);
    }

    private function actorFixture(string $projectAccessMode): array
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->organizations()->attach($organization->id, [
            'is_owner' => false,
            'is_active' => true,
            'project_access_mode' => $projectAccessMode,
        ]);

        return [$actor, $organization];
    }

    private function authorizer(CurrentReportAbacEvaluator $evaluator, iterable $resources): LaravelCurrentReportScopeAuthorizer
    {
        return new LaravelCurrentReportScopeAuthorizer(
            $evaluator,
            new LaravelReportScopedResourceAuthorizerRegistry($resources),
        );
    }

    private function target(): CurrentReportAuthorizationTarget
    {
        return new CurrentReportAuthorizationTarget(
            (new ReportDefinitionBuilder)->payload(),
            ReportOperation::RUN,
            null,
        );
    }

    private function commitConcurrentMutation(
        PostgresProcessRaceHarness $harness,
        int $index,
        callable $mutation,
    ): void {
        $children = [];
        try {
            $children[] = $harness->spawn($index, static fn (): array => ['updated' => $mutation()]);
            $harness->release($index);
            $harness->waitForChildren($children);
            $children = [];
            self::assertSame(['updated' => 1], $harness->result($index));
        } finally {
            $harness->terminateAndReap($children);
        }
    }

    private function harness(string $case): PostgresProcessRaceHarness
    {
        return new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR."report-scope-{$case}-".bin2hex(random_bytes(6)),
        );
    }
}

final class RecordingCurrentEvaluator implements CurrentReportAbacEvaluator
{
    private bool $triggered = false;

    public function __construct(
        private readonly mixed $onFirstEvaluation = null,
        private readonly mixed $grant = null,
    ) {}

    public function evaluate(
        int $actorId,
        string $permission,
        CurrentReportAuthorizationFacts $facts,
    ): CurrentReportPermissionDecision {
        if (! $this->triggered && is_callable($this->onFirstEvaluation)) {
            $this->triggered = true;
            ($this->onFirstEvaluation)();
        }

        $granted = is_callable($this->grant) ? ($this->grant)($facts, $permission) : true;

        return new CurrentReportPermissionDecision(
            $actorId,
            $permission,
            $facts->organizationId,
            $facts->projectId,
            $facts->resource,
            $granted,
        );
    }
}

final class ProjectResourceAuthorizer implements ReportScopedResourceAuthorizer
{
    private bool $triggered = false;

    public function __construct(private readonly mixed $onFirstAuthorization) {}

    public function kind(): string
    {
        return 'project';
    }

    public function authorize(
        User $actor,
        int $organizationId,
        ReportScopedResource $resource,
        CurrentReportAuthorizationFacts $facts,
    ): ReportScopedResourceAccessDecision {
        $project = Project::query()
            ->whereKey($resource->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where('is_archived', false)
            ->first();
        if (! $this->triggered) {
            $this->triggered = true;
            ($this->onFirstAuthorization)();
        }

        return new ReportScopedResourceAccessDecision(
            (int) $actor->id,
            $organizationId,
            $resource->projectId,
            $resource->kind,
            $resource->id,
            $project instanceof Project,
        );
    }
}
