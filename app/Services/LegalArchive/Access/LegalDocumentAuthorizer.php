<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Access;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\User;

interface LegalDocumentAuthorizer
{
    public function authorize(User $user, LegalArchiveDocument $document, string $ability): void;

    public function authorizePermission(User $user, LegalArchiveDocument $document, string $permission): void;
}
