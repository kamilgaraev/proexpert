<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportPermissionDecision;
use App\BusinessModules\Core\Reporting\Application\Access\ReportCatalogAuthorization;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionVisibilityResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\CurrentReportAbacEvaluator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportModuleEntitlement;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportScopedResourceAuthorizerRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Execution\LaravelCurrentReportScopeAuthorizer;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Support\Reporting\DeterministicReportModuleEntitlement;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\TestCase;

#[Group('postgresql')]
final class LaravelCurrentReportScopeAuthorizerTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_current_authorization_port_is_atomic_and_typed(): void
    {
        $catalog = new ReflectionMethod(CurrentReportScopeAuthorizer::class, 'authorizeCatalog');
        self::assertSame(
            ['int', 'int', DateTimeZone::class, 'array'],
            array_map(static fn ($parameter): string => (string) $parameter->getType(), $catalog->getParameters()),
        );
        self::assertSame(ReportCatalogAuthorization::class, (string) $catalog->getReturnType());

        $organization = new ReflectionMethod(CurrentReportScopeAuthorizer::class, 'authorizeForOrganization');
        self::assertSame(
            ['int', 'int', DateTimeZone::class, CurrentReportAuthorizationTarget::class],
            array_map(static fn ($parameter): string => (string) $parameter->getType(), $organization->getParameters()),
        );
        self::assertSame(CurrentReportAuthorization::class, (string) $organization->getReturnType());

        $exact = new ReflectionMethod(CurrentReportScopeAuthorizer::class, 'authorizeExact');
        self::assertSame(
            ['int', ReportScope::class, CurrentReportAuthorizationTarget::class],
            array_map(static fn ($parameter): string => (string) $parameter->getType(), $exact->getParameters()),
        );
        self::assertSame(CurrentReportAuthorization::class, (string) $exact->getReturnType());
    }

    public function test_exact_many_executes_real_authorizer_and_returns_typed_list(): void
    {
        [$actor, $organization] = $this->actorFixture();
        $scope = new ReportScope(
            (int) $organization->id,
            [(int) $organization->id],
            [],
            [],
            new DateTimeZone('UTC'),
        );
        $targets = [
            $this->target(ReportOperation::RUN, $scope),
            $this->target(ReportOperation::VIEW_SENSITIVE, $scope),
        ];

        $authorizations = $this->authorizer()->authorizeExactMany(
            (int) $actor->id,
            $scope,
            $targets,
        );

        self::assertCount(2, $authorizations);
        self::assertContainsOnlyInstancesOf(CurrentReportAuthorization::class, $authorizations);
        self::assertSame($targets[0], $authorizations[0]->target);
        self::assertSame($targets[1], $authorizations[1]->target);
    }

    public function test_inactive_actor_is_denied(): void
    {
        [$actor, $organization] = $this->actorFixture();
        $actor->update(['is_active' => false]);

        $this->assertOrganizationDenied($actor, $organization);
    }

    public function test_deleted_actor_is_denied(): void
    {
        [$actor, $organization] = $this->actorFixture();
        $actor->delete();

        $this->assertOrganizationDenied($actor, $organization);
    }

    public function test_inactive_organization_membership_is_denied(): void
    {
        [$actor, $organization] = $this->actorFixture();
        DB::table('organization_user')
            ->where('user_id', $actor->id)
            ->where('organization_id', $organization->id)
            ->update(['is_active' => false]);

        $this->assertOrganizationDenied($actor, $organization);
    }

    public function test_inactive_anchor_organization_is_denied(): void
    {
        [$actor, $organization] = $this->actorFixture();
        $organization->update(['is_active' => false]);

        $this->assertOrganizationDenied($actor, $organization);
    }

    public function test_deleted_anchor_organization_is_denied(): void
    {
        [$actor, $organization] = $this->actorFixture();
        $organization->delete();

        $this->assertOrganizationDenied($actor, $organization);
    }

    public function test_holding_scope_contains_anchor_and_active_direct_child(): void
    {
        [$actor, $holding] = $this->actorFixture('all', ['is_holding' => true]);
        $child = $this->organization(['parent_organization_id' => $holding->id]);

        $authorization = $this->authorizeForOrganization($actor, $holding);

        self::assertSame(
            $this->sortedIds($holding, $child),
            $authorization->decision->holdingOrganizationIds,
        );
    }

    public function test_detached_child_is_not_inherited(): void
    {
        [$actor, $holding] = $this->actorFixture('all', ['is_holding' => true]);
        $child = $this->organization(['parent_organization_id' => $holding->id]);
        $child->update(['parent_organization_id' => null]);

        self::assertSame(
            [(int) $holding->id],
            $this->authorizeForOrganization($actor, $holding)->decision->holdingOrganizationIds,
        );
    }

    public function test_child_of_foreign_holding_is_not_inherited(): void
    {
        [$actor, $holding] = $this->actorFixture('all', ['is_holding' => true]);
        $foreignHolding = $this->organization(['is_holding' => true]);
        $this->organization(['parent_organization_id' => $foreignHolding->id]);

        self::assertSame(
            [(int) $holding->id],
            $this->authorizeForOrganization($actor, $holding)->decision->holdingOrganizationIds,
        );
    }

    public function test_grandchild_is_not_inherited(): void
    {
        [$actor, $holding] = $this->actorFixture('all', ['is_holding' => true]);
        $child = $this->organization(['parent_organization_id' => $holding->id]);
        $this->organization(['parent_organization_id' => $child->id]);

        self::assertSame(
            $this->sortedIds($holding, $child),
            $this->authorizeForOrganization($actor, $holding)->decision->holdingOrganizationIds,
        );
    }

    public function test_inactive_and_deleted_children_are_not_inherited(): void
    {
        [$actor, $holding] = $this->actorFixture('all', ['is_holding' => true]);
        $inactive = $this->organization([
            'parent_organization_id' => $holding->id,
            'is_active' => false,
        ]);
        $deleted = $this->organization(['parent_organization_id' => $holding->id]);
        $deleted->delete();

        self::assertSame(
            [(int) $holding->id],
            $this->authorizeForOrganization($actor, $holding)->decision->holdingOrganizationIds,
        );
        self::assertNotSame($inactive->id, $deleted->id);
    }

    public function test_child_access_is_inherited_only_through_current_holding_parent(): void
    {
        [$actor, $holding] = $this->actorFixture('all', ['is_holding' => true]);
        $child = $this->organization(['parent_organization_id' => $holding->id]);
        $scope = new ReportScope(
            (int) $holding->id,
            $this->sortedIds($holding, $child),
            [],
            [],
            new DateTimeZone('UTC'),
        );

        self::assertSame(
            $scope->holdingOrganizationIds,
            $this->authorizer()->authorizeExact((int) $actor->id, $scope, $this->target())->decision->holdingOrganizationIds,
        );

        $childScope = new ReportScope((int) $child->id, [(int) $child->id], [], [], new DateTimeZone('UTC'));
        $this->expectException(ReportContractException::class);
        $this->authorizer()->authorizeExact((int) $actor->id, $childScope, $this->target());
    }

    public function test_all_project_mode_includes_every_accessible_active_project(): void
    {
        [$actor, $organization] = $this->actorFixture('all');
        $first = $this->project($organization);
        $second = $this->project($organization);

        self::assertSame(
            $this->sortedIds($first, $second),
            $this->authorizeForOrganization($actor, $organization)->decision->projectIds,
        );
    }

    public function test_assigned_project_mode_includes_only_active_assignments(): void
    {
        [$actor, $organization] = $this->actorFixture('assigned');
        $assigned = $this->project($organization);
        $this->project($organization);
        $this->assign($actor, $assigned);

        self::assertSame(
            [(int) $assigned->id],
            $this->authorizeForOrganization($actor, $organization)->decision->projectIds,
        );
    }

    public function test_inactive_project_assignment_is_excluded(): void
    {
        [$actor, $organization] = $this->actorFixture('assigned');
        $project = $this->project($organization);
        $this->assign($actor, $project, false);

        self::assertSame([], $this->authorizeForOrganization($actor, $organization)->decision->projectIds);
    }

    public function test_archived_project_is_excluded(): void
    {
        [$actor, $organization] = $this->actorFixture('all');
        $this->project($organization, ['is_archived' => true]);

        self::assertSame([], $this->authorizeForOrganization($actor, $organization)->decision->projectIds);
    }

    public function test_owner_and_active_participant_projects_are_included(): void
    {
        [$actor, $organization] = $this->actorFixture('all');
        $owned = $this->project($organization);
        $foreign = $this->organization();
        $participating = $this->project($foreign);
        $participating->organizations()->attach($organization->id, [
            'role' => 'contractor',
            'role_new' => 'contractor',
            'is_active' => true,
        ]);

        self::assertSame(
            $this->sortedIds($owned, $participating),
            $this->authorizeForOrganization($actor, $organization)->decision->projectIds,
        );
    }

    public function test_foreign_project_is_excluded(): void
    {
        [$actor, $organization] = $this->actorFixture('all');
        $foreign = $this->organization();
        $this->project($foreign);

        self::assertSame([], $this->authorizeForOrganization($actor, $organization)->decision->projectIds);
    }

    public function test_exact_selected_project_subset_is_preserved(): void
    {
        [$actor, $organization] = $this->actorFixture('all');
        $selected = $this->project($organization);
        $this->project($organization);
        $scope = new ReportScope(
            (int) $organization->id,
            [(int) $organization->id],
            [(int) $selected->id],
            [],
            new DateTimeZone('UTC'),
        );

        self::assertSame(
            [(int) $selected->id],
            $this->authorizer()->authorizeExact((int) $actor->id, $scope, $this->target())->decision->projectIds,
        );
    }

    public function test_newly_accessible_unrelated_project_does_not_widen_exact_scope(): void
    {
        [$actor, $organization] = $this->actorFixture('all');
        $selected = $this->project($organization);
        $scope = new ReportScope(
            (int) $organization->id,
            [(int) $organization->id],
            [(int) $selected->id],
            [],
            new DateTimeZone('UTC'),
        );
        $this->project($organization);

        self::assertSame(
            [(int) $selected->id],
            $this->authorizer()->authorizeExact((int) $actor->id, $scope, $this->target())->decision->projectIds,
        );
    }

    public function test_each_base_and_definition_permission_flips_only_its_dependent_visibility_bits(): void
    {
        [$actor, $organization] = $this->actorFixture('all');
        $scope = new ReportScope(
            (int) $organization->id,
            [(int) $organization->id],
            [],
            [],
            new DateTimeZone('UTC'),
        );
        $dependencies = [
            'reports.view' => ['view', 'run', 'export', 'download', 'manage', 'sensitive', 'audit'],
            'definition.view' => ['view', 'run', 'export', 'download', 'manage', 'sensitive', 'audit'],
            'reports.run' => ['run'],
            'reports.export' => ['export', 'download'],
            'definition.export' => ['export', 'download'],
            'reports.download' => ['download'],
            'reports.manage' => ['manage'],
            'reports.sensitive' => ['sensitive'],
            'definition.sensitive' => ['sensitive'],
            'reports.audit' => ['audit'],
            'definition.audit' => ['audit'],
        ];
        $operations = [
            'view' => ReportOperation::VIEW,
            'run' => ReportOperation::RUN,
            'export' => ReportOperation::EXPORT,
            'download' => ReportOperation::DOWNLOAD,
            'manage' => ReportOperation::MANAGE,
            'sensitive' => ReportOperation::VIEW_SENSITIVE,
            'audit' => ReportOperation::VIEW_AUDIT,
        ];

        foreach ($dependencies as $deniedPermission => $deniedBits) {
            foreach ($operations as $bit => $operation) {
                $evaluator = new MatrixCurrentReportEvaluator($deniedPermission);
                $target = $this->target($operation, $scope);
                $shouldBeDenied = in_array($bit, $deniedBits, true);

                try {
                    $authorization = $this->authorizer($evaluator)->authorizeExact(
                        (int) $actor->id,
                        $scope,
                        $target,
                    );
                    self::assertFalse($shouldBeDenied, "{$deniedPermission} did not flip {$bit}");
                    self::assertTrue($this->visibilityBit($authorization, $bit));
                } catch (ReportContractException) {
                    self::assertTrue($shouldBeDenied, "{$deniedPermission} unexpectedly flipped {$bit}");
                }

                self::assertSame($this->expectedPermissionChecks(), $evaluator->permissions);
            }
        }
    }

    public function test_all_seven_visibility_bits_are_evaluated_before_operation_admission(): void
    {
        [$actor, $organization] = $this->actorFixture('all');
        $scope = new ReportScope(
            (int) $organization->id,
            [(int) $organization->id],
            [],
            [],
            new DateTimeZone('UTC'),
        );
        $evaluator = new MatrixCurrentReportEvaluator('reports.view');

        try {
            $this->authorizer($evaluator)->authorizeExact(
                (int) $actor->id,
                $scope,
                $this->target(ReportOperation::VIEW, $scope),
            );
            self::fail('Denied view operation was authorized.');
        } catch (ReportContractException) {
            self::assertSame($this->expectedPermissionChecks(), $evaluator->permissions);
        }
    }

    public function test_catalog_reuses_one_ambient_scope_and_builds_independent_visibility_vectors(): void
    {
        [$actor, $organization] = $this->actorFixture('all');
        $alpha = (new ReportDefinitionBuilder)
            ->code('alpha')
            ->definitionHash(new Sha256Hash(str_repeat('a', 64)))
            ->permissionPolicy(new ReportPermissionPolicy(
                ['definition.view'],
                ['definition.export'],
                ['alpha.sensitive'],
                ['definition.audit'],
            ))
            ->payload();
        $zeta = (new ReportDefinitionBuilder)
            ->code('zeta')
            ->definitionHash(new Sha256Hash(str_repeat('f', 64)))
            ->permissionPolicy(new ReportPermissionPolicy(
                ['definition.view'],
                ['definition.export'],
                ['zeta.sensitive'],
                ['definition.audit'],
            ))
            ->payload();
        $forbidden = (new ReportDefinitionBuilder)
            ->code('forbidden')
            ->definitionHash(new Sha256Hash(str_repeat('b', 64)))
            ->permissionPolicy(new ReportPermissionPolicy(
                ['forbidden.view'],
                [],
                [],
                [],
            ))
            ->payload();
        $evaluator = new MatrixCurrentReportEvaluator(['alpha.sensitive', 'forbidden.view']);

        $catalog = $this->authorizer($evaluator)->authorizeCatalog(
            (int) $actor->id,
            (int) $organization->id,
            new DateTimeZone('UTC'),
            [
                new CurrentReportAuthorizationTarget($zeta, ReportOperation::VIEW, null),
                new CurrentReportAuthorizationTarget($forbidden, ReportOperation::VIEW, null),
                new CurrentReportAuthorizationTarget($alpha, ReportOperation::VIEW, null),
            ],
        );

        self::assertSame(
            [$alpha->definitionHash->value, $zeta->definitionHash->value],
            array_keys($catalog->authorizations),
        );
        $alphaAuthorization = $catalog->authorizations[$alpha->definitionHash->value];
        $zetaAuthorization = $catalog->authorizations[$zeta->definitionHash->value];
        self::assertFalse($alphaAuthorization->visibility->canViewSensitive);
        self::assertTrue($zetaAuthorization->visibility->canViewSensitive);
        self::assertNotSame($alphaAuthorization, $zetaAuthorization);
        self::assertSame(
            $alphaAuthorization->decision->toAuthorizationArray(),
            $zetaAuthorization->decision->toAuthorizationArray(),
        );
        self::assertSame(
            [
                ...$this->expectedPermissionChecksFor('zeta.sensitive'),
                'forbidden.view',
                'alpha.sensitive',
            ],
            $evaluator->permissions,
        );
    }

    public function test_catalog_checks_each_source_module_once_and_rechecks_it_in_the_next_decision(): void
    {
        [$actor, $organization] = $this->actorFixture('all');
        $entitlements = new MutableCurrentReportModuleEntitlement([
            'act-reporting' => true,
            'reports' => true,
        ]);
        $authorizer = $this->authorizer(entitlements: $entitlements);
        $sourcePolicy = new ReportPermissionPolicy(
            ['act_reports.view'],
            ['act_reports.export.excel'],
            [],
            [],
        );
        $sourceFirst = (new ReportDefinitionBuilder)
            ->code('source_first')
            ->sourceModule('act-reporting')
            ->coreAccessMode(ReportCoreAccessMode::SOURCE_MODULE_REPORT)
            ->formats(['xlsx'])
            ->permissionPolicy($sourcePolicy)
            ->definitionHash(new Sha256Hash(str_repeat('1', 64)))
            ->payload();
        $sourceSecond = (new ReportDefinitionBuilder)
            ->code('source_second')
            ->sourceModule('act-reporting')
            ->coreAccessMode(ReportCoreAccessMode::SOURCE_MODULE_REPORT)
            ->formats(['xlsx'])
            ->permissionPolicy($sourcePolicy)
            ->definitionHash(new Sha256Hash(str_repeat('2', 64)))
            ->payload();
        $generic = (new ReportDefinitionBuilder)
            ->code('generic_report')
            ->sourceModule('reports')
            ->definitionHash(new Sha256Hash(str_repeat('3', 64)))
            ->payload();
        $targets = array_map(
            static fn (ReportDefinition $definition): CurrentReportAuthorizationTarget => new CurrentReportAuthorizationTarget(
                $definition,
                ReportOperation::VIEW,
                null,
            ),
            [$sourceFirst, $sourceSecond, $generic],
        );

        $allowed = $authorizer->authorizeCatalog(
            (int) $actor->id,
            (int) $organization->id,
            new DateTimeZone('UTC'),
            $targets,
        );

        self::assertCount(3, $allowed->authorizations);
        self::assertSame([
            ((int) $organization->id).':act-reporting' => 1,
            ((int) $organization->id).':reports' => 1,
        ], $entitlements->calls);

        $entitlements->allowed['act-reporting'] = false;
        $revoked = $authorizer->authorizeCatalog(
            (int) $actor->id,
            (int) $organization->id,
            new DateTimeZone('UTC'),
            $targets,
        );

        self::assertSame([$generic->definitionHash->value], array_keys($revoked->authorizations));
        self::assertSame([
            ((int) $organization->id).':act-reporting' => 2,
            ((int) $organization->id).':reports' => 2,
        ], $entitlements->calls);
    }

    private function assertOrganizationDenied(User $actor, Organization $organization): void
    {
        try {
            $this->authorizeForOrganization($actor, $organization);
            self::fail('Revoked organization scope was authorized.');
        } catch (ReportContractException) {
            self::assertTrue(true);
        }
    }

    private function authorizeForOrganization(User $actor, Organization $organization): CurrentReportAuthorization
    {
        return $this->authorizer()->authorizeForOrganization(
            (int) $actor->id,
            (int) $organization->id,
            new DateTimeZone('UTC'),
            $this->target(),
        );
    }

    private function actorFixture(string $projectAccessMode = 'all', array $organizationAttributes = []): array
    {
        $organization = $this->organization($organizationAttributes);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->organizations()->attach($organization->id, [
            'is_owner' => false,
            'is_active' => true,
            'project_access_mode' => $projectAccessMode,
        ]);

        return [$actor, $organization];
    }

    private function organization(array $attributes = []): Organization
    {
        return Organization::factory()->create(array_merge(['is_active' => true], $attributes));
    }

    private function project(Organization $organization, array $attributes = []): Project
    {
        return Project::factory()->create(array_merge([
            'organization_id' => $organization->id,
            'status' => 'active',
            'is_archived' => false,
        ], $attributes));
    }

    private function assign(User $actor, Project $project, bool $active = true): void
    {
        $actor->assignedProjects()->attach($project->id, [
            'role' => 'member',
            'is_active' => $active,
            'assigned_at' => now(),
        ]);
    }

    private function authorizer(
        ?CurrentReportAbacEvaluator $evaluator = null,
        ?ReportModuleEntitlement $entitlements = null,
    ): LaravelCurrentReportScopeAuthorizer {
        return new LaravelCurrentReportScopeAuthorizer(
            $evaluator ?? new AlwaysGrantedCurrentReportEvaluator,
            new LaravelReportScopedResourceAuthorizerRegistry([]),
            new ReportDefinitionVisibilityResolver(
                new ReportDefinitionModuleAuthorizer($entitlements ?? new DeterministicReportModuleEntitlement),
            ),
            new \App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationFactSetFactory,
        );
    }

    private function target(
        ReportOperation $operation = ReportOperation::RUN,
        ?ReportScope $scope = null,
    ): CurrentReportAuthorizationTarget {
        $definition = $this->permissionMatrixDefinition();
        $snapshot = in_array($operation, [
            ReportOperation::EXPORT,
            ReportOperation::DOWNLOAD,
            ReportOperation::DRILL_DOWN,
        ], true)
            ? $this->snapshot($definition, $scope ?? new ReportScope(1, [1], [], [], new DateTimeZone('UTC')))
            : null;

        return new CurrentReportAuthorizationTarget(
            $definition,
            $operation,
            $snapshot,
        );
    }

    private function permissionMatrixDefinition(): ReportDefinition
    {
        return (new ReportDefinitionBuilder)
            ->permissionPolicy(new ReportPermissionPolicy(
                ['definition.view'],
                ['definition.export'],
                ['definition.sensitive'],
                ['definition.audit'],
            ))
            ->payload();
    }

    private function snapshot(ReportDefinition $definition, ReportScope $scope): ReportSnapshotRef
    {
        return new ReportSnapshotRef(
            'report',
            'snapshot',
            $scope,
            $definition->definitionHash,
            $definition->formulaVersion,
            new Sha256Hash(str_repeat('c', 64)),
            new DateTimeImmutable('2026-07-29T12:00:00+00:00'),
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }

    private function visibilityBit(CurrentReportAuthorization $authorization, string $bit): bool
    {
        return match ($bit) {
            'view' => $authorization->visibility->canView,
            'run' => $authorization->visibility->canRun,
            'export' => $authorization->visibility->canExport,
            'download' => $authorization->visibility->canDownload,
            'manage' => $authorization->visibility->canManage,
            'sensitive' => $authorization->visibility->canViewSensitive,
            'audit' => $authorization->visibility->canViewAudit,
            default => throw new \LogicException('Unknown visibility bit.'),
        };
    }

    private function expectedPermissionChecks(): array
    {
        return [
            'reports.view',
            'reports.run',
            'reports.export',
            'reports.download',
            'reports.manage',
            'reports.sensitive',
            'reports.audit',
            'definition.view',
            'definition.export',
            'definition.sensitive',
            'definition.audit',
        ];
    }

    private function expectedPermissionChecksFor(string $sensitivePermission): array
    {
        $permissions = $this->expectedPermissionChecks();
        $permissions[9] = $sensitivePermission;

        return $permissions;
    }

    private function sortedIds(Organization|Project ...$models): array
    {
        $ids = array_map(static fn (Organization|Project $model): int => (int) $model->id, $models);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}

final class MutableCurrentReportModuleEntitlement implements ReportModuleEntitlement
{
    /** @var array<string, bool> */
    public array $allowed;

    /** @var array<string, int> */
    public array $calls = [];

    /** @param array<string, bool> $allowed */
    public function __construct(array $allowed)
    {
        $this->allowed = $allowed;
    }

    public function organizationHasModule(int $organizationId, string $moduleSlug): bool
    {
        $key = $organizationId.':'.$moduleSlug;
        $this->calls[$key] = ($this->calls[$key] ?? 0) + 1;

        return $this->allowed[$moduleSlug] ?? false;
    }
}

final class AlwaysGrantedCurrentReportEvaluator implements CurrentReportAbacEvaluator
{
    public function evaluate(
        int $actorId,
        string $permission,
        CurrentReportAuthorizationFacts $facts,
    ): CurrentReportPermissionDecision {
        return new CurrentReportPermissionDecision(
            $actorId,
            $permission,
            $facts->organizationId,
            $facts->projectId,
            $facts->resource,
            true,
        );
    }
}

final class MatrixCurrentReportEvaluator implements CurrentReportAbacEvaluator
{
    public array $permissions = [];

    /** @var list<string> */
    private readonly array $deniedPermissions;

    public function __construct(string|array $deniedPermissions)
    {
        $this->deniedPermissions = is_string($deniedPermissions) ? [$deniedPermissions] : $deniedPermissions;
    }

    public function evaluate(
        int $actorId,
        string $permission,
        CurrentReportAuthorizationFacts $facts,
    ): CurrentReportPermissionDecision {
        $this->permissions[] = $permission;

        return new CurrentReportPermissionDecision(
            $actorId,
            $permission,
            $facts->organizationId,
            $facts->projectId,
            $facts->resource,
            ! in_array($permission, $this->deniedPermissions, true),
        );
    }
}
