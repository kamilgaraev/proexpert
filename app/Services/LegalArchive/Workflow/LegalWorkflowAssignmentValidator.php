<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Services\RoleScanner;
use App\Enums\UserProjectAccessMode;
use Closure;

final class LegalWorkflowAssignmentValidator
{
    private readonly Closure $lookup;

    /** @param callable(string, string, LegalArchiveDocument): bool|null $lookup */
    public function __construct(?callable $lookup = null)
    {
        $this->lookup = $lookup === null
            ? function (string $actorType, string $reference, LegalArchiveDocument $document): bool {
                return match ($actorType) {
                    'user' => $this->userExistsInScope((int) $reference, $document),
                    'role' => app(RoleScanner::class)->roleExists($reference)
                        || (new OrganizationCustomRole)->setConnection($document->getConnectionName())->newQuery()
                            ->where('organization_id', (int) $document->organization_id)
                            ->where('slug', $reference)
                            ->active()
                            ->exists(),
                    'party', 'external' => false,
                    default => false,
                };
            }
        : Closure::fromCallable($lookup);
    }

    public function exists(string $actorType, string $reference, LegalArchiveDocument $document): bool
    {
        $reference = trim($reference);
        if ($reference === '' || ! in_array($actorType, ['user', 'role', 'party', 'external'], true)) {
            return false;
        }
        if ($actorType === 'user' && ! ctype_digit($reference)) {
            return false;
        }

        return ($this->lookup)($actorType, $reference, $document);
    }

    private function userExistsInScope(int $userId, LegalArchiveDocument $document): bool
    {
        if ($userId < 1) {
            return false;
        }
        $membership = $document->getConnection()->table('organization_user')
            ->where('organization_id', (int) $document->organization_id)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->first(['project_access_mode']);
        if ($membership === null) {
            return false;
        }
        if ($document->primary_project_id === null
            || $membership->project_access_mode === UserProjectAccessMode::ALL_PROJECTS->value
        ) {
            return true;
        }

        return $document->getConnection()->table('project_user')
            ->where('project_id', (int) $document->primary_project_id)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->exists();
    }
}
