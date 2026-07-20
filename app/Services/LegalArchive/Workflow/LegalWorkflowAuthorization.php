<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

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
        $projectContexts = AuthorizationContext::query()
            ->where('type', AuthorizationContext::TYPE_PROJECT)
            ->whereIn('parent_context_id', $organizationContextIds)
            ->get(['id', 'parent_context_id']);
        $organizationByContextId = $organizations->mapWithKeys(
            static fn (AuthorizationContext $context): array => [(int) $context->id => (int) $context->resource_id],
        )->all();
        foreach ($projectContexts as $projectContext) {
            $organizationByContextId[(int) $projectContext->id] = $organizationByContextId[(int) $projectContext->parent_context_id] ?? 0;
        }
        $contextIds = array_values(array_unique([
            (int) $system->id,
            ...$organizationContextIds,
            ...$projectContexts->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        ]));
        $assignments = UserRoleAssignment::query()
            ->where('user_id', (int) $actor->id)
            ->active()
            ->whereIn('context_id', $contextIds)
            ->with(['conditions' => static fn ($query) => $query->active()])
            ->get();
        $resolver = app(PermissionResolver::class);
        $permissionMaps = [];
        foreach ($organizations as $organization) {
            $organizationId = (int) $organization->resource_id;
            $permissionMaps[$organizationId] = array_fill_keys($permissions, false);
            foreach ($assignments as $assignment) {
                $belongsToOrganization = (int) $assignment->context_id === (int) $system->id
                    || ($organizationByContextId[(int) $assignment->context_id] ?? 0) === $organizationId;
                if (! $belongsToOrganization || ! $this->conditionsAllow($assignment, ['organization_id' => $organizationId])) {
                    continue;
                }
                $assignment->setRelation('user', $actor);
                foreach ($permissions as $permission) {
                    if (! $permissionMaps[$organizationId][$permission]
                        && $resolver->hasPermission($assignment, $permission, ['organization_id' => $organizationId])) {
                        $permissionMaps[$organizationId][$permission] = true;
                    }
                }
            }
        }

        $result = $this->deniedMany($documents, $permissions);
        foreach ($eligible as $document) {
            $result[(int) $document->id] = $permissionMaps[(int) $document->organization_id] ?? array_fill_keys($permissions, false);
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

    private function deniedMany(Collection $documents, array $permissions): array
    {
        $result = [];
        foreach ($documents as $document) {
            $result[(int) $document->id] = array_fill_keys($permissions, false);
        }

        return $result;
    }
}
