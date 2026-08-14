<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class RunEstimateComposer
{
    public const PROMPT_CONTRACT = 'estimate-composer:v2';

    public function __construct(
        private AiRoleRunRepository $runs,
        private EstimateComposerModel $model,
        private string $modelName,
    ) {}

    /** @return list<array<string, mixed>> */
    public function run(EstimateComposerInput $input): array
    {
        $runInput = new AiRoleRunInput(
            organizationId: $input->organizationId,
            projectId: $input->projectId,
            sessionId: $input->sessionId,
            documentId: null,
            pageId: null,
            subjectType: 'estimate_session',
            subjectId: (string) $input->sessionId,
            subjectVersion: $input->snapshotToken,
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

            $replayed = [];
            foreach ($stored as $intent) {
                if (! is_array($intent)) {
                    throw new RuntimeException('estimate_composer_replay_invalid');
                }
                $replayed[] = EstimateWorkIntent::fromArray($intent)->toArray();
            }

            return $replayed;
        }
        if ($claim->disposition !== 'owned' || $claim->ownerUuid === null) {
            throw new RuntimeException('estimate_composer_role_run_'.$claim->disposition);
        }
        $physicalAttemptId = null;
        try {
            $raw = $this->model->compose(
                $input,
                function (string $attemptId) use ($claim, &$physicalAttemptId): void {
                    $this->runs->startPhysicalAttempt($claim->runId, $claim->ownerUuid, $attemptId);
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

            return $projection['intents'];
        } catch (Throwable $exception) {
            $this->runs->fail($claim->runId, $claim->ownerUuid, new AiRoleRunFailure(
                code: 'estimate_composer_failed',
                ambiguous: $physicalAttemptId !== null,
                physicalAttemptId: $physicalAttemptId,
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
                    if ($intent->workKey === null
                        || isset($candidateWorkKeys[$intent->workKey])
                        || isset($supplementaryWorkKeys[$intent->workKey])) {
                        throw new InvalidArgumentException('estimate_composer_supplementary_duplicate');
                    }
                    if ($intent->derivedQuantityId !== null && ! isset($allowedQuantityIds[$intent->derivedQuantityId])) {
                        throw new InvalidArgumentException('estimate_composer_derived_quantity_invalid');
                    }
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
                $quarantined[] = ['index' => $index, 'reason' => $exception->getMessage()];
            }
        }
        ksort($supplementaryById, SORT_STRING);

        return [
            'intents' => [...array_map(
                static fn (array $candidate): array => $intentsById[$candidate['candidate_id']],
                $input->candidates,
            ), ...array_values($supplementaryById)],
            'quarantined' => $quarantined,
        ];
    }
}
