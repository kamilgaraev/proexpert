<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;

final class LegalWorkflowActorResolver
{
    private readonly Closure $roleLookup;

    private readonly bool $usesDefaultRoleLookup;

    /** @var array<string, Closure> */
    private readonly array $customResolvers;

    /**
     * @param  callable(User, string, int, LegalArchiveDocument): bool|null  $roleLookup
     * @param  array<string, callable(User, string, int, LegalArchiveDocument): bool>  $customResolvers
     */
    public function __construct(
        ?callable $roleLookup = null,
        array $customResolvers = [],
    ) {
        $this->usesDefaultRoleLookup = $roleLookup === null;
        $this->roleLookup = $roleLookup === null
            ? static function (User $actor, string $role, int $organizationId, LegalArchiveDocument $document): bool {
                $authorization = app(AuthorizationService::class);
                $organizationContext = AuthorizationContext::findOrganizationContext($organizationId);
                if (! $organizationContext instanceof AuthorizationContext) {
                    return false;
                }
                if ($authorization->hasRole($actor, $role, (int) $organizationContext->id)) {
                    return true;
                }
                if ($document->primary_project_id === null) {
                    return false;
                }
                $projectContext = AuthorizationContext::findProjectContext(
                    (int) $document->primary_project_id,
                    $organizationId,
                );

                if (! $projectContext instanceof AuthorizationContext) {
                    return false;
                }

                return $authorization->hasRole($actor, $role, (int) $projectContext->id);
            }
        : Closure::fromCallable($roleLookup);
        $this->customResolvers = array_map(
            static fn (callable $resolver): Closure => Closure::fromCallable($resolver),
            $customResolvers,
        );
    }

    public function canAct(User $actor, LegalWorkflowStep $step, LegalArchiveDocument $document): bool
    {
        $organizationId = (int) $document->organization_id;
        if (
            $organizationId < 1
            || (int) $step->organization_id !== $organizationId
            || (int) $actor->current_organization_id !== $organizationId
        ) {
            return false;
        }

        $reference = trim((string) $step->actor_reference);

        return match ((string) $step->actor_type) {
            'user' => ctype_digit($reference) && (int) $reference === (int) $actor->id,
            'role' => ($this->roleLookup)($actor, $reference, $organizationId, $document),
            'party', 'external' => isset($this->customResolvers[(string) $step->actor_type])
                && ($this->customResolvers[(string) $step->actor_type])($actor, $reference, $organizationId, $document),
            default => false,
        };
    }

    public function supports(string $actorType): bool
    {
        return in_array($actorType, ['user', 'role'], true) || isset($this->customResolvers[$actorType]);
    }

    /**
     * @param  Collection<int, LegalArchiveDocument>  $documents
     * @return array<int, bool>
     */
    public function forMany(User $actor, Collection $documents): array
    {
        if ($this->usesDefaultRoleLookup && $actor::class === User::class) {
            return $this->batchedForMany($actor, $documents);
        }

        $resolved = [];
        $roleCache = [];
        foreach ($documents as $document) {
            $instance = $document->relationLoaded('latestWorkflowInstance')
                ? $document->latestWorkflowInstance
                : null;
            if ($instance === null || ! $instance->relationLoaded('steps')) {
                continue;
            }
            foreach ($instance->steps->where('status', 'active') as $step) {
                $reference = trim((string) $step->actor_reference);
                if ((string) $step->actor_type !== 'role') {
                    $resolved[(int) $step->id] = $this->canAct($actor, $step, $document);

                    continue;
                }
                $key = implode(':', [
                    (int) $document->organization_id,
                    (int) ($document->primary_project_id ?? 0),
                    $reference,
                ]);
                if (! array_key_exists($key, $roleCache)) {
                    $roleCache[$key] = $this->canAct($actor, $step, $document);
                }
                $resolved[(int) $step->id] = $roleCache[$key];
            }
        }

        return $resolved;
    }

    private function batchedForMany(User $actor, Collection $documents): array
    {
        $roleSteps = [];
        $resolved = [];
        foreach ($documents as $document) {
            $instance = $document->relationLoaded('latestWorkflowInstance') ? $document->latestWorkflowInstance : null;
            if ($instance === null || ! $instance->relationLoaded('steps')) {
                continue;
            }
            foreach ($instance->steps->where('status', 'active') as $step) {
                if ((string) $step->actor_type !== 'role') {
                    $resolved[(int) $step->id] = $this->canAct($actor, $step, $document);

                    continue;
                }
                if ((int) $document->organization_id < 1
                    || (int) $step->organization_id !== (int) $document->organization_id
                    || (int) $actor->current_organization_id !== (int) $document->organization_id) {
                    $resolved[(int) $step->id] = false;

                    continue;
                }
                $roleSteps[] = [$document, $step, trim((string) $step->actor_reference)];
            }
        }
        if ($roleSteps === []) {
            return $resolved;
        }

        $organizationIds = collect($roleSteps)->map(static fn (array $item): int => (int) $item[0]->organization_id)->unique()->values()->all();
        $roles = collect($roleSteps)->pluck(2)->filter()->unique()->values()->all();
        $system = AuthorizationContext::findSystemContext();
        if (! $system instanceof AuthorizationContext) {
            foreach ($roleSteps as [, $step]) {
                $resolved[(int) $step->id] = false;
            }

            return $resolved;
        }
        $organizationContexts = AuthorizationContext::query()
            ->where('type', AuthorizationContext::TYPE_ORGANIZATION)
            ->where('parent_context_id', (int) $system->id)
            ->whereIn('resource_id', $organizationIds)
            ->get(['id', 'resource_id']);
        $organizationContextByOrganization = $organizationContexts->mapWithKeys(
            static fn (AuthorizationContext $context): array => [(int) $context->resource_id => (int) $context->id],
        )->all();
        $projectIds = collect($roleSteps)->map(static fn (array $item): ?int => $item[0]->primary_project_id === null ? null : (int) $item[0]->primary_project_id)->filter()->unique()->values()->all();
        $projectContexts = $projectIds === [] ? collect() : AuthorizationContext::query()
            ->where('type', AuthorizationContext::TYPE_PROJECT)
            ->whereIn('parent_context_id', array_values($organizationContextByOrganization))
            ->whereIn('resource_id', $projectIds)
            ->get(['id', 'resource_id', 'parent_context_id']);
        $projectContextByPair = $projectContexts->mapWithKeys(
            static fn (AuthorizationContext $context): array => [(int) $context->parent_context_id.':'.(int) $context->resource_id => (int) $context->id],
        )->all();
        $contextIds = array_values(array_unique([
            ...array_values($organizationContextByOrganization),
            ...array_values($projectContextByPair),
        ]));
        $assignments = $contextIds === [] ? collect() : UserRoleAssignment::query()
            ->where('user_id', (int) $actor->id)
            ->active()
            ->whereIn('context_id', $contextIds)
            ->whereIn('role_slug', $roles)
            ->get(['context_id', 'role_slug']);
        $assigned = $assignments->mapWithKeys(
            static fn (UserRoleAssignment $assignment): array => [(int) $assignment->context_id.':'.(string) $assignment->role_slug => true],
        )->all();
        foreach ($roleSteps as [$document, $step, $role]) {
            $organizationContextId = $organizationContextByOrganization[(int) $document->organization_id] ?? null;
            $projectContextId = $document->primary_project_id === null || $organizationContextId === null
                ? null
                : ($projectContextByPair[$organizationContextId.':'.(int) $document->primary_project_id] ?? null);
            $resolved[(int) $step->id] = ($organizationContextId !== null && isset($assigned[$organizationContextId.':'.$role]))
                || ($projectContextId !== null && isset($assigned[$projectContextId.':'.$role]));
        }

        return $resolved;
    }
}
