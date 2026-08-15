<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ExplicitDocumentRetryEligibility;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;

use function trans_message;

final readonly class EstimateGenerationDocumentActionBuilder
{
    private const IGNORE_STATUSES = ['ready', 'failed', 'needs_review'];

    public function __construct(
        private AuthorizationService $authorization,
        private ExplicitDocumentRetryEligibility $explicitRetry = new ExplicitDocumentRetryEligibility,
    ) {}

    /** @return list<array<string, mixed>> */
    public function forDocument(EstimateGenerationDocument $document, User $user): array
    {
        $session = $document->relationLoaded('session') ? $document->session : null;
        if (! $session instanceof EstimateGenerationSession || ! $this->belongsToContext($document, $session, $user)) {
            return [];
        }

        if (! EstimateGenerationMutationPolicy::canMutateDocuments($session)) {
            return [];
        }

        if (! $this->authorization->can($user, 'estimate_generation.review', [
            'organization_id' => (int) $document->organization_id,
            'project_id' => (int) $document->project_id,
        ])) {
            return [];
        }

        $status = (string) $document->status;
        $actions = [];
        if ((string) $document->processing_control_status === 'active'
            && $this->hasActiveProcessing($document, $status)) {
            $actions[] = [
                ...$this->action($document, $session, 'stop_document_processing', 'stop', true),
                'source_version' => (string) $document->source_version,
            ];
        }
        if ((string) $document->processing_control_status === 'paused'
            && (string) $document->processing_control_reason === 'cost_limit_reached') {
            $actions[] = [
                ...$this->action($document, $session, 'confirm_document_cost', 'confirm-cost', true),
                'source_version' => (string) $document->source_version,
            ];
        }
        if ($this->explicitRetry->allowed($document)) {
            $actions[] = [
                ...$this->action($document, $session, 'retry_document', 'retry', true),
                'source_version' => (string) $document->source_version,
                'retry_disposition' => 'explicit_system_failure_retry',
            ];
        }
        if (in_array($status, self::IGNORE_STATUSES, true)) {
            $actions[] = $this->action($document, $session, 'ignore_document', 'ignore', true);
        }

        return $actions;
    }

    private function hasActiveProcessing(EstimateGenerationDocument $document, string $status): bool
    {
        if (! $document->relationLoaded('processingUnits')) {
            return in_array($status, ['uploaded', 'queued', 'processing'], true);
        }

        $sourceVersion = (string) ($document->source_version ?? '');

        return $document->processingUnits->contains(
            static fn ($unit): bool => in_array($unit->status->value, ['pending', 'running'], true)
                && ($sourceVersion === '' || (string) $unit->source_version === $sourceVersion),
        );
    }

    private function belongsToContext(
        EstimateGenerationDocument $document,
        EstimateGenerationSession $session,
        User $user,
    ): bool {
        return (int) $user->current_organization_id === (int) $document->organization_id
            && (int) $session->organization_id === (int) $document->organization_id
            && (int) $session->project_id === (int) $document->project_id
            && (int) $session->getKey() === (int) $document->session_id;
    }

    /** @return array<string, mixed> */
    private function action(
        EstimateGenerationDocument $document,
        EstimateGenerationSession $session,
        string $action,
        string $suffix,
        bool $requiresConfirmation,
    ): array {
        return [
            'action' => $action,
            'label' => trans_message('estimate_generation.action_'.$action),
            'method' => 'POST',
            'endpoint' => sprintf(
                '/api/v1/admin/projects/%d/estimate-generation/sessions/%d/documents/%d/%s',
                (int) $document->project_id,
                (int) $session->getKey(),
                (int) $document->getKey(),
                $suffix,
            ),
            'requires_confirmation' => $requiresConfirmation,
            'state_version' => (int) $session->state_version,
        ];
    }
}
