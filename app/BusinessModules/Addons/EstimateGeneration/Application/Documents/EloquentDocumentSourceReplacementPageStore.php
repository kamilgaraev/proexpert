<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use Illuminate\Database\Eloquent\Builder;

final class EloquentDocumentSourceReplacementPageStore implements DocumentSourceReplacementPageStore
{
    public function removeStalePages(
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $documentId,
        string $acceptedSourceVersion,
    ): int {
        return EstimateGenerationDocumentPage::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('document_id', $documentId)
            ->where(static function (Builder $query) use ($acceptedSourceVersion): void {
                $query->whereNull('source_version')
                    ->orWhere('source_version', '<>', $acceptedSourceVersion);
            })
            ->delete();
    }
}
