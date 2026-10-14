<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiWireNotStarted;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class RunEstimateComposer
{
    public const PROMPT_CONTRACT = 'estimate-composer:v4';

    public function __construct(
        private AiRoleRunRepository $runs,
        private EstimateComposerModel $model,
        private string $modelName,
        private EstimateComposerBatchPlanner $batches = new EstimateComposerBatchPlanner,
        private ?EstimateComposerAllowance $allowance = null,
    ) {}

    /** @return list<array<string, mixed>> */
    public function run(EstimateComposerInput $input): array
    {
        return $this->runDetailed($input)['intents'];
    }

    /** @return array{intents:list<array<string,mixed>>,quarantined:list<array<string,mixed>>} */
    public function runDetailed(EstimateComposerInput $input): array
    {
        $limit = $this->allowance?->partLimit($input);
        if ($input->wireScope?->creditOperationId !== null && $this->allowance === null) {
            throw new InvalidArgumentException('estimate_composer_allowance_unavailable');
        }
        if ($limit === 0) {
            throw new InvalidArgumentException('estimate_composer_parts_exceed_approved_plan');
        }
        $policy = \App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy::forOperation(
            $input->organizationId, $input->projectId, $input->sessionId, $input->wireScope?->creditOperationId, 'estimate_composition');
        $plan = $this->batches->withWirePolicy($policy)->plan($input);
        if ($limit !== null && count($plan) > $limit) {
            throw new InvalidArgumentException('estimate_composer_parts_exceed_approved_plan');
        }
        if (count($plan) === 1) {
            return $this->runBatch($plan[0]);
        }
        $existing = [];
        $supplementary = [];
        $quarantined = [];
        $workKeys = array_fill_keys(array_column($input->candidates, 'work_key'), true);
        foreach ($plan as $batch) {
            $outcome = $this->runBatch($batch);
            foreach ($outcome['quarantined'] as $rejected) {
                $quarantined[] = [...$rejected, 'batch_key' => $batch->batchKey];
            }
            foreach ($outcome['intents'] as $intent) {
                if ($intent['kind'] === 'existing') {
                    $existing[$intent['candidate_id']] = $intent;
                } elseif (! isset($workKeys[$intent['work_key']])) {
                    $identity = CanonicalPipelineJson::encode([$intent['work_key'], $intent['derived_quantity_id'], $intent['source_fact_ids']]);
                    if (isset($supplementary[$intent['work_key']]) && $supplementary[$intent['work_key']]['identity'] !== $identity) {
                        throw new InvalidArgumentException('estimate_composer_cross_batch_work_conflict');
                    }
                    $supplementary[$intent['work_key']] ??= ['identity' => $identity, 'intent' => $intent];
                }
            }
        }

        return ['intents' => [...array_map(static fn (array $candidate): array => $existing[$candidate['candidate_id']], $input->candidates),
            ...array_column(array_values($supplementary), 'intent')], 'quarantined' => $quarantined];
    }

    /** @return array{intents:list<array<string,mixed>>,quarantined:list<array<string,mixed>>} */
    private function runBatch(EstimateComposerInput $input): array
    {
        $runInput = new AiRoleRunInput(
            organizationId: $input->organizationId,
            projectId: $input->projectId,
            sessionId: $input->sessionId,
            documentId: null,
            pageId: null,
            subjectType: 'estimate_session',
            subjectId: (string) $input->sessionId,
            subjectVersion: $input->batchKey === null ? $input->snapshotToken : hash('sha256', $input->snapshotToken.'|'.$input->batchKey),
            role: AiAnalysisRole::EstimateComposer,
            model: $this->modelName,
            promptContractVersion: self::PROMPT_CONTRACT,
            inputFingerprint: $input->fingerprint(),
        );
        $owner = AiOperationContext::deterministicId('estimate-composer-owner|'.$input->fingerprint().'|'.bin2hex(random_bytes(16)));
        $claim = $this->runs->claim($runInput, $owner);
        if ($claim->disposition === 'replay' && $claim->result !== null) {
            $stored = $claim->result->payload['work_intents'] ?? null;
            if (! is_array($stored) || ! array_is_list($stored)) {
                throw new RuntimeException('estimate_composer_replay_invalid');
            }

            $quarantined = $claim->result->payload['quarantined_intents'] ?? [];
            if (! is_array($quarantined) || ! array_is_list($quarantined)) {
                throw new RuntimeException('estimate_composer_replay_invalid');
            }
            $replayed = [];
            $quantityProvenance = new ComposedQuantityProvenance($input->derivedQuantities, $input->facts);
            $allowedFactIds = array_fill_keys(array_column($input->facts, 'id'), true);
            foreach ($stored as $index => $intent) {
                if (! is_array($intent)) {
                    throw new RuntimeException('estimate_composer_replay_invalid');
                }
                $typed = EstimateWorkIntent::fromArray($intent);
                try {
                    if ($typed->kind === 'supplementary') {
                        $typed = $this->bindSupplementary($typed, $input, $quantityProvenance);
                    }
                    $replayed[] = $typed->toArray();
                } catch (InvalidArgumentException $exception) {
                    $quarantined[] = $this->quarantine($index, $exception, $intent, $allowedFactIds);
                }
            }

            return ['intents' => $replayed, 'quarantined' => $quarantined];
        }
        if ($claim->disposition !== 'owned' || $claim->ownerUuid === null) {
            throw new RuntimeException('estimate_composer_role_run_'.$claim->disposition);
        }
        $physicalAttemptId = null;
        try {
            $raw = $this->model->compose(
                $input,
                function (string $attemptId) use ($input, $claim, &$physicalAttemptId): void {
                    $creditId = $input->wireScope?->creditOperationId;
                    $this->runs->startPhysicalAttempt($claim->runId, $claim->ownerUuid, $attemptId, $creditId, $creditId === null ? null : 'estimate_composition');
                    $physicalAttemptId = $attemptId;
                },
            );
            if ($physicalAttemptId === null) {
                throw new UsageInvariantViolation('Estimate composer returned without a physical attempt identity.');
            }
            if (array_keys($raw) !== ['work_intents']) {
                throw new InvalidArgumentException('estimate_composer_result_shape_invalid');
            }
            $projection = $this->project($raw['work_intents'], $input);
            $this->runs->complete($claim->runId, $claim->ownerUuid, new AiRoleRunResult([
                'schema_version' => 1,
                'role' => AiAnalysisRole::EstimateComposer->value,
                'work_intents' => $projection['intents'],
                'quarantined_intents' => $projection['quarantined'],
                'result_state' => $projection['quarantined'] === [] ? 'ready' : 'partial',
            ], $physicalAttemptId));

            return $projection;
        } catch (Throwable $exception) {
            $this->runs->fail($claim->runId, $claim->ownerUuid, new AiRoleRunFailure(
                code: 'estimate_composer_failed',
                ambiguous: $physicalAttemptId !== null && ! $exception instanceof AiWireNotStarted,
                physicalAttemptId: $physicalAttemptId,
                wireNotStarted: $exception instanceof AiWireNotStarted,
            ));

            throw $exception;
        }
    }

    /** @return array{intents:list<array<string,mixed>>,quarantined:list<array{index:int,reason:string}>} */
    private function project(mixed $payload, EstimateComposerInput $input): array
    {
        if (! is_array($payload) || ! array_is_list($payload) || count($payload) > 1000) {
            throw new InvalidArgumentException('estimate_composer_result_shape_invalid');
        }
        $candidateById = [];
        $intentsById = [];
        foreach ($input->candidates as $candidate) {
            if (isset($candidateById[$candidate['candidate_id']])) {
                throw new InvalidArgumentException('estimate_composer_input_candidate_duplicate');
            }
            $candidateById[$candidate['candidate_id']] = $candidate;
            $intentsById[$candidate['candidate_id']] = (new EstimateWorkIntent(
                'existing',
                $candidate['candidate_id'],
                null,
                null,
                null,
                $candidate['source_fact_ids'],
                $candidate['technology_package_candidate'],
                [],
                [],
                [],
            ))->toArray();
        }
        $allowedFactIds = array_fill_keys(array_column($input->facts, 'id'), true);
        $allowedQuantityIds = array_fill_keys(array_column($input->derivedQuantities, 'id'), true);
        $allowedTechnologyCandidates = array_fill_keys(array_values(array_filter(
            array_column($input->candidates, 'technology_package_candidate'),
            'is_string',
        )), true);
        $candidateWorkKeys = array_fill_keys(array_column($input->candidates, 'work_key'), true);
        $supplementaryById = [];
        $supplementaryWorkKeys = [];
        $seenCandidateIds = [];
        $quarantined = [];
        $quantityProvenance = new ComposedQuantityProvenance($input->derivedQuantities, $input->facts);
        foreach ($payload as $index => $record) {
            try {
                if (! is_array($record) || array_is_list($record)) {
                    throw new InvalidArgumentException('estimate_work_intent_shape_invalid');
                }
                $kind = $record['kind'] ?? null;
                $candidate = $kind === 'existing' && is_string($record['candidate_id'] ?? null)
                    ? ($candidateById[$record['candidate_id']] ?? null)
                    : null;
                if ($kind === 'existing' && $candidate === null) {
                    throw new InvalidArgumentException('estimate_composer_candidate_coverage_invalid');
                }
                $projected = [
                    'kind' => $kind,
                    'candidate_id' => $kind === 'supplementary'
                        ? 'supplementary:'.substr(hash('sha256', json_encode([
                            $input->fingerprint(), $index, $record['work_key'] ?? null,
                            $record['source_fact_ids'] ?? null,
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 0, 32)
                        : ($candidate['candidate_id'] ?? null),
                    'work_key' => $kind === 'existing' ? null : ($record['work_key'] ?? null),
                    'name' => $kind === 'existing' ? null : ($record['name'] ?? null),
                    'derived_quantity_id' => $kind === 'existing' ? null : ($record['derived_quantity_id'] ?? null),
                    'source_fact_ids' => $kind === 'existing'
                        ? ($candidate['source_fact_ids'] ?? [])
                        : ($record['source_fact_ids'] ?? null),
                    'technology_package_candidate' => $kind === 'existing'
                        ? ($candidate['technology_package_candidate'] ?? null)
                        : ($record['technology_package_candidate'] ?? null),
                    'assumptions' => $record['assumptions'] ?? null,
                    'exclusions' => $record['exclusions'] ?? null,
                    'missing_document_recommendations' => $record['missing_document_recommendations'] ?? null,
                ];
                if ($kind === 'supplementary' && (array_key_exists('unit', $record) || array_key_exists('work_section', $record))) {
                    $projected['unit'] = $record['unit'] ?? null;
                    $projected['work_section'] = $record['work_section'] ?? null;
                }
                if ($kind === 'supplementary' && array_key_exists('entity_id', $record)) {
                    $projected['entity_id'] = $record['entity_id'];
                }
                $intent = EstimateWorkIntent::fromArray($projected);
                if (isset($seenCandidateIds[$intent->candidateId]) || isset($supplementaryById[$intent->candidateId])) {
                    throw new InvalidArgumentException('estimate_composer_candidate_duplicate');
                }
                foreach ($intent->sourceFactIds as $factId) {
                    if (! isset($allowedFactIds[$factId])) {
                        throw new InvalidArgumentException('estimate_composer_source_fact_invalid');
                    }
                }
                if ($intent->kind === 'supplementary') {
                    if ($intent->workSection !== null && ! in_array($intent->workSection, $input->allowedWorkSections, true)) {
                        throw new InvalidArgumentException('estimate_composer_work_section_not_selected');
                    }
                    if ($intent->workKey === null
                        || isset($candidateWorkKeys[$intent->workKey])
                        || isset($supplementaryWorkKeys[$intent->workKey])) {
                        throw new InvalidArgumentException('estimate_composer_supplementary_duplicate');
                    }
                    if ($intent->derivedQuantityId !== null && ! isset($allowedQuantityIds[$intent->derivedQuantityId])) {
                        throw new InvalidArgumentException('estimate_composer_derived_quantity_invalid');
                    }
                    $intent = $this->bindSupplementary($intent, $input, $quantityProvenance);
                    if ($intent->technologyPackageCandidate !== null
                        && ! isset($allowedTechnologyCandidates[$intent->technologyPackageCandidate])) {
                        throw new InvalidArgumentException('estimate_composer_technology_candidate_invalid');
                    }
                    $supplementaryWorkKeys[$intent->workKey] = true;
                    $supplementaryById[$intent->candidateId] = $intent->toArray();

                    continue;
                }
                $seenCandidateIds[$intent->candidateId] = true;
                $intentsById[$intent->candidateId] = $intent->toArray();
            } catch (InvalidArgumentException $exception) {
                $quarantined[] = $this->quarantine($index, $exception, $record, $allowedFactIds);
            }
        }
        ksort($supplementaryById, SORT_STRING);
        if ($input->candidates === [] && $supplementaryById === [] && $quarantined === []) {
            $quarantined[] = ['index' => -1, 'reason' => 'estimate_composer_work_scope_unresolved',
                'work_name' => null, 'next_action' => 'provide_work_scope', 'source_fact_ids' => []];
        }

        return [
            'intents' => [...array_map(
                static fn (array $candidate): array => $intentsById[$candidate['candidate_id']],
                $input->candidates,
            ), ...array_values($supplementaryById)],
            'quarantined' => $quarantined,
        ];
    }

    private function bindSupplementary(EstimateWorkIntent $intent, EstimateComposerInput $input, ComposedQuantityProvenance $quantityProvenance): EstimateWorkIntent
    {
        if ($intent->workSection !== null && ! in_array($intent->workSection, $input->allowedWorkSections, true)) {
            throw new InvalidArgumentException('estimate_composer_work_section_not_selected');
        }
        if (array_diff($intent->sourceFactIds, array_column($input->facts, 'id')) !== []) {
            throw new InvalidArgumentException('estimate_composer_source_fact_invalid');
        }
        $quantityBinding = $quantityProvenance->binding($intent->toArray());
        $target = $intent->entityId ?? $quantityBinding['entity_id'] ?? null;
        $sourceBinding = (new WorkIntentSourceClassifier)->binding([
            ...$intent->toArray(), ...($target === null ? [] : ['entity_id' => $target]),
        ], $input->facts);

        return EstimateWorkIntent::fromArray([...$intent->toArray(), 'entity_id' => $sourceBinding['entity_id']]);
    }

    private function quarantine(int $index, InvalidArgumentException $exception, mixed $record, array $allowedFactIds): array
    {
        return ['index' => $index, 'reason' => $exception->getMessage(),
            'work_name' => is_array($record) && is_string($record['name'] ?? null) && mb_strlen($record['name']) <= 300 ? $record['name'] : null,
            'next_action' => 'confirm_work_source',
            'source_fact_ids' => is_array($record) && is_array($record['source_fact_ids'] ?? null)
                ? array_values(array_filter($record['source_fact_ids'], static fn ($id): bool => is_string($id) && isset($allowedFactIds[$id]))) : []];
    }
}
