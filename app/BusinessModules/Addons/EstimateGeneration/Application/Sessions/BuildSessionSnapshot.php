<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationAction;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\ReadinessResult;

use function trans_message;

final class BuildSessionSnapshot
{
    /** @var array<string, list<EstimateGenerationAction>> */
    private const STATUS_ACTIONS = [
        'draft' => [EstimateGenerationAction::UploadDocuments, EstimateGenerationAction::StartDocumentProcessing, EstimateGenerationAction::Cancel],
        'processing_documents' => [EstimateGenerationAction::Cancel],
        'input_review_required' => [EstimateGenerationAction::ConfirmInput, EstimateGenerationAction::Retry, EstimateGenerationAction::Cancel],
        'ready_to_generate' => [EstimateGenerationAction::Generate, EstimateGenerationAction::Cancel],
        'generating' => [EstimateGenerationAction::Cancel],
        'estimate_review_required' => [EstimateGenerationAction::Review, EstimateGenerationAction::Generate, EstimateGenerationAction::Export, EstimateGenerationAction::Cancel],
        'ready_to_apply' => [EstimateGenerationAction::Apply, EstimateGenerationAction::Review, EstimateGenerationAction::Generate, EstimateGenerationAction::Export, EstimateGenerationAction::Cancel],
        'failed' => [EstimateGenerationAction::Retry, EstimateGenerationAction::Cancel, EstimateGenerationAction::Archive],
        'applied' => [EstimateGenerationAction::Export, EstimateGenerationAction::Archive],
        'cancelled' => [EstimateGenerationAction::Archive],
    ];

    /** @var array<string, string> */
    private const ACTION_PERMISSIONS = [
        'upload_documents' => 'estimate_generation.upload_documents',
        'start_document_processing' => 'estimate_generation.generate',
        'generate' => 'estimate_generation.generate',
        'review' => 'estimate_generation.view',
        'apply' => 'estimate_generation.apply',
        'export' => 'estimate_generation.export',
        'retry' => 'estimate_generation.generate',
        'confirm_input' => 'estimate_generation.review',
        'cancel' => 'estimate_generation.generate',
        'archive' => 'estimate_generation.generate',
    ];

    public function handle(
        EstimateGenerationSession $session,
        array $permissions,
        array|ReadinessResult $readinessSummary = [],
        array $documentsSummary = [],
        bool $readinessEvaluated = true,
    ): SessionSnapshotData {
        if ($readinessSummary instanceof ReadinessResult) {
            $readinessSummary = $readinessSummary->toArray();
        }
        $status = $session->status instanceof EstimateGenerationStatus
            ? $session->status
            : EstimateGenerationStatus::from((string) $session->status);
        $blockers = $this->list($readinessSummary['blockers'] ?? []);
        $warnings = $this->list($readinessSummary['warnings'] ?? []);
        $actions = $this->availableActions($session, $status, $permissions, $blockers, $readinessEvaluated);
        $draft = is_array($session->draft_payload) ? $session->draft_payload : [];
        $metrics = is_array($readinessSummary['metrics'] ?? null) ? $readinessSummary['metrics'] : [];

        return new SessionSnapshotData(
            id: (int) $session->getKey(),
            status: $status,
            processingStage: (string) ($session->processing_stage ?? $status->value),
            processingProgress: max(0, min(100, (int) ($session->processing_progress ?? 0))),
            stateVersion: (int) ($session->state_version ?? 0),
            availableActions: $actions,
            blockingIssues: $blockers,
            warnings: $warnings,
            nextAction: $actions[0]['action'] ?? null,
            readinessEvaluated: $readinessEvaluated,
            documentsSummary: $documentsSummary,
            estimateSummary: is_array($draft['quality_summary'] ?? null) ? $draft['quality_summary'] : [],
            reviewSummary: array_filter(
                $metrics,
                static fn (string $key): bool => str_starts_with($key, 'review_items_'),
                ARRAY_FILTER_USE_KEY,
            ),
            appliedEstimateId: $session->applied_estimate_id === null ? null : (int) $session->applied_estimate_id,
            updatedAt: $session->updated_at?->toISOString() ?? '',
            projectId: (int) $session->project_id,
            objectInput: EstimateGenerationSessionInputData::fromValidated(
                is_array($session->input_payload) ? $session->input_payload : [],
            )->toArray(),
            canGenerate: (bool) ($readinessSummary['can_generate'] ?? false),
            canApply: (bool) ($readinessSummary['can_apply'] ?? false),
        );
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<array<string, mixed>>  $blockers
     * @return list<array{action: string, label: string, method: string, endpoint: string, requires_confirmation: bool}>
     */
    private function availableActions(
        EstimateGenerationSession $session,
        EstimateGenerationStatus $status,
        array $permissions,
        array $blockers,
        bool $readinessEvaluated,
    ): array {
        if ($status === EstimateGenerationStatus::Archived) {
            return [];
        }

        $available = [];
        foreach (self::STATUS_ACTIONS[$status->value] ?? [] as $action) {
            if ($action === EstimateGenerationAction::Export && ! $status->allowsExport()) {
                continue;
            }
            $permission = self::ACTION_PERMISSIONS[$action->value] ?? null;
            if ($permission === null || ! in_array($permission, $permissions, true)) {
                continue;
            }
            if ($action === EstimateGenerationAction::Apply && (! $readinessEvaluated || $blockers !== [])) {
                continue;
            }

            $available[] = $this->actionPayload($session, $action);
        }

        return $available;
    }

    /** @return array{action: string, label: string, method: string, endpoint: string, requires_confirmation: bool} */
    private function actionPayload(EstimateGenerationSession $session, EstimateGenerationAction $action): array
    {
        $base = sprintf(
            '/api/v1/admin/projects/%d/estimate-generation/sessions/%d',
            (int) $session->project_id,
            (int) $session->getKey(),
        );
        [$method, $suffix] = match ($action) {
            EstimateGenerationAction::UploadDocuments => ['POST', '/documents'],
            EstimateGenerationAction::StartDocumentProcessing => ['POST', '/analyze'],
            EstimateGenerationAction::Generate => ['POST', '/generate'],
            EstimateGenerationAction::ConfirmInput => ['POST', '/confirm-input'],
            EstimateGenerationAction::Retry => ['POST', '/retry'],
            EstimateGenerationAction::Cancel => ['POST', '/cancel'],
            EstimateGenerationAction::Archive => ['POST', '/archive'],
            EstimateGenerationAction::Review => ['GET', '/review-items'],
            EstimateGenerationAction::Apply => ['POST', '/apply'],
            EstimateGenerationAction::Export => ['GET', '/export'],
            default => ['GET', ''],
        };

        return [
            'action' => $action->value,
            'label' => trans_message('estimate_generation.action_'.$action->value),
            'method' => $method,
            'endpoint' => $base.$suffix,
            'requires_confirmation' => in_array($action, [
                EstimateGenerationAction::Apply,
                EstimateGenerationAction::Cancel,
                EstimateGenerationAction::Archive,
            ], true),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
