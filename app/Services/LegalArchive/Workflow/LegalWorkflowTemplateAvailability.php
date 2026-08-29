<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use Illuminate\Support\Collection;

interface LegalWorkflowTemplateAvailability
{
    public function isAvailable(LegalArchiveDocument $document): bool;

    /**
     * @param  Collection<int, LegalArchiveDocument>  $documents
     * @return array<int, bool>
     */
    public function forMany(Collection $documents): array;
}
