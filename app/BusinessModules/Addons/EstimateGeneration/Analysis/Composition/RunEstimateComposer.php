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
            return $this->validate($claim->result->payload['work_intents'] ?? null, $input);
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
            $intents = $this->validate($raw['work_intents'], $input);
            $this->runs->complete($claim->runId, $claim->ownerUuid, new AiRoleRunResult([
                'schema_version' => 1,
                'role' => AiAnalysisRole::EstimateComposer->value,
                'work_intents' => $intents,
            ], $physicalAttemptId));

            return $intents;
        } catch (Throwable $exception) {
            $this->runs->fail($claim->runId, $claim->ownerUuid, new AiRoleRunFailure(
                code: 'estimate_composer_failed',
                ambiguous: $physicalAttemptId !== null,
                physicalAttemptId: $physicalAttemptId,
            ));

            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    private function validate(mixed $payload, EstimateComposerInput $input): array
    {
        if (! is_array($payload) || ! array_is_list($payload)) {
            throw new InvalidArgumentException('estimate_composer_result_shape_invalid');
        }
        $candidateById = [];
        foreach ($input->candidates as $candidate) {
            if (isset($candidateById[$candidate['candidate_id']])) {
                throw new InvalidArgumentException('estimate_composer_input_candidate_duplicate');
            }
            $candidateById[$candidate['candidate_id']] = $candidate;
        }
        $allowedFactIds = array_fill_keys(array_column($input->facts, 'id'), true);
        $allowedQuantityIds = array_fill_keys(array_column($input->derivedQuantities, 'id'), true);
        $allowedTechnologyCandidates = array_fill_keys(array_values(array_filter(
            array_column($input->candidates, 'technology_package_candidate'),
            'is_string',
        )), true);
        $candidateWorkKeys = array_fill_keys(array_column($input->candidates, 'work_key'), true);
        $intentsById = [];
        $supplementaryById = [];
        $supplementaryWorkKeys = [];
        foreach ($payload as $record) {
            if (! is_array($record)) {
                throw new InvalidArgumentException('estimate_work_intent_shape_invalid');
            }
            $intent = EstimateWorkIntent::fromArray($record);
            if (isset($intentsById[$intent->candidateId]) || isset($supplementaryById[$intent->candidateId])) {
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
            $candidate = $candidateById[$intent->candidateId] ?? null;
            if ($candidate === null) {
                throw new InvalidArgumentException('estimate_composer_candidate_coverage_invalid');
            }
            if ($intent->technologyPackageCandidate !== $candidate['technology_package_candidate']) {
                throw new InvalidArgumentException('estimate_composer_technology_candidate_invalid');
            }
            $intentsById[$intent->candidateId] = $intent->toArray();
        }
        if (array_diff_key($candidateById, $intentsById) !== [] || count($candidateById) !== count($intentsById)) {
            throw new InvalidArgumentException('estimate_composer_candidate_coverage_invalid');
        }

        ksort($supplementaryById, SORT_STRING);

        return [...array_map(
            static fn (array $candidate): array => $intentsById[$candidate['candidate_id']],
            $input->candidates,
        ), ...array_values($supplementaryById)];
    }
}
