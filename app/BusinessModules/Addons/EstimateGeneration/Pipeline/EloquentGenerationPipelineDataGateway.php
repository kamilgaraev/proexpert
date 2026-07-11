<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class EloquentGenerationPipelineDataGateway implements GenerationPipelineDataGateway
{
    public function source(PipelineContext $context): array
    {
        $session = EstimateGenerationSession::query()
            ->whereKey($context->sessionId)
            ->where('organization_id', $context->organizationId)
            ->where('project_id', $context->projectId)
            ->with(['documents.facts', 'documents.drawingElements', 'documents.quantityTakeoffs', 'documents.scopeInferences'])
            ->first();
        if (! $session instanceof EstimateGenerationSession
            || (int) $session->state_version !== $context->stateVersion
            || $session->status->value !== $context->sessionStatus
            || ! hash_equals($context->inputVersion, (string) ($session->input_payload['generation_attempt_id'] ?? ''))) {
            throw new StaleEstimateGenerationState($context->sessionId, $context->stateVersion);
        }

        $documents = $session->documents->map(static fn ($document): array => [
            'id' => (int) $document->id,
            'status' => (string) $document->status,
            'structured_payload' => is_array($document->structured_payload) ? $document->structured_payload : [],
            'facts_summary' => is_array($document->facts_summary) ? $document->facts_summary : [],
            'quality' => ['score' => $document->quality_score, 'level' => $document->quality_level, 'flags' => $document->quality_flags ?? []],
            'facts' => $document->facts->map(static fn ($fact): array => $fact->toArray())->all(),
            'drawing_elements' => $document->drawingElements->map(static fn ($element): array => $element->toArray())->all(),
            'quantity_takeoffs' => $document->quantityTakeoffs->map(static fn ($takeoff): array => $takeoff->toArray())->all(),
            'scope_inferences' => $document->scopeInferences->map(static fn ($inference): array => $inference->toArray())->all(),
        ])->values()->all();

        return [
            'input' => is_array($session->input_payload) ? $session->input_payload : [],
            'documents' => $documents,
            'user_id' => $session->user_id !== null ? (int) $session->user_id : null,
        ];
    }
}
