<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class EvaluationInputFingerprint
{
    public function fromSession(EstimateGenerationSession $session): string
    {
        $input = array_intersect_key($session->input_payload ?? [], array_flip([
            'description', 'building_type', 'construction_type', 'area', 'floors', 'height', 'parameters',
            'generation_mode', 'evaluation_mode', 'price_policy', 'profile_id', 'selected_sections', 'regional_context',
            'manual_input_revision', 'price_selection_revision', 'technology_selection_revision', 'scope_selection_revision',
        ]));
        $documents = $session->documents()->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
            ->orderBy('id')->get(['id', 'checksum_sha256', 'status'])->map(static fn ($document): array => [
                'id' => (int) $document->id, 'source_version' => DocumentSourceVersion::fromDocument($document),
                'included' => $document->status !== 'ignored',
            ])->all();

        return hash('sha256', CanonicalPipelineJson::encode(['schema_version' => 'evaluation-input:v1',
            'organization_id' => (int) $session->organization_id, 'project_id' => (int) $session->project_id, 'session_id' => (int) $session->id,
            'input' => $input, 'documents' => $documents]));
    }
}
