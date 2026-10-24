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
        private EstimateGenerationLearningRecorder $learning,
        private ?\App\BusinessModules\Addons\EstimateGeneration\Services\Learning\ComposerSourceClaimValidationService $sourceReviews = null) {}

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
        if (($input['kind'] ?? null) === 'source_requirement') {
            return $this->reviewSource($actor, $projectId, $sessionId, $documentId, $input);
        }
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
                function () use ($actor, $session, $projectId, $sessionId, $documentId, $input, $selected, $row, $rowId, $payload, $receipts, $fingerprint, $previous): array {
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
                    if ($input['decision'] !== 'revoke' && ($previous['decision'] ?? null) === 'accept') {
                        $originals = array_values(array_filter($this->learning->cellReviewProposalsForOperation($session, $previous['operation_id']),
                            static fn (array $original): bool => $original['run_id'] === $previous['run_id']
                                && $original['proposal_hash'] === $previous['proposal_hash'] && $original['proposal']['row_id'] === $rowId
                                && CanonicalPipelineJson::encode($original['binding']) === CanonicalPipelineJson::encode($previous['binding'])));
                        if (count($originals) !== 1) {
                            $this->invalid();
                        }
                        $original = $originals[0];
                        $oldCells = array_column($original['binding']['cells'], null, 'cell_id');
                        if (! isset($oldCells[$original['proposal']['identity_cell_id']], $oldCells[$original['proposal']['value_cell_id']])) {
                            $this->invalid();
                        }
                        $this->publisher->revokeReviewedLiteral($row['context'],
                            'equipment:review.'.hash('sha256', $rowId.'|'.$original['proposal']['identity_cell_id']),
                            $oldCells[$original['proposal']['value_cell_id']]['raw_value']);
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

    public function sourceRows(User $actor, EstimateGenerationSession $session, int $documentId): array
    {
        $this->authorization->authorize($actor, $session, 'estimate_generation.view');
        if ($this->sourceReviews === null || $this->database->getDriverName() !== 'pgsql') return [];
        $read = function () use ($actor, $session, $documentId): array {
            $fresh = EstimateGenerationSession::query()->where('organization_id', $session->getAttribute('organization_id'))
                ->where('project_id', $session->getAttribute('project_id'))->find($session->getKey());
            if (! $fresh instanceof EstimateGenerationSession || (int) $fresh->getAttribute('state_version') !== (int) $session->getAttribute('state_version')) return [];
            $this->authorization->authorize($actor, $fresh, 'estimate_generation.view');
            $canReview = $this->mutable($fresh);
            try {
                $this->authorization->authorize($actor, $fresh, 'estimate_generation.review');
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                $canReview = false;
            }
            $scope = [(int) $fresh->getAttribute('organization_id'), (int) $fresh->getAttribute('project_id'), (int) $fresh->getKey()];
            $capture = $this->sourceReviews->documentSourceReviewCandidates($scope, $documentId);
            $rows = [];
            foreach ($capture['sources'] ?? [] as $ref => $association) {
                $stored = $fresh->input_payload['document_source_review_rows'][$ref] ?? null;
                $status = $stored['decision'] ?? 'pending';
                if ($status === 'accept' && $this->sourceReviews->currentSourceReview($scope,
                    $stored['publication']['fact_id'] ?? '', $stored['fact_id'] ?? '') === null) $status = 'invalidated';
                $details = \App\BusinessModules\Addons\EstimateGeneration\Services\Learning\ComposerSourceClaimValidationService::requirementDetails($association);
                $source = $details['source'];
                $rows[] = ['kind' => 'source_requirement', 'schema_version' => 1, 'review_ref' => $ref, 'status' => $status,
                    'proposal' => array_intersect_key($details, array_flip(['parameter', 'comparator', 'threshold', 'unit', 'subject',
                        'document_edition', 'clause', 'conditions', 'equipment_identity'])),
                    'source' => ['document_id' => $documentId, 'source_version' => $source['source_version'], 'page' => $source['page_number'],
                        'region' => $source['region'], 'native_reference' => $source['native_reference']],
                    ...self::sourceOutcome($status), 'actions' => $canReview
                        ? ($status === 'pending' ? ['accept', 'reject'] : (in_array($status, ['accept', 'invalidated'], true) ? ['revoke'] : ['accept'])) : []];
            }

            return $rows;
        };
        if ($this->database->transactionLevel() > 0) {
            $isolation = $this->database->selectOne('SHOW transaction_isolation');
            $readOnly = $this->database->selectOne('SHOW transaction_read_only');

            return ($isolation->transaction_isolation ?? null) === 'repeatable read'
                && ($readOnly->transaction_read_only ?? null) === 'on' ? $read() : [];
        }

        return $this->database->transaction(function () use ($read): array {
            $this->database->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $read();
        });
    }

    private static function sourceOutcome(string $decision): array
    {
        return ['human_interpretation' => $decision === 'accept' ? 'confirmed_by_user' : 'unresolved',
            'source_correspondence' => 'unresolved', 'source_authority' => 'unresolved', 'engineering_applicability' => 'unresolved'];
    }

    private function reviewSource(User $actor, int $projectId, int $sessionId, int $documentId, array $input): array
    {
        $keys = ['kind', 'request_id', 'state_version', 'review_ref', 'decision', 'reason'];
        if (count($input) !== count($keys) || array_diff($keys, array_keys($input)) !== [] || ! Str::isUuid($input['request_id'] ?? '')
            || ! is_int($input['state_version'] ?? null) || $input['state_version'] < 0
            || ! is_string($input['review_ref'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $input['review_ref']) !== 1
            || ! in_array($input['decision'] ?? null, ['accept', 'reject', 'revoke'], true)
            || ! is_string($input['reason'] ?? null) || mb_strlen(trim($input['reason'])) < 12 || strlen($input['reason']) > 1000
            || $this->sourceReviews === null) $this->invalid();

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $documentId, $input): array {
            $sessionQuery = EstimateGenerationSession::query();
            $sessionQuery->where('organization_id', $actor->getAttribute('current_organization_id'))->where('project_id', $projectId)->lockForUpdate();
            $session = $sessionQuery->find($sessionId);
            if (! $session instanceof EstimateGenerationSession) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(EstimateGenerationSession::class, [$sessionId]);
            }
            $this->authorization->authorize($actor, $session, 'estimate_generation.view');
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            $documentQuery = EstimateGenerationDocument::query();
            $documentQuery->where('organization_id', $session->getAttribute('organization_id'))->where('project_id', $projectId)
                ->where('session_id', $sessionId)->lockForUpdate();
            $document = $documentQuery->find($documentId);
            if (! $document instanceof EstimateGenerationDocument) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(EstimateGenerationDocument::class, [$documentId]);
            }
            $payload = $session->input_payload ?? [];
            $receipts = $payload['document_cell_review_receipts'] ?? [];
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode([$documentId, $input]));
            if (isset($receipts[$input['request_id']])) {
                $receipt = $receipts[$input['request_id']];
                if ($receipt['actor_id'] !== (int) $actor->getKey() || ! hash_equals($receipt['fingerprint'], $fingerprint)) $this->invalid();

                return $receipt['result'];
            }
            if ((int) $session->getAttribute('state_version') !== $input['state_version'] || ! $this->mutable($session)) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            if ($document->getAttribute('ignored_at') !== null) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            if (count($receipts) >= 10000) $this->invalid();
            $scope = [(int) $session->getAttribute('organization_id'), $projectId, $sessionId];
            $capture = $this->sourceReviews->documentSourceReviewCandidates($scope, $documentId);
            $association = $capture['sources'][$input['review_ref']] ?? null;
            if ($association === null) $this->invalid();
            $original = array_column($capture['snapshot']->facts, null, 'id')[$association['source_fact_id']] ?? null;
            $entity = array_column($capture['snapshot']->entities, null, 'id')[$association['source_entity_id']] ?? null;
            if ($original === null || $entity === null) $this->invalid();
            $unitQuery = EstimateGenerationProcessingUnit::query();
            $unitQuery->where('organization_id', $scope[0])->where('project_id', $scope[1])
                ->where('session_id', $scope[2])->where('document_id', $documentId)->lockForUpdate();
            $unit = $unitQuery->find($association['output_identity']['processing_unit_id']);
            if (! $unit instanceof EstimateGenerationProcessingUnit) $this->invalid();
            $fresh = $this->sourceReviews->documentSourceReviewCandidates($scope, $documentId);
            if (CanonicalPipelineJson::encode($fresh['sources'][$input['review_ref']] ?? []) !== CanonicalPipelineJson::encode($association)) $this->invalid();
            $previous = $payload['document_source_review_rows'][$input['review_ref']] ?? null;
            $allowed = match ($previous['decision'] ?? 'pending') {
                'pending' => ['accept', 'reject'],
                'accept' => ['revoke'],
                'reject', 'revoke' => ['accept'],
                default => [],
            };
            if (! in_array($input['decision'], $allowed, true)) $this->invalid();

            return $this->publisher->transaction($scope[0], $scope[2], function () use ($actor, $session, $scope, $input,
                $association, $original, $entity, $capture, $payload, $receipts, $fingerprint, $previous): array {
                $publication = $previous['publication'] ?? null;
                if ($input['decision'] === 'accept') {
                    $version = $original->version;
                    foreach ($this->models->currentFacts(...[...$scope, $original->entityId]) as $fact) {
                        if ($fact->type === $original->type && $fact->sourceVersion === $original->sourceVersion) $version = max($version, $fact->version);
                    }
                    $publication = ['fact_id' => \App\BusinessModules\Addons\EstimateGeneration\Services\Learning\ComposerSourceClaimValidationService::sourceReviewPublicationId(
                        $scope, $input['review_ref'], $input['request_id']), 'version' => $version + 1];
                }
                $choice = $this->choices->write($actor, $session, 'document-source-review:'.$input['review_ref'], 'evaluation_answer',
                    ['schema_version' => 1, 'kind' => 'document_source_review', 'review_kind' => 'source_requirement',
                        'review_ref' => $input['review_ref'], 'decision' => $input['decision'], 'source' => $association, 'publication' => $publication],
                    $input['reason'], $input['request_id']);
                $review = $this->models->factsByIds(...[...$scope, [$choice['fact_id']]])[0] ?? null;
                if ($review === null) throw new \LogicException('document_source_review_fact_missing');
                if ($input['decision'] === 'accept') {
                    $this->publisher->writeReviewedSourceRequirement($entity, $original, $capture['snapshot']->evidence, $review);
                }
                $result = ['kind' => 'source_requirement', 'request_id' => $input['request_id'], 'state_version' => (int) $session->getAttribute('state_version') + 1,
                    'review_ref' => $input['review_ref'], 'decision_id' => $choice['decision_id'], 'decision' => $input['decision'],
                    'cost_units' => '0', ...self::sourceOutcome($input['decision'])];
                $payload['document_source_review_rows'][$input['review_ref']] = ['decision' => $input['decision'],
                    'fact_id' => $review->id, 'publication' => $publication];
                $payload['document_cell_review_receipts'] = [...$receipts, $input['request_id'] => ['actor_id' => (int) $actor->getKey(),
                    'fingerprint' => $fingerprint, 'result' => $result]];
                unset($payload['confirmed_evaluation']);
                $this->authorization->authorize($actor, $session, 'estimate_generation.view');
                $this->authorization->authorize($actor, $session, 'estimate_generation.review');
                $session->update(['input_payload' => $payload, 'state_version' => $result['state_version']]);

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
