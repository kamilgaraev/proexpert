<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentResultAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Database\Connection;

/** Facts alone are not an estimate revision; completing this operation releases its reserve without a charge. */
final readonly class CompleteEstimatorDocumentOperation
{
    public function __construct(private Connection $database, private EstimatorCreditOperationSettlement $settlement,
        private AdvanceEstimateGeneration $advance) {}

    public function handle(EstimateGenerationSession $session): bool
    {
        $id = $session->input_payload['documents_credit_operation_id'] ?? null;
        if (! is_string($id)) {
            return false;
        }

        return $this->database->transaction(function () use ($session, $id): bool {
            $session = EstimateGenerationSession::query()->whereKey($session->id)->where('organization_id', $session->organization_id)
                ->where('project_id', $session->project_id)->lockForUpdate()->firstOrFail();
            if (($session->input_payload['documents_credit_operation_id'] ?? null) !== $id) {
                return false;
            }
            $operation = $this->database->table('estimate_generation_credit_operations')->where('operation_id', $id)
                ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)->where('session_id', $session->id)
                ->where('kind', 'documents')->lockForUpdate()->firstOrFail();
            if (! in_array($operation->status, ['reserved', 'running'], true)) {
                return true;
            }
            $denial = app(DocumentResultAuthorization::class)->denialReason($session, (int) $operation->actor_user_id);
            if ($denial !== null) {
                $this->settlement->abort($session, $id, $denial);

                return true;
            }
            $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
            $outcome = [];
            foreach ($plan['documents'] ?? [] as $entry) {
                $document = $session->documents()->whereKey($entry['document_id'])->where('organization_id', $session->organization_id)
                    ->where('project_id', $session->project_id)->lockForUpdate()->firstOrFail();
                if (DocumentSourceVersion::fromDocument($document) !== $entry['source_version']) {
                    $this->settlement->abort($session, $id, 'documents_source_changed');

                    return true;
                }
                $units = EstimateGenerationProcessingUnit::query()->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
                    ->where('session_id', $session->id)->where('document_id', $document->id)->where('source_version', $entry['source_version'])
                    ->with('page')->orderBy('id')->lockForUpdate()->get();
                if ($units->pluck('id')->map(static fn ($unit): int => (int) $unit)->all() !== array_column($entry['units'], 'id')) {
                    $this->settlement->abort($session, $id, 'documents_manifest_changed');

                    return true;
                }
                foreach ($units as $unit) {
                    if ($unit->page?->status !== 'excluded'
                        && (in_array($unit->status->value, ['pending', 'running'], true)
                            || ($unit->status->value === 'failed' && $unit->attempt_count < ProcessDocumentUnit::MAX_ATTEMPTS))) {
                        return true;
                    }
                    $outcome[] = ['unit_id' => (int) $unit->id, 'source_version' => $unit->source_version,
                        'status' => $unit->status->value, 'output_version' => $unit->output_version,
                        'excluded' => $unit->page?->status === 'excluded'];
                }
            }
            if (in_array($session->status->value, ['processing_documents', 'input_review_required'], true)) {
                $session = $this->advance->documentsNeedReview($session, 'project_planning_requires_approval', ['planning_requires_paid_approval']);
            }
            $pendingUsage = $this->database->table('estimate_generation_credit_attempts')->where('operation_id', $id)
                ->where(static fn ($query) => $query->where('status', 'admitted')->orWhereNull('usage_transferred_at'))->exists();
            if ($pendingUsage) {
                $this->settlement->abort($session, $id, 'documents_usage_unavailable');
            } else {
                $receipt = $this->settlement->publishDocuments($session, $id, $outcome);
                $session->update(['analysis_payload' => [...($session->analysis_payload ?? []), 'credit_operation_receipt' => $receipt]]);
            }

            return true;
        }, 3);
    }
}
