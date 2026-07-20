<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\User;
use Closure;

final class LegalWorkflowActorResolver
{
    private readonly Closure $roleLookup;

    private readonly Closure $assignmentLookup;

    /** @var array<string, Closure> */
    private readonly array $customResolvers;

    /**
     * @param  callable(User, string, int, LegalArchiveDocument): bool|null  $roleLookup
     * @param  callable(string, string, LegalArchiveDocument): bool|null  $assignmentLookup
     * @param  array<string, callable(User, string, int, LegalArchiveDocument): bool>  $customResolvers
     */
    public function __construct(
        ?callable $roleLookup = null,
        ?callable $assignmentLookup = null,
        array $customResolvers = [],
    ) {
        $this->roleLookup = $roleLookup === null
            ? static function (User $actor, string $role, int $organizationId): bool {
                $roles = app(AuthorizationService::class)->getUserRoleSlugs($actor, [
                    'organization_id' => $organizationId,
                ]);

                return in_array($role, $roles, true);
            }
        : Closure::fromCallable($roleLookup);
        $this->assignmentLookup = $assignmentLookup === null
            ? static function (string $actorType, string $reference, LegalArchiveDocument $document): bool {
                $organizationId = (int) $document->organization_id;

                return match ($actorType) {
                    'user' => ctype_digit($reference) && \Illuminate\Support\Facades\DB::table('organization_user')
                        ->where('organization_id', $organizationId)
                        ->where('user_id', (int) $reference)
                        ->exists(),
                    'role' => app(RoleScanner::class)->roleExists($reference)
                        || OrganizationCustomRole::query()
                            ->where('organization_id', $organizationId)
                            ->where('slug', $reference)
                            ->active()
                            ->exists(),
                    default => false,
                };
            }
        : Closure::fromCallable($assignmentLookup);
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

    public function assignmentExists(string $actorType, string $reference, LegalArchiveDocument $document): bool
    {
        $reference = trim($reference);
        if ($reference === '' || ! in_array($actorType, ['user', 'role', 'party', 'external'], true)) {
            return false;
        }
        if (in_array($actorType, ['party', 'external'], true)) {
            return isset($this->customResolvers[$actorType]);
        }

        return ($this->assignmentLookup)($actorType, $reference, $document);
    }
}
