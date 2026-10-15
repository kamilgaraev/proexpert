<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Access;

use App\Models\User;
use Illuminate\Support\Collection;

interface LegalDocumentInternalAccessFilter
{
    public function filterInternalDocuments(User $user, Collection $documents): Collection;
}
