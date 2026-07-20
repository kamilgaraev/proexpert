<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Closure;

final class LegalWorkflowActorResolver
{
    private readonly Closure $roleLookup;

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
}
