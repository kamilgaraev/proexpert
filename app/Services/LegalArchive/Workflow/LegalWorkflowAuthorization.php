<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Domain\Authorization\Enums\ConditionType;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class LegalWorkflowAuthorization
{
    public function __construct(private readonly ?LegalDocumentAuthorizer $objectAccess = null) {}

    public function can(User $actor, LegalArchiveDocument $document, string $permission): bool
    {
        $organizationId = (int) $document->organization_id;
        if (
            $organizationId < 1
            || (string) $document->getKey() === ''
        ) {
            return false;
        }
        if ((int) $actor->current_organization_id !== $organizationId) {
            if (
                $this->objectAccess === null
                || ! in_array($permission, [
                    'legal_archive.workflow.approve',
                    'legal_archive.workflow.reject',
                    'legal_archive.workflow.return',
                ], true)
            ) {
                return false;
            }
            try {
                $this->objectAccess->authorize($actor, $document, 'approve');

                return true;
            } catch (AuthorizationException) {
                return false;
            }
        }

        $context = ['organization_id' => $organizationId];
        if ($document->primary_project_id !== null) {
            $context['project_id'] = (int) $document->primary_project_id;
        }

        return $actor->hasPermission($permission, $context);
    }

    public function assertCan(User $actor, LegalArchiveDocument $document, string $permission): void
    {
        if (! $this->can($actor, $document, $permission)) {
            throw new DomainException('legal_workflow_access_denied');
        }
    }

    /**
     * @param  Collection<int, LegalArchiveDocument>  $documents
     * @param  list<string>  $permissions
     * @return array<int, array<string, bool>>
     */
    public function forMany(User $actor, Collection $documents, array $permissions): array
    {
        $batched = $this->batchedForMany($actor, $documents, $permissions);
        if ($batched !== null) {
            return $batched;
        }

        $byContext = [];
        $resolvedContexts = [];
        $result = [];
        foreach ($documents as $document) {
            $documentId = (int) $document->id;
            $organizationId = (int) $document->organization_id;
            if ($organizationId < 1 || (int) $actor->current_organization_id !== $organizationId) {
                $result[$documentId] = array_fill_keys($permissions, false);

                continue;
            }
            $context = ['organization_id' => $organizationId];
            if ($document->primary_project_id !== null) {
                $context['project_id'] = (int) $document->primary_project_id;
            }
            $contextKey = $organizationId.':'.($context['project_id'] ?? 'organization');
            if (! isset($resolvedContexts[$contextKey])) {
                $resolvedContexts[$contextKey] = true;
                foreach ($permissions as $permission) {
                    $byContext[$contextKey][$permission] = $actor->hasPermission($permission, $context);
                }
            }
            $result[$documentId] = $byContext[$contextKey];
        }

        return $result;
    }

    private function batchedForMany(User $actor, Collection $documents, array $permissions): ?array
    {
        if ($documents->isEmpty() || $actor::class !== User::class || ! app()->bound(PermissionResolver::class)) {
            return null;
        }

        $eligible = $documents->filter(static fn (LegalArchiveDocument $document): bool => (int) $document->organization_id > 0
            && (int) $document->organization_id === (int) $actor->current_organization_id);
        $organizationIds = $eligible->pluck('organization_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        if ($organizationIds === []) {
            return $this->deniedMany($documents, $permissions);
        }

        $system = AuthorizationContext::findSystemContext();
        if (! $system instanceof AuthorizationContext) {
            return $this->deniedMany($documents, $permissions);
        }
        $organizations = AuthorizationContext::query()
            ->where('type', AuthorizationContext::TYPE_ORGANIZATION)
            ->where('parent_context_id', (int) $system->id)
            ->whereIn('resource_id', $organizationIds)
            ->get(['id', 'resource_id']);
        $organizationContextIds = $organizations->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($organizationContextIds === []) {
            return $this->deniedMany($documents, $permissions);
        }
        $projectIds = $eligible->pluck('primary_project_id')
            ->filter(static fn (mixed $id): bool => $id !== null && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $projectContexts = $projectIds === [] ? collect() : AuthorizationContext::query()
            ->where('type', AuthorizationContext::TYPE_PROJECT)
            ->whereIn('parent_context_id', $organizationContextIds)
            ->whereIn('resource_id', $projectIds)
            ->get(['id', 'resource_id', 'parent_context_id']);
        $organizationContextByOrganization = $organizations->mapWithKeys(
            static fn (AuthorizationContext $context): array => [(int) $context->resource_id => (int) $context->id],
        )->all();
        $organizationByContextId = $organizations->mapWithKeys(
            static fn (AuthorizationContext $context): array => [(int) $context->id => (int) $context->resource_id],
        )->all();
        $projectContextByOrganizationAndProject = $projectContexts->mapWithKeys(
            static function (AuthorizationContext $context) use ($organizationByContextId): array {
                $organizationId = $organizationByContextId[(int) $context->parent_context_id] ?? 0;

                return [$organizationId.':'.(int) $context->resource_id => (int) $context->id];
            },
        )->all();
        $contextIds = array_values(array_unique([
            (int) $system->id,
            ...$organizationContextIds,
            ...$projectContexts->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        ]));
        $assignments = UserRoleAssignment::query()
            ->where('user_id', (int) $actor->id)
            ->active()
            ->whereIn('context_id', $contextIds)
            ->with([
                'conditions' => static fn ($query) => $query->active(),
            ])
            ->get();
        $activeProjectCounts = $this->activeProjectCounts($assignments);
        $resolver = app(PermissionResolver::class);
        $assignmentsByContext = $assignments->groupBy(static fn (UserRoleAssignment $assignment): int => (int) $assignment->context_id);

        $result = $this->deniedMany($documents, $permissions);
        foreach ($eligible as $document) {
            $organizationId = (int) $document->organization_id;
            $organizationContextId = $organizationContextByOrganization[$organizationId] ?? null;
            $projectContextId = $document->primary_project_id === null || $organizationContextId === null
                ? null
                : ($projectContextByOrganizationAndProject[$organizationId.':'.(int) $document->primary_project_id] ?? null);
            $documentPermissions = array_fill_keys($permissions, false);
            foreach (array_values(array_filter([(int) $system->id, $organizationContextId, $projectContextId])) as $contextId) {
                foreach ($assignmentsByContext->get($contextId, collect()) as $assignment) {
                    $context = [
                        'organization_id' => $organizationId,
                        'project_id' => $document->primary_project_id === null ? null : (int) $document->primary_project_id,
                        'user_id' => (int) $assignment->user_id,
                        'active_projects_count' => $activeProjectCounts[(int) $assignment->user_id] ?? 0,
                    ];
                    if (! $this->conditionsAllow($assignment, $context)) {
                        continue;
                    }
                    foreach ($permissions as $permission) {
                        if (! $documentPermissions[$permission] && $resolver->hasPermission($assignment, $permission, $context)) {
                            $documentPermissions[$permission] = true;
                        }
                    }
                }
            }
            $result[(int) $document->id] = $documentPermissions;
        }

        return $result;
    }

    private function conditionsAllow(UserRoleAssignment $assignment, array $context): bool
    {
        foreach ($assignment->conditions as $condition) {
            if (! $condition->evaluate($context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Collection<int, UserRoleAssignment>  $assignments
     * @return array<int, int>
     */
    private function activeProjectCounts(Collection $assignments): array
    {
        $userIds = $assignments
            ->filter(static fn (UserRoleAssignment $assignment): bool => $assignment->conditions->contains(
                static fn ($condition): bool => $condition->condition_type === ConditionType::PROJECT_COUNT,
            ))
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($userIds === []) {
            return [];
        }

        return DB::table('project_user')
            ->join('projects', 'projects.id', '=', 'project_user.project_id')
            ->whereIn('project_user.user_id', $userIds)
            ->where('project_user.is_active', true)
            ->where('projects.status', 'active')
            ->whereNull('projects.deleted_at')
            ->groupBy('project_user.user_id')
            ->pluck(DB::raw('COUNT(DISTINCT project_user.project_id)'), 'project_user.user_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    private function deniedMany(Collection $documents, array $permissions): array
    {
        $result = [];
        foreach ($documents as $document) {
            $result[(int) $document->id] = array_fill_keys($permissions, false);
        }

        return $result;
    }
}
