<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Execution;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Application\Access\ReportCatalogAuthorization;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionVisibilityResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\CurrentReportAbacEvaluator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportExactManyAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportScopedResourceAuthorizerRegistry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class LaravelCurrentReportScopeAuthorizer implements CurrentReportExactManyAuthorizer, CurrentReportScopeAuthorizer
{
    public function __construct(
        private CurrentReportAbacEvaluator $abac,
        private LaravelReportScopedResourceAuthorizerRegistry $resources,
        private ReportDefinitionVisibilityResolver $visibilityResolver,
    ) {}

    public function authorizeForOrganization(
        int $actorId,
        int $organizationId,
        DateTimeZone $timezone,
        CurrentReportAuthorizationTarget $target,
    ): CurrentReportAuthorization {
        return $this->transaction(function () use ($actorId, $organizationId, $timezone, $target): CurrentReportAuthorization {
            $actor = $this->activeActor($actorId, $organizationId);
            $holdingIds = $this->accessibleHoldingOrganizationIds($organizationId);
            $projectIds = $this->accessibleProjectIds($actorId, $organizationId, $holdingIds);
            $scope = new ReportScope($organizationId, $holdingIds, $projectIds, [], $timezone);

            return $this->authorizeInsideTransaction($actor, $scope, $target);
        });
    }

    public function authorizeCatalog(
        int $actorId,
        int $organizationId,
        DateTimeZone $timezone,
        array $targets,
    ): ReportCatalogAuthorization {
        return $this->transaction(function () use ($actorId, $organizationId, $timezone, $targets): ReportCatalogAuthorization {
            $actor = $this->activeActor($actorId, $organizationId);
            $holdingIds = $this->accessibleHoldingOrganizationIds($organizationId);
            $projectIds = $this->accessibleProjectIds($actorId, $organizationId, $holdingIds);
            $scope = new ReportScope($organizationId, $holdingIds, $projectIds, [], $timezone);
            $occurredAt = new DateTimeImmutable;
            $correlationId = (string) Str::uuid();
            $this->resources->authorizeAll($actor, $scope->organizationId, $scope->resources, $occurredAt);
            $authorizations = [];
            $context = null;

            foreach ($targets as $target) {
                if (! $target instanceof CurrentReportAuthorizationTarget
                    || $target->operation !== ReportOperation::VIEW
                    || $target->snapshot !== null) {
                    throw new \InvalidArgumentException('report_catalog_target_invalid');
                }
                $authorization = $this->authorization(
                    $actor,
                    $scope,
                    $target,
                    $occurredAt,
                    false,
                    $correlationId,
                );
                if (! $authorization->visibility->canView) {
                    continue;
                }
                $hash = $target->definition->definitionHash->value;
                if (isset($authorizations[$hash])) {
                    throw new \InvalidArgumentException('report_catalog_target_invalid');
                }
                $authorizations[$hash] = $authorization;
                $context ??= new ReportExecutionContext(
                    $authorization->actor,
                    $scope,
                    $authorization->visibility,
                    $authorization->decision,
                );
            }

            if (! $context instanceof ReportExecutionContext) {
                throw new \InvalidArgumentException('report_catalog_authorization_invalid');
            }

            return new ReportCatalogAuthorization($context, $authorizations);
        });
    }

    public function authorizeExact(
        int $actorId,
        ReportScope $requestedScope,
        CurrentReportAuthorizationTarget $target,
    ): CurrentReportAuthorization {
        return $this->transaction(function () use ($actorId, $requestedScope, $target): CurrentReportAuthorization {
            $actor = $this->activeActor($actorId, $requestedScope->organizationId);
            $allowedHoldingIds = $this->accessibleHoldingOrganizationIds($requestedScope->organizationId);
            foreach ($requestedScope->holdingOrganizationIds as $organizationId) {
                if (! in_array($organizationId, $allowedHoldingIds, true)) {
                    throw new \InvalidArgumentException('report_holding_scope_revoked');
                }
            }
            $allowedProjectIds = $this->accessibleProjectIds(
                $actorId,
                $requestedScope->organizationId,
                $requestedScope->holdingOrganizationIds,
            );
            foreach ($requestedScope->projectIds as $projectId) {
                if (! in_array($projectId, $allowedProjectIds, true)) {
                    throw new \InvalidArgumentException('report_project_scope_revoked');
                }
            }

            return $this->authorizeInsideTransaction($actor, $requestedScope, $target);
        });
    }

    public function authorizeExactMany(
        int $actorId,
        ReportScope $requestedScope,
        array $targets,
    ): array {
        if (! array_is_list($targets) || $targets === []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        return $this->transaction(function () use ($actorId, $requestedScope, $targets): array {
            $this->lockExactAuthorizationFacts($actorId, $requestedScope);
            $actor = $this->activeActor($actorId, $requestedScope->organizationId);
            $allowedHoldingIds = $this->accessibleHoldingOrganizationIds($requestedScope->organizationId);
            foreach ($requestedScope->holdingOrganizationIds as $organizationId) {
                if (! in_array($organizationId, $allowedHoldingIds, true)) {
                    throw new \InvalidArgumentException('report_holding_scope_revoked');
                }
            }
            $allowedProjectIds = $this->accessibleProjectIds(
                $actorId,
                $requestedScope->organizationId,
                $requestedScope->holdingOrganizationIds,
            );
            foreach ($requestedScope->projectIds as $projectId) {
                if (! in_array($projectId, $allowedProjectIds, true)) {
                    throw new \InvalidArgumentException('report_project_scope_revoked');
                }
            }

            $occurredAt = new DateTimeImmutable;
            $this->resources->authorizeAll(
                $actor,
                $requestedScope->organizationId,
                $requestedScope->resources,
                $occurredAt,
            );
            $authorizations = [];
            foreach ($targets as $target) {
                if (! $target instanceof CurrentReportAuthorizationTarget) {
                    throw new \InvalidArgumentException('report_authorization_targets_invalid');
                }
                $authorizations[] = $this->authorization(
                    $actor,
                    $requestedScope,
                    $target,
                    $occurredAt,
                    true,
                );
            }

            return $authorizations;
        });
    }

    private function authorizeInsideTransaction(
        User $actor,
        ReportScope $scope,
        CurrentReportAuthorizationTarget $target,
    ): CurrentReportAuthorization {
        $occurredAt = new DateTimeImmutable;
        $this->resources->authorizeAll($actor, $scope->organizationId, $scope->resources, $occurredAt);

        return $this->authorization($actor, $scope, $target, $occurredAt, true);
    }

    private function authorization(
        User $actor,
        ReportScope $scope,
        CurrentReportAuthorizationTarget $target,
        DateTimeImmutable $occurredAt,
        bool $assertOperation,
        ?string $correlationId = null,
    ): CurrentReportAuthorization {
        $permissions = $this->permissionVector((int) $actor->id, $scope, $target, $occurredAt);
        $visibility = new ReportVisibility(
            $permissions['view'],
            $permissions['run'],
            $permissions['export'],
            $permissions['download'],
            $permissions['manage'],
            $permissions['sensitive'],
            $permissions['audit'],
        );
        if ($assertOperation) {
            $this->assertOperationAllowed($target->operation, $visibility);
        }
        $decision = new AuthorizationDecisionContext(
            'queue',
            $scope->organizationId,
            $scope->holdingOrganizationIds,
            $scope->projectIds,
            $scope->resources,
            $scope->timezone,
            $correlationId ?? (string) Str::uuid(),
            null,
        );

        return new CurrentReportAuthorization(
            new ReportActor((int) $actor->id, 'active', []),
            $decision,
            $visibility,
            $target,
        );
    }

    private function permissionVector(
        int $actorId,
        ReportScope $scope,
        CurrentReportAuthorizationTarget $target,
        DateTimeImmutable $occurredAt,
    ): array {
        $visibility = $this->visibilityResolver->resolve(
            $scope->organizationId,
            $target->definition,
            $target->operation,
            $target->exportFormat,
            fn (string $permission): bool => $this->grantedForEveryFact(
                $actorId,
                $scope,
                $occurredAt,
                $permission,
            ),
        );

        return [
            'view' => $visibility->canView,
            'run' => $visibility->canRun,
            'export' => $visibility->canExport,
            'download' => $visibility->canDownload,
            'manage' => $visibility->canManage,
            'sensitive' => $visibility->canViewSensitive,
            'audit' => $visibility->canViewAudit,
        ];
    }

    private function grantedForEveryFact(
        int $actorId,
        ReportScope $scope,
        DateTimeImmutable $occurredAt,
        string $permission,
    ): bool {
        $facts = [new CurrentReportAuthorizationFacts(
            'queue',
            $actorId,
            $scope->organizationId,
            null,
            null,
            $occurredAt,
        )];
        foreach ($scope->projectIds as $projectId) {
            $facts[] = new CurrentReportAuthorizationFacts('queue', $actorId, $scope->organizationId, $projectId, null, $occurredAt);
        }
        foreach ($scope->resources as $resource) {
            $facts[] = new CurrentReportAuthorizationFacts(
                'queue',
                $actorId,
                $scope->organizationId,
                $resource->projectId,
                $resource,
                $occurredAt,
            );
        }
        $baseGranted = $this->decisionMatches(
            $this->abac->evaluate($actorId, $permission, $facts[0]),
            $actorId,
            $permission,
            $facts[0],
        );
        if (! $baseGranted) {
            return false;
        }

        foreach (array_slice($facts, 1) as $fact) {
            $decision = $this->abac->evaluate($actorId, $permission, $fact);
            if (! $this->decisionMatches($decision, $actorId, $permission, $fact)) {
                return false;
            }
        }

        return true;
    }

    private function decisionMatches(
        \App\BusinessModules\Core\Reporting\Application\Access\CurrentReportPermissionDecision $decision,
        int $actorId,
        string $permission,
        CurrentReportAuthorizationFacts $fact,
    ): bool {
        return $decision->granted
            && $decision->actorId === $actorId
            && hash_equals($decision->permission, $permission)
            && $decision->organizationId === $fact->organizationId
            && $decision->projectId === $fact->projectId
            && $decision->resource?->canonicalIdentity() === $fact->resource?->canonicalIdentity();
    }

    private function activeActor(int $actorId, int $organizationId): User
    {
        $actor = User::query()
            ->whereKey($actorId)
            ->where('is_active', true)
            ->whereHas('organizations', static function ($query) use ($organizationId): void {
                $query->where('organizations.id', $organizationId)
                    ->where('organization_user.is_active', true);
            })
            ->first();
        if (! $actor instanceof User) {
            throw new \InvalidArgumentException('report_actor_scope_revoked');
        }

        return $actor;
    }

    private function accessibleHoldingOrganizationIds(int $organizationId): array
    {
        $anchor = Organization::query()->whereKey($organizationId)->where('is_active', true)->first();
        if (! $anchor instanceof Organization) {
            throw new \InvalidArgumentException('report_anchor_organization_missing');
        }
        $ids = [$organizationId];
        if ((bool) $anchor->is_holding) {
            $children = Organization::query()
                ->where('parent_organization_id', $organizationId)
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $ids = array_merge($ids, $children);
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function accessibleProjectIds(int $actorId, int $organizationId, array $holdingOrganizationIds): array
    {
        $membership = DB::table('organization_user')
            ->where('user_id', $actorId)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->first(['project_access_mode']);
        if ($membership === null) {
            return [];
        }
        $query = Project::query()
            ->where('status', 'active')
            ->where('is_archived', false)
            ->where(function ($query) use ($holdingOrganizationIds): void {
                $query->whereIn('organization_id', $holdingOrganizationIds)
                    ->orWhereHas('organizations', static function ($participants) use ($holdingOrganizationIds): void {
                        $participants->whereIn('organizations.id', $holdingOrganizationIds)
                            ->where('project_organization.is_active', true);
                    });
            });
        if (($membership->project_access_mode ?? 'assigned') !== 'all') {
            $query->whereHas('users', static function ($users) use ($actorId): void {
                $users->where('users.id', $actorId)->where('project_user.is_active', true);
            });
        }

        return $query->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function assertOperationAllowed(ReportOperation $operation, ReportVisibility $visibility): void
    {
        $allowed = match ($operation) {
            ReportOperation::VIEW, ReportOperation::DRILL_DOWN => $visibility->canView,
            ReportOperation::RUN => $visibility->canRun,
            ReportOperation::EXPORT => $visibility->canExport,
            ReportOperation::DOWNLOAD => $visibility->canDownload,
            ReportOperation::MANAGE => $visibility->canManage,
            ReportOperation::VIEW_SENSITIVE => $visibility->canViewSensitive,
            ReportOperation::VIEW_AUDIT => $visibility->canViewAudit,
        };
        if (! $allowed) {
            throw new \InvalidArgumentException('report_operation_forbidden');
        }
    }

    private function lockExactAuthorizationFacts(int $actorId, ReportScope $scope): void
    {
        User::query()->whereKey($actorId)->lockForUpdate()->first();

        Organization::query()
            ->whereIn('id', $scope->holdingOrganizationIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        DB::table('organization_user')
            ->where('user_id', $actorId)
            ->where('organization_id', $scope->organizationId)
            ->orderBy('organization_id')
            ->lockForUpdate()
            ->get();

        $projectAssignmentIds = DB::table('project_user')
            ->where('user_id', $actorId)
            ->orderBy('project_id')
            ->pluck('project_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $authoritativeProjectIds = array_values(array_unique([
            ...$scope->projectIds,
            ...$projectAssignmentIds,
        ]));
        sort($authoritativeProjectIds, SORT_NUMERIC);

        if ($authoritativeProjectIds !== []) {
            Project::query()
                ->whereIn('id', $authoritativeProjectIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        DB::table('project_user')
            ->where('user_id', $actorId)
            ->orderBy('project_id')
            ->lockForUpdate()
            ->get();

        if ($scope->projectIds !== []) {
            DB::table('project_organization')
                ->whereIn('project_id', $scope->projectIds)
                ->whereIn('organization_id', $scope->holdingOrganizationIds)
                ->orderBy('project_id')
                ->orderBy('organization_id')
                ->lockForUpdate()
                ->get();
        }

        $assignments = DB::table('user_role_assignments')
            ->where('user_id', $actorId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'context_id', 'role_slug', 'role_type']);
        $assignmentIds = $assignments->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $contextIds = $assignments->pluck('context_id')->map(static fn ($id): int => (int) $id)->all();
        $customRoleSlugs = $assignments
            ->where('role_type', 'custom')
            ->pluck('role_slug')
            ->filter(static fn (mixed $slug): bool => is_string($slug) && $slug !== '')
            ->values()
            ->all();

        if ($contextIds !== []) {
            $contexts = DB::table('authorization_contexts')
                ->whereIn('id', $contextIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'parent_context_id']);
            $parentContextIds = $contexts->pluck('parent_context_id')
                ->filter(static fn (mixed $id): bool => $id !== null)
                ->map(static fn ($id): int => (int) $id)
                ->all();
            if ($parentContextIds !== []) {
                DB::table('authorization_contexts')
                    ->whereIn('id', $parentContextIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }
        }
        if ($assignmentIds !== []) {
            DB::table('role_conditions')
                ->whereIn('assignment_id', $assignmentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }
        if ($customRoleSlugs !== []) {
            DB::table('organization_custom_roles')
                ->where('organization_id', $scope->organizationId)
                ->whereIn('slug', $customRoleSlugs)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                return $callback();
            }

            return DB::transaction(function () use ($callback): mixed {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

                return $callback();
            }, 1);
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, previous: $exception);
        }
    }
}
