<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;

use function trans_message;

final readonly class EstimateGenerationDocumentActionBuilder
{
    private const RETRY_STATUSES = ['ready', 'failed', 'needs_review', 'ignored'];

    private const IGNORE_STATUSES = ['ready', 'failed', 'needs_review'];

    public function __construct(private AuthorizationService $authorization) {}

    /** @return list<array{action: string, label: string, method: string, endpoint: string, requires_confirmation: bool, state_version: int}> */
    public function forDocument(EstimateGenerationDocument $document, User $user): array
    {
        $session = $document->relationLoaded('session') ? $document->session : null;
        if (! $session instanceof EstimateGenerationSession || ! $this->belongsToContext($document, $session, $user)) {
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
        if (in_array($status, self::RETRY_STATUSES, true)) {
            $actions[] = $this->action($document, $session, 'retry_document', 'retry', false);
        }
        if (in_array($status, self::IGNORE_STATUSES, true)) {
            $actions[] = $this->action($document, $session, 'ignore_document', 'ignore', true);
        }

        return $actions;
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

    /** @return array{action: string, label: string, method: string, endpoint: string, requires_confirmation: bool, state_version: int} */
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
