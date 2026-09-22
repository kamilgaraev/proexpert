<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use Illuminate\Validation\ValidationException;
use DomainException;

final class ExecutiveDocumentCoverageSnapshot
{
    public function forDocument(ExecutiveDocument $document): array
    {
        $coverage = ['project_id' => (int) $document->project_id];
        $declaration = data_get($document->metadata, 'coverage', []);
        if (! is_array($declaration) || ($declaration !== [] && $document->completed_work_id === null)) {
            throw ValidationException::withMessages(['metadata.coverage' => trans_message('executive_documentation.requirements.coverage_invalid')]);
        }
        $locationId = (int) data_get($document->metadata, 'project_location_id', 0);
        if ($locationId > 0) {
            if (! ProjectLocation::query()->whereKey($locationId)
                ->where('organization_id', $document->organization_id)
                ->where('project_id', $document->project_id)->exists()) {
                throw new BusinessLogicException(trans_message('executive_documentation.errors.project_location_not_found'), 404);
            }
            $coverage['project_location_id'] = $locationId;
        }
        if ($document->work_type_id !== null) {
            $coverage['work_type_id'] = (int) $document->work_type_id;
        }
        if ($document->completed_work_id !== null) {
            $work = CompletedWork::query()->with('workType')->whereKey($document->completed_work_id)
                ->where('organization_id', $document->organization_id)
                ->where('project_id', $document->project_id)->first();
            if ($work === null) {
                throw new BusinessLogicException(trans_message('executive_documentation.errors.completed_work_not_found'), 404);
            }
            $coverage['completed_work_id'] = (int) $work->id;
            $coverage['source_quantity'] = (string) ($work->completed_quantity ?? $work->quantity ?? '0');
            $coverage['measurement_unit_id'] = $work->workType?->measurement_unit_id;
            try {
                $coverage = array_merge($coverage, app(ExecutiveDocumentCoverageDeclaration::class)->normalize(
                    $declaration, $coverage['source_quantity'], (int) $coverage['measurement_unit_id'],
                ));
            } catch (DomainException) {
                throw ValidationException::withMessages(['metadata.coverage' => trans_message('executive_documentation.requirements.coverage_invalid')]);
            }
        }
        return $coverage;
    }
}
