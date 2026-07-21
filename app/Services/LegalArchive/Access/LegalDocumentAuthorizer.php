<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Access;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

interface LegalDocumentAuthorizer
{
    public function authorize(User $user, LegalArchiveDocument $document, string $ability): void;

    public function authorizePermission(User $user, LegalArchiveDocument $document, string $permission): void;

    public function scopeAccessibleQuery(Builder $query, User $user, int $organizationId, string $ability = 'view'): Builder;
}
