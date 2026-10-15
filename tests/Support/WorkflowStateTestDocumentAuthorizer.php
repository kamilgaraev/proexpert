<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Access\LegalDocumentInternalAccessFilter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

final class WorkflowStateTestDocumentAuthorizer implements LegalDocumentAuthorizer, LegalDocumentInternalAccessFilter
{
    public function authorize(User $user, LegalArchiveDocument $document, string $ability): void
    {
        throw new AuthorizationException;
    }

    public function authorizePermission(User $user, LegalArchiveDocument $document, string $permission): void
    {
        if (! $user->hasPermission($permission, [
            'organization_id' => (int) $document->organization_id,
            'project_id' => $document->primary_project_id === null ? null : (int) $document->primary_project_id,
        ])) {
            throw new AuthorizationException;
        }
    }

    public function filterInternalDocuments(User $user, Collection $documents): Collection
    {
        return $documents->filter(static fn (LegalArchiveDocument $document): bool => (int) $document->organization_id === (int) $user->current_organization_id && (int) $document->id > 0);
    }

    public function scopeAccessibleQuery(Builder $query, User $user, int $organizationId, string $ability = 'view'): Builder
    {
        throw new RuntimeException('Not used by workflow state tests');
    }
}
