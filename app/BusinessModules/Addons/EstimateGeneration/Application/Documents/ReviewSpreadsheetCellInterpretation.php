<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\UserEvaluationChoiceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ReviewSpreadsheetCellInterpretation
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private SpreadsheetCellReviewSource $sources, private UserEvaluationChoiceWriter $choices,
        private ProjectModelRepository $models, private AtomicDocumentUnitPublicationWriter $publisher,
        private EstimateGenerationLearningRecorder $learning) {}

    public function rows(User $actor, EstimateGenerationSession $session, int $documentId): array
    {
        $this->authorization->authorize($actor, $session, 'estimate_generation.view');
        $canReview = $this->mutable($session);
        if ($canReview) {
            try {
                $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                $canReview = false;
            }
        }
        $result = [];
        foreach ($this->proposals($session) as $proposal) {
            $binding = $proposal['binding'];
            if ($binding['document_id'] !== $documentId || $this->current($session, $proposal) === null) {
                continue;
            }
            $cells = array_column($binding['cells'], null, 'cell_id');
            $state = $session->input_payload['document_cell_review_rows'][$proposal['proposal']['row_id']] ?? null;
            $status = $state !== null && $state['proposal_hash'] === $proposal['proposal_hash'] ? $state['decision'] : 'pending';
            $result[] = ['proposal_run_id' => $proposal['run_id'], 'proposal_hash' => $proposal['proposal_hash'],
                'document_id' => $documentId, 'unit_id' => $binding['unit_id'], 'source_version' => $binding['source_version'],
                'sheet' => $binding['sheet'], 'row' => $binding['row'], 'identity_cell' => $cells[$proposal['proposal']['identity_cell_id']],
                'value_cell' => $cells[$proposal['proposal']['value_cell_id']], 'status' => $status,
                'human_interpretation' => $status === 'accept' ? 'confirmed_by_user' : 'unresolved',
                'source_correspondence' => $status === 'accept' ? 'passed' : 'unresolved', 'engineering_applicability' => 'unresolved',
                'actions' => $canReview ? ($status === 'accept' ? ['revoke'] : ($status === 'pending' ? ['accept', 'reject'] : [])) : []];
        }

        usort($result, static fn (array $left, array $right): int => [$left['unit_id'], $left['row'], $left['proposal_run_id']]
            <=> [$right['unit_id'], $right['row'], $right['proposal_run_id']]);

        return $result;
    }

    public function handle(User $actor, int $projectId, int $sessionId, int $documentId, array $input): array
    {
        $keys = ['request_id', 'state_version', 'source_version', 'unit_id', 'proposal_run_id', 'proposal_hash', 'decision', 'reason',
            ...(($input['decision'] ?? null) === 'accept' ? ['identity_confirmation'] : [])];
        if (count($input) !== count($keys) || array_diff($keys, array_keys($input)) !== [] || ! Str::isUuid($input['request_id'] ?? '')
            || ! is_int($input['state_version'] ?? null) || $input['state_version'] < 0
            || ! is_int($input['unit_id'] ?? null) || $input['unit_id'] < 1 || ! is_int($input['proposal_run_id'] ?? null) || $input['proposal_run_id'] < 1
            || ! is_string($input['source_version'] ?? null) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $input['source_version']) !== 1
            || ! is_string($input['proposal_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $input['proposal_hash']) !== 1
            || ! in_array($input['decision'] ?? null, ['accept', 'reject', 'revoke'], true)
            || ! is_string($input['reason'] ?? null) || mb_strlen(trim($input['reason'])) < 12 || strlen($input['reason']) > 1000
            || ($input['decision'] === 'accept' && ($input['identity_confirmation'] ?? null) !== 'new_document_local_equipment')) {
            $this->invalid();
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $documentId, $input): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->lockForUpdate()->findOrFail($sessionId);
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            $payload = $session->input_payload ?? [];
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode([$documentId, $input]));
            $receipts = $payload['document_cell_review_receipts'] ?? [];
            if (isset($receipts[$input['request_id']])) {
                $receipt = $receipts[$input['request_id']];
                if ($receipt['actor_id'] !== (int) $actor->id || ! hash_equals($receipt['fingerprint'], $fingerprint)) {
                    $this->invalid();
                }

                return $receipt['result'];
            }
            if ((int) $session->state_version !== $input['state_version'] || ! $this->mutable($session)) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            if (count($receipts) >= 10000) {
                $this->invalid();
            }
            EstimateGenerationDocument::query()->where('organization_id', $session->organization_id)->where('project_id', $projectId)
                ->where('session_id', $sessionId)->lockForUpdate()->findOrFail($documentId);
            EstimateGenerationProcessingUnit::query()->where('organization_id', $session->organization_id)->where('project_id', $projectId)
                ->where('session_id', $sessionId)->where('document_id', $documentId)->lockForUpdate()->findOrFail($input['unit_id']);
            $selected = null;
            foreach ($this->proposals($session) as $proposal) {
                if ($proposal['run_id'] === $input['proposal_run_id'] && $proposal['proposal_hash'] === $input['proposal_hash']
                    && $proposal['binding']['document_id'] === $documentId && $proposal['binding']['unit_id'] === $input['unit_id']
                    && $proposal['binding']['source_version'] === $input['source_version']) {
                    $selected = $proposal;
                    break;
                }
            }
            $row = $selected === null ? null : $this->current($session, $selected);
            if ($row === null) {
                $this->invalid();
            }
            $rowId = $selected['proposal']['row_id'];
            $previous = $payload['document_cell_review_rows'][$rowId] ?? null;
            if (($input['decision'] === 'revoke' && (($previous['decision'] ?? null) !== 'accept'
                    || $previous['proposal_hash'] !== $selected['proposal_hash'] || $previous['run_id'] !== $selected['run_id']))
                || ($input['decision'] !== 'revoke' && $previous !== null
                    && (($previous['decision'] === 'accept' && $this->current($session, $previous) !== null)
                        || $previous['proposal_hash'] === $selected['proposal_hash']))) {
                $this->invalid();
            }

            return $this->publisher->transaction((int) $session->organization_id, $sessionId,
                function () use ($actor, $session, $projectId, $sessionId, $documentId, $input, $selected, $row, $rowId, $payload, $receipts, $fingerprint): array {
                    $scope = [(int) $session->organization_id, $projectId, $sessionId];
                    $entityKey = 'equipment:review.'.hash('sha256', $rowId.'|'.$selected['proposal']['identity_cell_id']);
                    $choice = $this->choices->write($actor, $session, 'document-cell-review:'.$rowId, 'evaluation_answer',
                        ['kind' => 'document_cell_review', 'decision' => $input['decision'], 'entity_key' => $entityKey,
                            'proposal' => $selected['proposal'], 'binding' => $selected['binding'],
                            'proposal_run_id' => $selected['run_id'], 'proposal_hash' => $selected['proposal_hash'],
                            'operation_id' => $selected['operation_id'], 'input_fingerprint' => $selected['input_fingerprint'],
                            ...($input['decision'] === 'accept' ? ['identity_confirmation' => $input['identity_confirmation']] : [])],
                        $input['reason'], $input['request_id']);
                    $review = $this->models->fact(...[...$scope, $choice['fact_id']]);
                    if ($review === null) {
                        throw new \LogicException('document_cell_review_fact_missing');
                    }
                    if ($input['decision'] === 'accept') {
                        $cells = array_column($row['cells'], null, 'cell_id');
                        $cell = $cells[$selected['proposal']['value_cell_id']];
                        $native = 'xlsx:sheet:'.$row['sheet'].'!'.$cell['address'];
                        $claim = new ObservationClaim('literal:'.hash('sha256', $rowId), 'observer_literal', $entityKey, 'equipment_specification',
                            ['type' => 'string', 'data' => $cell['raw_value']], null, 'cell:'.hash('sha256', $native), true,
                            ...[...$scope, $row['source_version'], ['document_id' => $documentId, 'unit_type' => 'spreadsheet_sheet',
                                'unit_index' => $row['context']->index, 'native_reference' => $native,
                                'source_entity_key' => $cells[$selected['proposal']['identity_cell_id']]['raw_value'],
                                'fact_source' => ['kind' => 'native', 'coordinate_space' => 'native', 'value' => $native]]]);
                        $literal = $row['literal_sources']->bindReviewed($row['context'], $row['native'], [$claim], $review);
                        $publication = new DocumentUnitPublication([$claim], [new ArbitrationDecision($claim->id, 'accepted', [$claim->id],
                            [$claim->evidenceRef], 'human_reviewed_literal', null)], literalSources: $literal);
                        $this->publisher->writeReviewedLiteral($publication, $row['context'], $review);
                        $this->learning->recordCellReview($session, $selected, $review);
                    } elseif ($input['decision'] === 'revoke') {
                        $cells = array_column($row['cells'], null, 'cell_id');
                        $this->publisher->revokeReviewedLiteral($row['context'], $entityKey, $cells[$selected['proposal']['value_cell_id']]['raw_value']);
                    }
                    $result = ['request_id' => $input['request_id'], 'state_version' => (int) $session->state_version + 1,
                        'decision_id' => $choice['decision_id'], 'decision' => $input['decision'], 'cost_units' => '0',
                        'human_interpretation' => $input['decision'] === 'accept' ? 'confirmed_by_user' : 'unresolved',
                        'source_correspondence' => $input['decision'] === 'accept' ? 'passed' : 'unresolved', 'engineering_applicability' => 'unresolved'];
                    $next = $payload;
                    $next['document_cell_review_rows'][$rowId] = [...$selected, 'decision' => $input['decision'], 'decision_id' => $choice['decision_id']];
                    $next['document_cell_review_receipts'] = [...$receipts, $input['request_id'] => ['actor_id' => (int) $actor->id,
                        'fingerprint' => $fingerprint, 'result' => $result]];
                    unset($next['confirmed_evaluation']);
                    $this->authorization->authorize($actor, $session, 'estimate_generation.review');
                    $session->update(['input_payload' => $next, 'state_version' => $result['state_version']]);

                    return $result;
                });
        }, 3);
    }

    private function proposals(EstimateGenerationSession $session): array
    {
        $current = $session->input_payload['generation_credit_operation_id'] ?? null;
        $operation = is_string($current) ? $this->database->table('estimate_generation_credit_operations')->where('operation_id', $current)
            ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)->where('session_id', $session->id)
            ->where('kind', 'generation')->where('status', 'settled')->whereNotNull('receipt')->first(['input_hash']) : null;
        $currentReceipts = is_string($current) ? $this->learning->cellReviewProposalsForOperation($session, $current) : [];
        $proposals = is_string($current) && ($session->analysis_payload['credit_operation_receipt']['operation_id'] ?? null) === $current
            && $this->mutable($session) && $operation !== null
            && hash_equals($operation->input_hash, (new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint)->fromSession($session))
            ? $currentReceipts : [];
        $loaded = is_string($current) ? [$current => $currentReceipts] : [];
        foreach ($session->input_payload['document_cell_review_rows'] ?? [] as $stored) {
            if (($stored['decision'] ?? null) !== 'accept') {
                continue;
            }
            $loaded[$stored['operation_id']] ??= $this->learning->cellReviewProposalsForOperation($session, $stored['operation_id']);
            foreach ($loaded[$stored['operation_id']] as $original) {
                if ($original['proposal_hash'] === $stored['proposal_hash'] && $this->current($session, $original) !== null) {
                    $proposals = array_values(array_filter($proposals, static fn (array $row): bool => $row['proposal']['row_id'] !== $stored['proposal']['row_id']));
                    $proposals[] = $original;
                }
            }
        }

        return $proposals;
    }

    private function current(EstimateGenerationSession $session, array $proposal): ?array
    {
        $binding = $proposal['binding'];
        $row = $this->sources->resolve($session, $binding['document_id'], $binding['unit_id'], $binding['row_id']);

        return $row !== null && CanonicalPipelineJson::encode(SpreadsheetCellReviewSource::wire([$row])[0]) === CanonicalPipelineJson::encode($binding)
            && SpreadsheetCellReviewSource::proposal($proposal['proposal'], [$row]) !== null ? $row : null;
    }

    private function mutable(EstimateGenerationSession $session): bool
    {
        return in_array($session->status->value, ['input_review_required', 'ready_to_generate', 'estimate_review_required', 'ready_to_apply'], true);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['cell_review' => trans_message('estimate_generation.cell_review.invalid')]);
    }
}
