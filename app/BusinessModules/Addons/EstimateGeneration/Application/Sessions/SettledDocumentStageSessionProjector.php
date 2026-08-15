<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;

final readonly class SettledDocumentStageSessionProjector
{
    /**
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $documents
     * @return array<string, mixed>
     */
    public function project(array $session, array $documents): array
    {
        if ((string) ($session['status'] ?? '') !== EstimateGenerationStatus::ProcessingDocuments->value
            || (int) ($documents['total'] ?? 0) === 0
            || (int) ($documents['pending'] ?? 0) > 0
            || (int) ($documents['action_required'] ?? 0) === 0) {
            return $session;
        }

        return [
            ...$session,
            'status' => EstimateGenerationStatus::InputReviewRequired->value,
            'processing_stage' => 'input_review_required',
            'processing_progress' => max(35, (int) ($session['processing_progress'] ?? 0)),
        ];
    }
}
